<?php

declare(strict_types=1);

namespace Jeeflow\Core\Domain;

use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;

/**
 * 流程实例 —— DDD 聚合根（充血模型）
 *
 * 对齐 Java ProcessInstance。所有状态修改通过聚合根方法完成。
 */
class ProcessInstance
{
    private ?string $instanceId = null;
    private ?string $parentId = null;
    private ?string $defineId = null;
    private int $state = ProcessInstanceState::DOING;
    private ?string $parentNodeName = null;
    private ?string $businessNo = null;
    private string $operator = '';
    private ?string $expireTime = null;
    private FlowData $variables;
    /** @var ProcessTask[] */
    private array $tasks = [];
    private ?string $createTime = null;
    private ?string $createUser = null;
    private ?string $updateTime = null;
    private ?string $updateUser = null;

    /**
     * @param array<string,mixed> $define 流程定义行（id/name/displayName/type/state/content/version）
     */
    public static function create(array $define, string $operator, ?FlowData $args = null,
                                   ?string $parentId = null, ?string $parentNodeName = null): self
    {
        $inst = new self();
        $inst->parentId = $parentId;
        $inst->parentNodeName = $parentNodeName;
        $inst->defineId = (string) ($define['id'] ?? '');
        $inst->operator = $operator;
        $inst->state = ProcessInstanceState::DOING;
        $inst->businessNo = $args?->getStr(FlowConst::BUSINESS_NO);
        $inst->variables = $args !== null ? $args->copy() : FlowData::create();
        $inst->tasks = [];
        $now = date('Y-m-d H:i:s');
        $inst->createTime = $now;
        $inst->createUser = $operator;
        $inst->updateTime = $now;
        $inst->updateUser = $operator;
        return $inst;
    }

    // ═══ 命令方法 ═══

    public function completeTask(string $taskId, string $operator, ?FlowData $args): void
    {
        $task = $this->findDoingTask($taskId);
        $task->finish($operator, $args);
        if ($args !== null) {
            $this->variables->setAll($args->toArray());
            // 提取 f_ 前缀变量持久化到流程变量
            $formData = FlowData::create();
            foreach ($args->keys() as $key) {
                if (str_starts_with($key, FlowConst::FORM_DATA_PREFIX)) {
                    $formData->set($key, $args->get($key));
                }
            }
            if (!$formData->isEmpty()) {
                $this->addVariable($formData);
            }
        }
    }

