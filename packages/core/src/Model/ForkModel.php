<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;

/**
 * 分支节点模型
 */
class ForkModel extends NodeModel
{
    protected function exec(Execution $execution): void
    {
        $this->runOutTransition($execution);
    }
}
