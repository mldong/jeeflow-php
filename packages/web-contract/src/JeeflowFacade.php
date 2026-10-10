<?php

declare(strict_types=1);

namespace Jeeflow\WebContract;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\DecisionModel;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Spi\UserSearchProviderInterface;
use Jeeflow\Core\Util\CcActorUtil;
use Jeeflow\Core\Util\JeeflowQueryParser;
use Jeeflow\Core\Util\SurrogateRule;

/**
 * 统一门面 —— 对齐 Java JeeflowFacade
 *
 * 40 action 路由入口。集成方只需实现一个转发 controller：
 * 把 body JSON 转成 array 传入 flow()，所有流程能力按 action 路由。
 *
 * 返回统一结构 {code: 0, msg: "成功", data: {...}}
 */
class JeeflowFacade
{
    private JeeflowEngine $engine;
    private ProcessRepositoryInterface $repository;
    private ?ProcessExtRepositoryInterface $extRepository;
    private JeeflowQueryParser $queryParser;
    private ?UserSearchProviderInterface $userSearchProvider = null;

    private const DEFAULT_STATE_IN = [10, 20, 30, 40, 45, 50];
    private const DEFAULT_STATS_LIMIT = 10;
    private const VALID_GRANULARITY = ['hour' => true, 'day' => true, 'week' => true, 'month' => true];
    private const VALID_DIMENSION = [
        'state' => true, 'define' => true, 'category' => true,
        'approver' => true, 'applicant' => true, 'node' => true,
        'stuckNode' => true, 'stuckApprover' => true, 'durationBucket' => true,
    ];

    /** issues/137 §3-1：内部异常对外只说这一句（固定文案，其余七栈逐字复刻；spec 06 §2.12） */
    public const INTERNAL_FAILURE_MSG = '流程处理失败';

    public function __construct(JeeflowEngine $engine, ProcessRepositoryInterface $repository,
                                ?ProcessExtRepositoryInterface $extRepository = null)
    {
        $this->engine = $engine;
        $this->repository = $repository;
        $this->extRepository = $extRepository;
        $this->queryParser = new JeeflowQueryParser();
        $this->publishExtRepository($extRepository);
    }

    /**
     * 把扩展仓储暴露给引擎（issues/116 批次 D）：委托代理自动生效由**引擎内置**实现，
     * 它只认 `ServiceContext` 里的 `ProcessExtRepositoryInterface`。而集成方（laravel 壳、demo、
     * 各语言单测）绝大多数是 `new JeeflowFacade($engine, $repo, new PdoProcessExtRepository($pdo))`
     * 直接构造、并不往容器注册——不桥这一步，委托就永远查不到仓储（表现为"能力又消失了"）。
     *
     * 仅在尚未有该 SPI 时注册，**不覆盖**集成方自己的注册（集成方可以放 NullSurrogateInterceptor
     * 或自建恒返回 null 的仓储来关闭）。缺扩展仓储属于正常部署形态：不注册即静默跳过，不打断建单。
     */
    private function publishExtRepository(?ProcessExtRepositoryInterface $extRepository): void
    {
        if ($extRepository === null) return;
        if (ServiceContext::find(ProcessExtRepositoryInterface::class) !== null) return;
        ServiceContext::put(ProcessExtRepositoryInterface::class, $extRepository);
    }

    public function setUserSearchProvider(?UserSearchProviderInterface $provider): void
    {
        $this->userSearchProvider = $provider;
    }

    /**
     * 统一入口
     * @param array<string, mixed> $args
     * @return array{code:int, msg:string, data:mixed}
     */
    public function flow(string $action, ?array $args = null): array
    {
        try {
            if ($args === null) $args = [];
            $result = match ($action) {
                // ── 流程定义 ──
                'processDefine/page' => $this->definePage($args),
                'processDefine/detail' => $this->defineDetail($args),
                'processDefine/startAndExecute' => $this->startAndExecute($args),
                'processDefine/deploy' => $this->deploy($args),
                'processDefine/redeploy' => $this->redeploy($args),
                'processDefine/remove' => $this->defineRemove($args),
                'processDefine/upAndDown' => $this->defineUpAndDown($args),
                // ── 流程实例 ──
                'processInstance/page' => $this->instancePage($args),
                'processInstance/detail' => $this->instanceDetail($args),
                'processInstance/startAndExecute' => $this->startAndExecute($args),
                'processInstance/withdraw' => $this->withdraw($args),
                // ── 统计（issues/103） ──
                'processInstance/stats/overview' => $this->statsOverview($args),
                'processInstance/stats/trend' => $this->statsTrend($args),
                'processInstance/stats/group' => $this->statsGroup($args),
                // ── 流程任务 ──
                'processTask/todoList' => $this->todoList($args),
                'processTask/doneList' => $this->doneList($args),
                'processTask/execute' => $this->execute($args),
                'processTask/detail' => $this->taskDetail($args),
                'processTask/jumpAbleTaskNameList' => $this->jumpAbleTaskNameList($args),
                'processTask/surrogate' => $this->taskSurrogate($args),
                'processTask/addCandidate' => $this->taskSurrogate($args),
                'processTask/transfer' => $this->taskTransfer($args),
                'processTask/removeTaskActor' => $this->taskRemoveActor($args), // issues/115 残留：第 47 个 action
                'processTask/latest' => $this->taskLatest($args),
                'processTask/candidatePage' => $this->candidatePage($args),
                // ── 视图端点 ──
                'processDefine/getLastByName' => $this->getLastByName($args),
                'processInstance/highLight' => $this->highLight($args),
                'processInstance/approvalRecord' => $this->approvalRecord($args),
                'processInstance/getAssigneeTextData' => $this->getAssigneeTextData($args),
                'processInstance/createCCInstance' => $this->createCCInstance($args),
                'processInstance/updateCCStatus' => $this->updateCCStatus($args),
                'processInstance/ccList' => $this->ccList($args),
                'processInstance/bizData' => $this->bizData($args),
                // ── 流程设计（需扩展仓储）──
                'processDesign/page' => $this->designPage($args),
                'processDesign/detail' => $this->designDetail($args),
                'processDesign/save' => $this->designSave($args),
                'processDesign/update' => $this->designUpdate($args),
                'processDesign/updateDefine' => $this->designUpdateDefine($args),
                'processDesign/remove' => $this->designRemove($args),
                'processDesign/deploy' => $this->designDeploy($args),
                'processDesign/redeploy' => $this->designRedeploy($args),
                'processDesign/listByType' => $this->designListByType($args),
                // ── 委托代理（需扩展仓储）──
                'processSurrogate/page' => $this->surrogatePage($args),
                'processSurrogate/save' => $this->surrogateSave($args),
                'processSurrogate/update' => $this->surrogateUpdate($args), // issues/77
                'processSurrogate/detail' => $this->surrogateDetail($args), // issues/77
                'processSurrogate/remove' => $this->surrogateRemove($args),
                default => $this->error('未知 action: ' . $action),
            };
            // issues/75：所有响应出口统一 id 字符串化（对齐四语言全局 exit hook，
            // 覆盖 designDetail 单记录 + 嵌套 his 列表等此前漏掉的 int id 泄漏面）
            if (isset($result['data'])) {
                $result['data'] = $this->stringifyIds($result['data']);
            }
            return $result;
        } catch (\Throwable $e) {
            // issues/137 §3-1：判别式五条与理由见 isForeignDetail()——引擎写的中文契约文案照旧逐字透出
            // （其余七栈、十三集成壳与前端 toast 都按原文对齐，不能在这条上收窄），只把**外来/内部异常**
            // 的原文换成固定文案，原文只进日志与 previous 链（对齐 java log.log(SEVERE, …, e) 的 cause 分离）。
            if (self::isForeignDetail($e::class, $e->getMessage(), $e->getPrevious(), $e->getTrace(), $e->getFile())) {
                $this->logInternalFailure($action, new JeeflowException(self::INTERNAL_FAILURE_MSG, 99999999, $e));
                return $this->error(self::INTERNAL_FAILURE_MSG);
            }
            return $this->error($e->getMessage());
        }
    }

    /**
     * issues/137 §3-1：判「这条异常的 message 能不能原样进 msg」——抽成纯静态函数
     * （java 参考实现 JeeflowFacade::isForeignDetail(type, message, cause, trace)，rust parse_error_message
     * 同姿势），文案判据与副作用（{@see logInternalFailure()}）各自可测。
     *
     * **不能简单收窄成「只透 JeeflowException」**：本栈普查（2026-10-02，各 packages 包 src/ 下全部 29 处 throw）
     * 证实契约文案存在**裸异常腿**——ProcessTask:81/84/100、ProcessInstance:429/434、
     * ModelParser:70「读取流程定义 JSON 失败」、本门面 requireExt()「未接入 IProcessExtRepository…」与
     * idListArgs()「id 缺失或非法」（\InvalidArgumentException），都是裸 \RuntimeException/\InvalidArgumentException
     * 携带中文契约文案，其余栈与前端 toast 按原文逐字对齐 ⇒ 收窄会静默改写契约面且没人报警。
     * 所以按「这段文案是谁写的」判，五条（顺序即优先级，true ⇒ 属内部信息 ⇒ 出口只给固定文案）：
     *  1. message 为 null/空串 ⇒ 内部（旧形状 `?: (string) $e` 的兜底会吐类名＋文件＋栈，必是内部）；
     *  2. 契约异常族（JeeflowException 及其子类）⇒ 逐字透出（本条返回 false）；
     *  3. message 恰等于 cause 的原文/字符串化 ⇒ 内部（裸包装只是搬运下层原文，引擎没写过它）；
     *  4. 运行时/反射/JSON 解析器/驱动自己抛的族（\Error 全族——含 \TypeError/\ValueError/
     *     \DivisionByZeroError、\PDOException、\JsonException、\ReflectionException）⇒ 内部；
     *     注意引擎拿来当契约载体的 \RuntimeException/\InvalidArgumentException **不在**这一族；
     *  5. 抛出点不在引擎命名空间（Jeeflow\…，排除 Jeeflow\Tests\…）⇒ 内部（引擎没写过的一律不外透）。
     *
     * @param string      $type    异常类型（$e::class）
     * @param ?string     $message 异常文案（可为 null/空串）
     * @param ?\Throwable $cause   previous 链（可为 null）
     * @param array       $trace   $e->getTrace()——trace[0]['class'] 即抛出点所在类（对应 java
     *                             trace[0].getClassName()，继承方法也报**声明类**，实测）；
     *                             ⚠️ trace[0]['file'] 是**调用方**文件，不能拿来判归属
     * @param ?string     $file    抛出点文件（$e->getFile()，闭包/顶层抛出的兜底归属）
     * @return bool true ⇒ 属内部信息，出口只给固定文案
     */
    public static function isForeignDetail(string $type, ?string $message, ?\Throwable $cause, array $trace, ?string $file = null): bool
    {
        if ($message === null || $message === '') {
            return true;
        }
        if (is_a($type, JeeflowException::class, true)) {
            return false;
        }
        if ($cause !== null && ($message === $cause->getMessage() || $message === (string) $cause)) {
            return true;
        }
        if (self::runtimeInternal($type)) {
            return true;
        }
        return !self::thrownInsideEngine($trace, $file);
    }

    /** 运行时/反射/JSON 解析器/驱动构造的异常族（java jvmInternal 的本栈等价类型；其 message 一律是内部信息） */
    private static function runtimeInternal(string $type): bool
    {
        return is_a($type, \Error::class, true)                 // \TypeError/\ValueError/\DivisionByZeroError/「Call to a member function … on null」全在此族
            || is_a($type, \PDOException::class, true)          // 驱动（java SQLException 族；注意它继承 \RuntimeException，必须单列）
            || is_a($type, \JsonException::class, true)         // JSON 解析器（JSON_THROW_ON_ERROR）
            || is_a($type, \ReflectionException::class, true);  // 反射（java ReflectiveOperationException 族）
    }

