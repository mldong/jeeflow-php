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
    public const COUNTERSIGN_DISAGREE = 20;
}
