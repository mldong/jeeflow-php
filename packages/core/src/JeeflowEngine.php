<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\Event\PendingInstanceEnd;
use Jeeflow\Core\Interceptor\SurrogateInterceptor;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\StartModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Util\CcActorUtil;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 工作流引擎实现 —— 薄编排层
 *
 * 对齐 Java JeeflowEngineImpl。
 */
class JeeflowEngine implements JeeflowEngineInterface
{
    /**
     * 退回族 submitType（spec §11.3 码 6「含退发起人、软拒绝、跳转回退」，共用 TASK_REJECT 一号、
     * 载荷再分）：2 拒绝 / 3 退回上一步 / 6 退回发起人 / 20 会签拒绝（软拒绝，issues/91 一票否决）。
     * 0 发起 / 1 同意 / 4 跳转 / 5 重新提交 ⇒ {@see ProcessEventTypeEnum::TASK_COMPLETE}。
     * 与 java `JeeflowEngineImpl.REJECT_SUBMIT_TYPES` 同表（§11.7 行为基准取 java）。
     */
    private const REJECT_SUBMIT_TYPES = [
        SubmitType::REJECT,
        SubmitType::ROLLBACK,
        SubmitType::ROLLBACK_TO_OPERATOR,
        SubmitType::COUNTERSIGN_DISAGREE,
    ];

    private ProcessRepositoryInterface $repository;

    /**
     * 委托代理自动生效开关（issues/116 批次 D，**默认开启**）。
     * 关闭一行：`new JeeflowEngine($repo, surrogateAutoApply: false)`
     * 或 `$engine->setSurrogateAutoApply(false)`；见 SurrogateInterceptor 类注释的三条关闭形。
     */
    private bool $surrogateAutoApply;

    /** 扩展仓储显式注入（null → 从 ServiceContext 解析；两路都没有则静默跳过） */
    private ?ProcessExtRepositoryInterface $extRepository;

    /** 委托应用器覆盖点（null → 内置 SurrogateInterceptor，或集成方注册到 ServiceContext 的实例） */
    private ?SurrogateInterceptor $surrogateApplier = null;

    public function __construct(ProcessRepositoryInterface $repository, bool $surrogateAutoApply = true,
                                ?ProcessExtRepositoryInterface $extRepository = null)
    {
        $this->repository = $repository;
        $this->surrogateAutoApply = $surrogateAutoApply;
        $this->extRepository = $extRepository;
    }

    public function getRepository(): ProcessRepositoryInterface
    {
        return $this->repository;
    }

    /** 委托代理自动生效是否开启（issues/116，默认 true） */
    public function isSurrogateAutoApply(): bool
    {
        return $this->surrogateAutoApply;
    }

    /**
     * 开/关委托代理自动生效（引擎内置行为，issues/116）。
     * 关闭后 `processSurrogate/*` 回到"仅台账"语义：配了委托也不会追加到任务参与者。
     */
    public function setSurrogateAutoApply(bool $enabled): void
    {
        $this->surrogateAutoApply = $enabled;
    }

    /**
     * 覆盖委托应用器：传 `null` 恢复内置默认；传 `NullSurrogateInterceptor` 即"注册空实现"式关闭；
     * 传自定义子类可在应用委托前后叠加集成方自己的逻辑。
     */
    public function setSurrogateApplier(?SurrogateInterceptor $applier): void
    {
        $this->surrogateApplier = $applier;
    }

    // ═══ 启动流程 ═══

