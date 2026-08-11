<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Model\JoinModel;

/**
 * 合并分支处理器 —— 等待所有并行分支完成后再继续
 *
 * 对齐 Java MergeBranchHandler。
 */
class MergeBranchHandler implements HandlerInterface
{
    private JoinModel $joinModel;

    public function __construct(JoinModel $joinModel)
    {
        $this->joinModel = $joinModel;
    }

    public function handle(Execution $execution): void
    {
        $instance = $execution->getProcessInstance();
        $doingTasks = $instance->getDoingTasks();
        $execution->setMerged(empty($doingTasks));

        if (!$execution->isMerged()) {
            $hasActive = false;
            foreach ($this->joinModel->getInputs() as $input) {
                $sourceName = $input->getSource()->getName();
                foreach ($doingTasks as $task) {
                    if ($sourceName === $task->getTaskName()) {
                        $hasActive = true;
                        break 2;
                    }
                }
            }
            $execution->setMerged(!$hasActive);
        }
    }
}
