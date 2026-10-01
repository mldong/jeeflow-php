<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\UserProviderInterface;

/**
 * 流程工具 —— 对齐 Java FlowUtil
 */
final class FlowUtil
{
    public const PERMISSION_PREFIX = 'PERMISSION_';
    public const PERM_EDIT = 2;

    /** 本栈日期时间落库格式（与 ProcessTask::create 写 create_time 的 `date()` 同档） */
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * 解析期待完成时间（issues/126 案 A，逐字对齐 Java `FlowUtil.processTime` /
     * C# `FlowUtil.ProcessTime`）——三档，**顺序不能变**：
     *
     *  1. **变量档**：`args` 里存在**键名等于 expr 原串**的项 ⇒ 用该项的值
     *     （DateTimeInterface / 毫秒整数时间戳 / "Y-m-d H:i:s" 字符串；字符串解析失败 ⇒ null）。
     *     值为其它类型（bool / float / array / object）⇒ **落穿**继续走第 2、3 档。
     *  2. **相对档**：expr 以 `s|m|h|d` 结尾且前缀是整数 ⇒ now + N 秒/分/时/天
     *     （`d` 走日历加天，不乘 86400 秒）。空白只在前缀那一侧被裁（issues/137 E，见 `tryInt`）——
     *     **本档判单位符用的仍是 `$expr` 原串末位**，`"2h "` 认不出单位照旧落穿，别在这里改成先 trim 整串。
     *  3. **绝对档**：把 expr 本身按 "Y-m-d H:i:s" 解析 ⇒ 时刻；失败 ⇒ null。
     *
     * 三条**故意的**实现取舍（都不是随手写的）：
     *  - 相对档前缀不是整数（节点误配成 `xh`）时**落穿**到绝对档、最终多为 null，
     *    而不是像 Java 那样 `Integer.parseInt` 抛异常打断建单——本轮八栈统一按 C# 的
     *    `int.TryParse` 落穿口径（配置写错不该让流程卡死）；要改成"跟 Java 一样抛"必须八栈同批改。
     *  - 解析失败一律返回 **null**（这一列留空），**绝不退回 now()**——那正是 issues/126 病灶的形状。
     *  - 变量档命中但值类型不认识时是"落穿"，不是"提前 return null"（与 Java/C# 同）。
     *
     * 取时来源与本栈写 `create_time` 同一来源（PHP 默认时区，秒级精度）；本栈 `date.timezone`
     * 未设是**另一条已知遗留（120-C）**，不在本轮范围，别在这里顺手改。
     *
     * @param string|null   $expr 节点配的到期表达式；null/去空白后为空 ⇒ 返回 null（列留空）
     * @param FlowData|null $args 变量源（建单＝实例变量；回退新建＝随行拷贝那份）
     * @return string|null "Y-m-d H:i:s"；null ⇒ 这一列保持 NULL
     */
    public static function processTime(?string $expr, ?FlowData $args = null): ?string
    {
        if ($expr === null) {
            return null;
        }
        $args ??= FlowData::create();

        // ── 第 1 档：变量档（优先于相对档——args 里真有个键叫 "2h" 时取变量值） ──
        if ($args->has($expr)) {
            $v = $args->get($expr);
            if ($v instanceof \DateTimeInterface) {
                return $v->format(self::DATETIME_FORMAT);
            }
            // 毫秒时间戳：只认 int（PHP 无 Java 的 Long/Integer 之分，JSON 大整数解出 float 时按"类型不认识"落穿）
            // 秒级 floor：本栈 datetime 列是秒精度，与 Java 落库时的精度一致
            if (is_int($v)) {
                return self::fromEpochSeconds(intdiv($v, 1000));
            }
            if (is_string($v)) {
                // 解析失败 ⇒ null（**返回**，不落穿；对齐 Java/C# 的字符串分支）
                return self::parseAbsolute($v);
            }
            // 其它类型 ⇒ 落穿
        }

        if (trim($expr) === '') {
            return null;
        }

        // ── 第 2 档：相对档（后缀大小写敏感，与 Java endsWith("s") 逐字一致） ──
        $unit = substr($expr, -1);
        $offset = ['s' => 1, 'm' => 60, 'h' => 3600][$unit] ?? null;
        if ($offset !== null) {
            $n = self::tryInt(substr($expr, 0, -1));
            if ($n !== null) {
                return self::fromEpochSeconds(time() + $n * $offset);
            }
            // 前缀非整数 ⇒ 落穿到绝对档（见方法注释的"故意的"取舍）
        } elseif ($unit === 'd') {
            $n = self::tryInt(substr($expr, 0, -1));
            if ($n !== null) {
                // 日历加天（对齐 Java Calendar.add(DAY_OF_MONTH)，不乘 86400 秒）
                return self::now()->modify(sprintf('%+d days', $n))->format(self::DATETIME_FORMAT);
            }
        }

        // ── 第 3 档：绝对档 ──
        return self::parseAbsolute($expr);
    }

