<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
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

        // 一票否决检查
        $submitType = $execution->getArgs()->getInt(FlowConst::SUBMIT_TYPE);
        if ($submitType === SubmitType::COUNTERSIGN_DISAGREE) {
            $execution->setMerged(true);
            return;
        }

        $csType = $this->taskModel->getCountersignType();

        // 串行会签 (countersignType=2): 全部成员完成才流转
        if ($csType === 2) {
            $execution->setMerged($finishedCount >= count($allTasks));
        } else {
            // 并行会签：检查条件
            $cond = $this->taskModel->getCountersignCompletionCondition();
            if ($cond === '') {
                // 无特殊条件 → 全部完成
                $execution->setMerged($finishedCount >= count($allTasks));
            } else {
                // 表达式求值（需要 SPI）
                $evaluator = \Jeeflow\Core\ServiceContext::find(\Jeeflow\Core\Spi\ExpressionEvaluatorInterface::class);
                if ($evaluator !== null) {
                    $vars = $this->buildCountersignVars($instance, $allTasks);
                    $vars->setAll($execution->getArgs()->toArray());
                    try {
                        $result = $evaluator->eval($cond, $vars);
                        $execution->setMerged($result === true);
                    } catch (\Throwable) {
                        $execution->setMerged(false);
                    }
                } else {
                    $execution->setMerged(false);
                }
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
