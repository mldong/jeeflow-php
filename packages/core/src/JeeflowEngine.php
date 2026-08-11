<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\StartModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;

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
            foreach ($exec->getProcessTaskList() as $task) {
                $this->repository->saveTask($task);
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
                // 驳回：回到上一步
                // 简化实现：直接完成当前任务不流转
            } else {
                $targetNode = $model->getNode($nodeName);
                if ($targetNode === null) {
                    throw new JeeflowException("根据节点名称[{$nodeName}]无法找到节点模型");
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
        foreach ($exec->getProcessTaskList() as $task) {
            $this->repository->saveTask($task);
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
}