    /** 抛出点是否落在引擎命名空间（排除测试桩：Jeeflow\Tests\ 抛的不算引擎契约文案，对齐 java 排除 test 包） */
    private static function thrownInsideEngine(array $trace, ?string $file): bool
    {
        if ($trace !== []) {
            $symbol = $trace[0]['class'] ?? $trace[0]['function'] ?? null;
            if (is_string($symbol) && $symbol !== '' && !str_contains($symbol, '{closure')) {
                return str_starts_with($symbol, 'Jeeflow\\') && !str_starts_with($symbol, 'Jeeflow\\Tests\\');
            }
        }
        // 闭包/顶层抛出兜底：按抛出点文件归属（trace[0]['file'] 是调用方文件，只能用 getFile()）
        if (is_string($file) && $file !== '') {
            foreach (self::engineSrcDirs() as $dir) {
                if (str_starts_with($file, $dir)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 引擎各包 src/ 目录（锚点类推导真实路径，monorepo 与 composer 安装形态都成立） */
    private static function engineSrcDirs(): array
    {
        static $dirs = null;
        if ($dirs !== null) return $dirs;
        $dirs = [];
        $anchors = [JeeflowException::class, self::class, \Jeeflow\Persist\PdoDynamicTableWriter::class, \Jeeflow\RepositoryPDO\PdoProcessRepository::class];
        foreach ($anchors as $anchor) {
            if (!class_exists($anchor)) continue;
            $f = (new \ReflectionClass($anchor))->getFileName();
            if (is_string($f)) $dirs[] = dirname($f) . DIRECTORY_SEPARATOR;
        }
        return $dirs;
    }

    /**
     * 内部失败的唯一副作用出口：原文只进**日志与异常对象的 previous 链**（$wrapped->getPrevious()
     * 即原始异常），绝不进 msg 或任何其它对外字段。protected 供测试子类覆写捕获——
     * 文案判据（isForeignDetail 纯函数）与副作用各自可测。
     */
    protected function logInternalFailure(string $action, JeeflowException $wrapped): void
    {
        error_log('[jeeflow-php] action 执行失败: action=' . $action . ' ' . $wrapped->getPrevious());
    }

    // ═══ 流程定义 ═══

    private function definePage(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $page = $this->repository->pageDefines($query);
        return $this->pageResult($page);
    }

    private function defineDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? null);
        $def = $this->repository->findDefineById($id);
        if ($def === null) return $this->error('流程定义不存在');
        $data = [
            'id' => $def['id'],
            'name' => $def['name'],
            'displayName' => $def['displayName'] ?? '',
            'type' => $def['type'] ?? null,
            'state' => $def['state'],
            'version' => $def['version'],
            'jsonObject' => $this->parseGraph($def['content'] ?? ''),
        ];
        return $this->ok($data);
    }

    private function startAndExecute(array $args): array
    {
        $defineId = $this->toStr($args[FlowConst::PROCESS_DEFINE_ID_KEY] ?? '');
        // issues/129：空串与缺键同档，一律走 operatorOf 归一（旧 `?? 'user1'` 只兜 unset/null）
        $operator = $this->operatorOf($args);

        $flowArgs = FlowData::create();
        foreach ($args as $k => $v) {
            if ($k !== FlowConst::PROCESS_DEFINE_ID_KEY && $k !== 'operator') {
                $flowArgs->set($k, $v);
            }
        }

        $inst = $this->engine->startProcessInstanceById($defineId, $operator, $flowArgs);

        // 自动完成申请节点（assignee="applicant" → 发起人）
        $doingTasks = $this->repository->findDoingTasks($inst->getInstanceId());
        foreach ($doingTasks as $task) {
            $this->repository->addTaskActor($task->getTaskId(), [$operator]);
            $flowArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
            // f_nextNodeOperator → tf_nextNodeOperator
            $startNextOp = $flowArgs->get(FlowConst::PROCESS_START_NEXT_NODE_OPERATOR);
            if ($startNextOp !== null && $startNextOp !== '') {
                $flowArgs->set(FlowConst::NEXT_NODE_OPERATOR, $startNextOp);
            }
            $this->engine->executeProcessTask($task->getTaskId(), $operator, $flowArgs);
        }

        return $this->ok([FlowConst::PROCESS_INSTANCE_ID_KEY => $inst->getInstanceId()]);
    }

    private function deploy(array $args): array
    {
        $content = $this->contentString($args);
        $model = ModelParser::parse($content);
        $name = $model->getName();
        // 查找同名最新定义
        $existing = $this->repository->findLatestDefineByName($name);
        $version = 0;
        if ($existing !== null) {
            $version = ($existing['version'] ?? 0) + 1;
        }
        $defineId = (string) $this->repository->getIdGenerator()->nextId();
        $this->repository->addDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'state' => 1,
            'content' => $content,
            'version' => $version,
            'createUser' => $args['operator'] ?? null,
            'updateUser' => $args['operator'] ?? null,
        ]);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function redeploy(array $args): array
    {
        $defineId = $this->toStr($args[FlowConst::PROCESS_DEFINE_ID_KEY] ?? '');
        $content = $this->contentString($args);
        $model = ModelParser::parse($content);
        $this->repository->updateDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'content' => $content,
            'updateUser' => $args['operator'] ?? 'system',
        ]);
        return $this->ok();
    }

    private function defineRemove(array $args): array
    {
        foreach ($this->idListArgs($args) as $id) {
            $this->repository->removeDefine($id);
        }
        return $this->ok();
    }

    private function defineUpAndDown(array $args): array
    {
        $state = (int) ($args['opType'] ?? $args['state'] ?? 1);
        foreach ($this->idListArgs($args) as $id) {
            $this->repository->updateDefineState($id, $state);
        }
        return $this->ok();
    }

    // ═══ 流程实例 ═══

    private function instancePage(array $args): array
    {
        $query = $this->queryParser->parse($args);
        // issues/129：归属谓词「我发起的」——`{"operator":""}` 视同未传，回落缺省 user1
        $userId = $this->operatorOf($args);
        $query->add('t.operator', 'EQ', $userId);
        $page = $this->repository->pageInstances($query);
        return $this->pageResult($page);
    }

    private function instanceDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        $inst = $this->repository->findInstanceById($id);
        if ($inst === null) return $this->error('流程实例不存在');

        $def = $this->repository->findDefineById($inst->getDefineId());
        $jsonObject = $def !== null ? $this->parseGraph($def['content'] ?? '') : null;
        $firstTaskNodeId = $this->firstTaskNodeId($jsonObject);

        $tasks = [];
        $activeTaskList = [];
        foreach ($inst->getTasks() as $t) {
            $vo = $this->taskVo($t);
            $ext = $t->getVariables()->toArray();
            $doing = $t->getTaskState() === ProcessTaskState::DOING;
            // issues/121 P1：行上值优先（引擎建单时写入，历史行同样有效），缺键（存量行）才回退现算
            $rowFirst = $ext['isFirstTaskNode'] ?? null;
            $ext['isFirstTaskNode'] = $rowFirst !== null
                ? (bool) $rowFirst
                : ($doing && $t->getTaskName() === $firstTaskNodeId);
            $vo['ext'] = $ext;
            $tasks[] = $vo;
            if ($doing) $activeTaskList[] = $vo;
        }

        $data = [
            'id' => $inst->getInstanceId(),
            'parentId' => $inst->getParentId(),
            'processDefineId' => $inst->getDefineId(),
            'state' => $inst->getState(),
            'parentNodeName' => $inst->getParentNodeName(),
            'businessNo' => $inst->getBusinessNo(),
            'operator' => $inst->getOperator(),
            // issues/124：变量唯一对外出口是 ext，variables 全集不进契约；空变量出 {} 而非 null
            'ext' => $inst->getVariables()->toArray() ?: (object)[],
            'formData' => $this->formDataOf($inst->getVariables()->toArray(), FlowConst::FORM_DATA_PREFIX),
            'createTime' => $inst->getCreateTime(),
            'createUser' => $inst->getCreateUser(),
            'displayName' => $def['displayName'] ?? null,
            'name' => $def['name'] ?? null,
            'version' => $def['version'] ?? null,
            'jsonObject' => $jsonObject,
            'tasks' => $tasks,
            'activeTaskList' => $activeTaskList,
        ];
        return $this->ok($data);
    }

    /**
     * 撤回（issues/113/114）：整单撤回 —— 实例与全部进行中任务置 WITHDRAW(30)。
     *
     * operator **硬必填**：缺失或空串直接 `operator 必填`，严禁缺省回落 user1 等固定账号
     * （PHP 此前正是 `$args['operator'] ?? 'user1'` + 零鉴权，撤回人被静默记成 user1 且不报错）。
     * 归属判据三条见 {@link canWithdraw}；语义细节见规范 06 §processInstance/withdraw。
     */
    private function withdraw(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $operator = trim($this->toStr($args['operator'] ?? ''));
        if ($operator === '') return $this->error('operator 必填');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');
        if (!$this->canWithdraw($inst, $operator)) return $this->error('无权限撤回该流程实例');
        // 聚合根置态：仅进行中任务 → 30（已完成 20 / 已终止 40 / 已废弃 99 行不改写），
        // 实例与被撤任务的 update_user 一并回写为真实撤回人。
        // issues/134 案 A：实例状态守卫就在聚合根 withdraw 里（state≠10 ⇒ 抛 20010009 固定文案），
        // 这一句排在下面 updateInstance **之前**且中间无任何改写 ⇒ 被拒时实例与任务行一行都不动、
        // 也不落库；出口由 flow() 的 catch 统一成 code=99999999 + msg 逐字（码值不进 msg，121 口径）。
        $inst->withdraw($operator);
        // updateInstance 级联落库（PDO 仓内部逐任务 updateTask；内存仓与聚合共享对象引用）
        $this->repository->updateInstance($inst);
        // TASK_WITHDRAW（spec §11.3 码 8，issues/132 新增）：撤回把实例 state 写 30 **落库之后**、
        // 被撤任务行更新完成，**每轮撤回只 fire 一次**（一次撤回动的是一个事实，不逐任务发）。
        // 上面聚合根守卫（issues/134 非 10 一律拒 20010009）被拒的那次走不到这里 ⇒ 被拒不发本支。
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::TASK_WITHDRAW,
            $instanceId,
            null,
            [
                ProcessPublisher::KEY_INSTANCE_ID => $instanceId,
                ProcessPublisher::KEY_OPERATOR => $operator,
            ],
        ));
        return $this->ok();
    }

    /**
     * 撤回归属判据（issues/114，命中任一即放行，全不命中拒绝）：
     *
     * 1. operator = 实例发起人（{@code wf_process_instance.operator}）——
     *    ⚠️ **不可复用 ProcessTask::isAllowed**：本栈 isAllowed 只判「operator 在不在该任务
     *    actorIds」+ auto/admin 放行，从不查发起人，这一支必须显式补；
     * 2. operator 是该实例任一**进行中**任务的参与者（{@code wf_process_task_actor.actor_id}，
     *    以仓储读回的参与者为准，不用聚合副本）；
     * 3. operator ∈ {flow.auto, flow.admin}（沿用 isAllowed 既有放行约定）。
     */
    private function canWithdraw(ProcessInstance $inst, string $operator): bool
    {
        if ($this->isPrivilegedOperator($operator)) return true;
        if ($operator === $inst->getOperator()) return true;
        foreach ($this->repository->findDoingTasks((string) $inst->getInstanceId()) as $task) {
            if (in_array($operator, $this->actorIdsOf($task), true)) return true;
        }
        return false;
    }

    /** 系统代执行（flow.auto）/ 超级管理员（flow.admin）放行 —— 撤回与转办共用同一约定 */
    private function isPrivilegedOperator(string $operator): bool
    {
        return strcasecmp($operator, FlowConst::AUTO_ID) === 0
            || strcasecmp($operator, FlowConst::ADMIN_ID) === 0;
    }

    /** 任务参与者归一为字符串列表（PDO 行/JSON 反序列化都可能给 int） */
    private function actorIdsOf(ProcessTask $task): array
    {
        return array_values(array_unique(array_map(
            static fn($id): string => trim((string) $id), $task->getActorIds()
        )));
    }

    // ═══ 流程任务 ═══

    private function todoList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        // issues/129：归属谓词「我参与（未办结）」——空串视同未传，回落缺省 user1
        $userId = $this->operatorOf($args);
        $query->add('pta.actor_id', 'EQ', $userId);
        $page = $this->repository->pageTodoTasks($query);
        return $this->pageResult($page);
    }

    private function doneList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        // issues/129：归属谓词「我已办」——空串视同未传，回落缺省 user1
        $userId = $this->operatorOf($args);
        $query->add('t.operator', 'EQ', $userId);
        $page = $this->repository->pageDoneTasks($query);
        return $this->pageResult($page);
    }

    private function execute(array $args): array
    {
        $taskId = $this->toStr($args[FlowConst::PROCESS_TASK_ID_KEY] ?? '');
        // issues/129：办理人同样按「空串与缺键同档」归一，避免空串被当成真实办理人写进任务
        $operator = $this->operatorOf($args);
        $submitType = (int) ($args[FlowConst::SUBMIT_TYPE] ?? SubmitType::AGREE);

        $flowArgs = FlowData::create();
        foreach ($args as $k => $v) {
            if ($k !== FlowConst::PROCESS_TASK_ID_KEY && $k !== 'operator') {
                $flowArgs->set($k, $v);
            }
        }
        $flowArgs->set(FlowConst::SUBMIT_TYPE, $submitType);

        // 分发逻辑（spec §2.8）
        if ($submitType === SubmitType::REJECT) {
            $this->engine->executeAndJumpToEnd($taskId, $operator, $flowArgs);
        } elseif ($submitType === SubmitType::ROLLBACK) {
            $this->engine->executeAndJumpTask($taskId, $operator, $flowArgs, null);
        } elseif ($submitType === SubmitType::JUMP) {
            $taskName = $this->toStr($args[FlowConst::TASK_NAME] ?? '');
            $this->engine->executeAndJumpTask($taskId, $operator, $flowArgs, $taskName);
        } elseif ($submitType === SubmitType::ROLLBACK_TO_OPERATOR) {
            $this->engine->executeAndJumpToFirstTaskNode($taskId, $operator, $flowArgs);
        } elseif ($submitType === 20) { // COUNTERSIGN_DISAGREE
            $flowArgs->set(FlowConst::COUNTERSIGN_DISAGREE_FLAG, 1);
            $this->engine->executeProcessTask($taskId, $operator, $flowArgs);
        } else {
            // 默认执行（0 APPLY / 1 AGREE / 5 RE_APPLY）
            $this->engine->executeProcessTask($taskId, $operator, $flowArgs);
        }
        return $this->ok();
    }

    private function taskDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        $operator = $this->toStr($args['operator'] ?? '');
        $task = $this->repository->findTaskById($id);
        if ($task === null) return $this->error('任务不存在');

        $inst = $this->repository->findInstanceById($task->getProcessInstanceId());
        $def = $inst !== null ? $this->repository->findDefineById($inst->getDefineId()) : null;

        // issues/82-5：任务级 ext.isFirstTaskNode（前端 detail.vue 双兜底 record.ext?.isFirstTaskNode）
        // 首个任务节点且 DOING → true，与 instance detail 的 activeTaskList 行语义一致
        $tExt = $task->getVariables()->toArray();
        $doing = $task->getTaskState() === ProcessTaskState::DOING;
        // 先留住行上值再覆写出口，否则丢掉「缺键」这个事实就没法回退现算
        $tRowFirst = $tExt['isFirstTaskNode'] ?? null;
        $tExt['isFirstTaskNode'] = $tRowFirst !== null ? (bool) $tRowFirst : false;
        $vo = $this->taskVo($task);
        $vo['ext'] = $tExt;
        $vo['executable'] = $task->isAllowed($operator);
        $vo['jsonObject'] = $def !== null ? $this->parseGraph($def['content'] ?? '') : null;
        if ($def !== null) {
            if ($tRowFirst === null) {
                // 存量行没有落库标记 ⇒ 回退现算（仅进行中口径）
                $tExt['isFirstTaskNode'] = $doing && $task->getTaskName() === $this->firstTaskNodeId($vo['jsonObject']);
            }
            $vo['ext'] = $tExt;
        }
        // taskModel
        if ($def !== null) {
            try {
                $model = ModelParser::parse((string) ($def['content'] ?? ''));
                $node = $model->getNode($task->getTaskName());
                if ($node !== null) {
                    // issues/62：taskModel 补 form/ext（字段权限，对齐 boot2 setTaskModel）
                    $vo['taskModel'] = [
                        'name' => $node->getName(),
                        'displayName' => $node->getDisplayName(),
                        'type' => $node instanceof TaskModel ? 'task' : 'unknown',
                        'form' => $node instanceof TaskModel ? ($node->getForm() ?: null) : null,
                        'ext' => $node instanceof TaskModel ? $node->getExt()->toArray() : null,
                    ];
                }
            } catch (\Throwable $ignored) {}
        }
        return $this->ok($vo);
    }

    private function jumpAbleTaskNameList(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->ok([]);
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->ok([]);

        $result = [];
        try {
            $model = ModelParser::parse((string) ($def['content'] ?? ''));
            foreach ($model->getNodes() as $node) {
                if ($node instanceof TaskModel) {
                    $result[] = [
                        'label' => $node->getDisplayName(),
                        'value' => $node->getName(),
                    ];
                }
            }
        } catch (\Throwable $ignored) {}
        return $this->ok($result);
    }

    /**
     * 加签（`processTask/surrogate`，`processTask/addCandidate` 同体复用本方法）。
     *
     * issues/142 B 批（spec 06-facade.md §2.11「归属值写侧归一」）三处收口，逐字照 §2.10 的四点要求：
     *  - **两形同判据**：逗号串与数组都过 {@see CcActorUtil::normalizeActors()}（§2.10 已落地的那一枚
     *    单点，§2.11 末段点名"复用、不要再抄第二份"）。旧形状两条腿两把尺子——串腿
     *    `array_filter(array_map('trim', explode(...)))` **不带回调**＝假值判据，实测吃掉 `'0'`
     *    （要求④在本腿失守）；数组腿原样直连仓储（不 trim、不丢空、不收类型，`null` 元素真往
     *    `actor_id` 里灌）。
     *  - **主键档另判一档**：`processTaskId` 缺失/空串/纯空白响亮报错，不得拿 `''` 当 id 落库
     *    （旧形状 `:546` 完全不校验，`addTaskActor('', …)` 照跑）；沿用本仓 transfer 腿既有文案
     *    `processTaskId 缺失或非法`，不新造。
     *  - **空 actorIds 是报错不是成功**：丢完为空 ⇒ `actorIds 缺失`（码 99999999）。
     *    旧形状返回 **code=0 成功**，是八栈独一份、§2.11③ 点名的违反档；文案与 go/node 同档，
     *    本仓错误信封（`error()` ⇒ 99999999）不变，不新造错误码。
     *
     * 写侧还有第二层兜底（两仓 `addTaskActor` 自己再挡一次，§2.11①「两层都挡」），
     * 本方法的空档判定仍**必须先于**任何仓储调用——绕过门面直连仓储的调用方与门面得同判据。
     */
    private function taskSurrogate(array $args): array
    {
        $taskId = CcActorUtil::normalizeActor($args['processTaskId'] ?? $args['id'] ?? '');
        if ($taskId === '') return $this->error('processTaskId 缺失或非法');
        // 逗号串与数组两形同判据：trim → 空串/纯空白/null 丢弃 → 同次调用折叠（§2.11 表第一行）
        $actorIds = CcActorUtil::normalizeActors($args['actorIds'] ?? []);
        if ($actorIds === []) return $this->error('actorIds 缺失');
        $this->repository->addTaskActor($taskId, $actorIds);
        return $this->ok();
    }

    /**
     * 转办（issues/115，规范 06 §processTask/transfer 七条语义）：摘原办理人 + 换新参与人。
     *
     * 与 {@link taskSurrogate} 加签是两回事——加签**只追加**（原人保留可办，本轮语义不动），
     * 本 action 把待办从 A 的列表**挪到** B 的列表：
     * 1. 摘原人只删 fromActor 那一行参与者（会签节点转的是"自己那一票"，其余成员不受影响）；
     * 2. toActor 追加为参与人，办理规则不变；
     * 3. 任务不新建（沿用同一 processTaskId，高亮图/节点进度不变）；
     * 4. 留痕三件（缺一不可）：任务变量 submitType=7 槽位 + tf_transferHistory 追加式账本
     *    （+ 单跳便捷键 tf_transferTo/tf_transferReason）+ 末跳可读文案 tf_approvalComment；
     * 5. 变量合并序由 ProcessTask::finish 保证（任务既有变量 ← 本次提交参数，args 最高），
     *    故 B 办结后账本仍在、submitType 槽位被 B 的 1/2/20 覆盖属预期；
     * 6. toActor 已是参与者 / fromActor 不在参与者里 → 明确报错；
     * 7. 任务非进行中 → 明确报错。
     * 鉴权：只能转自己那一条待办（operator == fromActor），flow.auto/flow.admin 除外；
     * operator 硬必填。失败码一律 99999999，msg 逐字对齐跨栈统一文案。
     */
    private function taskTransfer(array $args): array
    {
        $operator = trim($this->toStr($args['operator'] ?? ''));
        if ($operator === '') return $this->error('operator 必填');
        // issues/142 B 批（spec 06-facade.md §2.11 表第二行）：from/to 两个归属位**归一后再用**，
        // 且过的是 §2.10 那同一枚单点（{@see CcActorUtil::normalizeActor()}，与加签集合腿、两仓写侧
        // 逐字同一条尺子）——不 trim 就会与写侧判重错开，同一人落两行；非标量入参数组/对象判成空，
        // 走下面既有的"必填"档响亮报错（旧形状 `(string)` 强转数组会撞 Array to string conversion）。
        $fromActor = CcActorUtil::normalizeActor($args['fromActor'] ?? '');
        if ($fromActor === '') return $this->error('fromActor 必填');
        $toActor = CcActorUtil::normalizeActor($args['toActor'] ?? '');
        if ($toActor === '') return $this->error('toActor 必填');
        // 主键档：空串与纯空白同判"缺失"，不得拿 '' 当 id 往下落库（与加签腿同一条文案）
        $taskId = CcActorUtil::normalizeActor($args[FlowConst::PROCESS_TASK_ID_KEY] ?? $args['id'] ?? '');
        if ($taskId === '') return $this->error('processTaskId 缺失或非法');

        $task = $this->repository->findTaskById($taskId);
        if ($task === null) return $this->error('任务不存在');
        if (!$this->isPrivilegedOperator($operator) && $operator !== $fromActor) {
            return $this->error('无权限转办该任务');
        }
        if ($task->getTaskState() !== ProcessTaskState::DOING) {
            return $this->error('任务非进行中，不可转办');
        }
        // 参与者以仓储读回的 wf_process_task_actor 为判据（内存仓/PDO 仓同源）
        $actors = $this->actorIdsOf($task);
        if (!in_array($fromActor, $actors, true)) return $this->error('原办理人不是该任务参与人');
        if (in_array($toActor, $actors, true)) return $this->error('目标人已是该任务参与人');

        // ①摘原人（仅 fromActor 那一行）+ ②加新人（同一 taskId，任务不新建）
        $this->repository->removeTaskActor($taskId, [$fromActor]);
        $this->repository->addTaskActor($taskId, [$toActor]);

        // ④留痕三件 —— 时间一律 yyyy-MM-dd HH:mm:ss 字符串（§2.4 跨栈同形，严禁 ISO 方言/时刻对象）
        $reason = trim($this->toStr($args['reason'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $vars = $task->getVariables();
        // 账本必须是追加式列表而非单跳键：审批记录槽位就是任务行本身，B 办结时 submitType 会被
        // 他的办理参数覆盖——没有跨跳账本，多跳转办只剩末跳、办结后转办事实整体消失（审计断链）。
        $ledger = [];
        $existing = $vars->get(FlowConst::TRANSFER_HISTORY);
        if (is_array($existing)) {
            foreach ($existing as $hop) {
                $ledger[] = $hop; // 只追加：既往各跳原样带过来，不重排不裁剪
            }
        }
        // 六键固定 camelCase、顺序与契约同形；reason 无值写 ""（不写 null）
        $ledger[] = [
            'submitType' => SubmitType::TRANSFER,
            'fromActor' => $fromActor,
            'toActor' => $toActor,
            'reason' => $reason,
            'time' => $now,
            'operator' => $operator,
        ];
        $vars->set(FlowConst::TRANSFER_HISTORY, $ledger);
        $vars->set(FlowConst::SUBMIT_TYPE, SubmitType::TRANSFER);
        $vars->set(FlowConst::TRANSFER_TO, $toActor);
        $vars->set(FlowConst::TRANSFER_REASON, $reason);
        // 末跳可读文案走前端既有读取位（approvalRecord 的 variable/ext）；多跳只留末跳
        $vars->set(FlowConst::APPROVAL_COMMENT, $reason === ''
            ? $fromActor . ' 转办给 ' . $toActor
            : $fromActor . ' 转办给 ' . $toActor . '（' . $reason . '）');
        $task->setVariables($vars);
        // ⚠️ 契约条款 4：严禁覆写任务 actor_id/operator——进行中任务该列恒无值是既有不变量，
        // 而 pageDoneTasks 按 state<>10 AND operator=? 过滤，写进去会让被摘走的人在单据撤回/终止后
        // 「凭空」在我已办列表看到从没办过的单（Node 实测踩过）。"办理人记谁"由下面的 update_user
        // + 账本 tf_transferHistory[].operator 承载，不占 actor_id。
        $task->setUpdateTime($now);
        $task->setUpdateUser($operator);
        $this->repository->updateTask($task);
        // TASK_TRANSFER（spec §11.3 码 7，issues/132 新增）：任务参与者被替换**并落库之后** fire
        // （上面 removeTaskActor/addTaskActor/updateTask 三次写都已完成，§11.2 原则 3）。
        // sourceId=taskId；转办不新建任务行 ⇒ 本支不伴随 PROCESS_TASK_START(3)。
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::TASK_TRANSFER,
            $taskId,
            null,
            [
                ProcessPublisher::KEY_INSTANCE_ID => (string) $task->getProcessInstanceId(),
                ProcessPublisher::KEY_TASK_ID => $taskId,
                ProcessPublisher::KEY_FROM_ACTOR => $fromActor,
                ProcessPublisher::KEY_TO_ACTOR => $toActor,
                ProcessPublisher::KEY_OPERATOR => $operator,
            ],
        ));
        return $this->ok();
    }

    /**
     * 摘除参与人（issues/115 残留 · 门面第 **47** 个 action，规范 06 §processTask/removeTaskActor）。
     * SPI 侧 `removeTaskActor` 从第一天起就是必选方法、两仓都实现，只是没上门面 ⇒ 摘人只能靠
     * `transfer`（摘 A **并**加 B），本 action 补的就是这一段（八栈同批）。
     *
     * 三个兄弟 action 的分工（混用是本 action 最大的风险）：
     * ① `processTask/surrogate`／`addCandidate` ＝ **只加**；② `processTask/transfer` ＝ **换人**
     * （摘 A 加 B，写 submitType=7 ＋ tf_transferHistory 留痕）；③ 本 action ＝ **只摘不加、零留痕**：
     * 删掉 `actorIds` 在本任务的参与者行，不新建任务、不写任何任务变量、不覆写任务 actor_id/operator
     * 列、**不 fire 事件**（issues/132 §11.3 定稿的事件集里没有"摘除参与人"这一码，码 7
     * `TASK_TRANSFER` 的语义是"参与者被替换"，只摘不加却发码 7 等于把没发生的转办写进事件流）。
     *
     * 守卫次序逐栈一致（规范同节钉死，门禁按 msg 断言，不接受各栈自行排序）：
     * `operator 必填` → `processTaskId/actorIds 缺失` → `任务不存在` → `无权限摘除该任务参与人`
     * → `任务非进行中，不可摘除参与人` → `至少需保留一名参与人` → 落库。
     *
     * ⚠️ 与 `taskSurrogate`/`taskTransfer` 的两处**有意**不同，勿"顺手对齐"成本栈的旧形状：
     *  - 缺参数档出跨栈统一文案 `processTaskId/actorIds 缺失`（规范语义 8「同族同文案」）。本栈两兄弟
     *    现有的是分开的 `processTaskId 缺失或非法` ＋ `actorIds 缺失`，改它们的 msg 会破既有门禁格，
     *    属另一件事（新增 action 一律按 spec 出合并档）。
     *  - 只认 `processTaskId`，**不**像两兄弟那样兼容 `id` 别名：java 基准腿与 node 腿都只有这一个入参，
     *    新增 action 不再扩大入参面。
     */
    private function taskRemoveActor(array $args): array
    {
        // 必填档先判：参数全缺时若先报主键缺失，会把鉴权缺口藏进"缺参数"报错里。
        // 硬必填、严禁回落 user1（与 transfer/withdraw 同口径）；归一走 §2.11 那一枚单点，
        // 判空只认 `=== ''`（'0' 是合法 actor id，empty()/无回调 array_filter 的假值判据会吃掉它）。
        $operator = CcActorUtil::normalizeActor($args['operator'] ?? null);
        if ($operator === '') return $this->error('operator 必填');
        // 主键档 ＋ 归属值档同判"缺参数"：processTaskId 缺失/空串/纯空白，或 actorIds 过归一后为空
        // （逗号串与数组两形同判据）。两条都不落库；归一丢掉的空串/纯空白元素也永远不会成为删除实参。
        $taskId = CcActorUtil::normalizeActor($args[FlowConst::PROCESS_TASK_ID_KEY] ?? null);
        $actorIds = CcActorUtil::normalizeActors($args['actorIds'] ?? []);
        if ($taskId === '' || $actorIds === []) return $this->error('processTaskId/actorIds 缺失');
        // 0 与负数同样走**缺参数**档，而不是让它去仓储查一圈再报「任务不存在」（spec 语义 8）：
        // 拿 0/负数当 id 去查、去落库，和没传 id 是同一种调用方错误。
        // 非数字串**不在**统一之列——本栈没有 java `toLong` 那层"折成 null"的形状，它仍按
        // 仓储查不到落「任务不存在」，spec 明文把那一档留作各栈既有形状、不作跨栈判据。
        if (preg_match('/^-?\d+$/', $taskId) && (int)$taskId <= 0) {
            return $this->error('processTaskId/actorIds 缺失');
        }
        $task = $this->repository->findTaskById($taskId);
        if ($task === null) return $this->error('任务不存在');
        // 归属判据同 transfer（语义 3）：operator ∈ 被摘集合（两侧都取归一后的串，比较才咬得上），
        // 或 flow.auto/flow.admin（isPrivilegedOperator 既有口径，strcasecmp 天然大小写不敏感）。
        // transfer 能"摘 A 加 B"是因为 A 就是操作人本人，本 action 同理不得成为借道摘他人的口子。
        if (!$this->isPrivilegedOperator($operator) && !in_array($operator, $actorIds, true)) {
            return $this->error('无权限摘除该任务参与人');
        }
        // 语义 4：仅进行中（DOING=10）任务可摘人。已办结/废弃/撤回的历史参与人行是 approvalRecord 的
        // 取证依据（它读全状态任务行），摘它等于改写审批历史。
        if ($task->getTaskState() !== ProcessTaskState::DOING) {
            return $this->error('任务非进行中，不可摘除参与人');
        }
        // 参与者以仓储读回的 wf_process_task_actor 行值为判据。
        // ⚠️ 这里**故意不用** $this->actorIdsOf($task)：那个 helper 逐元素 trim，恰好把语义 6
        // 要保住的东西抹掉——库里的行可能是修复前落下的未 trim 原值 `" leader "`。
        $targets = $actorIds;
        $toDelete = [];
        $remaining = 0;
        foreach ($task->getActorIds() as $row) {
            // 语义 6「匹配取归一值、`DELETE` 取「原值 ∪ trim 值」两形并集」（§2.11 硬要求②的删除腿，
            // owner 2026-10-02 裁定）：门面这一层**匹配**用归一形 ⇒ `" leader "` 行的归一值 'leader'
            // 能被打中；**交给仓储的却是那一行的原值**——它是仓储删除腿并集里的「原值形」那一份，
            // 用来命中修复前落下的未 trim 历史脏行（SQL 侧是 `WHERE actor_id = ?` 列值精确比较，
            // 归一值打不中未 trim 的原值；只交归一值就是"判成同一人却一条没删"的**假成功**，
            // 门面报成功而被摘的人待办还在）。
            // 并集的另一份「trim 形」由仓储自己补：两仓 `removeTaskActor` 都过
            // `CcActorUtil::deleteForms()`，把门面交出的原值展开成 原值 ∪ trim 值 两形
            // （第三方绕过门面直连仓储传 `" 8601 "` 时，靠 trim 形才删得掉写侧归一后的规范行 `8601`，
            // issues/142 §9.2 那一路）。门面只管"交出原值"，不要在门面侧先 trim 掉。
            $normalized = CcActorUtil::normalizeActor($row);
            if ($normalized === '') {
                continue;   // 归一后为空的历史脏行（actor_id=''/纯空白）既不匹配，也不算"一个人"
            }
            if (in_array($normalized, $targets, true)) {
                $toDelete[] = is_string($row) ? $row : (string) $row;
            } else {
                $remaining++;
            }
        }
        // 语义 5「不得摘空」按**能办单的人数**判（脏行撑不起下限，否则"摘空"会伪装成成功）；
        // 判据取集合差（上面的 $remaining），不是"入参条数"——actorIds 里混非参与者 id 也绕不过。
        // 少了这一条就会造出无人可办、也无法撤回重派的死单，比"配错表达式落 NULL"更难恢复。
        if ($toDelete !== [] && $remaining === 0) return $this->error('至少需保留一名参与人');
        // 语义 7「幂等」：actorIds 里不属于本任务参与者的人静默忽略；一个都没命中 ⇒ 空操作、成功信封
        // （前端双点、集成层重放第二次不再报错）。要"人不在任务里就报错"请用 transfer。
        // 落库后即返回：**不 updateTask、不写变量、不置 submitType、不 ProcessPublisher::notify**。
        if ($toDelete !== []) $this->repository->removeTaskActor($taskId, $toDelete);
        return $this->ok();
    }

    private function taskLatest(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $doingTasks = $this->repository->findDoingTasks($instanceId);
        if (empty($doingTasks)) return $this->ok(null);
        return $this->ok($this->taskVo($doingTasks[0]));
    }

    // ═══ issues/61：候选分页 + 业务数据读取 ═══

    /**
     * 候选用户分页（对齐 Java JeeflowFacade#candidatePage）
     *
     * 优先从流程定义解析下一任务节点候选（candidateUsers/candidateGroups），
     * 命中则逐个映射用户信息（UserSearchProviderInterface 优先，其次 UserProviderInterface，兜底原样）；
     * 未命中则走 UserSearchProviderInterface::page 用户分页搜索（未配置明确报错）。
     */
    private function candidatePage(array $args): array
    {
        $taskId = $this->toStr($args[FlowConst::PROCESS_TASK_ID_KEY] ?? $args['id'] ?? '');
        if ($taskId === '') return $this->error('processTaskId 缺失');
        $task = $this->repository->findTaskById($taskId);
        if ($task === null) return $this->error('任务不存在');
        $inst = $this->repository->findInstanceById($task->getProcessInstanceId());
        if ($inst === null) return $this->error('流程实例不存在');
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->error('流程定义不存在');

        $candidateIds = [];
        try {
            $model = ModelParser::parse((string) ($def['content'] ?? ''));
            $candidateIds = $model->getNextTaskModelCandidates($task->getTaskName());
        } catch (\Throwable $ignored) {}

        if ($candidateIds !== []) {
            // 候选配置命中 → 用户信息映射（UserSearchProviderInterface 优先，其次 UserProviderInterface）
            $rows = [];
            foreach ($candidateIds as $actorId) {
                $u = null;
                if ($this->userSearchProvider !== null) {
                    $u = $this->userSearchProvider->findById($actorId);
                }
                if ($u === null) {
                    $userProvider = ServiceContext::find(UserProviderInterface::class);
                    if ($userProvider !== null) {
                        $user = $userProvider->getUser($actorId);
                        if ($user !== null) {
                            $u = ['userId' => $actorId, 'realName' => $user['realName'] ?? ''];
                            if (!empty($user['deptName'])) {
                                $u['deptName'] = $user['deptName'];
                            }
                        }
                    }
                }
                if ($u === null) {
                    $u = ['userId' => $actorId, 'realName' => $actorId];
                }
                $rows[] = $this->candidateRow($actorId, $u);
            }
            return $this->pageResult(new PageResult(1, 10, count($rows), $rows));
        }
        // 无模型候选 → 用户分页搜索（依赖 UserSearchProviderInterface）
        if ($this->userSearchProvider === null) {
            return $this->error('未配置 UserSearchProviderInterface（用户搜索钩子）');
        }
        return $this->pageResult($this->userSearchProvider->page($this->queryParser->parse($args)));
    }

    /**
     * candidatePage 模型候选行键归一（issues/80，对齐 Java candidateRow）
     *
     * 前端 UserSelect 按 valueField='id'/labelField='realName' 取值：
     * 主键 id（取 src.id → src.userId → actorId），realName 兜底 id，
     * 保留 userId 兼容旧消费方；userName/deptName 有则透传。
     */
    private function candidateRow(string $actorId, array $src): array
    {
        $id = $src['id'] ?? $src['userId'] ?? $actorId;
        $row = ['id' => (string) $id, 'realName' => $src['realName'] ?? (string) $id];
        if (isset($src['userId'])) {
            $row['userId'] = $src['userId'];
        }
        if (isset($src['userName'])) {
            $row['userName'] = $src['userName'];
        }
        if (isset($src['deptName'])) {
            $row['deptName'] = $src['deptName'];
        }
        return $row;
    }

    /**
     * 业务数据回显（对齐 Java JeeflowFacade#bizData）
     *
     * 表名取流程定义 content 顶层 relTableName（缺省回落 name）；
     * MetaTableReader 由集成方经 ServiceContext::put("metaTableReader", ...) 注册（需引入 persist 模块），
     * 未注册明确报错。
     */
    private function bizData(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? $args['id'] ?? '');
        if ($instanceId === '') return $this->error('processInstanceId 缺失');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->error('流程定义不存在');
        $tableName = $this->resolveRelTableName((string) ($def['content'] ?? ''));
        if ($tableName === null) return $this->error('流程定义未配置 relTableName');
        // issues/61：core 不编译期依赖 persist——按名查找，未注册明确报错
        $reader = ServiceContext::find('metaTableReader');
        if ($reader === null) {
            return $this->error('业务数据读取器未注册（ServiceContext::put("metaTableReader", new MetaTableReader(...))，需引入 jeeflow-persist）');
        }
        try {
            $result = $reader->readByProcessInstance($tableName, $instanceId);
            return $result === null ? $this->ok() : $this->ok($result);
        } catch (\Throwable $e) {
            // issues/137 §3-1：对齐 java（137-G）与 csharp（批二 dca1b33）——msg 只留契约固定文案
            // 「业务数据读取失败」，reader/驱动原文只进日志与 previous 链（旧形状把原文拼进 msg 属泄漏；
            // 前缀本体是跨栈契约文案，删拼接不删前缀）。
            $this->logInternalFailure('processInstance/bizData', new JeeflowException('业务数据读取失败', 99999999, $e));
            return $this->error('业务数据读取失败');
        }
    }

    /** 从流程定义 content 顶层解析 relTableName（缺省回落 name） */
    private function resolveRelTableName(string $content): ?string
    {
        if ($content === '') return null;
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return null;
        $tableName = isset($decoded['relTableName']) ? trim((string) $decoded['relTableName']) : '';
        if ($tableName === '') {
            $tableName = isset($decoded['name']) ? trim((string) $decoded['name']) : '';
        }
        return $tableName === '' ? null : $tableName;
    }

    private function getLastByName(array $args): array
    {
        $name = $this->toStr($args['processDefineName'] ?? '');
        $def = $this->repository->findLatestDefineByName($name);
        if ($def === null) return $this->error('流程定义不存在: ' . $name);
        return $this->ok([
            'id' => $def['id'],
            'name' => $def['name'],
            'displayName' => $def['displayName'] ?? '',
            'type' => $def['type'] ?? null,
            'state' => $def['state'],
            'version' => $def['version'],
        ]);
    }

    private function highLight(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');

        $activeNodeNames = [];
        $historyNodeNames = [];
        $historyEdgeNames = [];

        // 活跃节点 = 进行中任务
        $doing = $this->repository->findDoingTasks($instanceId);
        foreach ($doing as $t) {
            if (!in_array($t->getTaskName(), $activeNodeNames, true)) {
                $activeNodeNames[] = $t->getTaskName();
            }
        }

        // 历史节点 = 已完成任务（第一条腿；第二条腿「模型路径补全」在下面 collectPath 里并进来）
        $history = $this->repository->findHistoryTasks($instanceId);
        foreach ($history as $t) {
            if (!in_array($t->getTaskName(), $activeNodeNames, true) &&
                !in_array($t->getTaskName(), $historyNodeNames, true)) {
                $historyNodeNames[] = $t->getTaskName();
            }
        }

        // nodeProgress ＋ 模型路径补全（issues/153①②，spec 06 §4.6「三条义务」第 1/2 条）。
        // 模型解析**复用本方法既有那一条** ModelParser::parse 路径（与 buildNodeProgress 同一
        // ProcessModel 实例），不新写解析器、不二次 parse。
        // 本栈旧形状：$historyEdgeNames 声明成空数组后原样出口（中间没有任何写入），
        // historyNodeNames 只有任务行这一腿 ⇒ 不产生任务行的网关/结束节点全丢。
        $nodeProgress = [];
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def !== null) {
            try {
                $model = ModelParser::parse((string) ($def['content'] ?? ''));
                $nodeProgress = $this->buildNodeProgress($model, $history);
                // 从 start 沿 getOutputs() 递归：可达节点并进 historyNodeNames、走过的边名并进
                // historyEdgeNames；遇活跃节点停止深入（活跃分支还没走，它的下游不高亮）。
                // visited 走引用传递，与 java 那枚跨分支共享的 HashSet 同语义（防环＋不重复展开）。
                // ⚠ visited 必须是**变量**而非字面量 []：形参按引用接收，传字面量会在调用点抛
                //   catchable Error（"Argument #N ($visited) could not be passed by reference"），
                //   被下面那句 catch (\Throwable) 吞掉 ⇒ 模型补全腿静默不执行，而同一 try 里已经
                //   跑完的 buildNodeProgress 照常出口，读起来像"只有边腿坏了"。本轮实测踩过。
                $visited = [];
                $this->collectPath($model->getStart(), $activeNodeNames, $historyNodeNames,
                    $historyEdgeNames, $visited, $inst->getVariables(), $history);
            } catch (\Throwable $e) {
                // issues/156：吞之前落一条可观测记录。行为不变（仍返回成功信封、仍不高亮），
                // 但不再无声——本栈此前 `catch (\Throwable $ignored) {}` 把整条模型腿的
                // TypeError 咽掉，集成方与用户都拿不到任何信号，13 栈门禁只在活栈上照出
                // "nodeProgress 0 节点"这一句读数，根因得靠人肉拆开才看得见。
                error_log('[jeeflow-php] highLight 模型补全腿失败（已降级为不高亮）: '
                    . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        return $this->ok([
            'activeNodeNames' => $activeNodeNames,
            'historyNodeNames' => $historyNodeNames,
            'historyEdgeNames' => $historyEdgeNames,
            'nodeProgress' => $nodeProgress ?: (object)[],
        ]);
    }

    /**
     * 模型路径补全（issues/153①②；基准＝java `JeeflowFacade.collectPath`、go
     * `facade.go:1231-1263 collectPath`）：沿输出边递归，补全历史节点与历史边，遇活跃节点停止深入。
     *
     * 决策节点的**带表达式出边先求值**（spec 06 §4.6 义务 2）：求值为 false 的分支没有实际执行，
     * 边名与目标节点都不收、也不继续深入——否则未走的分支会被整条高亮出来。
     * 「把带 expr 的边整条丢弃」与「不求值全量收集」两种实现都违反该义务。
     *
     * @param string[]      $active   活跃节点名（只读）
     * @param string[]      $history  历史节点名（任务行腿已填，本方法就地补全）
     * @param string[]      $edges    历史边名（就地收集）
     * @param string[]      $visited  防环/防重复展开集合，跨分支共享（引用传递＝java 那枚 HashSet）
     * @param FlowData      $instanceVars 实例变量（求值 args 的底）
     * @param ProcessTask[] $historyTasks  实例全部任务行（取决策节点前置任务变量用）
     */
    private function collectPath(?NodeModel $node, array $active, array &$history, array &$edges,
                                 array &$visited, FlowData $instanceVars, array $historyTasks): void
    {
        if ($node === null) return;
        if (in_array($node->getName(), $visited, true)) return;
        $visited[] = $node->getName();

        foreach ($node->getOutputs() as $tm) {
            if ($node instanceof DecisionModel && $tm->getExpr() !== ''
                && !$this->evalDecisionExpr($node, $tm, $instanceVars, $historyTasks)) {
                continue;
            }
            $edgeName = $tm->getName();
            if ($edgeName !== '' && !in_array($edgeName, $edges, true)) {
                $edges[] = $edgeName;
            }
            $next = $tm->getTarget();
            if ($next === null) continue; // 出边目标节点未建档（解析期跳过），与 TransitionModel.execute 的落穿同档
            if (!in_array($next->getName(), $active, true) &&
                !in_array($next->getName(), $history, true)) {
                $history[] = $next->getName();
            }
            if (in_array($next->getName(), $active, true)) continue; // 遇活跃节点停止深入
            $this->collectPath($next, $active, $history, $edges, $visited, $instanceVars, $historyTasks);
        }
    }

    /**
     * 决策出边表达式求值（issues/153②；基准＝java `evalDecisionExpr`、go `evalDecisionExpr:1266+`）：
     * args ＝ 实例变量 ∪ 决策节点**前置任务**（输入边第一个源节点）的任务变量，与引擎运行期
     * `DecisionModel.exec` 同源（packages/core/src/Model/DecisionModel.php:40 那一句 SPI 调用口，
     * facade 不另立第二套求值通道）。变量覆盖方向与 java 一致：任务变量后灌、同名覆盖实例变量。
     *
     * ⚠️ **降级档**：`ExpressionEvaluatorInterface` **未注册**时整档判 false——带表达式的出边
     * 一条都不收。这是 spec 06 §4.6 义务 2 唯一允许的 false 档（宿主没给求值器就没有真相可高亮），
     * 不是求值失败；与 java（`evaluator == null ⇒ return false`）、go 逐字同形。
     */
    private function evalDecisionExpr(DecisionModel $decision, TransitionModel $tm,
                                      FlowData $instanceVars, array $historyTasks): bool
    {
        $evaluator = ServiceContext::find(ExpressionEvaluatorInterface::class);
        if ($evaluator === null) return false; // 降级档，见方法注释

        $args = $instanceVars->copy();
        $inputs = $decision->getInputs();
        if ($inputs !== []) {
            $src = $inputs[0]->getSource();
            if ($src !== null && $src->getName() !== '') {
                foreach ($historyTasks as $t) {
                    if ($src->getName() === $t->getTaskName()) {
                        $args->setAll($t->getVariables()->toArray());
                        break;
                    }
                }
            }
        }
        // 与 DecisionModel.exec 同一把尺子：只有字面 true 算命中（java 是 Boolean.TRUE.equals）
        return $evaluator->eval($tm->getExpr(), $args) === true;
    }

    private function approvalRecord(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        // spec 06 §4.6 approvalRecord 四条口径②（issues/154）：视图端点不因实例 id 不存在报错
        // ⇒ 出空数组。⚠ 本轮只并 approvalRecord 这一条；上面 highLight 的「实例不存在 ⇒ 报错」
        //   spec 未对它立这条，按现状保留不动。
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->ok([]);

        // 口径①：排序**必须** id ASC（雪花 id 单调，同秒并发插入时比 create_time/update_time 确定；
        // java 基准侧 JdbcProcessRepository.findHistoryTasks 已是 `ORDER BY id ASC`）。
        // 本栈这条腿读聚合根 getTasks()，两档仓储都不是 id ASC：
        //   · 内存仓＝建单追加序（ProcessInstance.php:207/255/291/303 依次 append）；
        //   · PDO 仓＝findInstanceById 加载关联任务时 `ORDER BY create_time`
        //     （PdoProcessRepository.php:198）。
        // 于是就地排序，且**只作用在 approvalRecord 这一条取数路径上**：getTasks()/setTasks() 是
        // detail/stats/latest/会签等多处消费方共用的聚合根方法，改它们的序会把不相关的出口一起带偏。
        // （usort 自 PHP 8 起稳定排序，等值 id 保持原相对序。）
        $tasks = $inst->getTasks();
        usort($tasks, fn(ProcessTask $a, ProcessTask $b)
            => self::compareNumericId((string) $a->getTaskId(), (string) $b->getTaskId()));

        $rows = [];
        foreach ($tasks as $t) {
            $rows[] = [
                // 口径④（九键）＋ issues/154「id 必须字符串化」：19 位雪花出 number 会被 JS 截精度。
                // 本栈 flow() 出口其实还有一道 issues/75 的全局 stringifyIds()（'id' 键入列），
                // 但引擎侧不自依赖那道钩子——与 java 侧 String.valueOf(t.getTaskId()) 同形，
                // 构造出的行本身就诚实（绕过 flow() 直取行构造、或宿主没有该钩子的场合仍正确）。
                'id' => (string) $t->getTaskId(),
                'taskName' => $t->getTaskName(),
                'displayName' => $t->getDisplayName(),
                'taskType' => $t->getTaskType(),
                'performType' => $t->getPerformType(),
                'taskState' => $t->getTaskState(),
                'operator' => $t->getActorId(),
                'finishTime' => $t->getFinishTime(),
                // 口径③：任务变量为空时出空对象，**不**回落实例变量（现读即无回落，此处钉住并留注）。
                'ext' => $t->getVariables()->toArray() ?: (object)[], // issues/124：variable 原串出口下线
            ];
        }
        return $this->ok($rows);
    }

    /**
     * 雪花 id 升序比较：按**数字串**比，不 `(int)` 强转——19 位 id 撞上 PHP_INT_MAX
     * (9223372036854775807) 边界会溢出成浮点，排序结果不可信。
     * 等长时逐字符比较与数值序一致；非等长时位数多者大（前导零不在本栈 id 生成器产物里）。
     */
    private static function compareNumericId(string $a, string $b): int
    {
        if ($a === $b) return 0;
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA !== $lenB) return $lenA <=> $lenB;
        return strcmp($a, $b);
    }

    private function getAssigneeTextData(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $includeNodeName = (bool) ($args['includeNodeName'] ?? true);
        $doing = $this->repository->findDoingTasks($instanceId);
        $result = [];
        foreach ($doing as $t) {
            foreach ($t->getActorIds() as $actorId) {
                // spec 06 §4.6 getAssigneeTextData 两条义务②（issues/155）：label 八栈严格
                // `节点显示名:用户id`，includeNodeName=false 时只出用户 id。本栈旧实现经
                // IUserProvider 取 realName ?: actorId ⇒ 出的是**姓名**，八栈里唯一异类，
                // 已并派为其余七栈形状（java JeeflowFacade.getAssigneeTextData 同样不查用户）。
                // 义务①：value 是参与者用户 id，本 action 只做文案，不得被当作节点定位键
                // （画布按节点回显办理人走 highLight.nodeProgress）。
                $result[] = [
                    'value' => $actorId,
                    'label' => $includeNodeName ? $t->getDisplayName() . ':' . $actorId : $actorId,
                ];
            }
        }
        return $this->ok($result);
    }

    private function createCCInstance(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        // issues/141 G10「空不创建行」（spec 06-facade.md §2.10 实现要求③）：手动腿与引擎腿过同一条
        // 归一腿（CcActorUtil::normalize，逗号串与数组两形同判据）——空串/纯空白/数组里的空元素一律
        // 丢弃，**丢完为空 ⇒ 与本仓既有的"空 actorIds"档同判**（沿用 抄送人不能为空 文案，不新造
        // 错误码/文案），既不建 cc 行也不 fire 码 4。
        // 旧形状：(array) 强转只把标量裹成单元素、且不筛空值 ⇒ ['']／['','  '] 都算"非空"往下走，
        // 实测真落 actor_id=''／'  ' 的行还 fire 码 4——空归属值正是 issues/129 那族的病根。
        $actorIds = CcActorUtil::normalize($args['actorIds'] ?? []);
        $operator = $this->toStr($args['operator'] ?? '');
        if ($actorIds === []) return $this->error('抄送人不能为空');
        // issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4）：手动腿与引擎腿同一条判据
        // （spec §11.7「三条入口共用一支」）——已有 cc 行的 (实例, 人) 跳过，不新增行、
        // 不重置未读、不更新原行时间；只有**实际新建的子集**拿去 fire。
        $created = $this->repository->createCcInstanceIfAbsent($instanceId, $operator, $actorIds);
        // 手动抄送腿同样 fire CC_CREATE（spec §11.2 原则 1 ＋ §11.3 码 4 三条路径同判 ＋
        // §11.6「java 是手动不 fire 那一派，本案唯一一处基准要向 go/py/node 学」——PHP 同病本轮并修）：
        // 「新增了一条抄送记录」这个事实与谁触发无关，cc 行落库之后逐抄送人发一次，
        // 与发起/办理腿共用 ProcessPublisher::notifyCcCreate 同一把收口（严禁集成层自行补发，§11.1）。
        // 入参＝实际新建子集（issues/141 G2）：重复抄送没发生"创建"⇒ 不发码 4，子集为空整支不 fire。
        if ($created !== []) {
            ProcessPublisher::notifyCcCreate($instanceId, $created);
        }
        return $this->ok();
    }

    /**
     * 标记抄送已读。
     *
     * issues/142 B 批（spec 06 §2.11 表第四行）：`operator` 是归属列的**比较位**，必须【归一后再比】。
     * 旧形状把入参原样递给仓储，于是两个方向都错：
     *   · 带前后空格的入参打不中已落库的规范值（该条永远未读，用户面是【点了已读没反应】）；
     *   · 空 operator 会把 state=1 批量打到历史 actor_id 为空串的脏行上——issues/129 那族
     *     【空归属值读全库】在写侧的复现。故归一后为空 ⇒ 一条 UPDATE 都不发。
     * 两仓各自还会再挡一次（§2.11 要求①【两层都挡】）：绕过门面直连仓储的调用方同样挡得住。
     * 主键档 processInstanceId 本轮不外扩——要报错就得新造文案，违 §2.11 要求③；
     * 同一条裁定在 java 也记了，见 issues/142 §9.1 裁定③／§9.2 第二批。
     */
    private function updateCCStatus(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        // issues/142 B 批（spec 06 §2.11 表第四行）：operator 先过 operatorOf（空串/缺键回落
        // demo 缺省 user1，issues/129 案 A 与 java operatorArg 同一条规则）再归一取 trim 值——
        // 不 trim 则「 9101 」打不中库里 trim 后的行（点了已读没反应）。java 基准同构：
        // normalizeActor(operatorArg(args))，其 error("operator 必填") 分支因 operatorArg
        // 恒回落 user1 而不可达，可观测行为就是「空 ⇒ 标记 user1 自己的行」，本栈照抄。
        // 仓储侧还有第二层 no-op 守卫（归一后为空 ⇒ 一条都不动，两层都挡）。
        $operator = CcActorUtil::normalizeActor($this->operatorOf($args));
        $this->repository->updateCcStatus($instanceId, $operator);
        return $this->ok();
    }

    private function ccList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        // issues/129：归属谓词「抄送我的」——空串视同未传，回落缺省 user1
        // issues/138：谓词列写作 cc.actor_id（spec 06 §2.5 口径表钉的列，语义不变），与 Java
        // JeeflowFacade.ccList 的 query.add("cc.actor_id","EQ",userId) 逐字同形。ccList 的行源
        // 已是 wf_process_instance（别名 t），被抄送人在 JOIN 进来的 cc 表上，故这里必须用 cc. 前缀；
        // 两仓储各自把该键落到 cc 表的列上（PDO 直接拼进 WHERE，内存仓按 EXISTS 匹配实例的 cc 行）。
        $userId = $this->operatorOf($args);
        $query->add('cc.actor_id', 'EQ', $userId);
        $page = $this->repository->pageCcInstances($query);
        return $this->pageResult($page);
    }

    // ═══ 内部辅助方法 ═══

    private function taskVo(object $task): array
    {
        return [
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
            'taskFormData' => $this->formDataOf($task->getVariables()->toArray(), FlowConst::TASK_FORM_DATA_PREFIX),
            'createTime' => $task->getCreateTime(),
        ];
    }

    private function parseGraph(string $content): mixed
    {
        if ($content === '') return null;
        $decoded = json_decode($content, true);
        return $decoded !== null ? $decoded : $content;
    }

    private function contentString(array $args): string
    {
        if (isset($args['content'])) {
            $c = $args['content'];
            return is_string($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE);
        }
        // 平铺模式：把整个 args 作为流程 JSON
        $filtered = [];
        foreach ($args as $k => $v) {
            if ($k !== 'operator') $filtered[$k] = $v;
        }
        return json_encode($filtered, JSON_UNESCAPED_UNICODE);
    }

    private function firstTaskNodeId(mixed $jsonObject): ?string
    {
        if (is_array($jsonObject) && isset($jsonObject['nodes'])) {
            foreach ($jsonObject['nodes'] as $n) {
                if (isset($n['type']) && ($n['type'] === 'sn:task' || str_ends_with($n['type'] ?? '', ':task'))) {
                    return $n['id'] ?? null;
                }
            }
        }
        return null;
    }

    private function formDataOf(array $variables, string $prefix): array|\stdClass
    {
        $result = [];
        foreach ($variables as $k => $v) {
            if (str_starts_with($k, $prefix)) {
                $result[$k] = $v;
                // 去前缀副本
                $stripped = substr($k, strlen($prefix));
                $result[$stripped] = $v;
            }
        }
        return $result ?: (object)[];
    }

    /** 节点成员进度（issues/41/82-10，对齐 Java/Go/Python）：按任务状态组装 nodeProgress——
     *  会签节点带 type（PARALLEL/SEQUENTIAL），成员 done 按完成状态逐人标记、active 仅进行中任务
     *  首位（非"所有未完成成员"）；动态参与人（无静态成员）不返回；name 走 UserProvider SPI
     *  （未注册/查不到缺省空串，前端降级显示 id）。成员取任务 actorIds 并集
     *  （PHP 引擎会签逐人建任务表驱动，无 operatorList 变量——与 Java/Go/Python 同构）。
     *  会签判定与 type 取**模型节点属性**（引擎建任务时 performType 未落任务表，取模型为准）。 */
    private function buildNodeProgress(ProcessModel $model, array $historyTasks): array
    {
        $progress = [];
        $names = [];
        $seen = [];
        foreach ($historyTasks as $t) {
            if (!isset($seen[$t->getTaskName()])) {
                $names[] = $t->getTaskName();
                $seen[$t->getTaskName()] = true;
            }
        }
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        foreach ($names as $name) {
            $ts = array_values(array_filter($historyTasks, fn($t) => $t->getTaskName() === $name));
            if (empty($ts)) continue;
            // 完整成员列表：会签串行任务变量 operatorList_{node} 优先（逐个创建时仅 1 个任务，
            // 全量办理人存于其变量——对齐 Go/Java buildNodeProgress），否则任务 actorIds 并集
            $csMembers = $this->readCountersignOperatorList($ts, $name);
            if (!empty($csMembers)) {
                // issues/156：成员 id 出口必须是字符串——PHP 会把纯数字串的数组键折叠成 int，
                // 而 SPI 签名是 getUser(string)（本文件 declare(strict_types=1)），
                // 19 位雪花出 number 还会被 JS 截精度（issues/75/92 同族）。
                $members = array_map('strval', $csMembers);
            } else {
                $memberSet = [];
                foreach ($ts as $t) {
                    foreach ($t->getActorIds() as $aid) {
                        $memberSet[$aid] = true;
                    }
                }
                if (empty($memberSet)) continue; // 动态参与人：无静态成员，不返回
                // array_keys() 这里取回的是**被折叠过的键**（'1001' ⇒ int 1001），必须显式收回字符串：
                // 否则 getUser(int) 当场 TypeError（被门面模型腿的 catch 吞掉 ⇒ nodeProgress 整体为空），
                // 且下面 $mid === $activeActor 恒 false ⇒ active 标记一起丢。
                $members = array_map('strval', array_keys($memberSet));
            }
            $doneSet = [];
            foreach ($ts as $t) {
                if ($t->getTaskState() === ProcessTaskState::FINISHED) {
                    foreach ($t->getActorIds() as $aid) {
                        $doneSet[$aid] = true;
                    }
                }
            }
            // active 仅进行中任务的首位处理人（其余未完成成员不带任何标记）
            $activeActor = null;
            foreach ($ts as $t) {
                if ($t->getTaskState() === ProcessTaskState::DOING && !empty($t->getActorIds())) {
                    $activeActor = $t->getActorIds()[0];
                    break;
                }
            }
            // 会签判定：模型节点属性（非任务表——任务 performType 未落库）
            $isCs = false;
            $csType = null;
            $node = $model->getNode($name);
            if ($node instanceof TaskModel) {
                $isCs = $node->getPerformType() === PerformType::COUNTERSIGN;
                if ($node->getCountersignType() !== null) {
                    $csType = $node->getCountersignType() === CountersignType::SERIAL
                        ? 'SEQUENTIAL' : 'PARALLEL';
                }
            }
            $nodeData = ['members' => []];
            foreach ($members as $mid) {
                $entry = ['id' => $mid, 'name' => ''];
                if ($userProvider !== null) {
                    $user = $userProvider->getUser($mid);
                    $entry['name'] = $user['realName'] ?? '';
                }
                if (isset($doneSet[$mid])) {
                    $entry['done'] = true;
                } elseif ($mid === $activeActor) {
                    $entry['active'] = true;
                }
                $nodeData['members'][] = $entry;
            }
            if ($isCs && $csType !== null) {
                $nodeData['type'] = $csType;
            }
            $progress[$name] = $nodeData;
        }
        return $progress;
    }

    /** 会签全量办理人：从任务变量 operatorList_{node} 还原（issues/93 串行逐个创建时仅 1 个任务，
     *  全量办理人存于该任务变量，对齐 Go/Java）。遍历节点全部任务，返回第一个非空列表（首位
     *  任务必带）；兼容 JSON 反序列化后的数组形态 */
    private function readCountersignOperatorList(array $ts, string $name): array
    {
        $key = FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $name;
        foreach ($ts as $t) {
            $value = $t->getVariables()->get($key);
            if (is_array($value)) {
                $list = [];
                foreach ($value as $o) {
                    $s = trim((string) $o);
                    if ($s !== '') $list[] = $s;
                }
                if (!empty($list)) return $list;
            } elseif ($value !== null && trim((string) $value) !== '') {
                return [trim((string) $value)];
            }
        }
        return [];
    }

    // ═══ 统计（issues/103） ═══

    private function statsOverview(array $args): array
    {
        $stateIn = $this->statsParseStateIn($args);
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);

        $allInsts = $this->repository->getAllInstances();
        $insts = $this->statsFilterInstances($allInsts, $stateIn, $start, $end);
        $total = count($insts);
        $inProgress = $completed = $withdrawn = $rejected = $suspended = 0;
        foreach ($insts as $inst) {
            match ($inst->getState()) {
                ProcessInstanceState::DOING => $inProgress++,
                ProcessInstanceState::FINISHED => $completed++,
                ProcessInstanceState::WITHDRAW => $withdrawn++,
                ProcessInstanceState::REJECTED => $rejected++,
                ProcessInstanceState::PENDING => $suspended++,
                default => null,
            };
        }

        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
        $todayNew = 0;
        foreach ($allInsts as $inst) {
            $ct = $inst->getCreateTime();
            if ($ct !== null && $ct >= $todayStart && $ct < $todayEnd) $todayNew++;
        }

        // avgDurationSeconds：state=20 完成实例平均时长，不受 stateIn 影响（对齐内置线 avgCompletedInstanceDurationSeconds）
        $instsForAvg = $this->statsFilterInstances($allInsts, null, $start, $end);
        $totalDur = 0;
        $durCount = 0;
        foreach ($instsForAvg as $inst) {
            if ($inst->getState() !== ProcessInstanceState::FINISHED) continue;
            $maxFinish = null;
            foreach ($inst->getTasks() as $task) {
                $ft = $task->getFinishTime();
                if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
            }
            if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                $totalDur += max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                $durCount++;
            }
        }
        $avgDur = $durCount > 0 ? intdiv($totalDur, $durCount) : 0;

        $rejectRate = self::statsRound4($rejected / max(1, $completed + $rejected));

        $allTasks = $this->repository->getAllTasks();
        $pending = $overdue = 0;
        $nowStr = date('Y-m-d H:i:s');
        foreach ($allTasks as $task) {
            if ($task->getTaskState() === ProcessTaskState::DOING) {
                $pending++;
                $exp = $task->getExpireTime();
                if ($exp !== null && $exp < $nowStr) $overdue++;
            }
        }

        // countersignRate/onTimeRate：全量已完成任务聚合，不限时间、不受 stateIn 影响（对齐内置线 countCompletedTask）
        $csTotal = $csCount = $onTime = $onTimeDenom = 0;
        foreach ($this->repository->getAllTasks() as $task) {
            if ($task->getTaskState() !== ProcessTaskState::FINISHED) continue;
            $csTotal++;
            if ($task->getPerformType() === PerformType::COUNTERSIGN) $csCount++;
            $exp = $task->getExpireTime();
            if ($exp !== null) {
                $onTimeDenom++;
                $ft = $task->getFinishTime();
                if ($ft !== null && $ft <= $exp) $onTime++;
            }
        }
        $countersignRate = $csTotal > 0 ? self::statsRound4($csCount / $csTotal) : 0.0;
        $onTimeRate = $onTimeDenom > 0 ? self::statsRound4($onTime / $onTimeDenom) : 0.0;

        return $this->ok([
            'total' => $total, 'inProgress' => $inProgress, 'completed' => $completed,
            'rejected' => $rejected, 'withdrawn' => $withdrawn, 'suspended' => $suspended,
            'todayNew' => $todayNew, 'avgDurationSeconds' => $avgDur,
            'rejectRate' => $rejectRate, 'pendingTaskCount' => $pending,
            'overdueTaskCount' => $overdue, 'countersignRate' => $countersignRate,
            'onTimeRate' => $onTimeRate,
        ]);
    }

    private function statsTrend(array $args): array
    {
        $granularity = (string)($args['granularity'] ?? '');
        if (!isset(self::VALID_GRANULARITY[$granularity])) {
            return $this->error('不支持的 granularity: ' . $granularity);
        }
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);
        // C：start/end 必填（对齐内置线 20010012 缺参语义），不再静默返回空 series
        if ($start === null || $end === null) {
            return $this->error('trend 缺少必填参数：start/end/granularity');
        }

        // 实例侧无 state 过滤（对齐内置线 countInstanceStartedByBucket）
        $insts = $this->statsFilterInstances($this->repository->getAllInstances(), null, $start, $end);
        $doneTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::FINISHED], $start, $end, 'finish');

        $buckets = self::statsEnumerateBuckets($start, $end, $granularity);
        $startedMap = [];
        foreach ($insts as $inst) {
            $ct = $inst->getCreateTime();
            if ($ct !== null) {
                $bk = self::statsBucketKey($ct, $granularity);
                $startedMap[$bk] = ($startedMap[$bk] ?? 0) + 1;
            }
        }
        $finishedMap = [];
        foreach ($doneTasks as $task) {
            $ft = $task->getFinishTime();
            if ($ft !== null) {
                $bk = self::statsBucketKey($ft, $granularity);
                $finishedMap[$bk] = ($finishedMap[$bk] ?? 0) + 1;
            }
        }

        $series = [];
        foreach ($buckets as $b) {
            $series[] = ['bucket' => $b, 'started' => $startedMap[$b] ?? 0, 'finished' => $finishedMap[$b] ?? 0];
        }
        // A：data 本体为裸数组（去掉 {granularity, series} 包装，对齐契约 spec 06 §4.2 / 内置线）
        return $this->ok($series);
    }

    private function statsGroup(array $args): array
    {
        $dimension = (string)($args['dimension'] ?? '');
        if (!isset(self::VALID_DIMENSION[$dimension])) {
            return $this->error('不支持的 dimension: ' . $dimension);
        }
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);
        $limit = isset($args['limit']) ? (int)$args['limit'] : self::DEFAULT_STATS_LIMIT;
        // 无 state 过滤（对齐内置线 groupByDimension：仅按时间限定，契约 group 无 stateIn 入参）
        $insts = $this->statsFilterInstances($this->repository->getAllInstances(), null, $start, $end);
        $doneTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::FINISHED], $start, $end, 'finish');
        $doingTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::DOING], null, null, 'create');

        $rows = [];
        if ($dimension === 'define') {
            // D 对齐内置线 mapper：count 全实例、avg 仅对 state=20 且有 finish 的实例聚合（除数=完成数）
            $grouped = [];
            foreach ($insts as $inst) {
                $did = $inst->getDefineId();
                if (!isset($grouped[$did])) $grouped[$did] = ['count' => 0, 'totalDur' => 0, 'durCount' => 0];
                $grouped[$did]['count']++;
                if ($inst->getState() === ProcessInstanceState::FINISHED) {
                    $maxFinish = null;
                    foreach ($inst->getTasks() as $task) {
                        $ft = $task->getFinishTime();
                        if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
                    }
                    if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                        $grouped[$did]['totalDur'] += max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                        $grouped[$did]['durCount']++;
                    }
                }
            }
            $entries = [];
            foreach ($grouped as $did => $agg) {
                $def = $this->repository->findDefineById($did);
                $entries[] = [
                    'key' => $def['name'] ?? (string)$did,
                    'label' => $def['displayName'] ?? $def['display_name'] ?? null,
                    'count' => $agg['count'],
                    'avgDurationSeconds' => $agg['durCount'] > 0 ? intdiv($agg['totalDur'], $agg['durCount']) : null,
                ];
            }
            usort($entries, fn($a, $b) => $b['count'] - $a['count']);
            $rows = array_slice($entries, 0, $limit);

        } elseif ($dimension === 'state') {
            $grouped = [];
            foreach ($insts as $inst) {
                $k = (string)$inst->getState();
                $grouped[$k] = ($grouped[$k] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => (string)$k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'category') {
            $defineTypes = [];
            foreach ($insts as $inst) {
                $did = $inst->getDefineId();
                if (!isset($defineTypes[$did])) {
                    $def = $this->repository->findDefineById($did);
                    $defineTypes[$did] = $def['type'] ?? '';
                }
            }
            $grouped = [];
            foreach ($insts as $inst) {
                $tp = $defineTypes[$inst->getDefineId()] ?? '';
                $grouped[$tp] = ($grouped[$tp] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'approver') {
            $grouped = [];
            foreach ($doneTasks as $task) {
                $op = $task->getActorId();
                if ($op === null || $op === '') continue;
                $grouped[$op] = ($grouped[$op] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'applicant') {
            $grouped = [];
            foreach ($insts as $inst) {
                $op = $inst->getOperator();
                if ($op === null || $op === '') continue;
                $grouped[$op] = ($grouped[$op] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'node') {
            $nodeAgg = [];
            foreach ($doneTasks as $task) {
                $dn = $task->getDisplayName();
                if ($dn === null || $dn === '') continue;
                $dur = 0;
                $ft = $task->getFinishTime();
                $ct = $task->getCreateTime();
                if ($ft !== null && $ct !== null) $dur = max(0, strtotime($ft) - strtotime($ct));
                if (!isset($nodeAgg[$dn])) $nodeAgg[$dn] = ['count' => 0, 'totalDur' => 0];
                $nodeAgg[$dn]['count']++;
                $nodeAgg[$dn]['totalDur'] += $dur;
            }
            uasort($nodeAgg, fn($a, $b) => $b['count'] - $a['count']);
            $rows = [];
            $i = 0;
            foreach ($nodeAgg as $name => $agg) {
                if ($i++ >= $limit) break;
                $rows[] = [
                    'key' => $name, 'label' => null, 'count' => $agg['count'],
                    'avgDurationSeconds' => $agg['count'] > 0 ? intdiv($agg['totalDur'], $agg['count']) : null,
                ];
            }

        } elseif ($dimension === 'stuckNode') {
            $grouped = [];
            foreach ($doingTasks as $task) {
                $dn = $task->getDisplayName();
                if ($dn === null || $dn === '') continue;
                $grouped[$dn] = ($grouped[$dn] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'stuckApprover') {
            $grouped = [];
            foreach ($doingTasks as $task) {
                foreach ($task->getActorIds() as $actorId) {
                    if ($actorId === null || $actorId === '') continue;
                    $grouped[$actorId] = ($grouped[$actorId] ?? 0) + 1;
                }
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'durationBucket') {
            $durations = [];
            foreach ($insts as $inst) {
                if ($inst->getState() !== ProcessInstanceState::FINISHED) continue;
                $maxFinish = null;
                foreach ($inst->getTasks() as $task) {
                    $ft = $task->getFinishTime();
                    if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
                }
                if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                    $durations[] = max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                }
            }
            $sameDay = $d1to3 = $d3to7 = $over7d = 0;
            foreach ($durations as $dur) {
                if ($dur < 86400) $sameDay++;
                elseif ($dur < 259200) $d1to3++;
                elseif ($dur < 604800) $d3to7++;
                else $over7d++;
            }
            $keys = ['sameDay', '1to3d', '3to7d', 'over7d'];
            $counts = [$sameDay, $d1to3, $d3to7, $over7d];
            $rows = [];
            foreach ($keys as $idx => $k) {
                $rows[] = ['key' => $k, 'label' => null, 'count' => $counts[$idx], 'avgDurationSeconds' => null];
            }
        }

        // A：data 本体为裸数组（去掉 {dimension, rows} 包装，对齐契约 spec 06 §4.2 / 内置线）
        return $this->ok($rows);
    }

    // ── 统计辅助函数 ──

    private static function statsRound4(float $v): float
    {
        return round($v, 4);
    }

    /** @return int[] */
    private function statsParseStateIn(array $args): array
    {
        if (isset($args['stateIn']) && is_array($args['stateIn']) && count($args['stateIn']) > 0) {
            return array_map('intval', $args['stateIn']);
        }
        return self::DEFAULT_STATE_IN;
    }

    /** @param ProcessInstance[] $insts  @param int[]|null $stateIn null=无 state 过滤（对齐内置线：仅 overview 用 stateIn） */
    private function statsFilterInstances(array $insts, ?array $stateIn, ?string $start, ?string $end): array
    {
        $stateSet = $stateIn !== null ? array_flip($stateIn) : null;
        $result = [];
        foreach ($insts as $inst) {
            if ($stateSet !== null && !isset($stateSet[$inst->getState()])) continue;
            $ct = $inst->getCreateTime();
            if ($start !== null && ($ct === null || $ct < $start)) continue;
            // end 含端（对齐内置线 create_time < date_add(end, interval 1 second)）
            if ($end !== null && ($ct === null || $ct > $end)) continue;
            $result[] = $inst;
        }
        return $result;
    }

    /** @param ProcessTask[] $tasks  @param int[] $states  @param 'finish'|'create' $timeField */
    private function statsFilterTasks(array $tasks, array $states, ?string $start, ?string $end, string $timeField): array
    {
        $stateSet = array_flip($states);
        $result = [];
        foreach ($tasks as $task) {
            if (!isset($stateSet[$task->getTaskState()])) continue;
            $t = $timeField === 'finish' ? $task->getFinishTime() : $task->getCreateTime();
            if ($start !== null && ($t === null || $t < $start)) continue;
            // end 含端（对齐内置线 finish_time < date_add(end, interval 1 second)）
            if ($end !== null && ($t === null || $t > $end)) continue;
            $result[] = $task;
        }
        return $result;
    }

    /** @return string[] */
    private static function statsEnumerateBuckets(string $start, string $end, string $granularity): array
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) return [];

        $buckets = [];
        $cur = new \DateTimeImmutable($start);
        $endDt = new \DateTimeImmutable($end);

        // 含 end 桶（对齐内置线枚举：start→end 双闭）——原来 < 少了末端桶
        while ($cur <= $endDt) {
            $buckets[] = self::statsBucketKey($cur->format('Y-m-d H:i:s'), $granularity);
            $cur = match ($granularity) {
                'hour' => $cur->modify('+1 hour'),
                'day' => $cur->modify('+1 day'),
                'week' => $cur->modify('+7 days'),
                'month' => $cur->modify('+1 month'),
            };
        }
        return array_values(array_unique($buckets));
    }

    private static function statsBucketKey(string $datetime, string $granularity): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) return '';
        return match ($granularity) {
            'hour' => date('Y-m-d H:00', $ts),
            'day' => date('Y-m-d', $ts),
            'week' => self::statsWeekKey($ts),
            'month' => date('Y-m', $ts),
            default => '',
        };
    }

    private static function statsWeekKey(int $ts): string
    {
        $isoYear = (int)date('o', $ts);
        $isoWeek = (int)date('W', $ts);
        return sprintf('%d-W%02d', $isoYear, $isoWeek);
    }

    // ── 响应构造 ──

    private function ok(mixed $data = null): array
    {
        return ['code' => 0, 'msg' => '成功', 'data' => $data];
    }

    private function error(string $msg): array
    {
        return ['code' => 99999999, 'msg' => $msg, 'data' => null];
    }

    // issues/75：id 类字段统一字符串化出口（对齐四语言全局 exit hook）。
    // 19 位雪花 id > JS Number.MAX_SAFE_INTEGER(2^53)，若以 JSON 数字下发，前端
    // JSON.parse 走 float64 会丢精度（奇数尾被四舍五入），导致 designer 保存
    // 时 processDesignId 指向不存在的记录、静默 no-op（S8a 偶发根因）。
    // Java 用全局 Jackson Long→String、Go 用 okResult(stringifyIDs)、Node 用字符串型 id；
    // PHP 此前仅列表行 (string)$row['id']，单记录端点(designDetail 等)漏了 → 此处统一补齐。
    private function stringifyIds(mixed $v): mixed
    {
        if (!is_array($v)) return $v; // 标量/对象原样
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k) && $this->isIdKey($k)) {
                $out[$k] = $this->toIdString($val);
            } else {
                $out[$k] = $this->stringifyIds($val); // 递归（嵌套数组/行列表/其结构）
            }
        }
        return $out;
    }

    // id 类键：camelCase（id/*Id，对齐 Go isIDKey）+ snake_case（id/*_id，PDO 原始行）。
    private function isIdKey(string $k): bool
    {
        return $k === 'id' || str_ends_with($k, 'Id') || str_ends_with($k, '_id');
    }

    // id 值转字符串：null 保持 null；字符串直通；整数→十进制；整数值 float→十进制。
    private function toIdString(mixed $v): mixed
    {
        if ($v === null) return null;
        if (is_string($v)) return $v;
        if (is_int($v)) return (string) $v;
        if (is_float($v) && floor($v) === $v) return (string) (int) $v;
        return (string) $v;
    }

    private function pageResult(PageResult $page): array
    {
        return $this->ok($page->toArray());
    }

    private function toStr(mixed $v): string
    {
        return $v !== null ? (string) $v : '';
    }

    // ═══ 流程设计（需扩展仓储） ═══

    private function requireExt(): ProcessExtRepositoryInterface
    {
        if ($this->extRepository === null) {
            throw new \RuntimeException('未接入 IProcessExtRepository，设计/委托 action 不可用');
        }
        return $this->extRepository;
    }

    // issues/63：时间格式化（§2.4 契约 yyyy-MM-dd HH:mm:ss）
    private function fmtTime(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return $v;
        $ts = is_string($v) ? strtotime($v) : false;
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : (string) $v;
    }

    // issues/63：设计行转换（兼容 PDO snake_case / InMemory camelCase）
    private function designRowToMap(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? '',
            'displayName' => $row['displayName'] ?? $row['display_name'] ?? '',
            'type' => $row['type'] ?? '',
            'icon' => $row['icon'] ?? null,
            'isDeployed' => (int) ($row['isDeployed'] ?? $row['is_deployed'] ?? 0),
            'remark' => $row['remark'] ?? null,
            'createTime' => $this->fmtTime($row['createTime'] ?? $row['create_time'] ?? null),
            'createUser' => $row['createUser'] ?? $row['create_user'] ?? null,
            'updateTime' => $this->fmtTime($row['updateTime'] ?? $row['update_time'] ?? null),
            'updateUser' => $row['updateUser'] ?? $row['update_user'] ?? null,
        ];
    }

    private function designPage(array $args): array
    {
        $ext = $this->requireExt();
        $query = $this->queryParser->parse($args);
        $page = $ext->pageDesigns($query);
        $result = $page->toArray();
        $result['rows'] = array_map([$this, 'designRowToMap'], $page->getRows());
        return $this->ok($result);
    }

    private function designDetail(array $args): array
    {
        $ext = $this->requireExt();
        $id = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($id);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($id);
        $jsonObject = $his !== null ? $this->parseGraph($his['content'] ?? '') : null;
        // 如果 jsonObject 缺失基本信息，从 design 补齐
        // issues/98：行键双读（PDO snake_case / InMemory camelCase），与 designRowToMap 同构
        if (is_array($jsonObject)) {
            if (empty($jsonObject['name'])) $jsonObject['name'] = $design['name'] ?? '';
            if (empty($jsonObject['displayName'])) $jsonObject['displayName'] = $design['displayName'] ?? $design['display_name'] ?? '';
        }
        $data = [
            'id' => $design['id'],
            'name' => $design['name'] ?? '',
            'displayName' => $design['displayName'] ?? $design['display_name'] ?? '',
            'type' => $design['type'] ?? null,
            'icon' => $design['icon'] ?? null,
            'isDeployed' => (int) ($design['isDeployed'] ?? $design['is_deployed'] ?? 0),
            'remark' => $design['remark'] ?? null,
            'jsonObject' => $jsonObject,
            'his' => $ext->findDesignHisList($id),
        ];
        return $this->ok($data);
    }

    private function designSave(array $args): array
    {
        $ext = $this->requireExt();
        $id = $args['id'] ?? null;
        if ($id !== null) {
            // 更新基本信息
            $ext->updateDesign(['id' => $this->toStr($id)] + $args);
            // 如果有 content，存快照并置未部署
            if (isset($args['content'])) {
                $content = is_string($args['content']) ? $args['content'] : json_encode($args['content'], JSON_UNESCAPED_UNICODE);
                $ext->saveDesignHis($this->toStr($id), $content, $args['operator'] ?? null);
                $ext->updateDesignDeployed($this->toStr($id), 0);
            }
            return $this->ok(['id' => $this->toStr($id)]);
        }
        // 新建
        $designId = $ext->saveDesign([
            'name' => $args['name'] ?? '',
            'displayName' => $args['displayName'] ?? '',
            'type' => $args['type'] ?? 'approval',
            'icon' => $args['icon'] ?? null,
            'remark' => $args['remark'] ?? null,
            'createUser' => $args['operator'] ?? null,
        ]);
        if (isset($args['content'])) {
            $content = is_string($args['content']) ? $args['content'] : json_encode($args['content'], JSON_UNESCAPED_UNICODE);
            $ext->saveDesignHis($designId, $content, $args['operator'] ?? null);
        }
        return $this->ok(['id' => $designId]);
    }

    private function designUpdate(array $args): array
    {
        $ext = $this->requireExt();
        $ext->updateDesign($args);
        return $this->ok();
    }

    private function designUpdateDefine(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['processDesignId'] ?? '');
        $content = $this->contentString($args);
        // 存快照
        $ext->saveDesignHis($designId, $content, $args['operator'] ?? null);
        // 同步 name/displayName/type
        try {
            $model = ModelParser::parse($content);
            $ext->updateDesign([
                'id' => $designId,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
            ]);
        } catch (\Throwable $ignored) {}
        // 置未部署
        $ext->updateDesignDeployed($designId, 0);
        return $this->ok();
    }

    private function designRemove(array $args): array
    {
        $ext = $this->requireExt();
        foreach ($this->idListArgs($args) as $id) {
            $ext->removeDesign($id);
        }
        return $this->ok();
    }

    private function designDeploy(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($designId);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($designId);
        if ($his === null) return $this->error('设计稿为空，无法发布');
        $content = $his['content'];
        $model = ModelParser::parse($content);
        // 版本管理
        $existing = $this->repository->findLatestDefineByName($model->getName());
        $version = 0;
        if ($existing !== null) $version = ($existing['version'] ?? 0) + 1;
        $defineId = (string) $this->repository->getIdGenerator()->nextId();
        $this->repository->addDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'state' => 1,
            'content' => $content,
            'version' => $version,
        ]);
        $ext->updateDesignDeployed($designId, 1);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function designRedeploy(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($designId);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($designId);
        if ($his === null) return $this->error('设计稿为空');
        $content = $his['content'];
        $model = ModelParser::parse($content);
        // 按 name 找现有定义
        $existing = $this->repository->findLatestDefineByName($model->getName());
        if ($existing !== null) {
            // 原地替换
            $this->repository->updateDefine([
                'id' => $existing['id'],
                'content' => $content,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
            ]);
            $defineId = $existing['id'];
        } else {
            $defineId = (string) $this->repository->getIdGenerator()->nextId();
            $this->repository->addDefine([
                'id' => $defineId,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
                'state' => 1,
                'content' => $content,
                'version' => 0,
            ]);
        }
        $ext->updateDesignDeployed($designId, 1);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function designListByType(array $args): array
    {
        $ext = $this->requireExt();
        $grouped = $ext->listDesignsByType();
        $result = [];
        foreach ($grouped as $type => $items) {
            foreach ($items as $d) {
                $def = $this->repository->findLatestDefineByName($d['name'] ?? '');
                $his = $ext->findLatestDesignHis($d['id'] ?? '');
                $result[$type][] = [
                    'processDesignId' => (string) ($d['id'] ?? ''),
                    'name' => $d['name'] ?? '',
                    // issues/98：行键双读（PDO snake_case / InMemory camelCase），与 designRowToMap 同构
                    'displayName' => $d['displayName'] ?? $d['display_name'] ?? '',
                    'icon' => $d['icon'] ?? null,
                    'remark' => $d['remark'] ?? null,
                    'processDefineId' => $def['id'] ?? null,
                    'processDefineState' => $def['state'] ?? null,
                    'jsonObject' => $his !== null ? $this->parseGraph($his['content'] ?? '') : null,
                ];
            }
        }
        return $this->ok($result);
    }

    // ═══ 委托代理 ═══

    private function surrogatePage(array $args): array
    {
        $ext = $this->requireExt();
        $query = $this->queryParser->parse($args);
        // issues/152 ②（案 A · 引擎侧立法）：t.operator 是归属列，与 instancePage() 同形注入
        // §2.5 归一后的 operator（缺键/空串/全空白 ⇒ demo 缺省 user1），spec 06 §2.5 口径表
        // ＋ §4.5「归属不变式」。修前这里是契约空白："只看自己授出的委托"全靠集成壳注入
        // operator（mldong-boot2-jeeflow WfFlowController），换宿主／直调门面就退化成全库台账
        // （vben5 前端本来不传 operator）。仓储那一层的第二道兜底见两处 pageSurrogates。
        $query->add('t.operator', 'EQ', $this->operatorOf($args));
        $page = $ext->pageSurrogates($query);
        $result = $page->toArray();
        // issues/77：行走 surrogateRowToMap（时间格式化 + 键归一），与 detail 同构
        $result['rows'] = array_map([$this, 'surrogateRowToMap'], $page->getRows());
        return $this->ok($result);
    }

    private function surrogateSave(array $args): array
    {
        $ext = $this->requireExt();
        $operator = $this->operatorOf($args);
        $id = $this->toStr($args['id'] ?? '');
        $surrogate = $id !== '' ? $ext->findSurrogateById($id) : null;
        if ($id !== '' && $surrogate === null) {
            return $this->error('委托记录不存在');
        }
        if ($surrogate === null) {
            $surrogate = ['createUser' => $operator, 'createTime' => date('Y-m-d H:i:s')];
            $surrogate['operator'] = $operator; // 授权人 = 操作人（新建必有）
        }
        $surrogate = $this->applySurrogateFields($surrogate, $args, $operator);
        if ($id === '') {
            $id = $ext->saveSurrogate($surrogate);
        } else {
            $ext->updateSurrogate($surrogate);
        }
        return $this->ok(['id' => $id]);
    }

    /** 委托更新（issues/77）：按 id 全字段更新，授权人缺省时保留原值（前端编辑表单不带 operator） */
    private function surrogateUpdate(array $args): array
    {
        $ext = $this->requireExt();
        $operator = $this->operatorOf($args);
        $id = $this->toStr($args['id'] ?? '');
        if ($id === '') {
            return $this->error('id 缺失或非法');
        }
        $surrogate = $ext->findSurrogateById($id);
        if ($surrogate === null) {
            return $this->error('委托记录不存在');
        }
        $surrogate = $this->applySurrogateFields($surrogate, $args, $operator);
        $surrogate['id'] = $id;
        $ext->updateSurrogate($surrogate);
        return $this->ok(['id' => $id]);
    }

    /** 委托详情（issues/77）：按 id 查单条，返回行结构（时间格式化） */
    private function surrogateDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        if ($id === '') {
            return $this->error('id 缺失或非法');
        }
        $surrogate = $this->requireExt()->findSurrogateById($id);
        if ($surrogate === null) {
            return $this->error('委托记录不存在');
        }
        return $this->ok($this->surrogateRowToMap($surrogate));
    }

    /** 删除委托（issues/95：前端「我的委托」行内/批量删除统一发 {ids}，与 define/design remove 同惯例） */
    private function surrogateRemove(array $args): array
    {
        $ext = $this->requireExt();
        foreach ($this->idListArgs($args) as $id) {
            $ext->removeSurrogate($id);
        }
        return $this->ok();
    }

    /** 删除/启停类 action 的批量主键：mldong IdsParam 惯例下 {ids} 数组优先，兼容单 {id}；
     *  两者皆缺失、空数组或含非法值一律报错，不得静默成功（issues/95，对齐 Java idListArgs） */
    private function idListArgs(array $args): array
    {
        if (isset($args['ids']) && is_array($args['ids'])) {
            $out = [];
            foreach ($args['ids'] as $id) {
                $s = $this->toStr($id);
                if ($s === '') {
                    throw new \InvalidArgumentException('id 缺失或非法');
                }
                $out[] = $s;
            }
            if ($out === []) {
                throw new \InvalidArgumentException('id 缺失或非法');
            }
            return $out;
        }
        $single = $this->toStr($args['id'] ?? '');
        if ($single === '') {
            throw new \InvalidArgumentException('id 缺失或非法');
        }
        return [$single];
    }

    /**
     * 操作人归一 —— 空串与缺键同档（issues/129 · spec 06-facade.md §2.5）
     *
     * 逐字对齐 Java `JeeflowFacade.operatorArg`：`{"operator":""}`（含全空白串）视同未传，
     * 与 unset/null 一并回落到 demo 缺省 `user1`。
     *
     * 修前的形状：本方法曾是 `array_key_exists && !== null ? toStr(...) : 'user1'`，
     * 内联点又用 `$args['operator'] ?? 'user1'`——`??` 只在 unset/null 时兜底，
     * **显式空串原样穿过**。而本栈仓储没有"空值 ⇒ 这条条件不加"的通用放行
     * （{@see PdoProcessRepository::buildConditions()} 逐条拼 `AND col = ?`，
     * {@see InMemoryProcessRepository::matchCondition()} 直接按值比对），
     * 于是空串被当成**真实归属值**去比对 ⇒ 症状不是 java/go/node/python/csharp 那五栈的
     * "读全库"，而是"我的列表悄悄变 0 行"（160 同库同时刻三档并排探针：
     * operator=user1 ⇒ 4 行 / operator="" ⇒ 0 行 / operator=__nobody__ ⇒ 0 行）。
     *
     * ⚠️ 本方法**不**用于 issues/114 的「operator 硬必填」出口（withdraw 族、transfer 族，
     * 见 {@see self::withdraw()}）——那里空串必须报错，严禁缺省回落，是另一条契约。
     */
    private function operatorOf(array $args): string
    {
        $operator = trim($this->toStr($args['operator'] ?? null));
        return $operator !== '' ? $operator : 'user1';
    }

    /** 委托写入公共字段。授权人（operator）仅在**显式且非空白**时覆盖：
     *  - update 缺省档（不带键 / 空串 / 全空白）⇒ 保留原授权人，不得清空（spec 06 §processSurrogate/update）
     *  - save 缺省档 ⇒ 保留调用点已归一的 $operator（{@see self::operatorOf}，缺省 user1）
     *  issues/152 ③：空串/全空白**绝不落进** operator——那种行是「死行」，getSurrogate 的
     *  `WHERE operator = ?` 永不命中它，台账看得见、待办永远不并人（比报错更难查）。
     *  三档入参一律走 §2.5 归一，与 java `applySurrogateFields` 同形。 */
    private function applySurrogateFields(array $s, array $args, string $operator): array
    {
        $s['processName'] = $this->toStr($args['processName'] ?? '');
        if (array_key_exists('operator', $args)) {
            $explicit = $this->toStr($args['operator']);
            if (trim($explicit) !== '') {
                $s['operator'] = $explicit; // 授权人 = 操作人（只认非空的显式值；原值入库，不 trim，与 java 同形）
            }
        }
        $s['surrogate'] = $this->toStr($args['surrogate'] ?? '');
        $s['startTime'] = $this->parseSurrogateTime($args['startTime'] ?? null);
        $s['endTime'] = $this->parseSurrogateTime($args['endTime'] ?? null);
        // 写侧判据（06 §4.5 条款 5「写侧」）：缺键→1、`''`/`'abc'`/`'1abc'` 等不可解析脏值→0 且不抛错、
        // 布尔 true→1/false→0、显式 0 不得被吞。不能写 `(int) $args['enabled']`——
        // PHP 的宽松前缀解析会让 `(int)'1abc'` 落 **1**（=启用），与契约方向相反。
        $s['enabled'] = SurrogateRule::normalizeEnabledArg($args['enabled'] ?? null);
        $s['updateUser'] = $operator;
        return $s;
    }

    // 时间窗解析：§2.4 契约 yyyy-MM-dd HH:mm:ss（空格），兼容 ISO T 与纯日期
    private function parseSurrogateTime(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    // issues/77：委托行转换（兼容 PDO snake_case / InMemory camelCase，时间格式化）
    private function surrogateRowToMap(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'processName' => $row['processName'] ?? $row['process_name'] ?? '',
            'operator' => $row['operator'] ?? '',
            'surrogate' => $row['surrogate'] ?? '',
            'startTime' => $this->fmtTime($row['startTime'] ?? $row['start_time'] ?? null),
            'endTime' => $this->fmtTime($row['endTime'] ?? $row['end_time'] ?? null),
            'enabled' => (int) ($row['enabled'] ?? 1),
            'createTime' => $this->fmtTime($row['createTime'] ?? $row['create_time'] ?? null),
            'createUser' => $row['createUser'] ?? $row['create_user'] ?? null,
            'updateTime' => $this->fmtTime($row['updateTime'] ?? $row['update_time'] ?? null),
            'updateUser' => $row['updateUser'] ?? $row['update_user'] ?? null,
        ];
    }
}
