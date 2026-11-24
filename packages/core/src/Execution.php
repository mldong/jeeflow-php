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
    /** @var array<string, ProcessTask> 已由嵌套引擎调用自行落库并 fire 的任务，禁止父 execution 再保存/再播码 3 */
    private array $persistedTasks = [];
    /** @var ProcessTask[] 已由嵌套引擎调用自行落库的历史任务，禁止父 execution 再保存 */
    private array $persistedHistoryTasks = [];
    /** @var Execution[] 父级联产生的 execution：其任务须由引擎保存，父实例须补写后再播终态 */
    private array $continuations = [];
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

    /**
     * <b>本次请求</b>点名的后加签办理人（已归一，没点人为空表）。
     *
     * 为什么不从 {@see self::$args} 读：`args` 是「实例变量 ← 本次参数」的合并结果，
     * 而历史形状下 `ProcessInstance::completeTask` 会把整包提交参数并进**实例变量**
     * ⇒ 上一轮提交里的 `tf_addSignOperators` 会随实例变量复活，凭空多建一条本节点待办。
     * `tf_nextNodeOperator` 同病（重提直返腿为此在引擎里显式剥键）。
     * 根因已在 `completeTask` 修掉（实例变量只收数据类键，spec 04「流程变量约定」末注），
     * 本字段是**第二道**：控制类参数一律只认当次请求，不靠剥键。
     *
     * @var string[]
     */
    private array $addSignOperators = [];

    public function __construct()
    {
        $this->args = FlowData::create();
    }

    public function addTask(ProcessTask $task): void
    {
        $this->processTaskList[] = $task;
    }

    /** 返回本次新任务、嵌套已落库任务与父级 continuation 任务，按 taskId 去重。 */
    public function getResultTasks(): array
    {
        $result = [];
        foreach ($this->processTaskList as $task) {
            if (!$this->isPersistedTask($task)) {
                $result[(string) $task->getTaskId()] = $task;
            }
        }
        foreach ($this->persistedTasks as $task) {
            $result[(string) $task->getTaskId()] = $task;
        }
        foreach ($this->continuations as $continuation) {
            foreach ($continuation->getResultTasks() as $task) {
                $result[(string) $task->getTaskId()] = $task;
            }
        }
        return array_values($result);
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

    /**
     * 标记嵌套引擎调用已自行落库的任务（issues/257 裁决 D）。
     *
     * 子流程 start/execute 各自已经 saveTask 并 fire 码 3；把同一对象再挂进父
     * processTaskList 会让父 persistTasks 重复 INSERT 同 id、重复发待办事件。
     * 这里只记 taskId 集合，由 persistTasks/saveNewTask 在建单漏斗处去重；
     * 调用方仍可正常保留返回任务列表，不改变 executeProcessTask 的出口。
     */
    public function addPersistedTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            if ($task instanceof ProcessTask && $task->getTaskId() !== null) {
                $this->persistedTasks[(string) $task->getTaskId()] = $task;
            }
        }
    }

    public function isPersistedTask(ProcessTask $task): bool
    {
        return $task->getTaskId() !== null
            && isset($this->persistedTasks[(string) $task->getTaskId()]);
    }

    /** 标记嵌套调用已自行落库的记录类历史行，父 persistHistoryTasks 不再重复保存。 */
    public function addPersistedHistoryTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            if ($task instanceof ProcessTask && $task->getTaskId() !== null) {
                $this->persistedHistoryTasks[(string) $task->getTaskId()] = true;
            }
        }
    }

    public function isPersistedHistoryTask(ProcessTask $task): bool
    {
        return $task->getTaskId() !== null
            && isset($this->persistedHistoryTasks[(string) $task->getTaskId()]);
    }

    /** 登记父级联 execution；引擎 persistTasks 会统一保存其任务并补写父实例。 */
    public function addContinuation(Execution $continuation): void
    {
        $this->continuations[] = $continuation;
    }

    /** @return Execution[] */
    public function getContinuations(): array
    {
        return $this->continuations;
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
    /** @return string[] 本次请求点名的后加签办理人（已归一） */
    public function getAddSignOperators(): array { return $this->addSignOperators; }
    /** @param string[]|null $v null 与空表同档＝本次没点人 */
    public function setAddSignOperators(?array $v): void { $this->addSignOperators = $v ?? []; }
    public function getEngine(): ?JeeflowEngineInterface { return $this->engine; }
    public function setEngine(?JeeflowEngineInterface $v): void { $this->engine = $v; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $v): void { $this->operator = $v; }
    public function getNodeModel(): ?NodeModel { return $this->nodeModel; }
    public function setNodeModel(?NodeModel $v): void { $this->nodeModel = $v; }
}
