<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Model\TaskModel;

/**
 * 会签任务处理器
 *
 * 对齐 Java CountersignHandler。
 */
class CountersignHandler implements HandlerInterface
{
    private TaskModel $taskModel;

    public function __construct(TaskModel $taskModel)
    {
        $this->taskModel = $taskModel;
    }

    public function handle(Execution $execution): void
    {
        $instance = $execution->getProcessInstance();

        // 获取所有属于该节点的任务
        $allTasks = array_values(array_filter(
            $instance->getTasks(),
            fn(ProcessTask $t) => $t->getTaskName() === $this->taskModel->getName()
        ));

        $finishedCount = count(array_filter(
            $allTasks,
            fn(ProcessTask $t) => $t->getTaskState() === ProcessTaskState::FINISHED
        ));

        // 会签拒绝（submitType=20）：仅当节点配置一票否决开关（countersignCompletionCondition
        // == ONE_VOTE_VETO，忽略大小写）时否决生效、会签节点立即推进；否则为软拒绝——
        // 否决者任务正常完成、countersignDisagreeFlag=1 已记录为变量（供下游节点参考），
        // 流程不阻断，继续走常规完成判定（issues/91，对齐 mldong 内置引擎 / Java CountersignHandler）
        $submitType = $execution->getArgs()->getInt(FlowConst::SUBMIT_TYPE);
        if ($submitType === SubmitType::COUNTERSIGN_DISAGREE
            && $this->isOneVoteVeto($this->taskModel->getCountersignCompletionCondition())) {
            $execution->setMerged(true);
            $this->abandonRemainingCountersignTasks($instance, $execution->getOperator());
            return;
        }

        $csType = $this->taskModel->getCountersignType();
        $merged = false;

        // 串行会签逐个创建（issues/93，对齐内置版 createCountersignTask + Java/Go/Python/Node）：
        // 每次仅 1 个 DOING 成员任务。本位（最后一位）完成 → 流转；否则创建下一位成员任务并停留。
        // 会签计数状态在首位任务变量（createCountersignTasks 写入），prepareExecution 已把
        // 刚完成任务变量同步到 execution.getProcessTask()，由此读取 loopCounter/operatorList。
        // 注：CountersignType::SERIAL === 1（此前误写 === 2 为死分支，串行被当作并行全员等待；
        // 在逐个创建下会导致首位完成即误判 merged 提前流转，故一并修正，对齐 Java）。
        if ($csType === CountersignType::SERIAL) {
            $completed = $execution->getProcessTask();
            $operatorList = null;
            $loopCounter = 0;
            if ($completed !== null && $completed->getVariables() !== null) {
                $operatorList = $this->toStringList($completed->getVariables()->get(
                    FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $this->taskModel->getName()));
                $loopCounter = $completed->getVariables()->getInt(
                    FlowConst::LOOP_COUNTER . '_' . $this->taskModel->getName(), 0);
            }
            if (!empty($operatorList) && $loopCounter + 1 < count($operatorList)) {
                $this->createNextCountersignTask(
                    $instance, $execution,
                    $operatorList[$loopCounter + 1], $loopCounter + 1, count($operatorList)
                );
            } else {
                // 最后一位完成 → 流转（全部成员完成才推进，issues/44 E16）
                $merged = true;
            }
        } else {
            // 并行会签：检查条件
            $cond = $this->taskModel->getCountersignCompletionCondition();
            if ($cond === '') {
                // 无特殊条件 → 全部完成
                $merged = $finishedCount >= count($allTasks);
            } else {
                // 表达式求值（需要 SPI）
                $evaluator = \Jeeflow\Core\ServiceContext::find(\Jeeflow\Core\Spi\ExpressionEvaluatorInterface::class);
                if ($evaluator !== null) {
                    $vars = $this->buildCountersignVars($instance, $allTasks);
                    $vars->setAll($execution->getArgs()->toArray());
                    try {
                        $result = $evaluator->eval($cond, $vars);
                        $merged = $result === true;
                    } catch (\Throwable) {
                        $merged = false;
                    }
                } else {
                    $merged = false;
                }
            }
        }

        if ($merged) {
            // 会签节点推进后废弃本节点剩余 DOING 会签任务（issues/91，对齐内置版
            // abandonProcessTask），不留孤儿待办
            $this->abandonRemainingCountersignTasks($instance, $execution->getOperator());
        }
        $execution->setMerged($merged);
    }

