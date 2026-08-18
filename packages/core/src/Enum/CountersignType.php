<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 会签类型 —— 对齐 Java CountersignTypeEnum
 */
final class CountersignType
{
    public const PARALLEL = 0;  // 并行会签
    public const SERIAL = 1;    // 串行会签

    public static function label(int $code): string
    {
        return match ($code) {
            self::PARALLEL => '并行会签',
            self::SERIAL => '串行会签',
            default => '未知(' . $code . ')',
        };
    }
}
