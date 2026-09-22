<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

/**
 * 委托查询**四判据**共用谓词（issues/116 批次 D）
 *
 * 契约依据：`docs/spec/06-facade.md` §4.5 条款 5/6 + `docs/spec/05-spi.md`「扩展仓储」小节 +
 * `docs/spec/08-compliance.md` 用例 27。条款 6 要求**内存仓与 SQL 仓两条路径都要满足条款 5**
 * ——同栈两仓对同一份数据给出不同结论即缺陷。本类是 PHP 侧"两仓同答案"的唯一维护点：
 * 内存仓 `InMemoryProcessExtRepository::getSurrogate` 与 SQL 仓
 * `PdoProcessExtRepository::getSurrogate` **都只走 {@link isEffective} 这一份单条裁决**。
 *
 * 取行 + 裁决的顺序（条款 1.4 + issues/123，两仓同形）：
 * **先**在流程作用域内按主键 id 取**最新一条**（{@link pickLatest}，不带生效判据过滤），
 * **再**由四判据裁决这一条（{@link isEffective}）；不生效即"未命中"，
 * **不回落到更旧那条**；但精确作用域判否（含池空）后**仍要看**全流程作用域的最新一条
 * ——条款 1.4 后半句，Java 既有测试 JdbcProcessExtRepositoryTest#testSurrogateCrudAndGet 钉住。
 * ⚠️ 顺序反过来（先按判据过滤、剩下的才取最新）就是 issues/123 的病灶。
 *
 * 四条判据：
 * 1. 空 `processName` = 全部流程兜底（先按当前流程名精确查，该作用域**一条记录都没有**
 *    才查 `process_name` 为 NULL/''；精确作用域里有记录就由它裁决）；
 * 2. 时间窗 `start_time <= now <= end_time`，**一侧为 NULL/空即该侧不限**；
 * 3. 自委托过滤 `surrogate <> operator`（自己委托给自己不生效）；
 * 4. `enabled` **只有 1 生效**，脏值（不可解析为整数）不得默认当启用
 *    ——PHP 既有方向 `(int)'abc'` → 0 即停用，本类保持该方向；
 * 5. （条款 1.4）多条并存时取**主键 id 最大**的那条来裁决，内存仓不得"取遍历到的首条"。
 *
 * 另有**写侧**判据 {@link normalizeEnabledArg()}（条款 5「写侧」：缺键→1、`''`/脏值→0 且不抛错），
 * 门面 `processSurrogate/save|update` 与 PDO 仓储落库前都过它，读写两侧同一套语义。
 */
