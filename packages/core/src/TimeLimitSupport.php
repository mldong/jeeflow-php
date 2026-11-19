<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Model\TaskModel;

/** 任务时限四键的求值器（spec 02 §4.5 / spec 04）。 */
final class TimeLimitSupport
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** autoExecute 仅精确 "Y" 开启；N/未填/未知值一律关闭。 */
    public static function autoExecuteEnabled(?TaskModel $node): bool
    {
        return $node !== null && $node->getAutoExecute() === 'Y';
    }

    /** callback 完成处理器注册名（空＝不调用；两端空白裁掉）。 */
    public static function callbackName(?TaskModel $node): string
    {
        return trim($node?->getCallback() ?? '');
    }

    /**
     * 首次提醒时刻（规范 02 §4.5）：变量 → 相对量（锚点＝任务创建时刻）→ 严格 HH:mm → 绝对。
     * 任何一档都不得回落"当前时刻"或 expireTime。返回 'Y-m-d H:i:s' 或 null。
     */
    public static function firstReminder(?string $expr, FlowData $args, string $createTime): ?string
    {
        if ($expr === null || trim($expr) === '') {
            return null;
        }
        if ($args->has($expr)) {
            $v = $args->get($expr);
            if ($v instanceof \DateTimeInterface) {
                return $v->format(self::DATETIME_FORMAT);
            }
            if (is_int($v)) {
                return date(self::DATETIME_FORMAT, intdiv($v, 1000));
            }
            if (is_string($v)) {
                $t = \DateTime::createFromFormat(self::DATETIME_FORMAT, $v);
                return $t !== false ? $t->format(self::DATETIME_FORMAT) : null;
            }
        }
        $rel = self::relative($expr, $createTime);
        if ($rel !== null) {
            return $rel;
        }
        $daily = self::dailyHHmm($expr, $createTime);
        if ($daily !== null) {
            return $daily;
        }
        $abs = \DateTime::createFromFormat(self::DATETIME_FORMAT, $expr);
        return $abs !== false ? $abs->format(self::DATETIME_FORMAT) : null;
    }

    /** 重复间隔（正整数 + s/m/h/d）；空/非法返回 null。 */
    public static function parseRepeat(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        if (strlen($s) < 2) {
            return null;
        }
        $unit = $s[strlen($s) - 1];
        if (!in_array($unit, ['s', 'm', 'h', 'd'], true)) {
            return null;
        }
        $prefix = trim(substr($s, 0, -1));
        if ($prefix === '' || !ctype_digit($prefix)) {
            return null;
        }
        $n = (int) $prefix;
        return $n > 0 ? ['n' => $n, 'unit' => $unit] : null;
    }

    public static function epochMillis(string $datetime): int
    {
        return (int) (strtotime($datetime) * 1000);
    }

    private static function relative(string $expr, string $createTime): ?string
    {
        if (strlen($expr) < 2) {
            return null;
        }
        $unit = $expr[strlen($expr) - 1];
        if (!in_array($unit, ['s', 'm', 'h', 'd'], true)) {
            return null;
        }
        $prefix = trim(substr($expr, 0, -1));
        if ($prefix !== '' && $prefix[0] === '+') {
            $prefix = substr($prefix, 1);
        }
        if ($prefix === '' || !ctype_digit($prefix)) {
            return null;
        }
        $base = strtotime($createTime);
        $n = (int) $prefix;
        $t = match ($unit) {
            's' => strtotime("+{$n} seconds", $base),
            'm' => strtotime("+{$n} minutes", $base),
            'h' => strtotime("+{$n} hours", $base),
            'd' => strtotime("+{$n} days", $base),
        };
        return $t !== false ? date(self::DATETIME_FORMAT, $t) : null;
    }

    private static function dailyHHmm(string $expr, string $createTime): ?string
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $expr, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mi = (int) $m[2];
        if ($h > 23 || $mi > 59) {
            return null;
        }
        $base = strtotime($createTime);
        $t = strtotime(date('Y-m-d', $base) . " {$m[1]}:{$m[2]}:00");
        if ($t === false) {
            return null;
        }
        if ($t < $base) {
            $t = strtotime('+1 day', $t);
        }
        return date(self::DATETIME_FORMAT, $t);
    }
}
