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
 * 4. `enabled` **只认整数 1**（issues/130 案 A，owner 拍板"只认整数 1，与 java 一致"）：
 *    接受集合只有 `int 1` 一档，`'1'` / `1.0` / `true` 这类"等价写法"与 `0` / `2` / 脏值 / null
 *    一律停用。八栈阵营以 Java `ProcessSurrogate#isEffective` 的
 *    `Integer.valueOf(1).equals(enabled)` 为基准（Go / Rust / MoonBit / C# 同形）；
 *    PHP 旧实现走 `(int)` 强转，属"宽"阵营（同阵营还有 Python / Node），本案收窄。
 *    ⚠️ **默认方向不变**：脏值→停用是 `docs/spec/05-spi.md`「脏值默认方向」条款钉过的正确方向
 *    （PHP `(int)'abc'`→0），与 C# `ToInt` 回落 1（启用）的相反默认明确区分——
 *    本案只收窄**接受集合**，方向一字不改。
 *    真整数列被驱动回读成字符串（PDO 缓冲/模拟预处理）由**仓储边界**先还原，见 {@link hydrateEnabled()}；
 *    判据本身不吃串。
 * 5. （条款 1.4）多条并存时取**主键 id 最大**的那条来裁决，内存仓不得"取遍历到的首条"。
 *
 * 另有**写侧**判据 {@link normalizeEnabledArg()}（条款 5「写侧」：缺键→1、`''`/脏值→0 且不抛错），
 * 门面 `processSurrogate/save|update` 与 PDO 仓储落库前都过它，读写两侧同一套语义。
 */
final class SurrogateRule
{
    /**
     * 判据④：`enabled` **只有整数 1 生效**（issues/130 案 A）。
     *
     * 接受集合＝ `{int 1}` 一档，其余一律停用：
     * - `null` / `0` / 其它整数（`2`、`-1`）→ 停用；
     * - 字符串 `'1'` / `'1.0'` / `''` / `'abc'` → 停用（**不再 `(int)` 强转**，等价写法不吃；
     *   方向与旧实现一致，`docs/spec/05-spi.md`「脏值默认方向」条款本来就要求脏值停用）；
     * - 浮点 `1.0`、布尔 `true` → 停用（不因"值相等"被认成启用）。
     *
     * 对齐 Java `Integer.valueOf(1).equals(enabled)`，与 Go / Rust / MoonBit / C# 同阵营。
     * ⚠️ 整数列被驱动字符串化（PDO 模拟预处理给 `'1'`）**不在这里放行**——那是驱动边界的活，
     * 见 {@link hydrateEnabled()}；自定义 SPI 仓储传非整数则按本判据停用，须实现方自己先归一。
     */
    public static function isEnabled(mixed $value): bool
    {
        return is_int($value) && $value === 1;
    }

    /**
     * **驱动边界**还原：把整数列被驱动字符串化后的形态换回 `int`，再交 {@link isEnabled()} 裁决。
     *
     * 为什么需要：`enabled` 在现网是 `INT`/`tinyint(1)`，而 PHP 的 PDO 在缓冲查询下
     * （mysqlnd 默认行为；本仓 MySQL 套件显式 `ATTR_EMULATE_PREPARES => true`）**把数值列一律
     * 回读成 PHP 字符串**。判据④只认整数之后，不在驱动边界还原就会让 MySQL 宿主的委托整体判废
     * 且毫无告警（Java `rs.getInt`、Go `Scan(&int)`、C# `GetFieldValue<int>` 干的是同一件事）。
     *
     * ⚠️ 这不是把接受集合放宽回去：只认**规范整数串**（`-?(0|[1-9]\d*)`，无空格、无前导零、
     * 无小数点），`'1.0'` / `' 1'` / `'01'` / `'abc'` / `'1abc'` 以及 `true` / `1.0` 一律原样返回，
     * 由判据④判停用。调用点只允许在**内置 SQL 仓储**装行处（`PdoProcessExtRepository`）；
     * 业务方自定义 SPI 仓储传非整数属 issues/130 §2 的分叉源，按案 A 由实现侧自行归一。
     */
    public static function hydrateEnabled(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1) {
            return (int) $value;
        }
        return $value;
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
     * ⚠️ 读侧判据④自 issues/130 案 A 起**只认整数 1**，本方法正是它能成立于门面的前提：
     * `'1'` / `true` 这类等价写法在**写侧**就落成整数 1，读侧才照样认生效（归一只做一次，
     * 且做在写入处，不做在读侧判据里）。
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
        if (!self::isEnabled($row['enabled'] ?? null)) return false;      // 只认整数 1；'1'/1.0/true/0/2/脏值/null 均不生效（issues/130 案 A）
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
