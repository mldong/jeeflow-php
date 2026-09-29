<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
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
 * PDO 仓储实现 —— 支持 MySQL / SQLite
 *
 * 对齐 Java JdbcProcessRepository。核心五表读写。
 */
class PdoProcessRepository implements ProcessRepositoryInterface
{
    private \PDO $pdo;
    private IdGeneratorInterface $idGenerator;

    public function __construct(\PDO $pdo, ?IdGeneratorInterface $idGenerator = null)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function getIdGenerator(): IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    /** 执行建表 SQL（用于测试或初始化） */
    public function initSchema(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    // ── 流程定义 ──

    public function addDefine(array $define): void
    {
        $id = (string) ($define['id'] ?? $this->idGenerator->nextId());
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_define (id, name, display_name, type, state, content, version, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $define['name'] ?? '',
            $define['displayName'] ?? '',
            $define['type'] ?? null,
            $define['state'] ?? 1,
            $define['content'] ?? null,
            $define['version'] ?? 1,
            $define['createTime'] ?? $now,
            $define['createUser'] ?? null,
            $define['updateTime'] ?? $now,
            $define['updateUser'] ?? null,
        ]);
    }

    public function findDefineById(int|string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_define WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->defineRow($row);
    }

    public function updateDefine(array $define): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'display_name', 'type', 'content', 'version'] as $f) {
            $camel = $this->toCamel($f);
            if (isset($define[$camel]) || isset($define[$f])) {
                $val = $define[$camel] ?? $define[$f];
                $fields[] = "$f = ?";
                $params[] = $val;
            }
        }
        if (isset($define['updateUser'])) { $fields[] = 'update_user = ?'; $params[] = $define['updateUser']; }
        $fields[] = 'update_time = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = (string) $define['id'];
        $stmt = $this->pdo->prepare('UPDATE wf_process_define SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function removeDefine(int|string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM wf_process_define WHERE id = ?');
        $stmt->execute([(string) $id]);
    }

    public function updateDefineState(int|string $id, int $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE wf_process_define SET state = ?, update_time = ? WHERE id = ?');
        $stmt->execute([$state, date('Y-m-d H:i:s'), (string) $id]);
    }

    public function findLatestDefineByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_define WHERE name = ? ORDER BY version DESC LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->defineRow($row);
    }

    public function pageDefines(PageQuery $query): PageResult
    {
        return $this->pagedQuery('wf_process_define', 't', $query, function ($row) {
            return $this->defineRow($row);
        });
    }

    // ── 流程实例 ──

    public function saveInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        if ($instance->getInstanceId() === null) {
            $instance->setInstanceId((string) $this->idGenerator->nextId());
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_instance
             (id, parent_id, process_define_id, state, parent_node_name, business_no, operator, expire_time, variable, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $instance->getInstanceId(),
            $instance->getParentId(),
            $instance->getDefineId(),
            $instance->getState(),
            $instance->getParentNodeName(),
            $instance->getBusinessNo(),
            $instance->getOperator(),
            $instance->getExpireTime(),
            json_encode($instance->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $instance->getCreateTime(),
            $instance->getCreateUser(),
            $instance->getUpdateTime(),
            $instance->getUpdateUser(),
        ]);
    }

    public function updateInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        $stmt = $this->pdo->prepare(
            'UPDATE wf_process_instance SET
             state = ?, variable = ?, expire_time = ?, update_time = ?, update_user = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $instance->getState(),
            json_encode($instance->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $instance->getExpireTime(),
            $instance->getUpdateTime(),
            $instance->getUpdateUser(),
            $instance->getInstanceId(),
        ]);

        // 同步子任务
        foreach ($instance->getTasks() as $task) {
            $existing = $this->findTaskById($task->getTaskId());
            if ($existing !== null) {
                $this->updateTask($task);
            }
        }
    }

    public function findInstanceById(int|string $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_instance WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $instance = $this->hydrateInstance($row);

        // 加载关联的任务
        $taskStmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE process_instance_id = ? ORDER BY create_time');
        $taskStmt->execute([(string) $id]);
        $tasks = [];
        while ($taskRow = $taskStmt->fetch(\PDO::FETCH_ASSOC)) {
            $task = $this->hydrateTask($taskRow);
            $tasks[] = $task;
        }
        $instance->setTasks($tasks);

        return $instance;
    }

    public function pageInstances(PageQuery $query): PageResult
    {
        [$whereSql, $whereParams] = $this->buildConditions($query);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM wf_process_instance t WHERE 1=1" . $whereSql);
        $countStmt->execute($whereParams);
        $total = (int) $countStmt->fetchColumn();

        // Fetch with JOIN to wf_process_define for displayName/version (align Java instanceRowToMap L1257-1262)
        $order = $query->getOrderBy() ?: 't.create_time DESC';
        $sql = "SELECT t.*, pd.name AS process_define_name, pd.display_name AS process_define_display_name, "
            . "pd.version AS process_define_version "
            . "FROM wf_process_instance t "
            . "LEFT JOIN wf_process_define pd ON t.process_define_id = pd.id "
            . "WHERE 1=1" . $whereSql . " ORDER BY $order"
            . SqlPaging::clause($query->getPageSize(), $query->getOffset());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $this->instanceRow($row);
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    // ── 流程任务 ──

    public function saveTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        if ($task->getTaskId() === null) {
            $task->setTaskId((string) $this->idGenerator->nextId());
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_task
             (id, process_instance_id, task_name, display_name, task_type, perform_type, task_state, operator, finish_time, expire_time, form_key, task_parent_id, variable, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $task->getTaskId(),
            $task->getProcessInstanceId(),
            $task->getTaskName(),
            $task->getDisplayName(),
            $task->getTaskType(),
            $task->getPerformType(),
            $task->getTaskState(),
            $task->getActorId(),
            $task->getFinishTime(),
            $task->getExpireTime(),
            $task->getFormKey(),
            $task->getParentTaskId(),
            json_encode($task->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $task->getCreateTime(),
            $task->getCreateUser(),
            $task->getUpdateTime(),
            $task->getUpdateUser(),
        ]);

        // 保存 actor 关系
        foreach ($task->getActorIds() as $actorId) {
            $actorStmt = $this->pdo->prepare(
                'INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time, create_user)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $actorStmt->execute([
                (string) $this->idGenerator->nextId(),
                $task->getTaskId(),
                $actorId,
                $task->getCreateTime(),
                $task->getCreateUser(),
            ]);
        }
    }

    public function updateTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        $stmt = $this->pdo->prepare(
            'UPDATE wf_process_task SET
             task_state = ?, operator = ?, finish_time = ?, variable = ?, update_time = ?, update_user = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $task->getTaskState(),
            $task->getActorId(),
            $task->getFinishTime(),
            json_encode($task->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $task->getUpdateTime(),
            $task->getUpdateUser(),
            $task->getTaskId(),
        ]);
    }

    public function findTaskById(int|string $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->hydrateTask($row);
    }

    public function findDoingTasks(int|string $instanceId, ?array $actorIds = null): array
    {
        $sql = 'SELECT t.* FROM wf_process_task t WHERE t.process_instance_id = ? AND t.task_state = ?';
        $params = [(string) $instanceId, ProcessTaskState::DOING];
        if ($actorIds !== null && !empty($actorIds)) {
            $sql .= ' AND t.id IN (SELECT process_task_id FROM wf_process_task_actor WHERE actor_id IN (' .
                    implode(',', array_fill(0, count($actorIds), '?')) . '))';
            $params = array_merge($params, $actorIds);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateTask($row);
        }
        return $result;
    }

    public function findHistoryTasks(int|string $instanceId): array
    {
        // issues/82-10：历史任务=实例全部任务（含进行中），对齐 Java 内存/Go(state=-1)/Node(null)/Python。
        // 此前 task_state != DOING 导致 highLight nodeProgress 拿不到会签进行中任务 → 成员/active 全丢。
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE process_instance_id = ? ORDER BY create_time');
        $stmt->execute([(string) $instanceId]);
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateTask($row);
        }
        return $result;
    }

    public function addTaskActor(int|string $taskId, array $actorIds): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($actorIds as $actorId) {
            // Check if already exists
            $check = $this->pdo->prepare('SELECT id FROM wf_process_task_actor WHERE process_task_id = ? AND actor_id = ?');
            $check->execute([(string) $taskId, $actorId]);
            if ($check->fetch() === false) {
                $stmt = $this->pdo->prepare('INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time, create_user) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([(string) $this->idGenerator->nextId(), (string) $taskId, $actorId, $now, null]);
            }
        }
    }

    /**
     * issues/115：按人摘行——WHERE 同时限定 process_task_id 与 actor_id，
     * 只删传入这几人在**该任务**下的参与者行，同任务其余参与人（会签其他成员、加签来的人）一行不动。
     * 传空列表直接返回，避免退化成"清空该任务全部参与者"。
     */
    public function removeTaskActor(int|string $taskId, array $actorIds): void
    {
        if ($actorIds === []) return;
        $stmt = $this->pdo->prepare(
            'DELETE FROM wf_process_task_actor WHERE process_task_id = ? AND actor_id = ?'
        );
        foreach ($actorIds as $actorId) {
            $stmt->execute([(string) $taskId, (string) $actorId]);
        }
    }

    public function pageTodoTasks(PageQuery $query): PageResult
    {
        $baseSql = ' FROM wf_process_task t '
            . 'LEFT JOIN wf_process_instance pi ON t.process_instance_id = pi.id '
            . 'LEFT JOIN wf_process_define pd ON pi.process_define_id = pd.id '
            . 'LEFT JOIN wf_process_task_actor pta ON t.id = pta.process_task_id '
            . 'WHERE t.task_state = ?';
        $baseParams = [ProcessTaskState::DOING];
        return $this->pagedTaskQuery($baseSql, $baseParams, $query);
    }

    public function pageDoneTasks(PageQuery $query): PageResult
    {
        $baseSql = ' FROM wf_process_task t '
            . 'LEFT JOIN wf_process_instance pi ON t.process_instance_id = pi.id '
            . 'LEFT JOIN wf_process_define pd ON pi.process_define_id = pd.id '
            . 'WHERE t.task_state != ?';
        $baseParams = [ProcessTaskState::DOING];
        return $this->pagedTaskQuery($baseSql, $baseParams, $query);
    }

    // ── 抄送 ──

    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void
    {
        // issues/141 G10「空不创建行」（spec 06-facade.md §2.10 实现要求①「两层都挡」）：**SQL 写侧**兜底——
        // 空串/纯空白/null 一律丢弃，落库值取 trim 后的串（" 123 " 与 "123" 是同一个人，与下面的 G2
        // 判重同一条尺子）。绕过引擎漏斗与门面**直连本仓储**的第三方调用方同样建不出 actor_id='' 的行，
        // 那空归属值正是 issues/129 那族「空 operator 读全库」的病根。判据单点复用 CcActorUtil，
        // 与内存仓逐字同一条（两仓必须同答案，issues/117 场景 27）。
        $actorIds = CcActorUtil::normalizeList($actorIds);
        $now = date('Y-m-d H:i:s');
        // issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4），与内存仓同一条判据：
        // 同一 (实例, 被抄送人) 已有 cc 行时直接跳过——①不新增行 ②不重置未读（state 保持原值）
        // ③不更新原行时间（连 UPDATE 都不发，create_time/update_time 逐字不变）。
        // 判重在写侧而不是查询侧：SELECT 保持现状不引入 DISTINCT（owner 2026-09-29 拍），
        // 库里历史遗留的重复行也不清理。
        $existing = $this->findCcActorIds($instanceId);
        foreach ($actorIds as $actorId) {
            if (in_array($actorId, $existing, false)) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO wf_process_cc_instance (id, process_instance_id, actor_id, state, create_time, create_user, update_time, update_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (string) $this->idGenerator->nextId(),
                (string) $instanceId,
                $actorId,
                0,
                $now,
                $operator,
                $now,
                $operator,
            ]);
            // 同一次调用内的重复也算"已存在"，只落一行
            $existing[] = $actorId;
        }
    }

    /**
     * issues/141 G2：某实例已有的 cc 行 actor id（写侧判重的读侧，SPI 覆写）。
     *
     * 返回**可追加**的 List——{@see self::createCcInstance()} 在插入过程中往里追加，
     * 让同一次调用内的重复也只落一行（与 Java JdbcProcessRepository.findCcActorIds 同形）。
     *
     * @return string[]
     */
    public function findCcActorIds(int|string $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT actor_id FROM wf_process_cc_instance WHERE process_instance_id = ?');
        $stmt->execute([(string) $instanceId]);
        $actorIds = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $actorIds[] = (string) $row['actor_id'];
        }
        return $actorIds;
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
            if (in_array($actorId, $existing, false)) {
                continue;
            }
            if (!in_array($actorId, $fresh, false)) {
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
        $stmt = $this->pdo->prepare('UPDATE wf_process_cc_instance SET state = 1, update_time = ? WHERE process_instance_id = ? AND actor_id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), (string) $instanceId, $operator]);
    }

    /**
     * issues/138 · ccList 行形状三要件（spec 06-facade.md §processInstance/ccList）
     *
     * 条文逐字：「`ccList` 的每一行**必须来自 `wf_process_instance`**（cc 表只当过滤/关联用），
     * `operator` 必须是**实例的 `operator`＝流程发起人**，行主键键名必须是 **`id`（实例 id）**」，
     * 且「rows 同 processInstance/page 行结构」。
     *
     * 旧实现把 `wf_process_cc_instance` 当行源（`FROM wf_process_cc_instance t`），于是出口是
     * cc 行的形状：主键叫 `processInstanceId`、没有 `id`、还多了 `actorId` —— 正是条文列的错法 ③。
     * 现在行源换成实例表，`t` 这个别名归实例表，cc 表退到 `cc` 别名的 JOIN，出口与 pageInstances
     * 共用同一个 instanceRow() 投影 ⇒ 两处形状由构造保证一致，不再各自漂移。
     *
     * 归属谓词（spec 06 §2.5 口径表钉的 `cc.actor_id EQ operator`，不许动）仍按 cc 的列过滤：
     * buildConditions 把 PageQuery 的列名原样拼进 WHERE，门面注入 `cc.actor_id` 即落到 JOIN 表的列，
     * 与 Java JdbcProcessRepository.pageCcInstances = pageInstances(query, cc=true)
     * （`LEFT JOIN wf_process_cc_instance cc ON t.id = cc.process_instance_id` +
     * CC_INSTANCE_WHITELIST 含 `cc.actor_id`）同一条 SQL 形状。
     */
    public function pageCcInstances(PageQuery $query): PageResult
    {
        // issues/141 G1 归属条件必填（spec 06-facade.md §2.5「抄送分页同一条尺子」）：
        // `cc.actor_id` 缺失或为空值 ⇒ 空页。旧形状是这条 LEFT JOIN 不带条件时返回**全部实例**，
        // 而内存仓那一侧只放"有 cc 行的实例"——同一栈两个仓储两个答案，正是 issues/117 场景 27
        // 立过法的那一类，所以 SQL 仓与内存仓必须钉在同一条判据上。
        // 空值档（空串/全空白/null）原先由 buildConditions 的"照值比对"自然得到 0 行
        // （issues/129 本栈无通用放行），这一格补的是"条件整条没给"。
        if (!self::hasEffectiveCondition($query, 'cc.actor_id')) {
            return new PageResult($query->getPageNum(), $query->getPageSize(), 0, []);
        }

        [$whereSql, $whereParams] = $this->buildConditions($query);

        // Count（与数据查询同一个 FROM/JOIN，行数口径不打架）
        $baseSql = ' FROM wf_process_instance t '
            . 'LEFT JOIN wf_process_cc_instance cc ON t.id = cc.process_instance_id '
            . 'LEFT JOIN wf_process_define pd ON t.process_define_id = pd.id '
            . 'WHERE 1=1';
        $countStmt = $this->pdo->prepare("SELECT COUNT(*)" . $baseSql . $whereSql);
        $countStmt->execute($whereParams);
        $total = (int) $countStmt->fetchColumn();

        $order = $query->getOrderBy() ?: 't.create_time DESC';
        $sql = "SELECT t.*, pd.name AS process_define_name, pd.display_name AS process_define_display_name, "
            . "pd.version AS process_define_version" . $baseSql . $whereSql . " ORDER BY $order"
            . SqlPaging::clause($query->getPageSize(), $query->getOffset());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $this->instanceRow($row);
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    /**
     * 是否给了某一列的**有效**条件（值非 null、字符串非全空白、集合非空）。
     * issues/141 G1：归属谓词"必填"的判据，与内存仓 InMemoryProcessRepository::hasEffectiveCondition
     * 同名同判据（两仓必须给同一个答案）。空串/全空白/null 都算没填。
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

    // ── 统计（issues/103） ──

    public function getAllInstances(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM wf_process_instance ORDER BY create_time');
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateInstance($row);
        }
        return $result;
    }

    public function getAllTasks(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM wf_process_task ORDER BY create_time');
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateTask($row);
        }
        return $result;
    }

    // ── 内部方法 ──

    /**
     * 实例行的唯一投影（issues/138）：pageInstances 与 pageCcInstances 共用 ⇒
     * 「rows 同 processInstance/page 行结构」（spec 06-facade.md:994）由构造保证。
     * 主键键名 `id`＝实例 id、`operator`＝实例 operator＝流程发起人，两者都是条文三要件。
     *
     * @param array<string, mixed> $row t.* + pd.* 三列的查询行
     * @return array<string, mixed>
     */
    private function instanceRow(array $row): array
    {
        return [
            'id' => PdoValue::strId($row['id']) ?? '',
            'parentId' => PdoValue::strId($row['parent_id']),
            'processDefineId' => PdoValue::strId($row['process_define_id']),
            'state' => (int) $row['state'],
            'parentNodeName' => $row['parent_node_name'],
            'businessNo' => $row['business_no'],
            'operator' => $row['operator'] ?? '',
            'ext' => json_decode((string)($row['variable'] ?? '{}'), true) ?: (object)[], // issues/124：variable 原串出口下线
            'createTime' => $row['create_time'],
            'createUser' => PdoValue::strId($row['create_user']),
            'updateTime' => $row['update_time'],
            'updateUser' => PdoValue::strId($row['update_user']),
            'expireTime' => $row['expire_time'],
            // JOIN fields from wf_process_define
            'processDefineName' => $row['process_define_name'] ?? null,
            'processDefineDisplayName' => $row['process_define_display_name'] ?? null,
            'processDefineVersion' => isset($row['process_define_version']) ? (int) $row['process_define_version'] : null,
            'displayName' => $row['process_define_display_name'] ?? null,
            'version' => isset($row['process_define_version']) ? (int) $row['process_define_version'] : null,
        ];
    }

    private function defineRow(array $row): array
    {
        return [
            'id' => PdoValue::strId($row['id']) ?? '',
            'name' => $row['name'],
            'displayName' => $row['display_name'],
            'type' => $row['type'],
            'state' => (int) $row['state'],
            'content' => $row['content'],
            'version' => (int) $row['version'],
            'createTime' => $row['create_time'] ?? null,
            'createUser' => PdoValue::strId($row['create_user'] ?? null),
            'updateTime' => $row['update_time'] ?? null,
            'updateUser' => PdoValue::strId($row['update_user'] ?? null),
        ];
    }

    private function hydrateInstance(array $row): ProcessInstance
    {
        $instance = new ProcessInstance();
        $instance->setInstanceId(PdoValue::strId($row['id']));
        $instance->setParentId(PdoValue::strId($row['parent_id']));
        $instance->setDefineId(PdoValue::strId($row['process_define_id']));
        $instance->setState((int) $row['state']);
        $instance->setParentNodeName($row['parent_node_name']);
        $instance->setBusinessNo($row['business_no']);
        $instance->setOperator(PdoValue::strId($row['operator'] ?? '') ?? '');
        $instance->setExpireTime($row['expire_time']);
        $vars = json_decode((string) ($row['variable'] ?? '{}'), true) ?: [];
        $instance->setVariables(FlowData::of($vars));
        $instance->setCreateTime($row['create_time']);
        $instance->setCreateUser(PdoValue::strId($row['create_user']));
        $instance->setUpdateTime($row['update_time']);
        $instance->setUpdateUser(PdoValue::strId($row['update_user']));
        return $instance;
    }

    /**
     * 从数据库行还原 ProcessTask（含 actorIds）
     */
    private function hydrateTask(array $row): ProcessTask
    {
        $task = new ProcessTask();
        $task->setTaskId(PdoValue::strId($row['id']));
        $task->setProcessInstanceId(PdoValue::strId($row['process_instance_id']));
        $task->setTaskName($row['task_name']);
        $task->setDisplayName($row['display_name']);
        $task->setTaskType($row['task_type'] !== null ? (int) $row['task_type'] : null);
        $task->setPerformType($row['perform_type'] !== null ? (int) $row['perform_type'] : null);
        $task->setTaskState((int) ($row['task_state'] ?? ProcessTaskState::DOING));
        $task->setActorId(PdoValue::strId($row['operator']));
        $task->setFinishTime($row['finish_time']);
        $task->setExpireTime($row['expire_time']);
        $task->setFormKey($row['form_key']);
        $task->setParentTaskId(PdoValue::strId($row['task_parent_id']));
        $vars = json_decode((string) ($row['variable'] ?? '{}'), true) ?: [];
        $task->setVariables(FlowData::of($vars));
        $task->setCreateTime($row['create_time']);
        $task->setCreateUser(PdoValue::strId($row['create_user']));
        $task->setUpdateTime($row['update_time']);
        $task->setUpdateUser(PdoValue::strId($row['update_user']));

        // 加载 actorIds
        $actorStmt = $this->pdo->prepare('SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ?');
        $actorStmt->execute([(string) $row['id']]);
        $actorIds = [];
        while ($actorRow = $actorStmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = PdoValue::strId($actorRow['actor_id']);
            if ($id !== null) {
                $actorIds[] = $id;
            }
        }
        $task->setActorIds($actorIds);

        return $task;
    }

    private function toCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    /**
     * 通用分页查询
     */
    private function pagedQuery(string $table, string $alias, PageQuery $query, callable $rowMapper): PageResult
    {
        [$whereSql, $whereParams] = $this->buildConditions($query);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table $alias WHERE 1=1" . $whereSql);
        $countStmt->execute($whereParams);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $order = $query->getOrderBy() ?: "$alias.create_time DESC";
        $sql = "SELECT $alias.* FROM $table $alias WHERE 1=1" . $whereSql . " ORDER BY $order"
            . SqlPaging::clause($query->getPageSize(), $query->getOffset());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $rowMapper($row);
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    private function pagedTaskQuery(string $baseSql, array $baseParams, PageQuery $query): PageResult
    {
        [$whereSql, $whereParams] = $this->buildConditions($query);
        $allParams = array_merge($baseParams, $whereParams);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(DISTINCT t.id)" . $baseSql . $whereSql);
        $countStmt->execute($allParams);
        $total = (int) $countStmt->fetchColumn();

        // Fetch - include process define info
        $order = $query->getOrderBy() ?: 't.create_time DESC';
        $sql = "SELECT DISTINCT t.*, pd.name AS process_define_name, pd.display_name AS process_define_display_name, "
            . "pd.version AS process_define_version, pi.variable AS instance_variable, pi.create_time AS instance_create_time "
            . $baseSql . $whereSql . " ORDER BY $order"
            . SqlPaging::clause($query->getPageSize(), $query->getOffset());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($allParams);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $task = $this->hydrateTask($row);
            // Parse instance variable JSON for ext/instanceExt (align with Java/Node)
            $instanceVarJson = $row['instance_variable'] ?? null;
            $instanceExt = null;
            if ($instanceVarJson !== null && is_string($instanceVarJson)) {
                $decoded = json_decode($instanceVarJson, true);
                $instanceExt = is_array($decoded) ? $decoded : null;
            }
            // issues/124：列表行的变量出口只有 ext（任务变量）与 instanceExt（实例变量）。
            // 此处曾是 php 最后一处漏网：门面侧 988b7ee 摘掉了 detail 族的 variable/variables，
            // 但 todoList/doneList 这类行由仓储直接投影，不经门面，故仍带原串
            // （线上 php 栈 doneList 每行 variable + instanceVariable，八栈同 action 只有它有）。
            // 判据基准取 Java JeeflowFacade.taskRowToMap 与本仓内存仓 taskToRow，两处同形。
            $ext = $task->getVariables()->toArray();
            // issues/121 P1：引擎建单必写的控制键不算「任务变量非空」，否则新建任务的 ext
            // 永远不再回退实例变量（issues/82-3 既有契约）——与内存仓同一判据。
            if (array_diff_key($ext, ['isFirstTaskNode' => 1]) === []) $ext = $instanceExt ?? [];
            $rows[] = [
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
                'ext' => $ext ?: (object)[],
                'instanceExt' => ($instanceExt ?? []) ?: (object)[],
                'taskFormData' => (object)[],
                'finishTime' => $task->getFinishTime(),
                'expireTime' => $task->getExpireTime(),
                'createTime' => $task->getCreateTime(),
                'createUser' => $task->getCreateUser(),
                'updateTime' => $task->getUpdateTime(),
                'updateUser' => $task->getUpdateUser(),
                // Process define info (from JOIN)
                'processDefineName' => $row['process_define_name'] ?? null,
                'processDefineDisplayName' => $row['process_define_display_name'] ?? null,
                'version' => $row['process_define_version'] ?? null,
                'instanceCreateTime' => $row['instance_create_time'] ?? null,
            ];
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    /**
     * 构建 WHERE 条件（从 PageQuery 的 conditions 生成 SQL）
     * @return array{0: string, 1: array}
     */
    private function buildConditions(PageQuery $query): array
    {
        $sql = '';
        $params = [];
        foreach ($query->getConditions() as $cond) {
            $col = $cond['column'];
            // Convert camelCase column to snake_case for DB
            $dbCol = $this->camelToSnake($col);
            $op = $cond['op'];
            $val = $cond['value'];
            $sql .= match ($op) {
                'EQ' => " AND $col = ?",
                'LIKE' => " AND $col LIKE ?",
                'GT' => " AND $col > ?",
                'LT' => " AND $col < ?",
                'GE' => " AND $col >= ?",
                'LE' => " AND $col <= ?",
                'IN' => " AND $col IN (" . implode(',', array_fill(0, is_array($val) ? count($val) : 1, '?')) . ")",
                default => '',
            };
            if ($op === 'LIKE') {
                $params[] = '%' . $val . '%';
            } elseif ($op === 'IN' && is_array($val)) {
                $params = array_merge($params, $val);
            } else {
                $params[] = $val;
            }
        }
        return [$sql, $params];
    }

    private function camelToSnake(string $input): string
    {
        // Strip alias prefix (e.g., "t.columnName" -> "columnName")
        $col = preg_replace('/^[a-z]+\./', '', $input);
        return strtolower(preg_replace('/[A-Z]/', '_$0', $col));
    }
}
