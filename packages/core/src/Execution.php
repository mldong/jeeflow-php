<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;

/**
 * 执行上下文 —— 流转过程中携带的状态
 *
 * 对齐 Java Execution。
 */
class Execution
{
    private ?string $processInstanceId = null;
    private ?string $processTaskId = null;
    private FlowData $args;
    private ?ProcessModel $processModel = null;
    private ?ProcessTask $processTask = null;
    private ?ProcessInstance $processInstance = null;
    /** @var ProcessTask[] */
    private array $processTaskList = [];
    private bool $merged = false;
    private ?JeeflowEngineInterface $engine = null;
    private string $operator = '';
    private ?NodeModel $nodeModel = null;

    public function __construct()
    {
        $this->args = FlowData::create();
    }

    public function addTask(ProcessTask $task): void
    {
        $this->processTaskList[] = $task;
    }

    /** @param ProcessTask[] $tasks */
    public function addTasks(array $tasks): void
    {
        foreach ($tasks as $t) {
            $this->processTaskList[] = $t;
        }
    }

    /** @return ProcessTask[] */
    public function getDoingTaskList(): array
    {
        return array_values(array_filter(
            $this->processTaskList,
            fn(ProcessTask $t) => $t->getTaskState() === ProcessTaskState::DOING
        ));
    }

    // ── Getters/Setters ──

    public function getProcessInstanceId(): ?string { return $this->processInstanceId; }
    public function setProcessInstanceId(?string $v): void { $this->processInstanceId = $v; }
    public function getProcessTaskId(): ?string { return $this->processTaskId; }
    public function setProcessTaskId(?string $v): void { $this->processTaskId = $v; }
    public function getArgs(): FlowData { return $this->args; }
    public function setArgs(FlowData $v): void { $this->args = $v; }
    public function getProcessModel(): ?ProcessModel { return $this->processModel; }
    public function setProcessModel(?ProcessModel $v): void { $this->processModel = $v; }
    public function getProcessTask(): ?ProcessTask { return $this->processTask; }
    public function setProcessTask(?ProcessTask $v): void { $this->processTask = $v; }
    public function getProcessInstance(): ?ProcessInstance { return $this->processInstance; }
    public function setProcessInstance(?ProcessInstance $v): void { $this->processInstance = $v; }
    /** @return ProcessTask[] */
    public function getProcessTaskList(): array { return $this->processTaskList; }
    /** @param ProcessTask[] $v */
    public function setProcessTaskList(array $v): void { $this->processTaskList = $v; }
    public function isMerged(): bool { return $this->merged; }
    public function setMerged(bool $v): void { $this->merged = $v; }
    public function getEngine(): ?JeeflowEngineInterface { return $this->engine; }
    public function setEngine(?JeeflowEngineInterface $v): void { $this->engine = $v; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $v): void { $this->operator = $v; }
    public function getNodeModel(): ?NodeModel { return $this->nodeModel; }
    public function setNodeModel(?NodeModel $v): void { $this->nodeModel = $v; }
}