    public function startProcessInstanceById(string $defineId, string $operator, ?FlowData $args = null,
                                              ?string $parentId = null, ?string $parentNodeName = null): ProcessInstance
    {
        return $this->runInTx(function () use ($defineId, $operator, $args, $parentId, $parentNodeName) {
            // 1. 查流程定义
            $define = $this->repository->findDefineById($defineId);
            if ($define === null) {
                throw new JeeflowException('流程定义不存在: ' . $defineId);
            }
            // 2. 解析流程模型
            $model = ModelParser::parse((string) $define['content']);
            // 3. 创建聚合根
            if ($args === null) $args = FlowData::create();
            // 3.5 注入用户信息 + 自动标题（对齐 Java JeeflowEngineImpl L77-78）
            FlowUtil::addUserInfoToArgs($operator, $args);
            FlowUtil::addAutoGenTitle($model->getDisplayName(), $args);
            $instance = ProcessInstance::create($define, $operator, $args, $parentId, $parentNodeName);
            // 4. 计算到期时间
            $expireTime = $model->getExpireTime();
            if ($expireTime !== '') {
                $instance->setExpireTime($expireTime); // 简化：不处理变量替换
            }
            // 5. 持久化
            $this->repository->saveInstance($instance);
            // 6. 处理抄送
            $ccActors = $args->get(FlowConst::CC_ACTORS_START);
            $this->handleCcActors($instance->getInstanceId(), $operator, $ccActors);
            // 7. 构建 Execution 并执行开始节点
            $exec = $this->buildExecution($model, $instance, $args, $operator);
            $start = $model->getStart();
            if ($start !== null) {
                $start->execute($exec);
            }
            // 8. 持久化产生的任务，并更新实例
            //    PROCESS_TASK_START 事件须在 saveTask 落库（分配 taskId）之后 fire——对齐
            //    spec §11.2 原则 3 / §11.3 码 3「任务落库后逐任务 fire，监听器可按 taskId 反查」与 Java
            //    JeeflowEngineImpl start 内循环（CreateTaskHandler 阶段 taskId 尚未生成，不 fire）。
            foreach ($exec->getProcessTaskList() as $task) {
                $this->saveNewTask($exec, $task);
            }
            $this->repository->updateInstance($instance);
            // 实例终态事件（码 2）：发起即办结的短流（start→end、decision 直达结束）与
            // 子流程父实例都从这一支落库后播——顺序判据同 persistTasks 的收口。
            $this->flushInstanceEndEvents($exec);
            return $instance;
        });
    }

    // ═══ 执行任务 ═══

    public function executeProcessTask(string $taskId, string $operator, ?FlowData $args = null): array
    {
        return $this->runInTx(function () use ($taskId, $operator, $args) {
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            $node = $model->getNode($exec->getProcessTask()->getTaskName());
            if ($node !== null) {
                $node->execute($exec);
            }
            // 抄送（§11.7 办理腿）：判据取**本次提交的 args**，不取 $exec->getArgs()——后者是
            // prepareExecution 合并过的「实例变量 ← 本次参数」，而实例变量在上一步办理时已被
            // completeTask 全量并入（含那一步的 tf_ccActors）⇒ 读合并值会让"抄送"这个事实被
            // 后续每一次办理重放一次（重复建 cc 行 + 重复 fire CC_CREATE + 重复站内信）。
            // §11.2 原则 1「同一事实只发一次」/§11.1「严禁出现重复抄送/重复通知」；
            // 行为基准＝Java JeeflowEngineImpl:130 `handleCcActors(..., args.get(CC_ACTORS))`。
            $ccActors = $args?->get(FlowConst::CC_ACTORS);
            $this->handleCcActors($exec->getProcessInstance()->getInstanceId(), $operator, $ccActors);
            $this->persistTasks($exec);
            return $exec->getProcessTaskList();
        });
    }

    public function executeAndJumpTask(string $taskId, string $operator, ?FlowData $args = null, ?string $nodeName = null): array
    {
        return $this->runInTx(function () use ($taskId, $operator, $args, $nodeName) {
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            if ($nodeName === null || $nodeName === '') {
                // issues/121 P2：驳回走血缘版——上一步来源＝当前行的 task_parent_id，
                // 由仓储把那条历史行取出来交给聚合根复活（取不到传 null，聚合根报 20010007）。
                $current = $exec->getProcessTask();
                $parentId = $current === null ? null : $current->getParentTaskId();
                $history = ($parentId !== null && $parentId !== '' && $parentId !== '0')
                    ? $this->repository->findTaskById($parentId)
                    : null;
                $exec->addTask($exec->getProcessInstance()->rejectTask($model, $current, $history));
            } else {
                $targetNode = $model->getNode($nodeName);
                if ($targetNode === null) {
                    throw new JeeflowException("根据节点名称[{$nodeName}]无法找到节点模型");
                }
                // issues/79 对齐 Java：跳转到首任务节点（start 直接后继）时 assignee 强制为发起人
                if ($targetNode instanceof TaskModel && FlowUtil::isFirstTaskName($model, $targetNode->getName())) {
                    $targetNode->setAssignee($exec->getProcessInstance()->getOperator());
                }
                $tm = new TransitionModel();
                $tm->setTarget($targetNode);
                $tm->setEnabled(true);
                $tm->execute($exec);
            }
            $this->persistTasks($exec);
            return $exec->getProcessTaskList();
        });
    }

