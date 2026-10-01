<?php

declare(strict_types=1);

namespace Jeeflow\Core\Repository;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Util\CcActorUtil;

/**
 * 内存仓储实现 —— 用于单元测试
 *
 * 对齐 Java MemoryProcessRepository。所有数据保存在内存数组中。
 */
class InMemoryProcessRepository implements ProcessRepositoryInterface
{
    /** @var array<string, array> 流程定义 */
    private array $defines = [];

    /** @var array<string, ProcessInstance> 流程实例 */
    private array $instances = [];

    /** @var array<string, ProcessTask> 流程任务 */
    private array $tasks = [];

    /** @var array<int, array> 抄送记录 */
    private array $ccInstances = [];

    private IdGeneratorInterface $idGenerator;

    public function __construct(?IdGeneratorInterface $idGenerator = null)
    {
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    public function getIdGenerator(): IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    // ── 定义 ──

    public function addDefine(array $define): void
    {
        $id = (string) ($define['id'] ?? $this->idGenerator->nextId());
        $define['id'] = $id;
        $this->defines[$id] = $define;
    }

    public function findDefineById(int|string $id): ?array
    {
        return $this->defines[(string) $id] ?? null;
    }

    public function updateDefine(array $define): void
    {
        $id = (string) $define['id'];
        if (isset($this->defines[$id])) {
            $this->defines[$id] = array_merge($this->defines[$id], $define);
        }
    }

    public function removeDefine(int|string $id): void
    {
        unset($this->defines[(string) $id]);
    }

    public function updateDefineState(int|string $id, int $state): void
    {
        $id = (string) $id;
        if (isset($this->defines[$id])) {
            $this->defines[$id]['state'] = $state;
        }
    }

    public function findLatestDefineByName(string $name): ?array
    {
        $found = null;
        foreach ($this->defines as $d) {
            if (($d['name'] ?? '') === $name) {
                if ($found === null || ($d['version'] ?? 0) > ($found['version'] ?? 0)) {
                    $found = $d;
                }
            }
        }
        return $found;
    }

    public function pageDefines(PageQuery $query): PageResult
    {
        $rows = array_values($this->defines);
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getDefineField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 实例 ──

    public function saveInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        if ($instance->getInstanceId() === null) {
            $instance->setInstanceId((string) $this->idGenerator->nextId());
        }
        $this->instances[$instance->getInstanceId()] = $instance;
    }

    public function updateInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        $this->instances[$instance->getInstanceId()] = $instance;
    }

    public function findInstanceById(int|string $id): ?object
    {
        return $this->instances[(string) $id] ?? null;
    }

    public function pageInstances(PageQuery $query): PageResult
    {
        $rows = [];
        foreach ($this->instances as $inst) {
            $rows[] = $this->instanceRowWithDefine($inst);
        }
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getInstanceField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 任务 ──

    public function saveTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        if ($task->getTaskId() === null) {
            $task->setTaskId((string) $this->idGenerator->nextId());
        }
        $this->tasks[$task->getTaskId()] = $task;
    }

