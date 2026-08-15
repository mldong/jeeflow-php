<?php

declare(strict_types=1);

namespace Jeeflow\Core\Interceptor;

use Jeeflow\Core\Execution;

/**
 * 流程拦截器 SPI —— 对齐 Java FlowInterceptor
 */
interface FlowInterceptor
{
    public function intercept(Execution $execution): void;
}
