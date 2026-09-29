<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

/**
 * **归属值（actor id）归一的唯一一条判据** —— 立法源头是 issues/141 G10「空抄送人不建 cc 行」
 * （spec 06-facade.md §2.10），issues/142 B 批按 §2.11 末段的"复用同一枚、不要再抄第二份"
 * 把同一判据搬到**任务参与者侧**（spec 06-facade.md §2.11）。
 *
 * 类名沿用 `CcActorUtil` 不改（改名会动到第三方 import 面，与"接口成员零增加"同一本账）；
 * 通用入口叫 {@see self::normalizeActors()} / {@see self::normalizeActor()}。
 *
 * 立法逐字依据：三条入口（发起 `f_ccActors`／办理 `tf_ccActors`／门面手动
 * `processInstance/createCCInstance`）解析抄送人集合时，**空串、纯空白、数组里的空元素一律丢弃**；
 * 丢完为空 ⇒ 不建任何 cc 行、也**不 fire CC_CREATE（码 4）**；逗号串与数组两种形态必须同判据。
 *
 * 为什么要有这一个类，而不是把判据抄在各调用点上（spec §2.10 的四点实现要求①「两层都挡」）：
 *
 *  - **漏斗层**：{@see \Jeeflow\Core\JeeflowEngine::handleCcActors()}（发起＋办理两腿共用）与
 *    {@see \Jeeflow\WebContract\JeeflowFacade::createCCInstance()}（手动腿）各调一次
 *    {@see self::normalize()}，逗号串与数组两形在这一步就同判据；
 *    {@see \Jeeflow\WebContract\JeeflowFacade::taskSurrogate()}（addCandidate 同体）调
 *    {@see self::normalizeActors()}，`processTask/transfer` 的 from/to 两个标量位调
 *    {@see self::normalizeActor()}（§2.11 表前两行）。
 *  - **写侧层**：{@see \Jeeflow\Core\Repository\InMemoryProcessRepository} 与 PDO 仓
 *    `Jeeflow\RepositoryPDO\PdoProcessRepository` 的 `createCcInstance()`／
 *    `createCcInstanceIfAbsent()`／**`addTaskActor()`** 各调一次 {@see self::normalizeList()}。
 *    只修漏斗的话，绕过引擎/门面**直连仓储**的调用方（集成层、第三方仓储消费者）照样能把空值灌进
 *    `actor_id`——那正是 issues/129 那族「空 operator 读全库」的病根。
 *
 * ⚠️ PHP 侧的两条本地坑（java 基准没有、但同一条判据必须一并管住）：
 *
 *  1. `array_filter()` 不带回调时按**假值**过滤 ⇒ `'0'` 这类"看起来像空"的正常 id 会被吃掉。
 *     本仓旧漏斗的逗号串腿正是这个形状（`f_ccActors='0'` 实测落 0 行），旧 `taskSurrogate` 的
 *     串腿是同一枚复本（`'0'` 加签后直接不见）。这里改成显式 `trim(...) === ''` 判空，
 *     **只丢真空值**（spec §2.10 实现要求④／§2.11 要求④反向哨兵）。
 *  2. `explode(',', '')` 与 java 的 `"".split(",")` 同形 ⇒ 得到**一个空元素**而不是零个，
 *     旧数组腿又完全不过滤 ⇒ 真落出 `actor_id=''`／`actor_id='  '` 的行。
 *
 * 落库与比较一律用 trim 后的串（实现要求②）：`" 123 "` 与 `"123"` 是同一个人，
 * 与 issues/141 G2 的写侧判重（`findCcActorIds`/`createCcInstanceIfAbsent`）咬合——不 trim
 * 会让同一人落两行，把 G2 打穿。
 *
 * ── issues/142 B 批（spec 06-facade.md §2.11「归属值写侧归一」）：同一枚判据搬到**任务侧** ──
 *
 * §2.11 末段要求"各栈的归一判据请复用 §2.10 已落地的那一枚单点……必要时改名成通用的
 * `normalizeActors`——不要再抄第二份"。本轮补出的两个**通用入口**（不是新判据，是同一判据的别名）：
 *
 *  - {@see self::normalizeActors()}：集合腿（`processTask/addCandidate`／`processTask/surrogate`
 *    的 `actorIds`，逗号串与数组两形同判据）；{@see self::normalize()} 是它在 cc 三支上的既有名字，
 *    现在是**一行转发**，两条腿不可能分叉。
 *  - {@see self::normalizeActor()}：标量腿（`processTask/transfer` 的 `fromActor`/`toActor`），
 *    判空尺子与集合腿逐字同源（都走 {@see self::toActorId()}）。
 *
 * 仓储写侧（两仓 `addTaskActor` 与 cc 的两个写入口）继续用元素级那一支 {@see self::normalizeList()}。
 *
 * 本类不依赖任何仓储/SPI 类型，也不挂到 `ProcessRepositoryInterface` 上：**接口成员一个都不加**
 * （PHP 接口不能有默认方法体，G2 那两个必选方法已经把"第三方自实现仓储升版致命"的账记进
 * changelog 了，本轮不再往接口上加深，见 spec 与本仓 `docs/` 待写 changelog）。
 */
