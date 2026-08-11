<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\MergeBranchHandler;

/**
 * 合并节点模型
 */
class JoinModel extends NodeModel
{
    protected function exec(Execution $execution): void
    {
        $this->fire(new MergeBranchHandler($this), $execution);
        if ($execution->isMerged()) {
            $this->runOutTransition($execution);
        }
    }
}
