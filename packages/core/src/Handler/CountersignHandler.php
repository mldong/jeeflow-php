<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
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

        // 串行会签 (countersignType=2): 全部成员完成才流转
        if ($csType === 2) {
            $merged = $finishedCount >= count($allTasks);
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