    public function executeAndJumpToEnd(string $taskId, string $operator, ?FlowData $args = null): array
    {
        return $this->runInTx(function () use ($taskId, $operator, $args) {
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            foreach ($model->getModels(EndModel::class) as $end) {
                $tm = new TransitionModel();
                $tm->setTarget($end);
                $tm->setEnabled(true);
                $tm->execute($exec);
            }
            $this->persistTasks($exec);
            return $exec->getProcessTaskList();
        });
    }

    public function executeAndJumpToFirstTaskNode(string $taskId, string $operator, ?FlowData $args = null): array
    {
        return $this->runInTx(function () use ($taskId, $operator, $args) {
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            $start = $model->getStart();
            if ($start !== null) {
                foreach ($start->getOutputs() as $tm) {
                    $tm->setEnabled(true);
                    // issues/79 对齐 Java：退回发起人时首个任务节点 assignee 强制为发起人
                    if ($tm->getTarget() instanceof TaskModel) {
                        $tm->getTarget()->setAssignee($exec->getProcessInstance()->getOperator());
                    }
                    $tm->execute($exec);
                }
            }
            $this->persistTasks($exec);
            return $exec->getProcessTaskList();
        });
    }

    // ═══ 内部方法 ═══

    private function prepareExecution(string $taskId, string $operator, ?FlowData $args): ?Execution
    {
        $task = $this->repository->findTaskById($taskId);
        if ($task === null || !$task->isDoing()) {
            throw new JeeflowException('未找到进行中的任务: ' . $taskId);
        }
        if (!$task->isAllowed($operator)) {
            throw new JeeflowException('操作人[' . $operator . ']无权执行此任务');
        }

        $instance = $this->repository->findInstanceById($task->getProcessInstanceId());
        if ($instance === null) return null;

        $define = $this->repository->findDefineById($instance->getDefineId());
        if ($define === null) return null;

        $model = ModelParser::parse((string) $define['content']);

        if ($args === null) $args = FlowData::create();
        FlowUtil::filterFieldByPerm($args, $model, $task->getTaskName());

        // 完成任务——聚合根内部修改了 instance 中的 task 状态
        $instance->completeTask($taskId, $operator, $args);

        // 将 instance 中的已完成 task 状态同步到 task 对象，并持久化
        foreach ($instance->getTasks() as $t) {
            if ($taskId === $t->getTaskId()) {
                $task->setTaskState($t->getTaskState());
                $task->setActorId($t->getActorId());
                $task->setFinishTime($t->getFinishTime());
                $task->setVariables($t->getVariables());
                $task->setUpdateTime($t->getUpdateTime());
                $task->setUpdateUser($t->getUpdateUser());
                break;
            }
        }
        $this->repository->updateTask($task);

        // 任务被办掉 / 被退回（spec §11.3 码 5 TASK_COMPLETE / 码 6 TASK_REJECT，issues/132 新增）：
        // 紧跟上面这次 updateTask（任务行 state 落库）之后 fire——§11.2 原则 3 的「落库之后」即此。
        // 四个办理入口（常规办理 / 跳转 / 退结束 / 退发起人）都汇过 prepareExecution 这一条漏斗，
        // 与 saveNewTask 之于 PROCESS_TASK_START 同构（契约「覆盖全部流转路径」）。
        // 两支互斥（§11.3 码 6「同一动作走 reject 就不再 fire complete」）：判据取载荷 submitType，
        // 不为「拒绝/退回上一步/退发起人/会签否决」各开一号（§11.2 原则 2）。
        $this->notifyTaskFinished($instance, $task, $operator, $args);

        // 合并流程变量
        $mergedArgs = FlowData::create();
        $mergedArgs->setAll($instance->getVariables()->toArray());
        $mergedArgs->setAll($args->toArray());

        $exec = $this->buildExecution($model, $instance, $mergedArgs, $operator);
        $exec->setProcessTask($task);
        $exec->setProcessTaskId($taskId);
        return $exec;
    }

