<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Handler\AddSignHandler;
use Jeeflow\Core\Handler\CountersignHandler;
use Jeeflow\Core\Support\AidantSupport;

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
    private string $reapplyMode = 'NORMAL';
    /** 后加签开关；缺省 false（节点不可加签，行为与既有普通任务逐字一致） */
    private bool $allowAddSign = false;
    /** 后加签轮次上限；0＝不限（缺省） */
    private int $addSignMaxRounds = 0;
    /** 已办撤回开关（issues/246）；缺省 false；会签/申请节点解析期禁配（RecallSupport::assertModelLegal） */
    private bool $allowRecall = false;
    /** 动态会签（征询模式）开关（issues/247）；缺省 false；与 allowAddSign 互斥（CollectSupport::assertModelLegal 解析期报错） */
    private bool $dynamicCountersign = false;

    public function __construct()
    {
        $this->ext = FlowData::create();
    }

    protected function exec(Execution $execution): void
    {
        if ($this->performType === PerformType::COUNTERSIGN) {
            $this->fire(new CountersignHandler($this), $execution);
            if ($execution->isMerged()) {
                $this->abandonAidantOnLeave($execution);
                $this->runOutTransition($execution);
            }
        } elseif ($this->allowAddSign) {
            // 后加签档：推进闸交给 AddSignHandler 裁决（本次点人→同节点续建且不流转；
            // 没点人但本节点还有兄弟待办→不流转；两者皆否→放行出边）。
            $this->fire(new AddSignHandler($this), $execution);
            if ($execution->isMerged()) {
                $this->abandonAidantOnLeave($execution);
                $this->runOutTransition($execution);
            }
        } elseif ($this->dynamicCountersign) {
            // 征询档（issues/247）：发起腿在门面（带 tf_addSignOperators 的提交不办结主办行，
            // 到不了这里）；能走进执行闸的只有成员行办结与主办行收齐后的拍板——两者复用
            // AddSignHandler 推进闸：主办还 DOING ⇒ merged=false 节点不动；主办办结且成员清零 ⇒ 放行。
            $this->fire(new AddSignHandler($this), $execution);
            if ($execution->isMerged()) {
                $this->abandonAidantOnLeave($execution);
                $this->runOutTransition($execution);
            }
        } else {
            $this->abandonAidantOnLeave($execution);
            $this->runOutTransition($execution);
        }
    }

    /**
     * 离开节点时清场未办结协办行（issues/253，规范 04「动态协办」）：waitCompletion=false
     * （缺省）时主办可随时办结推进，未办结协办行随废弃腿清场、账本记废弃，不留孤儿待办。
     *
     * 只在**真要离开本节点**（merged／普通档放行出边）时调用——串行会签/加签续建/征询未收齐
     * 那些"留在本节点"的分支不调，否则会把还在办的协办行提前废弃。幂等：已废弃行不再命中，
     * 会签档 CountersignHandler 先清过一遍也不会重复记账。
     */
    private function abandonAidantOnLeave(Execution $execution): void
    {
        AidantSupport::abandonPendingOnNode(
            $execution->getProcessInstance(), $this->getName(), $execution->getOperator());
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
    public function getReapplyMode(): string { return $this->reapplyMode; }
    public function setReapplyMode(string $v): void { $this->reapplyMode = $v; }
    public function isAllowAddSign(): bool { return $this->allowAddSign; }
    public function setAllowAddSign(bool $v): void { $this->allowAddSign = $v; }
    public function getAddSignMaxRounds(): int { return $this->addSignMaxRounds; }
    public function setAddSignMaxRounds(int $v): void { $this->addSignMaxRounds = $v; }
    public function isAllowRecall(): bool { return $this->allowRecall; }
    public function setAllowRecall(bool $v): void { $this->allowRecall = $v; }
    public function isDynamicCountersign(): bool { return $this->dynamicCountersign; }
    public function setDynamicCountersign(bool $v): void { $this->dynamicCountersign = $v; }
}
