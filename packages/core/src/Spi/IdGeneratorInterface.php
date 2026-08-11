<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * ID 生成器 SPI —— 对齐 Java IIdGenerator
 */
interface IdGeneratorInterface
{
    public function nextId(): int|string;
}

