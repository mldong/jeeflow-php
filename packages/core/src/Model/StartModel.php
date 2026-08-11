<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;

/**
 * 开始节点模型
 */
class StartModel extends NodeModel
{
    protected function exec(Execution $execution): void
    {
        $this->runOutTransition($execution);
    }
}
