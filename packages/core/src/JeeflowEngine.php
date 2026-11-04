<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Handler\CreateTaskHandler;
use Jeeflow\Core\Support\AddSignSupport;
use Jeeflow\Core\Support\RecallSupport;
use Jeeflow\Core\Support\ReapplySupport;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
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
        return $this->inTx(function () use ($defineId, $operator, $args, $parentId, $parentNodeName) {
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
            // 4. 计算到期时间（issues/137 A · 批二 §3-4：实例列＝**定义级表达式的求值结果**）
            //    基准＝java `JeeflowEngineImpl.java:93-96`（＝boot2 `ProcessInstanceServiceImpl.java:157-160`
            //    的"先判非空再写"形状），求值器复用本栈 issues/126 那一枚 {@see FlowUtil::processTime}，
            //    **不新造第二把尺子**。变量源＝**发起参数**（上面刚注入完用户信息与 autoGenTitle 的那份
            //    `$args`），与节点级表达式用的实例变量是两档。
            //    三条口径边界，逐条与 java 同形：
            //     - 定义没配顶层 expireTime ⇒ **不进** if ⇒ 这一列保持 create() 时的 NULL（不赋 now、不赋 ''）；
            //     - 配了但算不出（误配／负数档）⇒ processTime 返回 null ⇒ 写 NULL，**不兜底 now**
            //       （§3-2/§3-3 已定的落穿语义；兜底 now 等于静默造一个"发起即逾期"的实例）；
            //     - 绝不能像改前那样把**原串**搬进这一列：它在库上是 DATETIME，160 那台 MySQL 实测
            //       `@@sql_mode` 含 `STRICT_TRANS_TABLES` ⇒ `"2h"` 进列是硬错 1292，配了到期表达式的流程
            //       在 php 栈根本发起不了（真库读数见 MysqlSmokeTest::testM8/M9）。
            $expireTime = $model->getExpireTime();
            if ($expireTime !== '') {
                $instance->setExpireTime(FlowUtil::processTime($expireTime, $args));
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
            // 记录类（snaker:custom）历史行：**只落库、不发码 3、不过委托**，见 persistHistoryTasks
            $this->persistHistoryTasks($exec);
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
        return $this->inTx(function () use ($taskId, $operator, $args) {
            $args = ReapplySupport::stripReserved($args);
            $pre = $this->repository->findTaskById($taskId);
            if ($pre !== null && $pre->isDoing() && ReapplySupport::reapplyOf($pre->getVariables()) !== null) {
                return $this->executeReapply($pre, $operator, $args);
            }
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
        return $this->inTx(function () use ($taskId, $operator, $args, $nodeName) {
            $pre = $this->repository->findTaskById($taskId);
            if ($pre !== null && $pre->isDoing()
                && ReapplySupport::reapplyOf($pre->getVariables()) !== null) {
                throw new JeeflowException("带重提直返标记的申请待办不允许跳转，只接受重新提交(submitType=5)");
            }
            if ($nodeName === null || $nodeName === '') {
                // issues/243 甲案：血缘守卫排在 prepareExecution 之前——prepareExecution 内部
                // completeTask + updateTask 会把当前行办结，内存仓储无回滚，守卫后置 ⇒
                // 报错那一次结束后"待办凭空消失、又没有复活行"。判据本体只在
                // ProcessInstance::checkRejectable 一份，复活腿（rejectTask 第一行）复用它。
                $this->checkRollbackPossible($pre);
            } else {
                // issues/243 §8-2：命名跳转的「找不到节点模型」同一族缺口，同规矩前置——
                // 从前这句在 prepareExecution（内部 completeTask + updateTask 已把当前行办结）之后
                // 才抛，内存仓储无回滚 ⇒ 节点名写错一次就把办理人的待办凭空弄丢
                //（真库有动作级事务整笔回滚，观察不到差别 ⇒ 这一档只有内存仓储照得出来）。
                $this->checkJumpTargetExists($pre, $nodeName);
            }
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            if ($nodeName === null || $nodeName === '') {
                // issues/121 P2：驳回走血缘版——上一步来源＝当前行的 task_parent_id，
                // 由仓储把那条历史行取出来交给聚合根复活（取不到传 null，聚合根报 20010007）。
                $current = $exec->getProcessTask();
                $exec->addTask($exec->getProcessInstance()->rejectTask($model, $current, $this->lineageHistory($current)));
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
        return $this->inTx(function () use ($taskId, $operator, $args) {
            $pre = $this->repository->findTaskById($taskId);
            if ($pre !== null && $pre->isDoing()
                && ReapplySupport::reapplyOf($pre->getVariables()) !== null) {
                throw new JeeflowException("带重提直返标记的申请待办不允许拒绝，只接受重新提交(submitType=5)");
            }
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            // 拒绝结束：本节点未完成的加签任务一并废弃，不留孤儿待办（spec 04 · 后加签）
            AddSignSupport::abandonDoingOnNode($model, $exec->getProcessInstance(),
                (string) $exec->getProcessTask()?->getTaskName(), $operator);
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
        return $this->inTx(function () use ($taskId, $operator, $args) {
            $args = ReapplySupport::stripReserved($args);
            // 校验放在任何状态变更之前：来源节点配了 RETURN_TO_SOURCE 时，先读模型判准入
            $pre = $this->repository->findTaskById($taskId);
            if ($pre !== null && $pre->isDoing()
                && ReapplySupport::reapplyOf($pre->getVariables()) !== null) {
                throw new JeeflowException("带重提直返标记的申请待办不允许再次退回，只接受重新提交(submitType=5)");
            }
            $sourceModel = null;
            $direct = false;
            if ($pre !== null && $pre->isDoing()) {
                $preInst = $this->repository->findInstanceById($pre->getProcessInstanceId());
                $preDef = $preInst !== null ? $this->repository->findDefineById($preInst->getDefineId()) : null;
                if ($preDef !== null) {
                    $preModel = ModelParser::parse((string) $preDef['content']);
                    $node = $preModel->getNode($pre->getTaskName());
                    if ($node instanceof TaskModel) {
                        $sourceModel = $node;
                        $direct = ReapplySupport::parseMode($node->getReapplyMode(), $node->getName()) === ReapplySupport::RETURN_TO_SOURCE;
                    }
                }
            }
            $exec = $this->prepareExecution($taskId, $operator, $args);
            if ($exec === null) return [];
            $model = $exec->getProcessModel();
            // 退回发起人：本节点未完成的加签任务一并废弃，不留孤儿待办（spec 04 · 后加签）
            AddSignSupport::abandonDoingOnNode($model, $exec->getProcessInstance(),
                (string) $exec->getProcessTask()?->getTaskName(), $operator);
            if ($direct) {
                if ($sourceModel->getPerformType() === \Jeeflow\Core\Enum\PerformType::COUNTERSIGN) {
                    throw new JeeflowException("会签节点[{$sourceModel->getName()}]不支持退回修改直返");
                }
                if (FlowUtil::isFirstTaskName($model, $sourceModel->getName())) {
                    throw new JeeflowException("申请节点[{$sourceModel->getName()}]不能配置重提直返");
                }
                $applyModel = null;
                $start = $model->getStart();
                if ($start !== null) {
                    foreach ($start->getOutputs() as $tm) {
                        if ($tm->getTarget() instanceof TaskModel
                                && FlowUtil::isFirstTaskName($model, $tm->getTarget()->getName())) {
                            $applyModel = $tm->getTarget();
                            break;
                        }
                    }
                }
                if ($applyModel === null) {
                    throw new JeeflowException('流程定义缺少申请节点，无法退回发起人');
                }
                $applyModel->setAssignee($exec->getProcessInstance()->getOperator());
                $before = count($exec->getProcessTaskList());
                (new CreateTaskHandler($applyModel))->handle($exec);
                $created = array_slice($exec->getProcessTaskList(), $before);
                $rc = ReapplySupport::newContext((string) $pre->getTaskId(), $sourceModel->getName(),
                    ReapplySupport::scopesOf($pre->getVariables()));
                foreach ($created as $nt) {
                    $nt->getVariables()->set(ReapplySupport::VAR_REAPPLY_CTX, $rc);
                }
                $this->persistTasks($exec);
                return $exec->getProcessTaskList();
            }
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

    /**
     * submitType=5 且申请待办带直返上下文：校验来源后在来源节点直接建新待办，
     * 不执行申请节点出边、不重算来源前条件、不执行来源节点出边。
     */
    private function executeReapply(ProcessTask $applyTask, string $operator, ?FlowData $args): array
    {
        $st = $args?->get(FlowConst::SUBMIT_TYPE);
        if ($st !== null && (int) $st !== 5) {
            throw new JeeflowException('带重提直返标记的申请待办只接受重新提交(submitType=5)');
        }
        $instance = $this->repository->findInstanceById($applyTask->getProcessInstanceId());
        if ($instance === null) throw new JeeflowException('流程实例不存在');
        $define = $this->repository->findDefineById($instance->getDefineId());
        if ($define === null) throw new JeeflowException('流程定义不存在');
        $model = ModelParser::parse((string) $define['content']);
        $rc = ReapplySupport::reapplyOf($applyTask->getVariables());
        $sourceId = (string) ($rc['sourceTaskId'] ?? '');
        $source = $sourceId !== '' ? $this->repository->findTaskById($sourceId) : null;
        if ($source === null || $source->getTaskState() !== ProcessTaskState::FINISHED
                || $source->getProcessInstanceId() !== $applyTask->getProcessInstanceId()) {
            throw new JeeflowException('重提直返来源任务无效或未完成');
        }
        $sourceNode = $model->getNode($source->getTaskName());
        if (!$sourceNode instanceof TaskModel
                || ReapplySupport::parseMode($sourceNode->getReapplyMode(), $sourceNode->getName()) !== ReapplySupport::RETURN_TO_SOURCE) {
            throw new JeeflowException('重提直返来源节点配置已变更，拒绝执行');
        }
        if (!FlowUtil::isFirstTaskName($model, $applyTask->getTaskName())) {
            throw new JeeflowException('重提直返必须从申请待办发起');
        }
        $exec = $this->prepareExecution($applyTask->getTaskId(), $operator, $args);
        if ($exec === null) return [];
        // 遗留的下一节点指定人参数不得影响本次建单（历史记录不删）
        $exec->getArgs()->remove(FlowConst::NEXT_NODE_OPERATOR);
        $before = count($exec->getProcessTaskList());
        (new CreateTaskHandler($sourceNode))->handle($exec);
        $created = array_slice($exec->getProcessTaskList(), $before);
        $branch = ReapplySupport::branchOf($rc);
        foreach ($created as $nt) {
            $nt->getVariables()->set(ReapplySupport::VAR_BRANCH_CTX, $branch);
        }
        $this->persistTasks($exec);
        return $exec->getProcessTaskList();
    }

    // ═══ 内部方法 ═══

    /**
     * 当前行的血缘前驱那条历史行（issues/121 P2 的取行腿）：parent 为空 / '' / '0' ⇒ null
     * （P1 之前落的老行同样落在这一档），否则按 id 从仓储取。引擎的两处调用点
     * （{@see self::checkRollbackPossible()} 前置守卫、{@see self::executeAndJumpTask()} 复活腿）
     * 共用这一份取法，判据本体在 {@see ProcessInstance::checkRejectable()}。
     */
    private function lineageHistory(?ProcessTask $current): ?ProcessTask
    {
        $parentId = $current === null ? null : $current->getParentTaskId();
        if ($parentId === null || $parentId === '' || $parentId === '0') return null;
        $history = $this->repository->findTaskById($parentId);
        return $history instanceof ProcessTask ? $history : null;
    }

    /**
     * 退回上一步（{@code nodeName} 空 ⇒ 血缘腿）的写前置守卫（issues/243 甲案）。
     *
     * <p>只做一件事：把 {@see ProcessInstance::checkRejectable()} 的两道判据（20010007 无血缘／
     * 20010008 canRejected 不过）提到任何状态变更之前——本方法是**纯读**（task→instance→define→
     * parse model→parent history），不写任务行、不写实例、不 fire 事件、不建历史行。</p>
     *
     * <p><b>不</b>在这里报"任务不存在/不在进行中""无权办理"，也不在这里发明第二套文案——那几档留给
     * {@see self::prepareExecution()} 的既有固定错（它们本来也排在 completeTask 之前，天然零写入）；
     * 取不到实例/定义时同样一律放行，由 prepareExecution 返回 null 走原形状。</p>
     *
     * <p>与 java 参考实现的一处结构差异：本栈的血缘前驱由引擎从**仓储**取（java 在聚合根内
     * 沿 instance.tasks 找），故这里先取行再把它交给判据。</p>
     */
    private function checkRollbackPossible(?ProcessTask $current): void
    {
        if ($current === null || !$current->isDoing()) return;
        $instance = $this->repository->findInstanceById((string) $current->getProcessInstanceId());
        if (!$instance instanceof ProcessInstance) return;
        $define = $this->repository->findDefineById((string) $instance->getDefineId());
        if ($define === null) return;
        $model = ModelParser::parse((string) $define['content']);
        $instance->checkRejectable($model, $current, $this->lineageHistory($current));
    }

    /**
     * 命名跳转（{@code submitType=4}，{@code $nodeName} 非空那一支）的节点存在性前置守卫
     * （issues/243 §8-2）。
     *
     * <p>与 {@see self::checkRollbackPossible()} 同族同规矩：从前"根据节点名称[X]无法找到节点模型"
     * 是在 {@see self::prepareExecution()}（内部 completeTask + updateTask 已把当前行办结）之后才抛的，
     * 内存仓储无回滚 ⇒ 名字写错一次就把办理人的待办凭空弄丢。真库有动作级事务整笔回滚，观察不到差别，
     * 所以这一档同样只有内存仓储照得出来。</p>
     *
     * <p>纯读；取不到任务/不在进行中/取不到实例或定义一律放行，让 {@see self::prepareExecution()}
     * 报它自己原有的固定错（那些档本就在办结之前）。不新增第二种文案——抛的还是原来那一句。</p>
     */
    private function checkJumpTargetExists(?ProcessTask $current, string $nodeName): void
    {
        if ($current === null || !$current->isDoing()) return;
        $instance = $this->repository->findInstanceById((string) $current->getProcessInstanceId());
        if (!$instance instanceof ProcessInstance) return;
        $define = $this->repository->findDefineById((string) $instance->getDefineId());
        if ($define === null) return;
        if (ModelParser::parse((string) $define['content'])->getNode($nodeName) === null) {
            throw new JeeflowException("根据节点名称[{$nodeName}]无法找到节点模型");
        }
    }

    /**
     * 已办撤回（issues/246，规范 04 · 已办撤回）：办理人撤回自己已办结的那一手，同实例内复活重办。
     *
     * **判据全部排在任何状态变更之前**（报错即零写入——与后加签写入口 / 退回守卫同一规矩；
     * 内存仓储无回滚，先废弃再判错就会留下不可恢复的半撤状态）。位序逐字照 java：
     * 行存在且已办结 → 参与者/特权 → 实例 DOING → 节点开关 → 窗口（下游无任何 FINISHED）。
     *
     * ⚠️ 参与人判断**不走 {@see ProcessTask::isAllowed()}**：那条只认 DOING 行，
     * 而目标行正是 FINISHED——按名册（`actorIds`）判断，`flow.auto`/`flow.admin` 特权放行（与转办同口径）。
     *
     * 落库顺序：废弃行 updateTask → 复活行 saveTask（拿 taskId，全量写参与者行）＋码 3
     * → 原行盖 `tf_recallHistory`（recalledTaskId 指向复活行）updateTask → 实例 updateTime
     * → fire 码 10 TASK_RECALL（sourceId＝被撤回的旧行；**只发 10，不补发 5/6**）。
     *
     * 与 java 相同的**不扩面**：复活行走裸 `saveTask`＋`notifyTaskStart`，**不**过
     * {@see self::saveNewTask()} ⇒ 不并委托、不回填 `_branchCtx.root`（go 那腿过了 saveNewTask，
     * 差异记录在案，以参考实现为准）。撤回**不回滚业务数据**：f_ 已进实例变量、persist 已写业务表。
     *
     * @return ProcessTask 复活的新行（DOING，本实例本节点唯一活待办）
     */
    public function recallTask(string $taskId, string $operator): ProcessTask
    {
        return $this->inTx(function () use ($taskId, $operator): ProcessTask {
            // ── 判据 1：行存在且已办结 ──
            $target = $this->repository->findTaskById($taskId);
            if (!$target instanceof ProcessTask || !$target->isFinished()) {
                throw new JeeflowException(RecallSupport::MSG_NOT_FINISHED);
            }
            // ── 判据 2：参与者/特权（FINISHED 行按名册判断，不走只认 DOING 的 isAllowed）──
            $privileged = strcasecmp($operator, FlowConst::AUTO_ID) === 0
                || strcasecmp($operator, FlowConst::ADMIN_ID) === 0;
            if (!$privileged && !in_array($operator, $target->getActorIds(), true)) {
                throw new JeeflowException(RecallSupport::MSG_NO_PERMISSION);
            }
            // ── 判据 3：实例存在且 DOING ──
            $instance = $this->repository->findInstanceById((string) $target->getProcessInstanceId());
            if (!$instance instanceof ProcessInstance) {
                throw new JeeflowException('流程实例不存在');
            }
            if ($instance->getState() !== ProcessInstanceState::DOING) {
                throw new JeeflowException(RecallSupport::MSG_INSTANCE_NOT_DOING);
            }
            // ── 判据 4：节点开关 ──
            $define = $this->repository->findDefineById((string) $instance->getDefineId());
            if ($define === null) {
                throw new JeeflowException('流程定义不存在');
            }
            $model = ModelParser::parse((string) $define['content']);
            $node = $model->getNode($target->getTaskName());
            if (!$node instanceof TaskModel || !$node->isAllowRecall()) {
                throw new JeeflowException(sprintf(RecallSupport::MSG_SWITCH_OFF, $target->getTaskName()));
            }
            // ── 判据 5：窗口（下游任何 FINISHED ⇒ 拦；行集合＝聚合根里的全部任务行）──
            $doingDescendants = RecallSupport::validateWindow($instance->getTasks(), $target);

            // ══ 判据全过，以下才允许写 ══
            $isFirstTaskNode = FlowUtil::isFirstTaskName($model, $target->getTaskName());
            $resurrected = $instance->recallTask($node, $target, $operator, $doingDescendants, $isFirstTaskNode);
            foreach ($doingDescendants as $t) {
                $this->repository->updateTask($t);
            }
            $this->repository->saveTask($resurrected);
            // 复活行自己的待办另走码 3（规范 04 · 已办撤回；java 14674d7 补的这一腿——
            // 首版只 saveTask 漏 notifyTaskStart，go/python 均按 spec 走码 3，参考实现就此对齐）
            $this->notifyTaskStart($resurrected);
            // 账本排在 saveTask 之后：recalledTaskId 要指向复活行的 taskId；随后必须补一次
            // updateTask，PDO 仓存的是快照，不补这一刀账本就丢。
            RecallSupport::appendRecallHistory($target, $operator, $resurrected->getTaskId());
            $this->repository->updateTask($target);
            $this->repository->updateInstance($instance);
            // 码 10 TASK_RECALL：撤回事实落库之后 fire 一次（sourceId＝被撤回的旧行）
            ProcessPublisher::notify(ProcessEvent::of(
                ProcessEventTypeEnum::TASK_RECALL,
                $target->getTaskId(),
                null,
                [
                    ProcessPublisher::KEY_INSTANCE_ID => $instance->getInstanceId(),
                    ProcessPublisher::KEY_TASK_ID => $target->getTaskId(),
                    ProcessPublisher::KEY_OPERATOR => $operator,
                ],
            ));
            return $resurrected;
        });
    }

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

        // 后加签写入口校验（spec 04 · 后加签）：**必须排在 completeTask 之前**——内存仓储无回滚，
        // 报错时不得留下已完成的任务行（"报错且零写入"是这条链的硬要求）。
        // 四条判据（与 tf_nextNodeOperator 互斥／未开 allowAddSign 却传键／加给自己／超上限）
        // 的判据体只有 AddSignSupport 一份，本类只有一个调用点。
        AddSignSupport::validateSubmission($model, $task, $args, $operator);

        // 拦截器名可解析性前置校验（issues/244-①）：**同样必须排在 completeTask 之前**——
        // 与上面后加签四判据同一条规矩（内存仓储无回滚，报错即零写入）。
        // 从前"拦截器未注册"只在下游 `$node->execute()` 的 runInterceptors 才抛（此刻本行已
        // completeTask + updateTask 落库、事件也已 fire）⇒ 报错那一次当前待办被静默办结、
        // 新任务没建，实例 DOING 却零待办。判据本体只有 NodeModel 一份（纯读、不执行拦截器；
        // 为什么不是"再跑一次拦截器"见该方法注释）。执行期行为不动：拦截器仍在节点体后照常跑。
        $currentNode = $model->getNode($task->getTaskName());
        if ($currentNode !== null) {
            $currentNode->assertInterceptorsResolvable($model);
        }

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
        // 加签人取"本次请求"的值显式带上，不让处理器读合并后的 args（判据与病因见
        // Execution::$addSignOperators 的注释——实例变量会把上一轮的 tf_ 键复活）。
        $exec->setAddSignOperators(AddSignSupport::operators($args));
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
        // 分支根任务建单时 taskId 尚未生成（由仓储 saveTask 分配）⇒ 落库后回填 _branchCtx.root
        foreach ($exec->getProcessTaskList() as $task) {
            $scopes = ReapplySupport::scopesOf($task->getVariables());
            $changed = false;
            if ($task->getTaskId() !== null) {
                foreach ($scopes as $i => $sc) {
                    if (($sc['root'] ?? '') === '') { $scopes[$i]['root'] = (string) $task->getTaskId(); $changed = true; }
                }
            }
            if ($changed) {
                $task->getVariables()->set(ReapplySupport::VAR_BRANCH_CTX, $scopes);
                $this->repository->updateTask($task);
            }
        }
        // 记录类（snaker:custom）历史行落库（排在 updateInstance 之前——那边的级联只 UPDATE
        // 已存在的行，先 INSERT 才不会把 DONE 行漏成"内存里有、库里没有"）
        $this->persistHistoryTasks($exec);
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

    /**
     * 记录类节点（`snaker:custom`）历史行的**落库收口**（issues/142 A 批 ·
     * spec 02-flow-definition.md §6.2 第 1 条，owner 2026-09-30 拍）：
     *
     * 「落历史行」＝ `wf_process_task` 里**查得到**那一行（`task_state=20`），
     * 只在聚合内存对象里 append 一条不算做到——基准侧 java/c# 正犯着这一条
     * （`CustomModel.exec` 丢弃 `createHistoryTask` 的返回值只进 `instance.tasks`，
     * 而 `updateInstance` 的级联只 UPDATE `taskId != null` 的行 ⇒ 那一行永远进不了库）。
     * 本栈因此**不**复用 java 的落库姿势，改走仓储 `saveTask` 这条真 INSERT 腿
     * （内存仓与 PDO 仓两条 INSERT 通道都由各自的 `saveTask` 兑现）。
     *
     * 与待办腿的三点分界（判据见 {@see Execution::$historyTaskList} 注释）：
     * - **不 fire PROCESS_TASK_START（码 3）**：码 3 表达"新待办产生"，记录类节点不该有待办；
     * - **不过委托代理**（`applySurrogate`）：委托改写的是"谁能办这一单"，DONE 行无人可办；
     * - **不混进 `executeProcessTask` 的返回值**：那串是"本次新产生的待办"，
     *   门面/集成方按它算待办数，历史记录行进去就会凭空多一条待办（§6.1 硬结论 2）。
     *
     * 覆盖两条漏斗：发起路径与 `persistTasks`（办理／跳转／退结束／退发起人四个入口都汇这里）。
     */
    private function persistHistoryTasks(Execution $exec): void
    {
        foreach ($exec->getHistoryTaskList() as $task) {
            $this->repository->saveTask($task);
        }
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
            // issues/191：fire 在 inTx 事务内"发起"，但有模板 afterCommit 腿时派发已挪到 COMMIT 之后
            // （spec11 §11.3.1 派发时序＝提交之后）；无该能力的模板回落事务内即时播（与修前一致）。
            // 入参＝实际新建的子集而不是原始 ccArr（issues/141 G2）：spec §11.2 原则 1「码=事实」
            // ⇒ 重复抄送没发生"创建"就不该发这个事件；子集为空整支不 fire（不空转、也不照旧全量 fire）。
            if ($created !== []) {
                ProcessPublisher::notifyCcCreate((string) $instanceId, $created);
            }
        }
    }

    /**
     * 事务包裹（audit8 R9 145-7，spec/12 §12.2 原子面）——引擎五入口与门面整次 dispatch 都过这里。
     *
     * - 无模板注册：裸跑＝§12.1 条 4 显式降级（与修前一致）；
     * - 模板实现 `inTransaction()` 且当前已在外层事务中：**join 支**并入最外层——
     *   不二次 begin、不提前提交，提交/回滚归最外层 owner（嵌套复用，零-4 档）；
     * - 未实现 `inTransaction()` 探测的模板：回落 `required()`（宿主模板自管嵌套，
     *   如 Laravel `DB::transaction` 天然按事务层级计数，二次调用不会重复 begin）。
     */
    public function inTx(callable $action): mixed
    {
        $tx = ServiceContext::find(TransactionTemplateInterface::class);
        if ($tx === null) {
            return $action();
        }
        if (method_exists($tx, 'inTransaction') && $tx->inTransaction()) {
            return $action();
        }
        return $tx->required($action);
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
