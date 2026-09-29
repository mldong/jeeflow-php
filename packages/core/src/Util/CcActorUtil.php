<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

/**
 * 抄送人集合归一 —— issues/141 G10「空抄送人不建 cc 行」（spec 06-facade.md §2.10）的**唯一一条判据**。
 *
 * 立法逐字依据：三条入口（发起 `f_ccActors`／办理 `tf_ccActors`／门面手动
 * `processInstance/createCCInstance`）解析抄送人集合时，**空串、纯空白、数组里的空元素一律丢弃**；
 * 丢完为空 ⇒ 不建任何 cc 行、也**不 fire CC_CREATE（码 4）**；逗号串与数组两种形态必须同判据。
 *
 * 为什么要有这一个类，而不是把判据抄在各调用点上（spec §2.10 的四点实现要求①「两层都挡」）：
 *
 *  - **漏斗层**：{@see \Jeeflow\Core\JeeflowEngine::handleCcActors()}（发起＋办理两腿共用）与
 *    {@see \Jeeflow\WebContract\JeeflowFacade::createCCInstance()}（手动腿）各调一次
 *    {@see self::normalize()}，逗号串与数组两形在这一步就同判据。
 *  - **写侧层**：{@see \Jeeflow\Core\Repository\InMemoryProcessRepository} 与 PDO 仓
 *    `Jeeflow\RepositoryPDO\PdoProcessRepository` 的 `createCcInstance()`／
 *    `createCcInstanceIfAbsent()` 各调一次 {@see self::normalizeList()}。只修漏斗的话，
 *    绕过引擎/门面**直连仓储**的调用方（集成层、第三方仓储消费者）照样能把空值灌进 `actor_id`——
 *    那正是 issues/129 那族「空 operator 读全库」的病根。
 *
 * ⚠️ PHP 侧的两条本地坑（java 基准没有、但同一条判据必须一并管住）：
 *
 *  1. `array_filter()` 不带回调时按**假值**过滤 ⇒ `'0'` 这类"看起来像空"的正常 id 会被吃掉。
 *     本仓旧漏斗的逗号串腿正是这个形状（`f_ccActors='0'` 实测落 0 行）。这里改成显式
 *     `trim(...) === ''` 判空，**只丢真空值**（spec §2.10 实现要求④反向哨兵）。
 *  2. `explode(',', '')` 与 java 的 `"".split(",")` 同形 ⇒ 得到**一个空元素**而不是零个，
 *     旧数组腿又完全不过滤 ⇒ 真落出 `actor_id=''`／`actor_id='  '` 的行。
 *
 * 落库与比较一律用 trim 后的串（实现要求②）：`" 123 "` 与 `"123"` 是同一个人，
 * 与 issues/141 G2 的写侧判重（`findCcActorIds`/`createCcInstanceIfAbsent`）咬合——不 trim
 * 会让同一人落两行，把 G2 打穿。
 *
 * 本类不依赖任何仓储/SPI 类型，也不挂到 `ProcessRepositoryInterface` 上：**接口成员一个都不加**
 * （PHP 接口不能有默认方法体，G2 那两个必选方法已经把"第三方自实现仓储升版致命"的账记进
 * changelog 了，本轮不再往接口上加深，见 spec 与本仓 `docs/` 待写 changelog）。
 */
final class CcActorUtil
{
    /**
     * 形态归一：把三条入口的原始值（逗号串 / 数组 / null / 其它）收成一条 `string[]`。
     *
     * - `null`／未传／**非字符串非数组**（int、bool、object）⇒ 空集
     *   ——与引擎漏斗旧行为一致（`f_ccActors` 给标量本来就整支忽略），手动腿由此与
     *   "空 actorIds" 落进同一档（沿用各调用点既有文案，本类不新造错误语义，实现要求③）。
     * - 字符串 ⇒ 按逗号拆开后再走 {@see self::normalizeList()}（`"a,,b"` ⇒ `['a','b']`）。
     * - 数组 ⇒ 逐元素走 {@see self::normalizeList()}。
     *
     * 逗号串与数组两形**必须**过这同一支，别只修一条腿（spec §2.10 末句）。
     *
     * @return string[] 归一后的抄送人集合（trim 后、无空值、同次调用内已折叠重复、保持顺序）
     */
    public static function normalize(mixed $ccUserIds): array
    {
        if (is_string($ccUserIds)) {
            return self::normalizeList(explode(',', $ccUserIds));
        }
        if (is_array($ccUserIds)) {
            return self::normalizeList($ccUserIds);
        }
        return [];
    }

    /**
     * 元素级归一（仓储写侧的兜底腿，也是 {@see self::normalize()} 的 internals）：
     * 逐元素转串 → trim → **空串/纯空白丢弃** → 同一次调用内的重复折叠，顺序保持。
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
            if ($actorId === null) {
                continue;
            }
            if (is_array($actorId)) {
                continue;
            }
            if (is_object($actorId) && !method_exists($actorId, '__toString')) {
                continue;
            }
            // ⚠️ 判空一律用 trim(...) === ''，不用 empty()/array_filter 假值判据：
            // '0' 是合法 actor id，假值判据会把它吃掉（issues/141 G10 实现要求④反向哨兵）。
            $trimmed = trim((string) $actorId);
            if ($trimmed === '') {
                continue;
            }
            // 同一次调用内的重复折叠用**严格**串比较（照 java List.contains 的 equals 语义），
            // 不与"是否已有 cc 行"那档的存储判据混在一起（那是 G2 的既有尺子，本轮不动）。
            if (!in_array($trimmed, $actors, true)) {
                $actors[] = $trimmed;
            }
        }
        return $actors;
    }
}