    /** 一票否决开关：节点 countersignCompletionCondition == ONE_VOTE_VETO（忽略大小写） */
    private function isOneVoteVeto(string $condition): bool
    {
        return $condition !== '' && strcasecmp(trim($condition), 'ONE_VOTE_VETO') === 0;
    }

    /** 废弃本节点剩余 DOING 会签任务（merged 时调用）。
     *  刚完成的任务此刻已置 FINISHED，isDoing 过滤不会误伤 */
    private function abandonRemainingCountersignTasks($instance, string $operator): void
    {
        foreach ($instance->getTasks() as $task) {
            if ($task->getTaskName() === $this->taskModel->getName() && $task->isDoing()) {
                $task->abandon($operator);
            }
        }
    }

    /** 串行会签推进：创建下一位成员任务（issues/93）。
     *  新任务同时加入聚合根（$instance->tasks，供 updateInstance 级联/后续查询）与 execution
     *  （供 persistTasks 经 saveTask 落库并分配任务 id——saveTask 对 null id 自动分配）。
     *  会签计数状态随任务变量持久化（loopCounter+1 / operatorList 全量 / nrOfInstances），
     *  下一位完成时由本处理器再次读取推进，直至最后一位完成才 merged。 */
    private function createNextCountersignTask($instance, Execution $execution,
                                               string $nextActor, int $nextLoopCounter, int $total): void
    {
        $tm = $this->taskModel;
        $node = $tm->getName();
        $next = ProcessTask::create(
            $instance->getInstanceId(), $node, $tm->getDisplayName(),
            $tm->getTaskType(), $tm->getPerformType(), $tm->getForm() ?: null,
            [$nextActor], $execution->getOperator()
        );
        $completed = $execution->getProcessTask();
        $operatorList = $completed !== null
            ? $this->toStringList($completed->getVariables()->get(FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $node))
            : [];
        $next->getVariables()->set(FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $node, $operatorList);
        $next->getVariables()->set(FlowConst::LOOP_COUNTER . '_' . $node, $nextLoopCounter);
        $next->getVariables()->set(FlowConst::NR_OF_INSTANCES . '_' . $node, $total);
        $tasks = $instance->getTasks();
        $tasks[] = $next;
        $instance->setTasks($tasks);
        $execution->addTask($next);
    }

    /** 会签办理人列表取值兼容（JSON 反序列化后可能是数组 / 其他标量） */
    private function toStringList(mixed $value): array
    {
        $result = [];
        if ($value === null) return $result;
        if (is_array($value)) {
            foreach ($value as $o) {
                $s = trim((string) $o);
                if ($s !== '') $result[] = $s;
            }
        } else {
            $s = trim((string) $value);
            if ($s !== '') $result[] = $s;
        }
        return $result;
    }

    private function buildCountersignVars($instance, array $allTasks): FlowData
    {
        $prefix = FlowConst::COUNTERSIGN_VARIABLE_PREFIX . $this->taskModel->getName() . '_';
        $vars = FlowData::create();
        $vars->setAll($instance->getVariables()->toArray());
        $vars->set($prefix . FlowConst::NR_OF_INSTANCES, count($allTasks));
        $vars->set($prefix . FlowConst::NR_OF_ACTIVATE_INSTANCES,
            count(array_filter($allTasks, fn(ProcessTask $t) => $t->isDoing())));
        $vars->set($prefix . FlowConst::NR_OF_COMPLETED_INSTANCES,
            count(array_filter($allTasks, fn(ProcessTask $t) => $t->isFinished())));
        return $vars;
    }
}
