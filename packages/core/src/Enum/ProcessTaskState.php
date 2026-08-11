<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 流程任务状态 —— 对齐 Java ProcessTaskStateEnum
 */
final class ProcessTaskState
{
    public const DOING = 10;
    public const FINISHED = 20;
    public const WITHDRAW = 30;
    public const INTERRUPT = 40;
    public const PENDING = 50;
    public const ABANDON = 99;

    public static function label(int $code): string
    {
        return match ($code) {
            self::DOING => '进行中',
            self::FINISHED => '已完成',
            self::WITHDRAW => '已撤回',
            self::INTERRUPT => '强行终止',
            self::PENDING => '挂起',
            self::ABANDON => '已废弃',
            default => '未知(' . $code . ')',
        };
    }
}
