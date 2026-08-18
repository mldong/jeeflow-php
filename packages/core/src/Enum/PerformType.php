<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 任务参与类型 —— 对齐 Java ProcessTaskPerformTypeEnum
 */
final class PerformType
{
    public const NORMAL = 0;
    public const COUNTERSIGN = 1;

    public static function from(mixed $value): int
    {
        if (is_string($value)) {
            $upper = strtoupper($value);
            if ($upper === 'ALL' || $upper === 'COUNTERSIGN') {
                return self::COUNTERSIGN;
            }
            return (int) $value;
        }
        return (int) $value;
    }

    public static function label(int $code): string
    {
        return match ($code) {
            self::NORMAL => '普通参与',
            self::COUNTERSIGN => '会签参与',
            default => '未知(' . $code . ')',
        };
    }
}
