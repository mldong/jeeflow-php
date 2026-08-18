<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 任务类型 —— 对齐 Java ProcessTaskTypeEnum
 */
final class TaskType
{
    public const MAIN = 0;       // 主办任务
    public const ASSIST = 1;     // 协办任务
    public const RECORD = 2;     // 记录

    public static function label(int $code): string
    {
        return match ($code) {
            self::MAIN => '主办',
            self::ASSIST => '协办',
            self::RECORD => '记录',
            default => '未知(' . $code . ')',
        };
    }
}
