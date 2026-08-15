<?php

declare(strict_types=1);

namespace Jeeflow\Core\Metadata;

/**
 * SPI 元数据项（设计器字典源）——对齐 Java HandlerMeta
 */
final class HandlerMeta
{
    public function __construct(
        public string $type,
        public string $className,
        public string $displayName,
        public int $order = 0,
        public string $group = '',
    ) {
    }
}