    public function abandonTask(string $taskId, string $operator): void
    {
        $task = $this->findDoingTask($taskId);
        $task->abandon($operator);
        $this->state = ProcessInstanceState::ABANDON;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function finish(): void
    {
        $this->state = ProcessInstanceState::FINISHED;
        $this->updateTime = date('Y-m-d H:i:s');
    }

    public function reject(): void
    {
        $this->state = ProcessInstanceState::REJECTED;
        $this->updateTime = date('Y-m-d H:i:s');
    }

    public function interrupt(string $operator): void
    {
        foreach ($this->tasks as $task) {
            $task->interrupt($operator);
        }
        $this->state = ProcessInstanceState::INTERRUPT;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function withdraw(string $operator): void
    {
        foreach ($this->tasks as $task) {
            if ($task->isDoing()) {
                $task->withdraw();
            }
        }
        $this->state = ProcessInstanceState::WITHDRAW;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function addVariable(FlowData $args): void
    {
        $this->variables->setAll($args->toArray());
        $this->updateTime = date('Y-m-d H:i:s');
    }

    /**
     * 创建普通任务
     * @param string[] $actorIds
     */
    public function createTask(string $taskName, string $displayName, ?int $taskType,
                                ?int $performType, ?string $formKey, array $actorIds, string $operator): ProcessTask
    {
        $task = ProcessTask::create(
            $this->instanceId, $taskName, $displayName,
            $taskType, $performType, $formKey, $actorIds, $operator
        );
        $this->tasks[] = $task;
        return $task;
    }

    /**
     * 创建会签任务（每个 actor 一个独立 task）
     *
     * 串行会签（issues/93）：仅创建第一位成员任务，并把会签计数状态写入该任务变量
     * （operatorList_{node} 全量办理人 / loopCounter_{node} 当前序号 / nrOfInstances_{node} 总数），
     * 由 CountersignHandler 在每位成员完成时推进创建下一位——任意时刻仅 1 个 DOING 会签任务，
     * 对齐 mldong 内置引擎 createCountersignTask 与 Java/Go/Python/Node 的串行逐个创建。
     * PARALLEL / 未配置类型保持全员预创建。
     *
     * @param string[] $actorIds
     * @return ProcessTask[]
     */
    public function createCountersignTasks(string $taskName, string $displayName, ?int $taskType,
                                            ?int $performType, ?string $formKey, array $actorIds,
                                            string $operator, ?int $countersignType = null): array
    {
        // 串行会签逐个创建（issues/93）：仅建首位 + 记录任务变量
        if ($countersignType === CountersignType::SERIAL) {
            $first = ProcessTask::create(
                $this->instanceId, $taskName, $displayName,
                $taskType, $performType, $formKey, [$actorIds[0]], $operator
            );
            $first->getVariables()->set(FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $taskName, $actorIds);
            $first->getVariables()->set(FlowConst::LOOP_COUNTER . '_' . $taskName, 0);
            $first->getVariables()->set(FlowConst::NR_OF_INSTANCES . '_' . $taskName, count($actorIds));
            $this->tasks[] = $first;
            return [$first];
        }
        $list = [];
        foreach ($actorIds as $actorId) {
            $task = ProcessTask::create(
                $this->instanceId, $taskName, $displayName,
                $taskType, $performType, $formKey, [$actorId], $operator
            );
            $this->tasks[] = $task;
            $list[] = $task;
        }
        return $list;
    }

    /**
     * 驳回任务（退回上一步）—— 对齐 Java ProcessInstance.rejectTask
     *
     * 找到上一个任务节点并为其创建新待办任务：
     * 参与者 = 当前任务完成人（退回操作人，finish 后 actorId 即为操作人），
     * createUser 沿用当前任务的 createUser。无上一任务节点时返回 null（不流转）。
     *
     * @return ProcessTask|null 新建的上一步任务
     */
    public function rejectTask(ProcessModel $model, ProcessTask $currentTask): ?ProcessTask
    {
        $previousTaskName = $this->getPreviousTaskName($model, $currentTask->getTaskName());
        if ($previousTaskName !== null) {
            $prevNode = $model->getNode($previousTaskName);
            if ($prevNode instanceof TaskModel) {
                $newTask = $this->createTask(
                    $prevNode->getName(),
                    $prevNode->getDisplayName(),
                    $prevNode->getTaskType(),
                    $prevNode->getPerformType(),
                    $prevNode->getForm() ?: null,
                    $currentTask->getActorId() !== null ? [$currentTask->getActorId()] : [],
                    $currentTask->getCreateUser() ?? ''
                );
                return $newTask;
            }
        }
        return null;
    }

    /**
     * 上一个任务节点名（简单实现：找当前节点首条输入边的 source 节点）—— 对齐 Java getPreviousTaskName
     */
    private function getPreviousTaskName(ProcessModel $model, string $currentTaskName): ?string
    {
        $node = $model->getNode($currentTaskName);
        if ($node !== null && !empty($node->getInputs())) {
            $source = $node->getInputs()[0]->getSource();
            return $source?->getName();
        }
        return null;
    }

    // ═══ 查询方法 ═══

    /** @return ProcessTask[] */
    public function getDoingTasks(): array
    {
        return array_values(array_filter($this->tasks, fn(ProcessTask $t) => $t->isDoing()));
    }

    /** @return ProcessTask[] */
    public function getFinishedTasks(): array
    {
        return array_values(array_filter($this->tasks, fn(ProcessTask $t) => $t->isFinished()));
    }

    public function isAllTasksFinished(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->isDoing()) return false;
        }
        return true;
    }

    public function isDoing(): bool
    {
        return $this->state === ProcessInstanceState::DOING;
    }

    public function isFinished(): bool
    {
        return $this->state === ProcessInstanceState::FINISHED;
    }

    private function findDoingTask(string $taskId): ProcessTask
    {
        foreach ($this->tasks as $task) {
            if ($task->getTaskId() === $taskId) {
                if (!$task->isDoing()) {
                    throw new \RuntimeException("任务[{$taskId}]不是进行中状态");
                }
                return $task;
            }
        }
        throw new \RuntimeException("未找到任务[{$taskId}]或不在聚合根中");
    }

    // ═══ Getters/Setters ═══

    public function getInstanceId(): ?string { return $this->instanceId; }
    public function setInstanceId(?string $v): void { $this->instanceId = $v; }
    public function getParentId(): ?string { return $this->parentId; }
    public function setParentId(?string $v): void { $this->parentId = $v; }
    public function getDefineId(): ?string { return $this->defineId; }
    public function setDefineId(?string $v): void { $this->defineId = $v; }
    public function getState(): int { return $this->state; }
    public function setState(int $v): void { $this->state = $v; }
    public function getParentNodeName(): ?string { return $this->parentNodeName; }
    public function setParentNodeName(?string $v): void { $this->parentNodeName = $v; }
    public function getBusinessNo(): ?string { return $this->businessNo; }
    public function setBusinessNo(?string $v): void { $this->businessNo = $v; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $v): void { $this->operator = $v; }
    public function getExpireTime(): ?string { return $this->expireTime; }
    public function setExpireTime(?string $v): void { $this->expireTime = $v; }
    public function getVariables(): FlowData { return $this->variables; }
    public function setVariables(FlowData $v): void { $this->variables = $v; }
    /** @return ProcessTask[] */
    public function getTasks(): array { return $this->tasks; }
    /** @param ProcessTask[] $v */
    public function setTasks(array $v): void { $this->tasks = $v; }
    public function getCreateTime(): ?string { return $this->createTime; }
    public function setCreateTime(?string $v): void { $this->createTime = $v; }
    public function getCreateUser(): ?string { return $this->createUser; }
    public function setCreateUser(?string $v): void { $this->createUser = $v; }
    public function getUpdateTime(): ?string { return $this->updateTime; }
    public function setUpdateTime(?string $v): void { $this->updateTime = $v; }
    public function getUpdateUser(): ?string { return $this->updateUser; }
    public function setUpdateUser(?string $v): void { $this->updateUser = $v; }
}
