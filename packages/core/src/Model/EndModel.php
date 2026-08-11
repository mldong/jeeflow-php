<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\EndProcessHandler;

/**
 * 结束节点模型
 */
class EndModel extends NodeModel
{
    protected function exec(Execution $execution): void
    {
        $this->fire(new EndProcessHandler($this), $execution);
    }
}
