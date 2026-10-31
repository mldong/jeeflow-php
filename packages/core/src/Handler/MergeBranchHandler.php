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
        $execution->setMerged(\Jeeflow\Core\Support\ReapplySupport::mergeReady(
            $execution->getProcessInstance(), $this->joinModel,
            (string) ($execution->getProcessTaskId() ?? $execution->getProcessTask()?->getTaskId() ?? '')));
    }
}