final class SurrogateRule
{
    /**
     * 判据④：`enabled` 只有整数 1 生效，脏值不得当启用。
     *
     * - `null` / `0` / 其它整数（含 2、-1）→ 停用；
     * - 不可解析为整数的脏值（`'abc'` / `''`）→ 停用（对齐 PHP `(int)'abc'`→0 的既有方向，
     *   与 C# `ToInt` 回落 1 的相反默认明确区分）；
     * - `'1'` / `'1.0'` / `1.0` / `true` → 启用（对齐 SQL 侧 `enabled = 1` 的隐式转换）。
     */
    public static function isEnabled(mixed $value): bool
    {
        if ($value === null) return false;
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_float($value)) return $value === 1.0;
        $s = trim((string) $value);
        if ($s === '' || !is_numeric($s)) return false;
        return (int) $s === 1;
    }

    /**
     * **写侧**判据（06 §4.5 条款 5「写侧」）：`processSurrogate/save|update` 的 `enabled` 入参归一。
     *
     * 与上面 {@link isEnabled} 是两件事——那条管"库里这行生效吗"，这条管"门面把入参落成什么值"：
     * - 键缺失 / 显式 `null` → **1**（save/update 参数表「enabled 默认 1」）；
     * - 布尔按 `true→1 / false→0`；
     * - 可解析为整数的（`1` / `'1'` / `0` / `'2'`）按该整数原样落库；
     * - 空串 `''` 与**不可解析为整数的脏值**（`'abc'` / `'1x'` / `'1abc'`）→ **0**，且**不得抛错**。
     *
     * ⚠️ 这里不能直接用 PHP 的 `(int)` 强转：`(int)'1abc'` → **1**（宽松前缀解析），
     * 与契约「不可解析为整数的脏值落 0」方向相反（Node 首版则是 `toInt` 直接抛错打成 500）。
     * 故先做 `is_numeric` 判定再转。落 0 的行读侧（{@link isEnabled}）必然查不到生效委托——
     * 两侧同一套语义，跨层对拍用例即钉这一点。
     */
    public static function normalizeEnabledArg(mixed $raw): int
    {
        if ($raw === null) return 1;                                  // 缺键 / 显式 null → 契约默认「启用」
        if (is_bool($raw)) return $raw ? 1 : 0;                        // 布尔 true→1 / false→0
        if (is_int($raw) || is_float($raw)) return (int) $raw;
        if (is_array($raw) || is_object($raw)) return 0;               // 结构体入参：不可解析 → 停用，不抛错
        $s = trim((string) $raw);
        if ($s === '' || !is_numeric($s)) return 0;                    // '' / 'abc' / '1x' / '1abc' → 停用
        return (int) $s;
    }

    /**
     * 判据③：自委托过滤——被委托人与授权人同一人时不生效。
     *
     * `$surrogate` 为 null/空串也按"不生效"处理（无意义的台账行不该把任务推给空 id）。
     */
    public static function isSelfDelegation(string $operator, mixed $surrogate): bool
    {
        $agent = is_string($surrogate) ? trim($surrogate) : (string) ($surrogate ?? '');
        return $agent === '' || $agent === trim($operator);
    }

    /**
     * 判据②：时间窗判定，`$startTime` / `$endTime` 为 null 或空串表示**该侧不限**。
     *
     * `$at` 与窗边界一律先归一为 `Y-m-d H:i:s` 文本再做字典序比较（该格式字典序即时序）；
     * `DateTimeInterface` 入参也支持（内存仓不要求调用方先格式化）。
     */
    public static function inWindow(mixed $startTime, mixed $endTime, string $at): bool
    {
        $start = self::timeText($startTime);
        $end = self::timeText($endTime);
        if ($start !== null && $at < $start) return false;
        if ($end !== null && $at > $end) return false;
        return true;
    }

    /** 时间边界归一：null / 空串 / 不可读 → null（= 该侧不限）。 */
    public static function timeText(mixed $value): ?string
    {
        if ($value === null) return null;
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        if (is_int($value)) return date('Y-m-d H:i:s', $value);
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    /**
     * 单条裁决（规范 06 §4.5 条款 5 的四判据 + issues/123）：这一行委托此刻对该授权人生效吗。
     *
     * ⚠️ **调用方必须先按主键 id 选出「该作用域内最新一条」再问本方法**（条款 1.4）——
     * 本方法只裁决单条，不做多条择优，也**不回落**：最新一条不生效就是"未命中"。
     * 反过来写（先用判据把记录滤掉、剩下的才取最新）等价于"历史上留过一条窗内委托就永久生效"，
     * 用户随后改停用/挪窗口/自委托都不算数——issues/123 里 13 栈 L2-17/L2-18 全红的成因。
     *
     * 内存仓与 PDO 仓**共用本方法**（08-compliance 用例 27 要求双仓同答案）：行键两仓不同形
     * （内存 camelCase、PDO snake_case），故时间窗边界两个键名都认。
     *
     * @param array|null $row       委托行（null = 该作用域内没有记录）
     * @param string     $operator  授权人（判自委托）
     * @param string|null $at       判定时刻（`Y-m-d H:i:s`；null = 不做窗口比较）
     */
    public static function isEffective(?array $row, string $operator, ?string $at): bool
    {
        if ($row === null) return false;
        if (!self::isEnabled($row['enabled'] ?? null)) return false;      // 只认 1；0/2/脏值/null 均不生效
        if (self::isSelfDelegation($operator, $row['surrogate'] ?? null)) return false; // 含代理人为空
        if ($at === null) return true;
        return self::inWindow($row['start_time'] ?? $row['startTime'] ?? null,
            $row['end_time'] ?? $row['endTime'] ?? null, $at);
    }

    /**
     * 判据⑤（条款 1.4）：候选行里取**主键 id 最大**的一条 —— 与 SQL 侧 `ORDER BY id DESC` 同答案。
     *
     * 内存仓若"取遍历到的首条"就是插入序（最早一条），与 SQL 仓相反，属缺陷。
     * 无候选返回 null。
     *
     * ⚠️ 条款 1.4 + issues/123 的取行顺序是「**先**在本作用域内取 id 最大的一条，**再**交
     * {@link isEffective} 裁决」；本方法因此**不带**任何生效判据过滤。
     *
     * @param array<array-key, array> $rows 候选行
     * @param string $idKey 主键键名（内存仓 camelCase `id`，PDO 行同为 `id`）
     */
    public static function pickLatest(array $rows, string $idKey = 'id'): ?array
    {
        $best = null;
        $bestId = null;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = (string) ($row[$idKey] ?? '');
            if ($best === null || self::compareIds($id, (string) $bestId) > 0) {
                $best = $row;
                $bestId = $id;
            }
        }
        return $best;
    }

    /**
     * 主键序比较：纯数字串（自增/雪花 id）按**数值**序（先比长度再字典序，避免 float 精度丢失），
     * 其余按字符串序。雪花 id 达 19 位，`(int)`/`(float)` 比较会撞精度，故不转数值。
     */
    public static function compareIds(string $a, string $b): int
    {
        if ($a !== '' && $b !== '' && ctype_digit($a) && ctype_digit($b)) {
            return strlen($a) !== strlen($b) ? strlen($a) <=> strlen($b) : strcmp($a, $b);
        }
        return strcmp($a, $b);
    }
}
