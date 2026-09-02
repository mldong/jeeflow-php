<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 流程事件类型 —— 对齐 Java ProcessEventTypeEnum（code 一一对应）
 *
 * 值为 int backed，与 Java `ProcessEventTypeEnum.java` 的 code 保持一致，
 * 保证跨语言事件契约按 code 对齐（spec 04-extensions §7）。
 *
 * CC_CREATE(4) 为 issues/102 新增：Java 的 4 号位是 PROCESS_TASK_END，
 * 而 PHP 引擎从不 fire 独立任务结束事件（对齐 spec §7「从不 fire TASK_END」），
 * 故 4 号位复用于 CC_CREATE。本批次**仅 PHP 引擎实现 CC_CREATE**，
 * 其余五语言待 issues/102 后续批次。
 */
enum ProcessEventTypeEnum: int
{
    /** 流程实例开始（对齐 Java PROCESS_INSTANCE_START） */
    case INSTANCE_START = 1;
    /** 流程实例结束（办结+驳回合并，对齐 Java PROCESS_INSTANCE_END） */
    case INSTANCE_END = 2;
    /** 流程任务开始 / 新待办（对齐 Java PROCESS_TASK_START） */
    case TASK_START = 3;
    /** 抄送知会（issues/102 新增；本批次仅 PHP 引擎实现，逐抄送人） */
    case CC_CREATE = 4;

    public function label(): string
    {
        return match ($this) {
            self::INSTANCE_START => '流程实例开始',
            self::INSTANCE_END   => '流程实例结束',
            self::TASK_START     => '流程任务开始',
            self::CC_CREATE      => '抄送知会',
        };
    }
}
