<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\StartModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 工作流引擎实现 —— 薄编排层
 *
 * 对齐 Java JeeflowEngineImpl。
 */
class JeeflowEngine implements JeeflowEngineInterface
{
    private ProcessRepositoryInterface $repository;

    public function __construct(ProcessRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function getRepository(): ProcessRepositoryInterface
    {
        return $this->repository;
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
            //    TASK_START 事件须在 saveTask 落库（分配 taskId）之后 fire——对齐
            //    spec §4.4「任务落库后逐任务 fire，监听器可按 taskId 反查」与 Java
            //    JeeflowEngineImpl start 内循环（CreateTaskHandler 阶段 taskId 尚未生成，不 fire）。
            foreach ($exec->getProcessTaskList() as $task) {
                $this->repository->saveTask($task);
                $this->notifyTaskStart($task);
            }
            $this->repository->updateInstance($instance);
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
            // 抄送
            $ccActors = $exec->getArgs()->get(FlowConst::CC_ACTORS);
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
                // 驳回：回到上一步（issues/79 对齐 Java rejectTask：上一步任务节点新建待办，
                // 参与者 = 退回操作人，实例保持 DOING）
                $newTask = $exec->getProcessInstance()->rejectTask($model, $exec->getProcessTask());
                if ($newTask !== null) $exec->addTask($newTask);
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
        // 之后逐任务 fire TASK_START（对齐 Java JeeflowEngineImpl.persistTasks）。
        foreach ($exec->getProcessTaskList() as $task) {
            $this->repository->saveTask($task);
            $this->notifyTaskStart($task);
        }
        if ($exec->getProcessTask() !== null && $exec->getProcessTask()->getTaskId() !== null) {
            $this->repository->updateTask($exec->getProcessTask());
        }
        $this->repository->updateInstance($exec->getProcessInstance());
    }

    private function handleCcActors(?string $instanceId, string $operator, mixed $ccUserIds): void
    {
        if ($ccUserIds === null) return;
        if (is_string($ccUserIds)) {
            $ccArr = array_filter(array_map('trim', explode(',', $ccUserIds)));
        } elseif (is_array($ccUserIds)) {
            $ccArr = array_map('strval', $ccUserIds);
        } else {
            return;
        }
        if (!empty($ccArr) && $instanceId !== null) {
            $this->repository->createCcInstance($instanceId, $operator, $ccArr);
            // CC_CREATE（issues/102 新增；本批次仅 PHP 引擎实现）：逐抄送人 fire，与
            // createCcInstance 逐行 INSERT 的粒度对应（issue 102 表头「逐抄送人」）。
            // sourceId=instanceId，ccActorId=抄送人 id（监听器直接取用免反查 cc 表）。
            // fire 在 runInTx 事务内，监听器同连接反查可见本事务写入（与 Java 同事务一致）。
            foreach ($ccArr as $actorId) {
                ProcessPublisher::notify(ProcessEvent::of(
                    ProcessEventTypeEnum::CC_CREATE,
                    $instanceId,
                    (string) $actorId,
                ));
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
     * fire「任务开始」事件（TASK_START / 新待办）。
     *
     * 引擎契约（spec §4.4 / Java JeeflowEngineImpl.notifyTaskStart）：事件在任务行
     * **落库之后**触发，{@code sourceId = taskId} 必须可被监听器 findTaskById 反查。
     * 故本方法只在 saveTask（分配 taskId）之后调用；{@code getTaskId()===null} 不 fire
     * （对齐 Java 的 taskId 守卫注释——handler 阶段 taskId 尚为 null，那时 fire 会因
     * 监听器 sourceId 空守卫漏发「新待办」）。
     */
    private function notifyTaskStart(ProcessTask $task): void
    {
        if ($task->getTaskId() === null) {
            return;
        }
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::TASK_START,
            $task->getTaskId(),
        ));
    }
}