    private function buildExecution(ProcessModel $model, ProcessInstance $instance, FlowData $args, string $operator): Execution
    {
        $exec = new Execution();
        $exec->setProcessModel($model);
        $exec->setProcessInstance($instance);
        $exec->setProcessInstanceId($instance->getInstanceId());
        $exec->setEngine($this);
        $exec->setArgs($args);
        $exec->setOperator($operator);
        return $exec;
    }

    private function persistTasks(Execution $exec): void
    {
        // 共用 executeProcessTask / executeAndJumpTask / executeAndJumpToEnd /
        // executeAndJumpToFirstTaskNode 全部办理路径：saveTask 落库（分配 taskId）
        // 之后逐任务 fire PROCESS_TASK_START（对齐 Java JeeflowEngineImpl.persistTasks）。
        foreach ($exec->getProcessTaskList() as $task) {
            $this->saveNewTask($exec, $task);
        }
        if ($exec->getProcessTask() !== null && $exec->getProcessTask()->getTaskId() !== null) {
            $this->repository->updateTask($exec->getProcessTask());
        }
        $this->repository->updateInstance($exec->getProcessInstance());
        // 实例终态事件（码 2）：紧跟上面那次 updateInstance —— 行的 state 已落库才允许播
        $this->flushInstanceEndEvents($exec);
    }

    /**
     * 实例终态事件（码 2 `PROCESS_INSTANCE_END`）的统一收口——
     * spec §11.2 原则 3「只在落库之后 fire」／§11.3 码 2「实例 `state` 更新为
     * 20/30/40/45/50/99 之一并落库之后」／08-compliance 场景 32。
     * 逐字移植 Java `JeeflowEngineImpl#flushInstanceEndEvents`。
     *
     * 处理器（`EndProcessHandler`）只往 execution 挂 {@see PendingInstanceEnd}，
     * 本方法在实例行**真正落库之后**把它们播出去。两条路径都要覆盖，缺一即丢事件：
     *
     * - **正常路径**：登记的就是本次 execution 的实例，行已由调用方那次
     *   `repository->updateInstance`（或发起路径的 `saveInstance`+`updateInstance`）
     *   写好 ⇒ 这里只补播，不重复写；
     * - **子流程父实例路径**：子实例办结时处理器在**父实例**的 execution 上继续流转，
     *   父实例**不走**子流程这次的 `updateInstance`（本栈历史缺口与 java 同病：父实例终态
     *   只改内存，内存仓储因存的是对象引用而照不出来，SQL 仓储下那一行永远停在 10）
     *   ⇒ 这里按登记带的聚合根补一次 `updateInstance`，再播。先写后播的顺序对父实例同样成立。
     *
     * 载荷 state 取登记时刻的快照整数（＝刚落库那一行的值），不重读聚合根——登记之后
     * 流转还可能继续触碰该对象，重读会播出一个没写过的中间值。
     *
     * 本栈可到达的终态档位：码 2 只由结束节点产生（办结 20／拒绝 45，`EndProcessHandler`
     * 是 `finish()`/`reject()` 的唯一调用者）。其余档位各自的归宿——30 撤回走门面 `withdraw`
     * （先 `updateInstance` 后 fire 码 8，写后播已满足，且 §11.3 码 8 明写**不补发 2**）；
     * 40 终止、50 挂起、99 废弃在 main 源没有可达的收口点（`ProcessInstance::interrupt`／
     * `abandonTask` 生产路径零调用者，见 issues/132 事件腿报告与 08-compliance 场景 34 的
     * unreachable 记账）；将来出现写这些档位的收口点时，须在该次落库后补 fire，
     * 不得在集成层主动补发（spec §11.1）。
     *
     * 副作用（顺带收口）：修复前码 2 在 `node->execute` 里就地 fire，流转后续步骤抛异常
     * 导致事务回滚时事件已经漏出去；现在事件排在写库之后，回滚的那次不再播。
     */
    private function flushInstanceEndEvents(Execution $exec): void
    {
        $pending = $exec->drainPendingEnds();
        if ($pending === []) {
            return;
        }
        foreach ($pending as $end) {
            $instance = $end->getInstance();
            $ownInstance = $instance === null
                || ($exec->getProcessInstanceId() !== null
                    && $exec->getProcessInstanceId() === (string) $end->getInstanceId());
            if (!$ownInstance) {
                // 父实例（或更上层）被这一支流转连带办结：它的行不在本次 updateInstance 范围内，补写
                $this->repository->updateInstance($instance);
            }
            ProcessPublisher::notifyInstanceEnd($end->getInstanceId(), $end->getState());
        }
    }