    public function updateTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        $this->tasks[$task->getTaskId()] = $task;
    }

    public function findTaskById(int|string $id): ?object
    {
        return $this->tasks[(string) $id] ?? null;
    }

    public function findDoingTasks(int|string $instanceId, ?array $actorIds = null): array
    {
        $result = [];
        foreach ($this->tasks as $task) {
            if ($task->getProcessInstanceId() !== (string) $instanceId) continue;
            if ($task->getTaskState() !== ProcessTaskState::DOING) continue;
            if ($actorIds !== null && !empty($actorIds)) {
                $taskActors = $task->getActorIds();
                if (empty(array_intersect($actorIds, $taskActors))) continue;
            }
            $result[] = $task;
        }
        return $result;
    }

    public function findHistoryTasks(int|string $instanceId): array
    {
        // issues/82-10：历史任务=实例全部任务（含进行中），对齐 Java 内存/Go(state=-1)/Node(null)/Python。
        // 此前排除 DOING 导致 highLight nodeProgress 拿不到会签进行中任务 → 成员/active 全丢。
        $result = [];
        foreach ($this->tasks as $task) {
            if ($task->getProcessInstanceId() !== (string) $instanceId) continue;
            $result[] = $task;
        }
        return $result;
    }

    /**
     * 追加任务参与人（**增量**语义，原参与人不动）。
     *
     * issues/142 B 批（spec 06-facade.md §2.11「归属值写侧归一」要求①「两层都挡」的第二层）：
     * 入参先过 {@see CcActorUtil::normalizeList()}——trim、空串/纯空白/null 丢弃、同次调用折叠，
     * 落库值与判重**都取 trim 后的串**（`" 123 "` 与 `"123"` 是同一个人）。绕过门面直连本仓储的
     * 调用方（集成层、第三方仓储消费者）同样灌不进 `actor_id=''`，那是 issues/129 那族
     * 「空归属值读全库」的进水口。判据单点复用，与 PDO 仓逐字同一条（两仓必须同答案，issues/117 场景 27）。
     *
     * ⚠️ 判重是**严格**串比较（`in_array(..., true)`）：松散比较在 PHP 8 把数字串按数值比，
     * `'0' == '00'`、`'1' == '01'` ⇒ 第二个人被静默丢掉（issues/141 G2 在本栈的实测踩点，
     * commit a45dd13 收口；本方法比较的两侧都已经是归一后的串，不存在第二把尺子）。
     */
    public function addTaskActor(int|string $taskId, array $actorIds): void
    {
        // 写侧兜底＋空列表早退：归一后为空 ⇒ 一次写入都不发生（B 表实读本栈两仓都没有这一层）
        $actorIds = CcActorUtil::normalizeList($actorIds);
        if ($actorIds === []) return;
        $task = $this->tasks[(string) $taskId] ?? null;
        if ($task === null) return;
        $existing = $task->getActorIds();
        // 与既有值判重也取同一把尺子：既有集合过一遍归一再比（脏历史行里的 " 8601 " 与 "8601" 同人）
        $known = CcActorUtil::normalizeList($existing);
        foreach ($actorIds as $aid) {
            if (!in_array($aid, $known, true)) {
                $existing[] = $aid;
                $known[] = $aid;
            }
        }
        $task->setActorIds($existing);
    }

    /**
     * issues/115：按人摘行——只剔掉传入的那几个人，其余参与人原样保留。
     * 与 addTaskActor 同为**增量**语义（不是全量重置），转办摘原人依赖它。
     *
     * **归属值删除腿义务**（issues/137 §3-6 · spec 06-facade.md §processTask/removeTaskActor 语义 6，
     * owner 2026-10-02 拍「两形并集」）——**与上面 `addTaskActor` 的写侧义务不同，别照抄**：
     *
     *  1. **空值一律丢弃、不参与匹配**：`null`／`''`／纯空白都不得进删除集合，否则历史 `actor_id=''`
     *     脏行会被批量误删（那是替脏数据做掉唯一痕迹）；
     *  2. **非空值同时以「原值」与「trim 值」两形匹配**（按字面去重、保序）。只取 trim 形（本方法
     *     issues/142 §9.2 那批的旧形状）⇒ 门面按语义 6 交出的历史脏行原值 `" 9101 "` 被削成 `9101`，
     *     真库 NO PAD 排序规则下那一行删不掉而门面报成功（**假成功**：被摘的人待办还在）；只取原值
     *     ⇒ 绕过门面直连仓储传 `" 8601 "` 时删不掉写侧归一后落库的规范行 `8601`（§9.2 那一路，
     *     **这一半旧形状是对的，并集里继续保住**）；
     *  3. **并集为空 ⇒ 早退**，一行都不删（不得退化成"清空该任务全部参与者"）。
     *
     * 判据本体只有一枚＝{@see CcActorUtil::deleteForms()}（trim／判空仍复用 `CcActorUtil` 那一支，
     * 不在仓储里抄第二份）。判空一律 `trim((string) $x) === ''`——`'0'` 是合法 id 必须留下，
     * 且 `'0'` 与 `'00'` 是两个人；比较一律**严格**（`in_array(..., true)`），松散比较在 PHP 8
     * 把数字串按数值比（issues/141 G2 本栈实测踩点）。
     *
     * ⚠️ **库值那一侧按字面精确比、不再 trim**：并集已经在入参侧覆盖了两形（原值形打脏行、
     * trim 形打规范行），库值再 trim 会让"删未 trim 历史脏行"这件事在内存仓照不出来
     * （脏行 `' 9101 '` trim 后被 trim 形命中 ⇒ 假绿），也会让内存仓与 PDO 仓
     * （`actor_id = ?` 列值精确比较）两把尺子分叉。与 PDO 仓必须同判据同答案（issues/117 场景 27）。
     */
    public function removeTaskActor(int|string $taskId, array $actorIds): void
    {
        // 义务③：并集为空 ⇒ 早退，一次删除都不发生（含任务不存在那一档，同样是零操作不抛异常）
        $forms = CcActorUtil::deleteForms($actorIds);
        if ($forms === []) return;
        $task = $this->tasks[(string) $taskId] ?? null;
        if ($task === null) return;
        $kept = array_values(array_filter(
            $task->getActorIds(),
            static fn($aid): bool => !in_array((string) $aid, $forms, true)
        ));
        $task->setActorIds($kept);
    }

    public function pageTodoTasks(PageQuery $query): PageResult
    {
        // Extract actor filter from conditions
        $actorFilter = null;
        $remainingConditions = [];
        foreach ($query->getConditions() as $cond) {
            $clean = preg_replace('/^[a-z]+\./', '', $cond['column']);
            if ($clean === 'actor_id' && $cond['op'] === 'EQ') {
                $actorFilter = (string) $cond['value'];
            } else {
                $remainingConditions[] = $cond;
            }
        }
        $rows = [];
        foreach ($this->tasks as $task) {
            if ($task->getTaskState() !== ProcessTaskState::DOING) continue;
            if ($actorFilter !== null && !in_array($actorFilter, $task->getActorIds(), true)) continue;
            $rows[] = $this->taskToRow($task);
        }
        // Apply remaining filters
        foreach ($remainingConditions as $cond) {
            $rows = array_filter($rows, fn($row) => $this->matchCondition($row, $cond['column'], $cond['op'], $cond['value']));
        }
        $rows = array_values($rows);
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    public function pageDoneTasks(PageQuery $query): PageResult
    {
        $rows = [];
        foreach ($this->tasks as $task) {
            if ($task->getTaskState() === ProcessTaskState::DOING) continue;
            $rows[] = $this->taskToRow($task);
        }
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getTaskField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 抄送 ──

    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void
    {
        // issues/141 G10「空不创建行」（spec 06-facade.md §2.10 实现要求①「两层都挡」）：**写侧**兜底——
        // 空串/纯空白/null 一律丢弃，落库值取 trim 后的串（" 123 " 与 "123" 是同一个人）。绕过引擎漏斗
        // 与门面**直连本仓储**的调用方（集成层、第三方仓储消费者）同样建不出 actor_id='' 的行。
        // 判据与 PDO 仓、与漏斗层同一条：都走 \Jeeflow\Core\Util\CcActorUtil（单点复用，别再抄两份）。
        $actorIds = CcActorUtil::normalizeList($actorIds);
        // issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4），与 PDO 仓同一条判据：
        // 同一 (实例, 被抄送人) 已有 cc 行 ⇒ 跳过——①不新增行 ②不重置未读（state 保持原值）
        // ③不更新原行时间（createTime/updateTime 逐字不变）。判重放在**写侧**：查询侧不引入去重、
        // 历史重复行也不清理（owner 2026-09-29 拍「接受既成事实」）。
        $existing = $this->findCcActorIds($instanceId);
        foreach ($actorIds as $actorId) {
            if (in_array($actorId, $existing, true)) {
                continue;
            }
            $now = date('Y-m-d H:i:s');
            $this->ccInstances[] = [
                'processInstanceId' => (string) $instanceId,
                'actorId' => $actorId,
                'state' => 0,
                'createUser' => $operator,
                'createTime' => $now,
                // 建行即写 update_time，形状对齐 wf_process_cc_instance 的两列（PDO 仓 INSERT 同样
                // 带 update_time）。缺这一格 issues/141 G2 的③档「重复抄送不得刷新原行时间」
                // 在内存仓就无从可测——updateCcStatus 会刷它，所以它是真会动的列。
                'updateTime' => $now,
            ];
            // 同一次调用内重复给同一个人也算"已存在"，只落一行
            $existing[] = $actorId;
        }
    }

    /**
     * issues/141 G2：某实例已有 cc 行的 actor id（写侧判重的读侧，SPI 覆写）。
     *
     * @return string[]
     */
    public function findCcActorIds(int|string $instanceId): array
    {
        $ids = [];
        foreach ($this->ccInstances as $cc) {
            if ((string) $cc['processInstanceId'] === (string) $instanceId) {
                $ids[] = $cc['actorId'];
            }
        }
        return $ids;
    }

    /**
     * issues/141 G2：判重后只插新人并返回**实际新建**的子集（照 java
     * `IProcessRepository#createCcInstanceIfAbsent` 的 default 实现；PHP 接口不能有方法体，
     * 故自带两仓各自实现一份，判据逐字同一条）。
     *
     * 三条入口（发起 f_ccActors／办理 tf_ccActors／门面手动 createCCInstance）拿这个子集去 fire
     * CC_CREATE，子集为空整支不发（spec 11.2 原则 1「码=事实」）。
     *
     * issues/141 G10「空不创建行」（spec 06-facade.md §2.10）：入参先过 CcActorUtil::normalizeList
     * ——空串/纯空白/null 丢弃、值取 trim 后的串，故返回的**子集**不可能含空值（子集直接拿去 fire，
     * 带空值就等于往 CC_CREATE 事件里灌空归属人）。
     *
     * @param string[] $actorIds
     * @return string[]
     */
    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array
    {
        // issues/141 G10 写侧兜底：与 createCcInstance 同一条判据（单点复用，不另抄一份）
        $actorIds = CcActorUtil::normalizeList($actorIds);
        $existing = $this->findCcActorIds($instanceId);
        $fresh = [];
        foreach ($actorIds as $actorId) {
            if (in_array($actorId, $existing, true)) {
                continue;
            }
            if (!in_array($actorId, $fresh, true)) {
                $fresh[] = $actorId;
            }
        }
        if ($fresh !== []) {
            $this->createCcInstance($instanceId, $operator, $fresh);
        }
        return $fresh;
    }

    public function updateCcStatus(int|string $instanceId, string $operator): void
    {
        // issues/142 B 批（spec 06 §2.11 表第四行）：比较位先归一，**归一为空 ⇒ 一条都不动**。
        // 旧形状直接比入参原样：带前后空格打不中规范值（该条永远未读），而空 operator 会
        // 把所有历史 actor_id 为空串的脏行批量打成已读——issues/129 那族【空归属值读全库】
        // 在写侧的复现。门面腿已经挡过一次，这里是【两层都挡】的第二层（§2.11 要求①）。
        $operator = CcActorUtil::normalizeActor($operator);
        if ($operator === '') return;
        // 已读：state 0→1，并刷 update_time——与 PDO 仓的 `SET state = 1, update_time = ?` 同形
        // （issues/141 G2 之后内存仓的 cc 行带 state/updateTime，"重复抄送不重置未读/不刷时间"
        // 两档才有可对照的写点）。
        $now = date('Y-m-d H:i:s');
        foreach ($this->ccInstances as &$cc) {
            if ($cc['processInstanceId'] === (string) $instanceId && $cc['actorId'] === $operator) {
                $cc['state'] = 1;
                $cc['updateTime'] = $now;
            }
        }
        unset($cc);
    }

    /**
     * issues/138 · ccList 行形状三要件（spec 06-facade.md §processInstance/ccList）
     *
     * 条文：行**必须来自实例**（`wf_process_instance`），`operator`＝实例 operator＝流程发起人，
     * 主键键名＝`id`（实例 id），且「rows 同 processInstance/page 行结构」⇒ 与 pageInstances
     * 共用 instanceRowWithDefine() 投影。旧实现直接返回 cc 行原样（`processInstanceId/actorId/
     * state/createUser/createTime`，既无 `operator` 也无 `id`），是条文点名的错法 ③ + 缺要件。
     *
     * cc 行退成**过滤**：归属谓词列 `cc.actor_id`（spec 06 §2.5 口径表钉的列，不许动）映射到
     * 该实例的 cc 行集合上，取 EXISTS 语义 —— 与 Java MemoryProcessRepository.pageCcInstances
     * 同一写法（给实例行挂 cc.actor_id 再 matches），也与 PDO 侧
     * `LEFT JOIN wf_process_cc_instance cc ON t.id = cc.process_instance_id` + `cc.actor_id = ?`
     * 的过滤效果一致。
     *
     * issues/141 G1 在此之上再收一层：**归属条件必填**，缺失/空值一律空页（见方法体内的判据）。
     */
    public function pageCcInstances(PageQuery $query): PageResult
    {
        // issues/141 G1 归属条件必填（spec 06-facade.md §2.5「抄送分页同一条尺子」）：
        // `cc.actor_id` 缺失或为空值 ⇒ 空页。判据与 PdoProcessRepository::pageCcInstances 的
        // 同名同判据逐字一条——旧形状是本仓"无 cc 条件时保持 PDO LEFT JOIN 的同读数"（返全部实例），
        // 于是同一份数据两仓都可能各自漂移；G1 把这条尺子从"门面漏挂条件"延长到"仓储自己不认"：
        // 绕过门面的调用方必须在这一层顶住，而不是拿到整库。
        if (!self::hasEffectiveCondition($query, 'cc.actor_id')) {
            return new PageResult($query->getPageNum(), $query->getPageSize(), 0, []);
        }

        /** @var array<string, array<int, array<string, mixed>>> $ccByInstance */
        $ccByInstance = [];
        foreach ($this->ccInstances as $cc) {
            $ccByInstance[(string) $cc['processInstanceId']][] = $cc;
        }

        $ccConditions = [];
        $rowConditions = [];
        foreach ($query->getConditions() as $cond) {
            if (str_starts_with($cond['column'], 'cc.')) {
                $ccConditions[] = $cond;
            } else {
                $rowConditions[] = $cond;
            }
        }

        $rows = [];
        foreach ($this->instances as $inst) {
            $ccRows = $ccByInstance[(string) $inst->getInstanceId()] ?? [];
            // 行源始终是实例表，cc 只是关联过滤：无抄送行的实例不属于"抄送给我"。
            // （走到这里 $ccConditions 必非空——归属条件必填的判据已在方法入口挡掉空页档。）
            if ($ccConditions !== [] && $ccRows === []) continue;
            foreach ($ccConditions as $cond) {
                $col = $this->snakeToCamel(substr($cond['column'], 3));
                $hit = false;
                foreach ($ccRows as $cc) {
                    if ($this->compareValues($cc[$col] ?? null, $cond['op'], $cond['value'])) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) continue 2;
            }
            $row = $this->instanceRowWithDefine($inst);
            foreach ($rowConditions as $cond) {
                if (!$this->matchCondition($row, $cond['column'], $cond['op'], $cond['value'])) continue 2;
            }
            $rows[] = $row;
        }

        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    /**
     * 某一列是否给了**有效**条件（值非 null、字符串非全空白、集合非空）
     * —— 与 PdoProcessRepository::hasEffectiveCondition 同名同判据（issues/141 G1）。
     *
     * 只管归属谓词列的"必填"，不参与任何可选过滤的空值放行（issues/129 那条边界不变）。
     */
    private static function hasEffectiveCondition(PageQuery $query, string $column): bool
    {
        foreach ($query->getConditions() as $cond) {
            if ($cond['column'] !== $column) {
                continue;
            }
            $val = $cond['value'];
            if ($val === null) {
                continue;
            }
            if (is_string($val) && trim($val) === '') {
                continue;
            }
            if (is_array($val) && $val === []) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @return array<int, array> */
    public function getCcInstances(): array
    {
        return $this->ccInstances;
    }

    /** @return array<string, ProcessInstance> */
    public function getAllInstances(): array
    {
        return $this->instances;
    }

    /** @return array<string, ProcessTask> */
    public function getAllTasks(): array
    {
        return $this->tasks;
    }

    // ── 内部辅助 ──

    private function instanceToRow(ProcessInstance $inst): array
    {
        return [
            'id' => $inst->getInstanceId(),
            'parentId' => $inst->getParentId(),
            'processDefineId' => $inst->getDefineId(),
            'state' => $inst->getState(),
            'parentNodeName' => $inst->getParentNodeName(),
            'businessNo' => $inst->getBusinessNo(),
            'operator' => $inst->getOperator(),
            'ext' => $inst->getVariables()->toArray() ?: (object)[], // issues/124：variable 原串出口下线
            'createTime' => $inst->getCreateTime(),
            'createUser' => $inst->getCreateUser(),
            'updateTime' => $inst->getUpdateTime(),
            'updateUser' => $inst->getUpdateUser(),
            'expireTime' => $inst->getExpireTime(),
        ];
    }

    private function taskToRow(ProcessTask $task): array
    {
        // issues/82-3：instanceExt 容器（实例变量对象）+ ext 空回退实例变量
        // —— 对齐 Java taskRowToMap / PDO pagedTaskQuery（门面 pass-through，仓储必须出契约）
        $inst = $this->instances[(string) ($task->getProcessInstanceId() ?? '')] ?? null;
        $instanceExt = $inst !== null ? ($inst->getVariables()->toArray() ?: []) : [];
        $ext = $task->getVariables()->toArray();
        // issues/121 P1：引擎建单必写的控制键不算「任务变量非空」，否则新建任务的 ext
        // 永远不再回退实例变量（issues/82-3 既有契约）。
        if (array_diff_key($ext, ['isFirstTaskNode' => 1]) === []) $ext = $instanceExt;
        $row = [
            'id' => $task->getTaskId(),
            'processInstanceId' => $task->getProcessInstanceId(),
            'taskName' => $task->getTaskName(),
            'displayName' => $task->getDisplayName(),
            'taskType' => $task->getTaskType(),
            'performType' => $task->getPerformType(),
            'taskState' => $task->getTaskState(),
            'operator' => $task->getActorId(),
            'formKey' => $task->getFormKey(),
            'taskParentId' => $task->getParentTaskId(),
            'taskActorIdList' => $task->getActorIds(),
            'ext' => $ext ?: (object)[], // issues/124：variable 原串出口下线
            'instanceExt' => $instanceExt ?: (object)[],
            'taskFormData' => (object)[],
            'finishTime' => $task->getFinishTime(),
            'expireTime' => $task->getExpireTime(),
            'createTime' => $task->getCreateTime(),
            'createUser' => $task->getCreateUser(),
            'updateTime' => $task->getUpdateTime(),
            'updateUser' => $task->getUpdateUser(),
        ];
        if ($inst !== null) {
            $row['instanceCreateTime'] = $inst->getCreateTime();
            $def = $this->findDefineById($inst->getDefineId());
            $row['processDefineName'] = $def['name'] ?? null;
            $row['processDefineDisplayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
            $row['processDefineVersion'] = $def['version'] ?? null;
            $row['version'] = isset($def['version']) ? (int) $def['version'] : null;
        }
        return $row;
    }

    private function getDefineField(array $row, string $col): mixed
    {
        $map = ['id' => 'id', 'name' => 'name', 'display_name' => 'displayName', 'displayName' => 'displayName',
                'type' => 'type', 'state' => 'state', 'version' => 'version'];
        $key = $map[$col] ?? $col;
        return $row[$key] ?? null;
    }

    private function getInstanceField(array $row, string $col): mixed
    {
        $clean = preg_replace('/^[a-z]+\./', '', $col);
        return $row[$clean] ?? null;
    }

    private function getTaskField(array $row, string $col): mixed
    {
        $clean = preg_replace('/^[a-z]+\./', '', $col);
        return $row[$clean] ?? null;
    }

    private function applyFilters(array $rows, PageQuery $query, callable $fieldGetter): array
    {
        foreach ($query->getConditions() as $cond) {
            $rows = array_filter($rows, function ($row) use ($cond, $fieldGetter) {
                $val = $fieldGetter($row, $cond['column']);
                return $this->matchCondition($row, $cond['column'], $cond['op'], $cond['value']);
            });
        }
        return array_values($rows);
    }

    /**
     * m_ 查询列 → 行键值解析（issues/82-6：对齐 Java in-memory 的 pd./t. 白名单映射）
     *
     * 旧实现只剥表别名后按裸键取（pd.name→name / t.display_name→display_name），
     * 而内存行键是 camelCase（processDefineName / displayName），导致 pd. 前缀/snake_case 列
     * 静默失配。现：剥别名 + snake→camel，pd. 前缀映射到 processDefine 行键。
     */
    private function resolveColumnValue(array $row, string $col): mixed
    {
        $alias = '';
        if (str_contains($col, '.')) {
            [$alias, $col] = explode('.', $col, 2);
        }
        $field = $this->snakeToCamel($col);
        if ($alias === 'pd') {
            return $row['processDefine' . ucfirst($field)] ?? null;
        }
        return $row[$field] ?? null;
    }

    private function snakeToCamel(string $s): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $s))));
    }

    private function matchCondition(array $row, string $col, string $op, mixed $value): bool
    {
        return $this->compareValues($this->resolveColumnValue($row, $col), $op, $value);
    }

    /**
     * 实例行 + 定义信息（issues/138：pageInstances 与 pageCcInstances 共用同一投影，
     * 「rows 同 processInstance/page 行结构」由构造保证，不再两处各自漂移）。
     *
     * @return array<string, mixed>
     */
    private function instanceRowWithDefine(ProcessInstance $inst): array
    {
        $row = $this->instanceToRow($inst);
        // Enrich with define info (align Java instanceRowToMap L1257-1262)
        $def = $this->findDefineById($inst->getDefineId());
        if ($def !== null) {
            // 兼容 PDO snake_case / 内存 camelCase 定义键（issues/82-6）
            $row['processDefineName'] = $def['name'] ?? null;
            $row['processDefineDisplayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
            $row['processDefineVersion'] = isset($def['version']) ? (int) $def['version'] : null;
            $row['displayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
            $row['version'] = isset($def['version']) ? (int) $def['version'] : null;
        } else {
            $row['processDefineName'] = null;
            $row['processDefineDisplayName'] = null;
            $row['processDefineVersion'] = null;
            $row['displayName'] = null;
            $row['version'] = null;
        }
        return $row;
    }

    private function compareValues(mixed $actual, string $op, mixed $value): bool
    {
        return match ($op) {
            'EQ' => (string) $actual === (string) $value,
            'LIKE' => str_contains((string) $actual, (string) $value),
            'GT' => $actual > $value,
            'LT' => $actual < $value,
            'GE' => $actual >= $value,
            'LE' => $actual <= $value,
            'IN' => is_array($value) && in_array($actual, $value),
            default => true,
        };
    }
}