    /** 本栈取时来源（与 ProcessTask::create 的 `date('Y-m-d H:i:s')` 同一时钟/同一时区） */
    private static function now(): \DateTimeImmutable
    {
        return self::fromEpoch(time());
    }

    private static function fromEpochSeconds(int $epochSeconds): string
    {
        return self::fromEpoch($epochSeconds)->format(self::DATETIME_FORMAT);
    }

    /** Unix 秒 → 本地时区墙钟（对齐 Java `toLocalDateTime(new Date(ms))` 的转本地时区一档） */
    private static function fromEpoch(int $epochSeconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $epochSeconds))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * 相对档前缀整数判定（落穿式，不抛）。位宽超 int 视同"解析不出"，
     * 与 C# `int.TryParse` 对溢出返回 false 同档。
     *
     * issues/137 D（owner 2026-10-01 拍"判非负" · spec 04 §相对档前缀须非负整数）：**负数前缀同样算
     * 解析不出** ⇒ 落穿到第 3 档绝对时刻 ⇒ 仍解析不出就 NULL。放行 `-5h` 会算出一个**过去**的时刻，
     * 新建的任务行当场就是逾期——比"没配到期时间"更难发现，也与 processTime 方法注释里"任何一档都
     * 不得退化成取当前时间"（那正是 issues/126 的病灶形状）同向。
     * `s/m/h/d` 四档都经本函数（上面两处调用：`['s'=>1,'m'=>60,'h'=>3600]` 一档、`d` 一档），
     * 且 `d` 走 DateTime 日历加天而非乘 86400（负数＝历日倒退，是另一条独立病灶）⇒ 这一处判据把
     * 四档一起拦住，没有绕过本函数的旁路。
     *
     * 只裁负、**不裁加号**：正则里的 `+` 保留不动。各栈整数解析（python `[+-]?`、node `[-+]?\d+`、
     * java `Integer.parseInt`）都收 '+'，裁掉加号等于新造一处跨栈分叉 ⇒ `+2h` 在本栈仍是 now+7200s。
     *
     * issues/137 E（owner 2026-10-01 拍"统一 trim" · spec 04 §相对档前缀允许两端空白，基准＝java
     * `FlowUtil.parseIntOrNull` `bf1f401`）：**判整数之前**先裁掉前缀的两端空白。到期表达式是设计器
     * 手填/JSON 搬运的字符串，`" 2h"` 夹一个空格是常态，而各栈整数解析对空白的容忍度天然不同（go 在
     * `strconv.Atoi` 前显式 `TrimSpace`、rust `.trim()`、.NET `int.TryParse` 与 python `int()` 默认就收
     * 前后空白），本栈的正则是 `^…$` 锚死的、原本不吃 ⇒ 不 trim 就是"同一份流程定义别家有到期时间、
     * php 没有"。正则一个字不改（`[+-]?` 保留）。
     *
     * ⚠️ **裁的位置只到前缀，不动整串**：单位符判定（上面 `$unit = substr($expr, -1)`）与绝对档
     * 拿到的仍是**原串**——`"2h "` 的末位是空格、认不出单位，照旧按误配落穿；把整串去空白是
     * "顺手把裁空白做成裁容错"，那是另一件没立过法的事。变量档的键名同样不 trim（`$args->has($expr)`
     * 吃原串），否则 `" dueAt "` 会突然取到值，改的是另一件事。判负（137 D）在 trim 之后仍生效：
     * `" -5h"` ⇒ 裁成 `-5` ⇒ 命中 `$n < 0` ⇒ 落穿。
     */
    private static function tryInt(string $s): ?int
    {
        // issues/137 E：只裁前缀两端空白，之后的判定（整数格式 → 非负）一概不变
        $s = trim($s);
        if (preg_match('/^[+-]?\d{1,18}$/', $s) !== 1) {
            return null;
        }
        $n = (int) $s;
        return $n < 0 ? null : $n;
    }

    /**
     * 严格 "Y-m-d H:i:s" 解析（对齐 C# `DateTime.TryParseExact`）：
     * 字段缺失/多余尾串/越界（如 13 月）一律算解析不出 ⇒ null。
     * ⚠️ 这里比 Java 的 `SimpleDateFormat`（lenient，会把 "2026-13-45" 滚成 2027-02-18）严格，
     * 与本轮统一的 C# 口径一致：误配留空，不造一个谁都没要的时刻。
     */
    private static function parseAbsolute(string $text): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!' . self::DATETIME_FORMAT, $text);
        if ($d === false) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false
            && (($errors['error_count'] ?? 0) > 0 || ($errors['warning_count'] ?? 0) > 0)) {
            return null;
        }
        return $d->format(self::DATETIME_FORMAT);
    }

    /**
     * 注入发起人用户信息到流程变量（对齐 Java FlowUtil.addUserInfoToArgs）
     *
     * 通过 ServiceContext 获取 UserProviderInterface，查询用户信息后注入 u_* 变量。
     * flow.auto / flow.admin 跳过注入；UserProvider 未注册时静默跳过。
     */
    public static function addUserInfoToArgs(string $operator, FlowData $args): void
    {
        if (FlowConst::AUTO_ID === $operator || FlowConst::ADMIN_ID === $operator) {
            return;
        }
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        if ($userProvider === null) {
            return;
        }
        $u = $userProvider->getUser($operator);
        if ($u === null) {
            return;
        }
        $args->set(FlowConst::USER_USER_ID, $u['userId'] ?? $operator);
        $args->set(FlowConst::USER_REAL_NAME, $u['realName'] ?? $operator);
        $args->set(FlowConst::USER_DEPT_ID, $u['deptId'] ?? null);
        $args->set(FlowConst::USER_DEPT_NAME, $u['deptName'] ?? null);
        $args->set(FlowConst::USER_POST_ID, $u['postId'] ?? null);
        $args->set(FlowConst::USER_POST_NAME, $u['postName'] ?? null);
    }

    /**
     * 生成自动标题（对齐 Java FlowUtil.addAutoGenTitle）
     *
     * 格式："{realName}的{displayName}-{yyyy-MM-dd HH:mm:ss}"
     */
    public static function addAutoGenTitle(string $displayName, FlowData $args): void
    {
        $realName = $args->get(FlowConst::USER_REAL_NAME) ?? '';
        $title = $realName . '的' . $displayName . '-' . date('Y-m-d H:i:s');
        $args->set(FlowConst::AUTO_GEN_TITLE, $title);
    }

    /**
     * 指定任务名是否为第一个任务节点（start 的直接后继）—— 对齐 Java FlowUtil.isFirstTaskName
     */
    public static function isFirstTaskName(ProcessModel $model, string $taskName): bool
    {
        $start = $model->getStart();
        if ($start === null) {
            return false;
        }
        foreach ($start->getOutputs() as $tm) {
            if (strcasecmp($tm->getTo(), $taskName) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function filterFieldByPerm(FlowData $args, ProcessModel $model, string $taskName): void
    {
        $node = $model->getNode($taskName);
        if (!$node instanceof TaskModel) {
            return;
        }
        $ext = $node->getExt();
        $hasPerm = false;
        foreach ($ext->keys() as $k) {
            if (str_starts_with((string) $k, self::PERMISSION_PREFIX)) {
                $hasPerm = true;
                break;
            }
        }
        if (!$hasPerm) {
            return;
        }
        foreach ($args->keys() as $key) {
            if (!str_starts_with($key, FlowConst::FORM_DATA_PREFIX) || strlen($key) <= strlen(FlowConst::FORM_DATA_PREFIX)) {
                continue;
            }
            $fieldName = substr($key, strlen(FlowConst::FORM_DATA_PREFIX));
            if (!self::isEditable($ext, $fieldName)) {
                $args->remove($key);
            }
        }
    }

    public static function isEditable(FlowData $fieldPerm, string $fieldName): bool
    {
        $perm = $fieldPerm->get(self::PERMISSION_PREFIX . FlowConst::FORM_DATA_PREFIX . $fieldName);
        if ($perm === null) {
            $perm = $fieldPerm->get(self::PERMISSION_PREFIX . $fieldName);
        }
        if ($perm === null) {
            return true;
        }
        return (int) $perm === self::PERM_EDIT;
    }
}