    /**
     * 新任务落库**唯一收口**（发起 / 办理推进 / 串行会签每一步推进 / 跳转 四条路径都走这里）。
     *
     * 落库前先应用生效委托（issues/116 批次 D）：被委托人**并入参与者集合本身**，
     * 再由 `saveTask` 随任务一起全量写入 `wf_process_task_actor`。
     * 顺序不能反——此刻 taskId 尚未分配，走"事后 addTaskActor 补写"会打在空 id 上静默无效
     * （Java 首版正是这个病根，见 06 §4.5 条款 2 的 ⚠️）。
     * 开关 `$surrogateAutoApply`（默认开启）；未配置扩展仓储时静默跳过，不打断建单。
     */
    private function saveNewTask(Execution $exec, ProcessTask $task): void
    {
        $this->applySurrogate($exec, $task);
        $this->repository->saveTask($task);
        // PROCESS_TASK_START 在落库（分配 taskId）后 fire，见 start 内注释与 spec §11.2 原则 3
        $this->notifyTaskStart($task);
    }

    /** 应用生效委托（引擎内置、默认开启）；关闭或仓储缺席时零影响。 */
    private function applySurrogate(Execution $exec, ProcessTask $task): void
    {
        if (!$this->surrogateAutoApply) return;
        // 条款 1.1：**模型 name 优先，模型未带 name 才回落 wf_process_define.name**
        // （deploy 的 def.setName(model.getName()) 让两者正常恒等；回落不能省，
        //  空串=只命中全流程兜底，该流程自己配的委托会一条都查不到）。
        // 取值逻辑与 FlowInterceptor 挂点共用 SurrogateInterceptor::resolveProcessName，一处维护。
        $processName = SurrogateInterceptor::resolveProcessName($exec);
        $this->surrogateApplier()->apply($task, $processName);
    }

    /**
     * 解析委托应用器：显式注入 > ServiceContext 注册实例（集成方可放 NullSurrogateInterceptor
     * 走"注册空实现"式关闭）> 内置默认。每次重新解析，不缓存容器实例，
     * 以免集成方在首个任务之后才注册自己的实现。
     */
    private function surrogateApplier(): SurrogateInterceptor
    {
        if ($this->surrogateApplier !== null) return $this->surrogateApplier;
        $ctx = ServiceContext::find(SurrogateInterceptor::CONTEXT_KEY);
        if ($ctx instanceof SurrogateInterceptor) return $ctx;
        return new SurrogateInterceptor($this->extRepository);
    }

    private function handleCcActors(?string $instanceId, string $operator, mixed $ccUserIds): void
    {
        // issues/141 G10「空不创建行」（spec 06-facade.md §2.10）：发起 f_ccActors 与办理 tf_ccActors
        // 两条腿共用 CcActorUtil::normalize 这一支归一——逗号串与数组两种形态同判据，空串/纯空白/
        // 数组里的空元素一律丢弃，落库与比较值取 trim 后的串；**丢完为空 ⇒ 不建 cc 行、也不 fire 码 4**。
        // 旧形状的两处病灶都在这一条里收掉：
        //  ① 数组腿只做 strval、完全不过滤 ⇒ ['7801','','  '] 真落 3 行（含 actor_id=''／'  '）；
        //  ② 逗号串腿用 array_filter 假值判据 ⇒ '0' 这类"看起来像空"的正常 id 被吃掉（实测 0 行）。
        // 漏斗之外两仓写侧还各有一层兜底（InMemory/PdoProcessRepository::createCcInstance），
        // 直连仓储的调用方同样灌不进空值（spec §2.10 实现要求①「两层都挡」）。
        $ccArr = CcActorUtil::normalize($ccUserIds);
        if (!empty($ccArr) && $instanceId !== null) {
            // issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4）：同一 (实例, 被抄送人) 已有
            // cc 行时跳过——不新增行、不重置未读、不更新原行时间；**新建子集**才拿去 fire。
            $created = $this->repository->createCcInstanceIfAbsent($instanceId, $operator, $ccArr);
            // CC_CREATE（spec §11.3 码 4）：cc 行**落库之后**逐抄送人 fire，与 createCcInstance
            // 逐行 INSERT 的粒度对应（issue 102 表头「逐抄送人」）。三条路径（发起 f_ccActors /
            // 办理 tf_ccActors / 门面手动 createCCInstance）共用 ProcessPublisher::notifyCcCreate
            // 这一把收口（§11.7「发起与办理走同一个 notifyCcCreate」）。
            // sourceId=instanceId，ccActorId=抄送人 id（监听器直接取用免反查 cc 表）。
            // fire 在 runInTx 事务内，监听器同连接反查可见本事务写入（与 Java 同事务一致）。
            // 入参＝实际新建的子集而不是原始 ccArr（issues/141 G2）：spec §11.2 原则 1「码=事实」
            // ⇒ 重复抄送没发生"创建"就不该发这个事件；子集为空整支不 fire（不空转、也不照旧全量 fire）。
            if ($created !== []) {
                ProcessPublisher::notifyCcCreate((string) $instanceId, $created);
            }
        }
    }

