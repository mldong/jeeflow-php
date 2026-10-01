<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\Util\CcActorUtil;
use PHPUnit\Framework\TestCase;

/**
 * 参与者**删除腿**归属值判据（issues/137 §3-6 · owner 2026-10-02 拍「两形并集」· PHP 腿）。
 *
 * 立法逐字依据＝spec 06-facade.md **§processTask/removeTaskActor 语义 6** ＋ **§2.11** 写点表末行
 * （删除腿那一行）。判据本体只有一枚＝{@see CcActorUtil::deleteForms()}，本文件既钉这枚单点自己，
 * 也钉**内存仓** `removeTaskActor` 确实走了它。Java 基准腿＝`TaskActorDeleteFormsTest`，
 * SQL 仓一路＝{@see \Jeeflow\Tests\RepositoryPDO\PdoSqliteTaskActorDeleteFormsTest}
 * （两仓必须同答案，issues/117 场景 27）。
 *
 * **为什么是"两形并集"而不是只取一头**——1.8.36 之前八栈正好分成相反的两派，各自都有一种假成功：
 *
 *  - 只取 **trim 值**（本栈两仓此前的形状，csharp/rust/moon 同派）⇒ 门面按语义 6 交出的是**行上的原值**，
 *    历史脏行 `" 9101 "` 被削成 `9101`，真库（MySQL 8.0 NO PAD 排序规则）下那一行删不掉而门面报成功
 *    ——**被摘的人待办还在**（{@see self::testUntrimmedLegacyRowIsDeletedByItsRawForm()} 钉这一档，
 *    改前在本栈是**红的**：那正是本栈的假成功）；
 *  - 只取 **原值**（go/node/python/java 四栈九处的旧形状）⇒ 第三方绕过门面直连仓储传 `" 8601 "` 时
 *    删不掉写侧归一后落库的规范行 `8601`（issues/142 §9.2 那一路，
 *    {@see self::testNormalizedRowIsStillDeletedByTrimmedForm()} 钉这一档，改前改后都要绿）；
 *    且空值照喂 `DELETE`，把历史 `actor_id=''` 脏行批量误删
 *    （{@see self::testBlankInputNeverDeletesEmptyActorIdDirtyRow()} 与
 *    {@see self::testAllBlankInputIsNoOpAndNeverClearsAllActors()} 钉这两档）。
 *
 * 两形并集同时满足两侧，且按 §2.11 归一口径 `" 9101 "` 与 `9101` 本就是**同一个人**，两行都删才是
 * "摘掉这个人"的正确结果，不构成误删。**并集包含 trim 形**，因此 issues/142 B 批"删除位 trim"的
 * 既有测试无需反向改（`TaskActorBlankDroppedTest`／`RemoveTaskActor115Test` 那几格照绿）。
 *
 * ⚠️ 脏行夹具一律用**前导空格**：MySQL 5.7 的 PAD SPACE 只忽略尾部空格、8.0 的 NO PAD 连尾部也算，
 * 前导空格在**任何**排序规则下都与规范行不等，判据不会漂。种脏行必须**绕开写侧归一**
 * （`addTaskActor` 会 trim＋丢空，正常路径建不出脏行）——本文件直接经 `ProcessTask::create` 的
 * `$actorIds` 入参种进去。
 *
 * 门面级判据（守卫次序/权限/摘空/零留痕）在 `Jeeflow\Tests\WebContract\RemoveTaskActor115Test`，
 * 本文件不重复下探，只钉**删除腿**这一层。
 */
final class TaskActorDeleteFormsTest extends TestCase
{
    private const TASK = '7301';

