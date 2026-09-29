<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Event\PendingInstanceEnd;
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
    /**
     * 记录类节点（`snaker:custom`）产生的**历史行**（`task_state=20`）——与
     * {@see self::$processTaskList} 分开放是有原因的（issues/142 A 批 · spec 02 §6.1 第 2 条
     * 硬结论／§6.2 第 1 条）：`processTaskList` 那条腿在引擎侧是
     * `saveNewTask → applySurrogate → saveTask → notifyTaskStart`，即"新待办产生"的语义
     * （码 3 PROCESS_TASK_START）。历史行**不是待办**，塞进那条腿会造出"给已完成行发
     * 新待办事件"的假形状，还会让委托代理把一个 DONE 行的参与者改掉。
     * ⇒ **落库与码 3 解耦**：本列表由引擎 `persistHistoryTasks` 单独走仓储 `saveTask`
     *   （真落库，内存仓/SQL 仓同一条腿），既不 fire 码 3、也不过委托。
     *
     * @var ProcessTask[]
     */
    private array $historyTaskList = [];
    private bool $merged = false;
    private ?JeeflowEngineInterface $engine = null;
    private string $operator = '';
    private ?NodeModel $nodeModel = null;

    /**
     * 实例终态待播队列（spec §11.2 原则 3／码 2 触发时机，见 {@see PendingInstanceEnd}）：
     * 结束节点处理器只登记，真正的 fire 由引擎在 `repository->updateInstance` 成功返回后
     * 统一 flush（{@see JeeflowEngine::flushInstanceEndEvents()}）。
     *
     * 随 execution 生死，不留静态登记表——并发流转互不串味。
     *
     * @var PendingInstanceEnd[]
     */
    private array $pendingEnds = [];

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

    /** 挂一条记录类历史行（DONE，task_state=20），落库由引擎 persistHistoryTasks 收口 */
    public function addHistoryTask(ProcessTask $task): void
    {
        $this->historyTaskList[] = $task;
    }

    /** @param ProcessTask[] $tasks */
    public function addHistoryTasks(array $tasks): void
    {
        foreach ($tasks as $t) {
            $this->historyTaskList[] = $t;
        }
    }

    /** @return ProcessTask[] */
    public function getHistoryTaskList(): array
    {
        return $this->historyTaskList;
    }

    /** 登记一条待播的实例终态事件（不 fire，见 {@see self::drainPendingEnds()}） */
    public function addPendingEnd(?PendingInstanceEnd $pendingEnd): void
    {
        if ($pendingEnd !== null) {
            $this->pendingEnds[] = $pendingEnd;
        }
    }

    /**
     * 并入另一条 execution 的待播终态事件——子流程级联的收口姿势：处理器在**父实例**的
     * 临时 execution 上办结父实例，登记要随任务一起上收到外层 execution，
     * 与 {@see self::addTasks()} 同一条腿（漏了就是"父实例终态事件整支丢掉"）。
     *
     * @param PendingInstanceEnd[] $pendingEnds
     */
    public function addPendingEnds(array $pendingEnds): void
    {
        foreach ($pendingEnds as $pe) {
            if ($pe instanceof PendingInstanceEnd) {
                $this->pendingEnds[] = $pe;
            }
        }
    }

    /** @return PendingInstanceEnd[] */
    public function getPendingEnds(): array
    {
        return $this->pendingEnds;
    }

    /** 取走并清空（引擎 flush 用；清空保证同一条登记不会被播两次） */
    public function drainPendingEnds(): array
    {
        $taken = $this->pendingEnds;
        $this->pendingEnds = [];
        return $taken;
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
