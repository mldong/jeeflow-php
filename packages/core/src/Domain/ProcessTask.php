<?php

declare(strict_types=1);

namespace Jeeflow\Core\Domain;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;

/**
 * 流程任务 —— 聚合根 ProcessInstance 的子实体（充血模型）
 *
 * 对齐 Java ProcessTask。任务自己知道如何完成、废弃、判断权限。
 */
class ProcessTask
{
    private ?string $taskId = null;
    private ?string $processInstanceId = null;
    private string $taskName = '';
    private string $displayName = '';
    private ?int $taskType = null;
    private ?int $performType = null;
    private int $taskState = ProcessTaskState::DOING;
    private ?string $actorId = null;
    /** @var string[] */
    private array $actorIds = [];
    private ?string $finishTime = null;
    private ?string $expireTime = null;
    private ?string $formKey = null;
    private ?string $parentTaskId = null;
    private FlowData $variables;
    private ?string $createTime = null;
    private ?string $createUser = null;
    private ?string $updateTime = null;
    private ?string $updateUser = null;

    /**
     * @param string[] $actorIds
     */
    public static function create(
        ?string $instanceId,
        string $taskName,
        string $displayName,
        ?int $taskType,
        ?int $performType,
        ?string $formKey,
        array $actorIds,
        string $operator,
    ): self {
        $task = new self();
        $task->processInstanceId = $instanceId;
        $task->taskName = $taskName;
        $task->displayName = $displayName;
        $task->taskType = $taskType;
        $task->performType = $performType;
        $task->taskState = ProcessTaskState::DOING;
        $task->formKey = $formKey;
        $task->actorIds = $actorIds;
        $task->variables = FlowData::create();
        $now = date('Y-m-d H:i:s');
        $task->createTime = $now;
        $task->createUser = $operator;
        $task->updateTime = $now;
        $task->updateUser = $operator;
        return $task;
    }

    // ═══ 命令方法 ═══

    public function finish(string $operator, ?FlowData $args): void
    {
        if ($this->taskState !== ProcessTaskState::DOING) {
            throw new \RuntimeException("任务[{$this->taskName}]不是进行中状态，无法完成");
        }
        if (!$this->isAllowed($operator)) {
            throw new \RuntimeException("操作人[{$operator}]不在任务参与者列表中");
        }
        $this->taskState = ProcessTaskState::FINISHED;
        $this->actorId = $operator;
        if ($args !== null) {
            $this->variables->setAll($args->toArray());
        }
        $now = date('Y-m-d H:i:s');
        $this->finishTime = $now;
        $this->updateTime = $now;
        $this->updateUser = $operator;
    }

    public function abandon(string $operator): void
    {
        if ($this->taskState !== ProcessTaskState::DOING) {
            throw new \RuntimeException("任务[{$this->taskName}]不是进行中状态，无法废弃");
        }
        $this->taskState = ProcessTaskState::ABANDON;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function withdraw(): void
    {
        $this->taskState = ProcessTaskState::WITHDRAW;
    }

    public function interrupt(string $operator): void
    {
        if ($this->taskState === ProcessTaskState::DOING) {
            $this->taskState = ProcessTaskState::INTERRUPT;
            $this->updateTime = date('Y-m-d H:i:s');
            $this->updateUser = $operator;
        }
    }

    public function pending(string $operator): void
    {
        if ($this->taskState === ProcessTaskState::DOING) {
            $this->taskState = ProcessTaskState::PENDING;
            $this->updateTime = date('Y-m-d H:i:s');
            $this->updateUser = $operator;
        }
    }

    public function resume(string $operator): void
    {
        if ($this->taskState === ProcessTaskState::INTERRUPT) {
            $this->taskState = ProcessTaskState::DOING;
            $this->updateTime = date('Y-m-d H:i:s');
            $this->updateUser = $operator;
        }
    }

    // ═══ 查询方法 ═══

    public function isDoing(): bool
    {
        return $this->taskState === ProcessTaskState::DOING;
    }

    public function isFinished(): bool
    {
        return $this->taskState === ProcessTaskState::FINISHED;
    }

    public function isAllowed(string $operator): bool
    {
        if (strcasecmp($operator, FlowConst::AUTO_ID) === 0 || strcasecmp($operator, FlowConst::ADMIN_ID) === 0) {
            return true;
        }
        return $this->isDoing() && in_array($operator, $this->actorIds, true);
    }

    // ═══ Getters/Setters ═══

    public function getTaskId(): ?string { return $this->taskId; }
    public function setTaskId(?string $v): void { $this->taskId = $v; }
    public function getProcessInstanceId(): ?string { return $this->processInstanceId; }
    public function setProcessInstanceId(?string $v): void { $this->processInstanceId = $v; }
    public function getTaskName(): string { return $this->taskName; }
    public function setTaskName(string $v): void { $this->taskName = $v; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): void { $this->displayName = $v; }
    public function getTaskType(): ?int { return $this->taskType; }
    public function setTaskType(?int $v): void { $this->taskType = $v; }
    public function getPerformType(): ?int { return $this->performType; }
    public function setPerformType(?int $v): void { $this->performType = $v; }
    public function getTaskState(): int { return $this->taskState; }
    public function setTaskState(int $v): void { $this->taskState = $v; }
    public function getActorId(): ?string { return $this->actorId; }
    public function setActorId(?string $v): void { $this->actorId = $v; }
    /** @return string[] */
    public function getActorIds(): array { return $this->actorIds; }
    /** @param string[] $v */
    public function setActorIds(array $v): void { $this->actorIds = $v; }
    public function getFinishTime(): ?string { return $this->finishTime; }
    public function setFinishTime(?string $v): void { $this->finishTime = $v; }
    public function getExpireTime(): ?string { return $this->expireTime; }
    public function setExpireTime(?string $v): void { $this->expireTime = $v; }
    public function getFormKey(): ?string { return $this->formKey; }
    public function setFormKey(?string $v): void { $this->formKey = $v; }
    public function getParentTaskId(): ?string { return $this->parentTaskId; }
    public function setParentTaskId(?string $v): void { $this->parentTaskId = $v; }
    public function getVariables(): FlowData { return $this->variables; }
    public function setVariables(FlowData $v): void { $this->variables = $v; }
    public function getCreateTime(): ?string { return $this->createTime; }
    public function setCreateTime(?string $v): void { $this->createTime = $v; }
    public function getCreateUser(): ?string { return $this->createUser; }
    public function setCreateUser(?string $v): void { $this->createUser = $v; }
    public function getUpdateTime(): ?string { return $this->updateTime; }
    public function setUpdateTime(?string $v): void { $this->updateTime = $v; }
    public function getUpdateUser(): ?string { return $this->updateUser; }
    public function setUpdateUser(?string $v): void { $this->updateUser = $v; }
}