    private InMemoryProcessRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryProcessRepository();
    }

    // ── 夹具辅助 ──

    /**
     * 直接种参与者行（**绕开写侧归一**）：`addTaskActor` 会 trim＋丢空，正常路径建不出
     * `" 9101 "`／`''` 这类脏行，故走 `ProcessTask::create` 的 `$actorIds` 入参。
     *
     * @param string[] $actorIds
     */
    private function seedActors(string $taskId, array $actorIds): void
    {
        $task = ProcessTask::create('inst-' . $taskId, 'task1', '上级审批', 0, 0, null,
            $actorIds, 'user1', null, true);
        $task->setTaskId($taskId);
        $this->repo->saveTask($task);
    }

    /** @return string[] 该任务当前的参与者行值（读的是仓里的真实行值，含未 trim 脏行原样） */
    private function actors(string $taskId): array
    {
        $task = $this->repo->findTaskById($taskId);
        return $task === null ? [] : array_values(array_map(strval(...), $task->getActorIds()));
    }

    // ═══ 一、单点纯函数：CcActorUtil::deleteForms ═══

    /** 义务①：`null`／空串／纯空白／制表／换行一律丢弃，一个都不进删除集合。 */
    public function testBlankAndNullElementsAreDroppedEntirely(): void
    {
        $this->assertSame([], CcActorUtil::deleteForms([null, '', '   ', "\t", "\r\n", null]),
            '空值一律丢弃（不得喂进 DELETE，否则误删 actor_id=\'\' 脏行）');
    }

    /**
     * 义务③：入参本身为 `null`／空数组 ⇒ 空数组（调用方据此早退，一条 `DELETE` 都不发）。
     *
     * ⚠️ PHP 与 java 基准的一处**类型面**差别：java `actorDeleteForms(String... raw)` 可收
     * `(String[]) null`，而本栈两仓 `removeTaskActor(int|string $taskId, array $actorIds)` 的
     * `array` 类型标注 ＋ `declare(strict_types=1)` 使**整个入参为 null** 在仓储层就是 `TypeError`
     * （不是"零删除"）。故 null 档钉在单点函数这一层；仓储层钉的是 `[]` 与"全空元素"两形
     * （见 {@see self::testAllBlankInputIsNoOpAndNeverClearsAllActors()}）。
     */
    public function testNullAndEmptyInputYieldEmptyArray(): void
    {
        $this->assertSame([], CcActorUtil::deleteForms(null), '入参 null ⇒ 空数组（早退，不发 DELETE）');
        $this->assertSame([], CcActorUtil::deleteForms([]), '入参空数组 ⇒ 空数组');
        $this->assertSame([], CcActorUtil::deleteForms(['', '  ', null, "\t"]),
            '全为空值 ⇒ 空数组（早退，不得退化成"清空该任务全部参与者"）');
    }

    /** 义务②：非空值**同时**产出「原值」与「trim 值」两形，**原值在前**（保序）。 */
    public function testNonBlankValueYieldsBothRawAndTrimmedForms(): void
    {
        $this->assertSame([' 9101 ', '9101'], CcActorUtil::deleteForms([' 9101 ']),
            '带空格的值必须两形都进集合：原值形删脏行、trim 形删规范行');
    }

    /** 已经 trim 过的值两形相同 ⇒ 只一份，不得让 `IN` 列表白白翻倍。 */
    public function testAlreadyTrimmedValueCollapsesToASingleForm(): void
    {
        $this->assertSame(['9101'], CcActorUtil::deleteForms(['9101']));
    }

    /**
     * 跨元素去重：`" 9101 "` 与 `"9101"` 是**同一个人**的两种写法（§2.11 归一口径），
     * 并集里只该出现那两形各一次，不得出现四份。
     *
     * ⚠️ 去重是按**字面**做的，不是按"trim 后相同"折叠原值形：`"  9101  "`（两个空格）与
     * `" 9101 "`（一个空格）是**两种不同的原值形**，都得进集合——库里可能正是其中任一种脏法，
     * 少带一种就删不掉那一行。
     */
    public function testDuplicateFormsCollapseAcrossElements(): void
    {
        $this->assertSame([' 9101 ', '9101'],
            CcActorUtil::deleteForms([' 9101 ', '9101', '9101', ' 9101 ']),
            '同一原值形重复给 ⇒ 折叠成一份');
        $this->assertSame([' 9101 ', '9101', '  9101  '],
            CcActorUtil::deleteForms([' 9101 ', '  9101  ']),
            '不同原值形（一个空格 / 两个空格）⇒ 各自保留，trim 形仍只一份');
    }

    /**
     * 反向哨兵（§2.11 硬要求④）：判据只吃"trim 后为空"，**不吃**"看起来像空"的正常 id。
     * `'0'` 必须留下（php 无回调 `array_filter`、python `if not x`、js `filter(Boolean)`
     * 都会吃掉它），且 `'0'` 与 `'00'` 是**两个不同的人**——`in_array` 必须带第三参 `true`，
     * 松散比较在 PHP 8 把数字串按数值比（`'0' == '00'`）⇒ 第二个人被静默丢掉
     * （issues/141 G2 在本栈的实测踩点）。
     */
    public function testZeroLikeIdsSurviveAndStayDistinct(): void
    {
        $forms = CcActorUtil::deleteForms(['0', '00', ' 0 ']);

        $this->assertContains('0', $forms, "'0' 是合法 id，不得被当成空值丢掉");
        $this->assertContains('00', $forms, "'00' 与 '0' 是两个人，不得折叠");
        $this->assertContains(' 0 ', $forms, "' 0 ' 的原值形也要在（删未 trim 脏行）");
        $this->assertSame(['0', '00', ' 0 '], $forms,
            "三个入参 ⇒ '0'/'00' 各一份 ＋ ' 0 ' 的原值形一份；' 0 ' 的 trim 形与既有 '0' 折叠");
    }

    /** 保序：多个人按入参顺序展开，便于各栈 `IN` 列表与日志逐字对照。 */
    public function testOrderIsPreservedAcrossMultipleActors(): void
    {
        $this->assertSame(['a', ' b ', 'b', 'c'], CcActorUtil::deleteForms(['a', ' b ', 'c']));
    }

    /**
     * 入参形态收敛与 {@see CcActorUtil::normalizeActors()} **同一枚**（`itemsOf`）：
     * 逗号串腿照样两形并集。本栈两仓的签名是 `array $actorIds`，串形到不了仓储，
     * 但单点是 public 的，第三方直接调用时两形收敛必须一致（别一处拆串一处不拆）。
     */
    public function testCommaStringShapeUsesTheSameConvergence(): void
    {
        $this->assertSame([' 9101 ', '9101', '9102'], CcActorUtil::deleteForms(' 9101 ,9102'),
            '逗号串腿：拆串与两形展开同一枚尺子');
        $this->assertSame([], CcActorUtil::deleteForms(',, ,'),
            '全是空元素的逗号串 ⇒ 空数组（explode 的空元素由判据丢掉）');
    }

    /**
     * `null` 元素**不得**被串化成 `"null"` 再去匹配——那会删掉一个真名叫 `null` 的人
     * （§2.11 写侧义务，删除腿同样适用）。
     */
    public function testNullElementIsNeverStringifiedIntoAForm(): void
    {
        $this->seedActors(self::TASK, ['null', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [null, 'leader']);

        $this->assertSame(['null'], $this->actors(self::TASK),
            'null 元素丢弃、不得串化成 "null" 参与匹配');
        $this->assertNotContains('null', CcActorUtil::deleteForms([null]),
            '单点侧同一判据：null 不产出 "null" 形');
    }

    // ═══ 二、仓储删除腿：内存仓 removeTaskActor 确实走了这枚单点 ═══

    /**
     * **N 档（假成功修复，本栈改前必红）**：库里躺着修复前落下的未 trim 历史脏行 `" 9101 "`，
     * 门面按语义 6 交出**行上的原值**去删 ⇒ 必须真删掉。
     *
     * 只取 trim 形的实现（本栈 1.8.36 之前、以及 csharp/rust/moon）在这一格会把 `" 9101 "`
     * 削成 `9101`，脏行留在库里、门面报成功——被摘的人待办还在。
     */
    public function testUntrimmedLegacyRowIsDeletedByItsRawForm(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ']);

        $this->assertSame(['leader'], $this->actors(self::TASK),
            '未 trim 的历史脏行必须被原值形删掉（否则是门面报成功的假成功）');
    }

    /**
     * **N 档（142 §9.2 那一路不破，改前改后都要绿）**：库里是写侧归一后的规范行 `8601`，
     * 第三方绕过门面直连仓储传 `" 8601 "` ⇒ 也必须删得掉（靠 trim 形命中）。
     *
     * 这一格是 issues/142 B 批"删除位 trim"的既有判据，**并集方案下照绿**——
     * 所以本轮不需要反向改任何 142 的既有测试。
     */
    public function testNormalizedRowIsStillDeletedByTrimmedForm(): void
    {
        $this->seedActors(self::TASK, ['8601', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 8601 ']);

        $this->assertSame(['leader'], $this->actors(self::TASK),
            '规范行由 trim 形命中（issues/142 §9.2 的既有判据不破）');
    }

    /** 两形同时在库里（脏行＋规范行并存）⇒ 同一个人名下两行都要摘掉，其余人一行不动。 */
    public function testBothFormsOfTheSamePersonAreRemovedTogether(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', '9101', 'leader', 'boss']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ']);

        $this->assertSame(['leader', 'boss'], $this->actors(self::TASK),
            '归一后是同一个人 ⇒ 两行都摘（§2.11 口径），其余参与人原样保留（语义 1）');
    }

    /** 两种脏法并存（一个空格 / 两个空格）⇒ 各自的原值形都得命中，缺一种就删不掉那一行。 */
    public function testTwoDifferentRawPaddingsAreBothRemoved(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', '  9101  ', '9101', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ', '  9101  ']);

        $this->assertSame(['leader'], $this->actors(self::TASK),
            '去重按字面做、不按"trim 后相同"折叠原值形 ⇒ 两种脏法都删得掉');
    }

    /**
     * **P 档（脏行保护）**：空串／纯空白／`null` 入参**一律不参与匹配**——历史 `actor_id=''`
     * 脏行是待另案清洗的取证痕迹，不得被一次空值入参批量做掉。
     */
    public function testBlankInputNeverDeletesEmptyActorIdDirtyRow(): void
    {
        $this->seedActors(self::TASK, ['', '   ', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['', '   ', null]);

        $this->assertSame(['', '   ', 'leader'], $this->actors(self::TASK),
            '空值入参一行都不许删（含历史 actor_id=\'\'/纯空白脏行）');
    }

    /**
     * **P 档（不得退化成清空）**：展开后为空 ⇒ 早退，**一次删除都不发生**。
     * 少了这一条，一次误传空串就会把该任务全部参与者清空，留下永远无人可办的死任务
     * （语义 5「至少需保留一名参与人」的仓储侧对偶）。
     *
     * ⚠️ 整个入参为 `null` 那一形在本栈是 `TypeError`（`array` 标注 ＋ strict_types），
     * 见 {@see self::testNullAndEmptyInputYieldEmptyArray()}；这里钉 `[]`／全空白／含 null 元素三形。
     */
    public function testAllBlankInputIsNoOpAndNeverClearsAllActors(): void
    {
        $this->seedActors(self::TASK, ['zhangsan', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['', '  ']);
        $this->repo->removeTaskActor(self::TASK, []);
        $this->repo->removeTaskActor(self::TASK, [null, "\t", "\r\n"]);

        $this->assertSame(['zhangsan', 'leader'], $this->actors(self::TASK),
            '空入参各形都是零删除，不得清空参与者');
    }

    /** 非参与者静默忽略（语义 7 幂等）：一个都没命中 ⇒ 零删除＋不抛异常。 */
    public function testUnknownActorIsSilentlyIgnored(): void
    {
        $this->seedActors(self::TASK, ['zhangsan', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['stranger', ' 9999 ']);

        $this->assertSame(['zhangsan', 'leader'], $this->actors(self::TASK),
            '非参与者静默忽略，既有参与者一行不动');
    }

    /** 任务不存在 ⇒ 零操作、不抛异常（删除腿对不存在的 taskId 是 no-op）。 */
    public function testUnknownTaskIsNoOpWithoutThrowing(): void
    {
        $this->repo->removeTaskActor('404404', [' 9101 ']);

        $this->assertSame([], $this->actors('404404'));
        $this->assertNull($this->repo->findTaskById('404404'), '不得凭空建出任务行');
    }

    /**
     * 仓储腿的哨兵（与单点同一档）：删 `'0'` **不得**连带删 `'00'`——库值那一侧是
     * `in_array(..., true)` **严格**比较；松散比较在 PHP 8 把 `'0' == '00'` 判真，
     * 会一次摘掉两个人（issues/141 G2 在本栈的实测踩点）。
     */
    public function testRemovingZeroDoesNotTakeDownDoubleZero(): void
    {
        $this->seedActors(self::TASK, ['0', '00', '1', '01', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['0']);

        $this->assertSame(['00', '1', '01', 'leader'], $this->actors(self::TASK),
            "'0'/'00'/'1'/'01' 是四个不同的人，删一个不许带走另一个");
    }

    /**
     * 空值入参不得删掉 `actor_id=''` 脏行，**同时**真人照常删得掉（两件事不互相挡）：
     * 脏行保护靠的是"空值不进 `IN`"，不是"整次调用早退"。
     */
    public function testBlankValuesAreSkippedWhileRealActorsAreStillRemoved(): void
    {
        $this->seedActors(self::TASK, ['', ' 9101 ', '9101', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['', null, '   ', ' 9101 ']);

        $this->assertSame(['', 'leader'], $this->actors(self::TASK),
            '脏行两形都摘掉，actor_id=\'\' 的历史脏行原样保留');
    }

    /** 删除腿只摘行、不动任务本身的其余状态（语义 1「只摘不加」的仓储侧对偶）。 */
    public function testRemoveOnlyTouchesActorRows(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', 'leader']);
        $before = $this->repo->findTaskById(self::TASK);
        $stateBefore = $before->getTaskState();
        $updateUserBefore = $before->getUpdateUser();
        $updateTimeBefore = $before->getUpdateTime();

        $this->repo->removeTaskActor(self::TASK, [' 9101 ']);

        $after = $this->repo->findTaskById(self::TASK);
        $this->assertSame($stateBefore, $after->getTaskState(), '不改任务态');
        $this->assertSame($updateUserBefore, $after->getUpdateUser(), '不覆写留痕列 update_user');
        $this->assertSame($updateTimeBefore, $after->getUpdateTime(), '不覆写留痕列 update_time');
        $this->assertSame(['leader'], $this->actors(self::TASK));
    }
}
