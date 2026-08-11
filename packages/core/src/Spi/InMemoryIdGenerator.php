<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 内存自增 ID（测试用）
 */
class InMemoryIdGenerator implements IdGeneratorInterface
{
    private int $counter = 1000;

    public function nextId(): int|string
    {
        return (string) (++$this->counter);
    }
}
