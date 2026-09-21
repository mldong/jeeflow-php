<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 提交类型（submitType）—— 对齐 Java ProcessSubmitTypeEnum
 */
final class SubmitType
{
    public const APPLY = 0;
    public const AGREE = 1;
    public const REJECT = 2;
    public const ROLLBACK = 3;
    public const JUMP = 4;
    public const RE_APPLY = 5;
    public const ROLLBACK_TO_OPERATOR = 6;
    /** TRANSFER 转办（issues/115）：仅 processTask/transfer 写留痕用，不走 execute 分发 */
    public const TRANSFER = 7;
    public const COUNTERSIGN_DISAGREE = 20;

    public static function label(int $code): string
    {
        return match ($code) {
            self::APPLY => '发起申请',
            self::AGREE => '同意申请',
            self::REJECT => '拒绝申请',
            self::ROLLBACK => '退回上一步',
            self::JUMP => '跳转',
            self::RE_APPLY => '重新提交',
            self::ROLLBACK_TO_OPERATOR => '退回发起人',
            self::TRANSFER => '转办',
            // 契约 07 字典：20 是「会签拒绝」，与 2「拒绝申请」是两个不同语义的槽位，
            // 此前与 Java 同款误抄成「拒绝申请」，本轮一并对齐（2 不动）
            self::COUNTERSIGN_DISAGREE => '会签拒绝',
            default => '未知(' . $code . ')',
        };
    }
}
