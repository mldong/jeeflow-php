<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;

/**
 * 处理器接口 —— 节点执行中的具体逻辑
 *
 * 对齐 Java IHandler。
 */
interface HandlerInterface
{
    public function handle(Execution $execution): void;
}