    private function runInTx(callable $action): mixed
    {
        $tx = ServiceContext::find(TransactionTemplateInterface::class);
        if ($tx !== null) {
            return $tx->required($action);
        }
        return $action();
    }

    /**
     * fire「任务办结 / 任务退回」事件（spec §11.3 码 5 {@code TASK_COMPLETE} / 码 6
     * {@code TASK_REJECT}，issues/132 新增）。
     *
     * 调用点唯一：{@see self::prepareExecution()} 里 `repository->updateTask($task)` 之后——
     * 任务行的 `state` 就是在这次 updateTask 落库的（§11.2 原则 3），四个办理入口都汇过这里。
     *
     * 分派判据＝载荷 `submitType`：{@see self::REJECT_SUBMIT_TYPES} 命中发 6，其余发 5，二者互斥；
     * 缺省按 AGREE 处理（与 EndProcessHandler 读 submitType 的缺省同口径）。「跳转回退」
     * （submitType=4 且目标在上游）本栈仍归 5——引擎不为此做图回溯，监听器按载荷自判（§11.2 原则 2）。
     */
    private function notifyTaskFinished(ProcessInstance $instance, ProcessTask $task,
                                        string $operator, ?FlowData $args): void
    {
        if ($task->getTaskId() === null) {
            return; // 与 notifyTaskStart 同款守卫：无 taskId 的事实反查不到，不发
        }
        $submitType = $args?->getInt(FlowConst::SUBMIT_TYPE) ?? SubmitType::AGREE;
        $isReject = in_array($submitType, self::REJECT_SUBMIT_TYPES, true);
        ProcessPublisher::notify(ProcessEvent::of(
            $isReject ? ProcessEventTypeEnum::TASK_REJECT : ProcessEventTypeEnum::TASK_COMPLETE,
            $task->getTaskId(),
            null,
            [
                ProcessPublisher::KEY_INSTANCE_ID => $instance->getInstanceId(),
                ProcessPublisher::KEY_TASK_ID => $task->getTaskId(),
                ProcessPublisher::KEY_OPERATOR => $operator,
                ProcessPublisher::KEY_SUBMIT_TYPE => $submitType,
            ],
        ));
    }

    /**
     * fire「任务开始」事件（PROCESS_TASK_START / 新待办，spec §11.3 码 3）。
     *
     * 引擎契约（§11.2 原则 3 / Java JeeflowEngineImpl.notifyTaskStart）：事件在任务行
     * **落库之后**触发，{@code sourceId = taskId} 必须可被监听器 findTaskById 反查。
     * 故本方法只在 saveTask（分配 taskId）之后调用；{@code getTaskId()===null} 不 fire
     * （对齐 Java 的 taskId 守卫注释——handler 阶段 taskId 尚为 null，那时 fire 会因
     * 监听器 sourceId 空守卫漏发「新待办」）。
     *
     * 直传载荷键（§11.3 码 3 必备）：instanceId / taskId / actors。
     */
    private function notifyTaskStart(ProcessTask $task): void
    {
        if ($task->getTaskId() === null) {
            return;
        }
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::PROCESS_TASK_START,
            $task->getTaskId(),
            null,
            [
                ProcessPublisher::KEY_INSTANCE_ID => $task->getProcessInstanceId(),
                ProcessPublisher::KEY_TASK_ID => $task->getTaskId(),
                ProcessPublisher::KEY_ACTORS => $task->getActorIds(),
            ],
        ));
    }
}
