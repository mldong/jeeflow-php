<?php

declare(strict_types=1);

namespace Jeeflow\Core;

/** 仓储可选的实例级事务排他保护（spec 04 · 并发保护）。 */
interface IInstanceLockRepository
{
    public function lockInstance(string $instanceId): void;
}
