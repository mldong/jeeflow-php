<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Handler\CountersignHandler;

/**
 * 任务节点模型
 *
 * 对齐 Java TaskModel。
 */
class TaskModel extends NodeModel
{
    private string $form = '';
    private string $assignee = '';
    private string $assignmentHandler = '';
    private ?int $taskType = null;
    private ?int $performType = null;
    private string $reminderTime = '';
    private string $reminderRepeat = '';
    private string $expireTime = '';
    private string $autoExecute = '';
    private string $callback = '';
    private FlowData $ext;
    private string $candidateHandler = '';
    private ?int $countersignType = null;
    private string $countersignCompletionCondition = '';

    public function __construct()
    {
        $this->ext = FlowData::create();
    }

    protected function exec(Execution $execution): void
    {
        if ($this->performType === PerformType::COUNTERSIGN) {
            $this->fire(new CountersignHandler($this), $execution);
            if ($execution->isMerged()) {
                $this->runOutTransition($execution);
            }
        } else {
            $this->runOutTransition($execution);
        }
    }

    // ── 便捷字段（从 ext 中读取） ──

    public function getCandidateUsers(): ?string
    {
        return $this->ext->getStr('candidateUsers') ?: null;
    }

    public function getCandidateGroups(): ?string
    {
        return $this->ext->getStr('candidateGroups') ?: null;
    }

    public function getCandidateHandler(): ?string
    {
        if ($this->candidateHandler !== '') return $this->candidateHandler;
        return $this->ext->getStr('candidateHandler') ?: null;
    }

    // ── Getters/Setters ──

    public function getForm(): string { return $this->form; }
    public function setForm(string $v): void { $this->form = $v; }
    public function getAssignee(): string { return $this->assignee; }
    public function setAssignee(string $v): void { $this->assignee = $v; }
    public function getAssignmentHandler(): string { return $this->assignmentHandler; }
    public function setAssignmentHandler(string $v): void { $this->assignmentHandler = $v; }
    public function getTaskType(): ?int { return $this->taskType; }
    public function setTaskType(?int $v): void { $this->taskType = $v; }
    public function getPerformType(): ?int { return $this->performType; }
    public function setPerformType(?int $v): void { $this->performType = $v; }
    public function getReminderTime(): string { return $this->reminderTime; }
    public function setReminderTime(string $v): void { $this->reminderTime = $v; }
    public function getReminderRepeat(): string { return $this->reminderRepeat; }
    public function setReminderRepeat(string $v): void { $this->reminderRepeat = $v; }
    public function getExpireTime(): string { return $this->expireTime; }
    public function setExpireTime(string $v): void { $this->expireTime = $v; }
    public function getAutoExecute(): string { return $this->autoExecute; }
    public function setAutoExecute(string $v): void { $this->autoExecute = $v; }
    public function getCallback(): string { return $this->callback; }
    public function setCallback(string $v): void { $this->callback = $v; }
    public function getExt(): FlowData { return $this->ext; }
    public function setExt(FlowData $v): void { $this->ext = $v; }
    public function setCandidateHandler(string $v): void { $this->candidateHandler = $v; }
    public function getCountersignType(): ?int { return $this->countersignType; }
    public function setCountersignType(?int $v): void { $this->countersignType = $v; }
    public function getCountersignCompletionCondition(): string { return $this->countersignCompletionCondition; }
    public function setCountersignCompletionCondition(string $v): void { $this->countersignCompletionCondition = $v; }
}