final class CcActorUtil
{
    /**
     * 形态归一（cc 三支的既有入口名）：把原始值（逗号串 / 数组 / null / 其它）收成一条 `string[]`。
     *
     * issues/142 B 批起本方法是 {@see self::normalizeActors()} 的**别名**（同一枚判据，不抄第二份）：
     * 抄送三支与任务三支（addCandidate/surrogate）从此共用一支尺子。
     *
     * @return string[] 归一后的归属值集合（trim 后、无空值、同次调用内已折叠重复、保持顺序）
     */
    public static function normalize(mixed $ccUserIds): array
    {
        return self::normalizeActors($ccUserIds);
    }

    /**
     * **通用集合腿入口**（spec 06-facade.md §2.11 表第一行，issues/142 B 批）：
     * 逗号串与数组**两形同判据**——`processTask/addCandidate`／`processTask/surrogate` 的 `actorIds`
     * 与抄送三支共用这一支。
     *
     * - `null`／未传／**非字符串非数组**（int、bool、object）⇒ 空集
     *   ——与引擎漏斗旧行为一致（`f_ccActors` 给标量本来就整支忽略），调用方由此与
     *   "空 actorIds" 落进同一档（沿用各调用点既有文案，本类不新造错误语义，实现要求③）。
     * - 字符串 ⇒ 按逗号拆开后再走 {@see self::normalizeList()}（`"a,,b"` ⇒ `['a','b']`）。
     * - 数组 ⇒ 逐元素走 {@see self::normalizeList()}（元素不得被静默丢弃或串化成类型名）。
     *
     * 逗号串与数组两形**必须**过这同一支，别只修一条腿（spec §2.10 末句＋§2.11 表第一行）。
     *
     * @return string[]
     */
    public static function normalizeActors(mixed $raw): array
    {
        if (is_string($raw)) {
            return self::normalizeList(explode(',', $raw));
        }
        if (is_array($raw)) {
            return self::normalizeList($raw);
        }
        return [];
    }

    /**
     * **通用标量腿入口**（spec 06-facade.md §2.11 表第二行：`processTask/transfer` 的
     * `fromActor`/`toActor`）：归一后再用，空值档由调用方按自己既有的"必填"文案报错。
     *
     * 与集合腿共用同一条元素级判据（{@see self::toActorId()}），差别只在不拆逗号、不折叠：
     * trim ⇒ 空串/纯空白/`null`/数组/无 `__toString` 的 object ⇒ `''`。
     *
     * ⚠️ 返回 `''` 不代表"这个人是空"，代表"这个入参不是合法归属值"——调用方必须据此走
     * 既有的必填/缺参数档，严禁拿 `''` 当 id 往下落库（§2.11 主键档同理）。
     */
    public static function normalizeActor(mixed $raw): string
    {
        return self::toActorId($raw) ?? '';
    }

    /**
     * 元素级归一（**两仓写侧的兜底腿**，也是 {@see self::normalizeActors()} 的 internals）：
     * 逐元素转串 → trim → **空串/纯空白/null 丢弃** → 同一次调用内的重复折叠，顺序保持。
     *
     * `null` 元素直接丢弃；`array`／无 `__toString` 的 object 同样丢弃——它们不是合法的
     * actor id，而 `(string)` 强转这两类会撞 E_WARNING / Error（本仓 `failOnWarning` 会红），
     * java 侧 `Object::toString()` 会把它们变成 `"[x]"` 这种垃圾 id 落库，两边都不该有。
     *
     * @param array<mixed> $raw 原始集合（键任意，返回值重新下标）
     * @return string[]
     */
    public static function normalizeList(array $raw): array
    {
        $actors = [];
        foreach ($raw as $actorId) {
            $normalized = self::toActorId($actorId);
            if ($normalized === null) {
                continue;
            }
            // 同一次调用内的重复折叠用**严格**串比较（照 java List.contains 的 equals 语义），
            // 不与"是否已有行"那档的存储判据混在一起（那是 G2 的既有尺子，本轮不动）。
            // ⚠️ 松散比较在 PHP 8 里把数字串按数值比：'0' == '00'、'1' == '01' ⇒ 第二个人被静默丢掉
            // （issues/141 G2 实测踩点，commit a45dd13 收口；任务侧 addTaskActor 同一条尺子）。
            if (!in_array($normalized, $actors, true)) {
                $actors[] = $normalized;
            }
        }
        return $actors;
    }

    /**
     * 唯一的一条元素级判据（集合腿与标量腿都走这里，不存在第二份）。
     *
     * @return string|null 归一后的归属值；`null` ＝ 不是合法归属值（空串/纯空白/null/数组/非标量对象）
     */
    private static function toActorId(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return null;
        }
        if (is_object($raw) && !method_exists($raw, '__toString')) {
            return null;
        }
        // ⚠️ 判空一律用 trim(...) === ''，不用 empty()/无回调 array_filter 的假值判据：
        // '0' 是合法 actor id，假值判据会把它吃掉（issues/141 G10 与 issues/142 §2.11 要求④反向哨兵）。
        $trimmed = trim((string) $raw);
        return $trimmed === '' ? null : $trimmed;
    }
}
