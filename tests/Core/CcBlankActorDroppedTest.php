<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Util\CcActorUtil;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 空抄送人不建 cc 行（issues/141 G10 · 2026-09-29 owner 拍「空不创建行」· PHP 栈，内存仓一路）。
 *
 * 立法逐字依据＝spec 06-facade.md §2.10：三条入口（发起 `f_ccActors`／办理 `tf_ccActors`／门面手动
 * `processInstance/createCCInstance`）解析抄送人集合时，**空串、纯空白、数组里的空元素一律丢弃**；
 * 丢完为空 ⇒ 不建任何 cc 行、也**不 fire CC_CREATE（码 4）**；逗号串与数组两种形态必须同判据。
 * 四点实现要求：①两层都挡（漏斗＋写侧）②落库与比较取 trim 后的值 ③手动腿丢完为空与既有
 * "空 actorIds" 档同判（不新造错误码/文案）④反向哨兵 `"0"` 不得被当空值吃掉。
 *
 * PHP 侧的两条本地病灶（都在本件钉住，基准 java `5fbd5ac` 没有对应形状）：
 *
 *  - 旧漏斗的**逗号串腿**用 `array_filter()` 假值判据 ⇒ `'0'` 这类"看起来像空"的正常 id 被吃掉
 *    （改前实测 `f_ccActors='0'` 落 **0 行**，正是要求④的反向哨兵在本栈的真实踩点）；
 *  - 旧漏斗的**数组腿**只做 `strval` 不过滤 ⇒ `['7801','','  ']` 实测落 **3 行**（含 `actor_id=''`
 *    与 `actor_id='  '`），手动腿 `['']` 同样落一行还 fire 码 4。逗号串修好了、数组腿漏修，
 *    正是 §2.10 末句"两种形态必须同判据"要抓的形状。
 *
 * SQL 仓一路见 `Jeeflow\Tests\RepositoryPDO\PdoSqliteCcWriteIdempotentTest` 的 G10 段，
 * 两仓必须给同一个答案（issues/117 场景 27）。
 */
final class CcBlankActorDroppedTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private BlankCcRecorder $rec;

    /** 第三方自实现仓储姿势的替身：写侧**不归一**，好让漏斗层单独可观测。 */
    private RawCcSpyRepository $spy;
    private JeeflowEngine $spyEngine;
    private JeeflowFacade $spyFacade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);

        $flowJson = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($flowJson, '01-simple.json 必须存在');
        $define = [
            'id' => '1', 'name' => 'simple', 'displayName' => '简单审批流程', 'type' => 'approval',
            'state' => 1, 'content' => $flowJson, 'version' => 1,
        ];
        $this->repo->addDefine($define);

        // 同一套夹具再挂一双"不归一的仓储"替身：分层冗余（漏斗＋两仓写侧都归一）会让
        // "只还原漏斗"照不出红（行面被写侧兜住），spy 格把漏斗层变成单独可观测的一层。
        $this->spy = new RawCcSpyRepository();
        $this->spy->addDefine($define);
        $this->spyEngine = new JeeflowEngine($this->spy);
        $this->spyFacade = new JeeflowFacade($this->spyEngine, $this->spy);

        $this->rec = new BlankCcRecorder();
        ProcessEventListenerRegistry::register($this->rec);
    }

    protected function tearDown(): void
    {
        ProcessEventListenerRegistry::clear();
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ── 夹具辅助 ──

    /** 发起腿：f_ccActors 原样塞进流程变量（不做任何预处理，才叫"实测这一条腿"）。 */
    private function startWith(mixed $fCcActors): string
    {
        $args = FlowData::create();
        $args->set(FlowConst::CC_ACTORS_START, $fCcActors);
        $instanceId = (string) $this->engine->startProcessInstanceById('1', 'zhangsan', $args)->getInstanceId();
        $this->assertNotSame('', $instanceId, '前置：实例必须已落库');
        return $instanceId;
    }

    /** 发起腿不带抄送参数（给手动腿/办理腿/写侧兜底那些格当底座）。 */
    private function startInstancePlain(): string
    {
        $instanceId = (string) $this->engine
            ->startProcessInstanceById('1', 'zhangsan', FlowData::create())->getInstanceId();
        $this->assertNotSame('', $instanceId, '前置：实例必须已落库');
        return $instanceId;
    }

    /** 办理腿：tf_ccActors 走 executeProcessTask（与发起腿共用 JeeflowEngine::handleCcActors）。 */
    private function executeWithCc(string $instanceId, mixed $tfCcActors): void
    {
        $apply = $this->findDoingTask($instanceId, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'zhangsan', FlowData::create()
            ->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY)
            ->set(FlowConst::CC_ACTORS, $tfCcActors));
    }

    /** 手动腿原始返回（全空白档要看它是否与"空 actorIds"同档，不能假定成功）。 */
    private function manualCcRaw(string $instanceId, mixed $actorIds): array
    {
        return $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId,
            'operator' => 'zhangsan',
            'actorIds' => $actorIds,
        ]);
    }

    /** 手动腿：断言成功（只有非空档才用）。 */
    private function manualCc(string $instanceId, array $actorIds): void
    {
        $resp = $this->manualCcRaw($instanceId, $actorIds);
        $this->assertSame(0, $resp['code'], '手动抄送应成功: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int, array<string, mixed>> 某实例的 cc 行（按落库顺序，取证走仓储而不是返回值） */
    private function ccRows(string $instanceId): array
    {
        return array_values(array_filter(
            $this->repo->getCcInstances(),
            fn(array $row) => (string) $row['processInstanceId'] === $instanceId,
        ));
    }

    /** @return string[] 某实例已有 cc 行的 actorId 序列（走 SPI 读侧） */
    private function ccActorIds(string $instanceId): array
    {
        return $this->repo->findCcActorIds($instanceId);
    }

    /** @return string[] 捕获到的 CC_CREATE 事件的 ccActorId 序列（保持 fire 顺序） */
    private function firedCcActorIds(): array
    {
        return array_map(fn(ProcessEvent $e) => (string) $e->getCcActorId(), $this->rec->events);
    }

    private function findDoingTask(string $instanceId, string $taskName): object
    {
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($instance, '前置：实例应存在');
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        $this->fail("未找到进行中任务 {$taskName}");
    }

    // ═══ 正向对照：非空抄送人照旧建行＋逐人 fire ═══

    public function testNonBlankCcActorsStillCreateRowsAndFire(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->manualCc($instanceId, ['7501', '7502']);

        $this->assertSame(['7501', '7502'], $this->ccActorIds($instanceId), '正向对照：非空抄送人照旧逐人落行');
        $this->assertSame(['7501', '7502'], $this->firedCcActorIds(), '正向对照：照旧逐人 fire 码 4');
        $this->assertCount(2, $this->ccRows($instanceId), '正向对照：2 行');
    }

    // ═══ 手动腿（第三条入口） ═══

    /**
     * 全空白集合 ⇒ 不建行、不 fire；并且与既有的"空 actorIds"档**逐字同判**
     * （同一个 code＋同一句文案，spec §2.10 实现要求③「不新造错误码或文案」）。
     */
    public function testManualLegAllBlankCreatesNoRowAndFiresNothing(): void
    {
        $instanceId = $this->startInstancePlain();

        $blank = $this->manualCcRaw($instanceId, ['', '   ']);

        $this->assertSame(0, count($this->ccRows($instanceId)), 'G10：全空白不得建 cc 行');
        $this->assertSame([], $this->ccActorIds($instanceId), 'G10：cc 行集合应为空');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：全空白不得 fire 码 4');
        $this->assertSame($this->manualCcRaw($instanceId, []), $blank,
            'G10：全空白与"空 actorIds"必须同判（响应逐字相同，不新造错误语义）');
    }

    /** 混着给 ⇒ 只丢空元素，有效的人照旧建行＋fire。 */
    public function testManualLegDropsBlankElementsKeepsValidOnes(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->manualCc($instanceId, ['7601', '', '  ', '7602']);

        $this->assertSame(['7601', '7602'], $this->ccActorIds($instanceId),
            'G10：数组里的空元素丢弃、有效元素保留');
        $this->assertCount(2, $this->ccRows($instanceId), "G10：不得落出 actor_id='' 的行");
        $this->assertSame(['7601', '7602'], $this->firedCcActorIds(), 'G10：fire 的入参只含有效的人');
    }

    /** 入参键整个缺席／给标量 ⇒ 同样落进"空 actorIds"档（手动腿三形同判据）。 */
    public function testManualLegBlankScalarFormsFallIntoTheEmptySlot(): void
    {
        $instanceId = $this->startInstancePlain();

        foreach ([null, '', '   ', 0, false] as $raw) {
            $resp = $this->manualCcRaw($instanceId, $raw);
            $this->assertSame(99999999, $resp['code'],
                'G10：空/标量入参与空 actorIds 同档：' . var_export($raw, true));
            $this->assertStringContainsString('不能为空', $resp['msg']);
        }
        $this->assertSame([], $this->ccActorIds($instanceId), 'G10：以上各档一律零行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：以上各档一律零 fire');
    }

    // ═══ 发起腿 f_ccActors（逗号串／数组两形同判据） ═══

    /** 空串：explode(',', '') 与 java 的 "".split(",") 同形 ⇒ 得到一个空元素而不是零个。 */
    public function testStartLegEmptyStringCreatesNoRow(): void
    {
        $instanceId = $this->startWith('');

        $this->assertSame(0, count($this->ccRows($instanceId)), 'G10：f_ccActors 给空串不得建 cc 行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：也不得 fire 码 4');
    }

    /** 纯空白串 ⇒ 同上不建行。 */
    public function testStartLegBlankStringCreatesNoRow(): void
    {
        $instanceId = $this->startWith('   ');

        $this->assertSame(0, count($this->ccRows($instanceId)), 'G10：f_ccActors 给纯空白不得建 cc 行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：也不得 fire 码 4');
    }

    /** 逗号串里的空元素（'7701,,7702'）丢弃，两个人照旧。 */
    public function testStartLegCommaStringDropsEmptyElement(): void
    {
        $instanceId = $this->startWith('7701,,7702');

        $this->assertSame(['7701', '7702'], $this->ccActorIds($instanceId), 'G10：逗号串空元素丢弃');
        $this->assertCount(2, $this->ccRows($instanceId), 'G10：只有两行');
        $this->assertSame(['7701', '7702'], $this->firedCcActorIds(), 'G10：逐有效人 fire');
    }

    /** 数组形态给空元素 ⇒ 与逗号串同一判据（本栈旧形状恰好漏修的就是这一腿）。 */
    public function testStartLegCollectionDropsBlankElements(): void
    {
        $instanceId = $this->startWith(['7801', '', '  ']);

        $this->assertSame(['7801'], $this->ccActorIds($instanceId), 'G10：数组形态与逗号串同判据');
        $this->assertSame(['7801'], $this->firedCcActorIds(), 'G10：数组形态只 fire 有效的人');
    }

    /** 数组整个是全空元素 ⇒ 丢完为空 ⇒ 不建行、不 fire。 */
    public function testStartLegAllBlankCollectionCreatesNoRow(): void
    {
        $instanceId = $this->startWith(['', '  ']);

        $this->assertSame(0, count($this->ccRows($instanceId)), 'G10：数组全空元素不得建 cc 行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：丢完为空整支不 fire 码 4');
    }

    // ═══ 办理腿 tf_ccActors ═══

    /** 办理腿给纯空白串 ⇒ 不建行、不 fire。 */
    public function testExecuteLegBlankStringCreatesNoRow(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->executeWithCc($instanceId, '   ');

        $this->assertSame(0, count($this->ccRows($instanceId)), 'G10：tf_ccActors 纯空白不得建 cc 行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：也不得 fire 码 4');
    }

    /** 办理腿尾随逗号 ⇒ 空元素丢弃，那个人照旧。 */
    public function testExecuteLegDropsBlankKeepsValidActors(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->executeWithCc($instanceId, '8201,');

        $this->assertSame(['8201'], $this->ccActorIds($instanceId), 'G10：办理腿尾随逗号不得建空行');
        $this->assertSame(['8201'], $this->firedCcActorIds(), 'G10：办理腿只 fire 有效的人');
    }

    /** 办理腿数组形态混给空元素 ⇒ 与逗号串同判据。 */
    public function testExecuteLegCollectionDropsBlankElements(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->executeWithCc($instanceId, ['8301', '', '  ', '8302']);

        $this->assertSame(['8301', '8302'], $this->ccActorIds($instanceId),
            'G10：办理腿数组形态同样只丢空的');
        $this->assertSame(['8301', '8302'], $this->firedCcActorIds(), 'G10：办理腿 fire 不含空值');
    }

    // ═══ trim：落库与比较一律取 trim 后的值（与 G2 写侧判重咬合） ═══

    /** 落库值取 trim 后的串：带空格的人与不带空格的人是同一个人。 */
    public function testCcActorValuesAreTrimmed(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->manualCc($instanceId, [' 8301 ', '8302']);

        $this->assertSame(['8301', '8302'], $this->ccActorIds($instanceId), 'G10：入库值应是 trim 后的串');
        $this->assertSame(['8301', '8302'], $this->firedCcActorIds(),
            'G10：fire 的入参也是 trim 后的串（否则监听器收到 " 8301 " 与库里的 "8301" 两个值）');
    }

    /** 先抄 '8401' 再抄 ' 8401 ' ⇒ 判重命中，仍是 1 行、0 新 fire（trim 与 G2 同一条尺子）。 */
    public function testPaddedValueHitsTheDedupRule(): void
    {
        $instanceId = $this->startInstancePlain();
        $this->manualCc($instanceId, ['8401']);
        $this->rec->reset();

        $this->manualCc($instanceId, [' 8401 ']);

        $this->assertSame(['8401'], $this->ccActorIds($instanceId), 'G10：带空格的同一人不得再建第二行');
        $this->assertCount(1, $this->ccRows($instanceId), 'G10：判重命中后仍是 1 行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：判重命中 ⇒ 不 fire 码 4');
    }

    // ═══ 写侧兜底：绕过引擎/门面直连仓储也建不出空行（内存仓一路） ═══

    /** 漏斗修了但写侧没修 ⇒ 直连仓储照样灌空值；本条钉两层里的第二层。 */
    public function testRepoWritePathAlsoDropsBlankActors(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->repo->createCcInstance($instanceId, 'zhangsan', ['', '   ', null, '8501']);

        $this->assertSame(['8501'], $this->ccActorIds($instanceId),
            'G10：内存仓写侧空串/纯空白/null 都不建行');
        $this->assertCount(1, $this->ccRows($instanceId), 'G10：写侧只落那一行');
    }

    /** createCcInstanceIfAbsent 返回的子集也不得含空值（子集直接拿去 fire）。 */
    public function testIfAbsentSubsetExcludesBlankActors(): void
    {
        $instanceId = $this->startInstancePlain();

        $created = $this->repo->createCcInstanceIfAbsent($instanceId, 'zhangsan', ['', '8601', '  ', ' 8602 ']);

        $this->assertSame(['8601', '8602'], $created, 'G10：实际新建子集只含有效且 trim 后的人');
        $this->assertSame(['8601', '8602'], $this->ccActorIds($instanceId), 'G10：子集与落库行一致');
    }

    // ═══ 反向哨兵：判据只吃空值，不吃"看起来像空"的正常 id ═══

    /**
     * spec §2.10 实现要求④。PHP 尤其需要这一条：本栈旧漏斗的逗号串腿是
     * `array_filter(array_map('trim', explode(...)))`——不带回调的 array_filter 按**假值**过滤
     * ⇒ 字符串 '0' 被吃掉（改前实测 `f_ccActors='0'` 落 0 行）。
     */
    public function testNormalActorIdsAreNotMistakenForBlank(): void
    {
        $instanceId = $this->startInstancePlain();

        $this->manualCc($instanceId, ['0', 'user-1']);

        $this->assertSame(['0', 'user-1'], $this->ccActorIds($instanceId),
            "G10 只丢空串/纯空白：'0' 这类正常 id 不得被吃掉");
        $this->assertCount(2, $this->ccRows($instanceId), '反向哨兵：照旧逐人落行');
        $this->assertCount(2, $this->rec->events, '反向哨兵：照旧逐人 fire');
    }

    /** 逗号串形态的同一枚哨兵（两形都得过）：'0' 与尾随逗号混在一串里。 */
    public function testZeroActorSurvivesTheCommaStringForm(): void
    {
        $instanceId = $this->startWith('0,');

        $this->assertSame(['0'], $this->ccActorIds($instanceId),
            "G10：逗号串里的 '0' 不是空值，必须照旧建行（array_filter 假值判据的踩点）");
        $this->assertSame(['0'], $this->firedCcActorIds(), 'G10：且照旧 fire 码 4');
    }

    // ═══ 漏斗层单独可观测：写侧换成"不归一"的第三方替身 ═══
    //
    // 分层冗余的必然结果（本轮实测）：只还原漏斗时，数组空元素那批格仍被两仓写侧兜住、照不出红
    // ——c#/node 两栈同病，处置是加 spy 格：把写侧换成**不归一**的替身仓储（第三方自实现的姿势），
    // 于是"行面"只剩漏斗这一层在挡，判据归位到它该待的那一层。

    /** 发起腿：数组混给空元素 ⇒ 漏斗必须自己丢干净（替身仓储不会二次兜底）。 */
    public function testFunnelLegDropsBlanksOnItsOwnViaSpyRepo(): void
    {
        // 替身哨兵：先证明它自己**不带**任何空值判据（否则下面三格的"牙"来自仓储而不是漏斗）
        $this->spy->createCcInstance('probe', 'op', ['', '  ', 'probe-1']);
        $this->assertSame(['', '  ', 'probe-1'], $this->spy->findCcActorIds('probe'),
            '前置：替身仓储原样落行＝第三方自实现仓储的最小姿势，不给漏斗兜底');
        $this->spy->reset();

        $instanceId = (string) $this->spyEngine
            ->startProcessInstanceById('1', 'zhangsan',
                FlowData::create()->set(FlowConst::CC_ACTORS_START, ['7801', '', '  ']))
            ->getInstanceId();

        $this->assertSame(['7801'], $this->spy->findCcActorIds($instanceId),
            'G10：漏斗层必须自己把空元素丢干净（写侧替身兜不住）');
        $this->assertCount(1, $this->spy->rawRows, 'G10：只剩一行——空串/纯空白不得进仓储入参');
        $this->assertSame([['7801']], $this->spy->received,
            'G10：递给仓储的入参就该是归一后的集合，不是原始集合');
        $this->assertSame(['7801'], $this->firedCcActorIds(), 'G10：fire 的入参也不含空值');
    }

    /** 办理腿：同一替身档（f_ 与 tf_ 共用 handleCcActors，两腿都得有牙）。 */
    public function testExecuteLegDropsBlanksOnItsOwnViaSpyRepo(): void
    {
        $instanceId = (string) $this->spyEngine
            ->startProcessInstanceById('1', 'zhangsan', FlowData::create())->getInstanceId();
        $apply = null;
        foreach ($this->spy->findInstanceById($instanceId)->getDoingTasks() as $t) {
            if ($t->getTaskName() === 'apply') $apply = $t;
        }
        $this->assertNotNull($apply, '前置：替身夹具也要有 apply 待办');

        $this->spyEngine->executeProcessTask($apply->getTaskId(), 'zhangsan', FlowData::create()
            ->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY)
            ->set(FlowConst::CC_ACTORS, ['8301', '', '  ', '8302']));

        $this->assertSame(['8301', '8302'], $this->spy->findCcActorIds($instanceId),
            'G10：办理腿的漏斗归一同样独立成立（不靠写侧兜底）');
        $this->assertSame([['8301', '8302']], $this->spy->received,
            'G10：递给仓储的是归一后的两个人，空元素已在漏斗丢掉');
    }

    /** 手动腿：丢完为空 ⇒ 整支不建行、**连仓储都不该被调到**（与"空 actorIds"同档）。 */
    public function testManualLegEmptyAfterDropNeverTouchesRepository(): void
    {
        $instanceId = (string) $this->spyEngine
            ->startProcessInstanceById('1', 'zhangsan', FlowData::create())->getInstanceId();

        $resp = $this->spyFacade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId, 'operator' => 'zhangsan', 'actorIds' => ['', '   '],
        ]);

        $this->assertSame(99999999, $resp['code'], 'G10：全空白与空 actorIds 同档');
        $this->assertSame([], $this->spy->received,
            'G10：空判定必须在门面这一层收口——递给仓储哪怕一次，替身仓储就会落空行');
        $this->assertSame([], $this->spy->rawRows, 'G10：cc 表零行');
        $this->assertSame([], $this->firedCcActorIds(), 'G10：零 fire');
    }

    // ═══ 归一函数本体的单测：三条入口与两仓写侧共用的这一支 ═══

    /** 判据单点复用的那一条腿自身：trim／丢空／保序／折叠重复／'0' 存活／非标量不入列。 */
    public function testNormalizeSingleLegContract(): void
    {
        $this->assertSame([], CcActorUtil::normalize(null), 'null ⇒ 空集');
        $this->assertSame([], CcActorUtil::normalize(''), '空串 ⇒ 空集（explode 得到的那一个空元素也丢掉）');
        $this->assertSame([], CcActorUtil::normalize('   '), '纯空白 ⇒ 空集');
        $this->assertSame([], CcActorUtil::normalize(['', '  ', null]), '全空元素 ⇒ 空集');
        $this->assertSame(['0'], CcActorUtil::normalize('0'), "反向哨兵：'0' 存活");
        $this->assertSame(['0'], CcActorUtil::normalize([0]), '反向哨兵：int 0 转串后存活');
        $this->assertSame(['a', 'b'], CcActorUtil::normalize('a,,b'), '逗号串空元素丢弃');
        $this->assertSame(['a', 'b'], CcActorUtil::normalize(' a , b '), '逗号串逐项 trim');
        $this->assertSame(['a', 'b'], CcActorUtil::normalize(['a', '', '  ', 'b']), '数组空元素丢弃');
        $this->assertSame(['b', 'a'], CcActorUtil::normalize(['b', 'a', ' b ', 'a']),
            '同一次调用内的重复折叠（含 trim 后同值），顺序保持');
        $this->assertSame(['x'], CcActorUtil::normalize(['x', ['nested'], new \stdClass()]),
            '非标量元素不是合法 actor id ⇒ 丢弃（也不撞 Array-to-string 告警）');
        $this->assertSame(['toString-1'], CcActorUtil::normalize([new Stringable141(' toString-1 ')]),
            '带 __toString 的对象取串并 trim');
        $this->assertSame(['7701', '7702'], CcActorUtil::normalize('7701,,7702,'),
            '逗号串与数组两形同判据（尾随逗号不建空行）');
        $this->assertSame([0, 1], array_keys(CcActorUtil::normalize(['a', '', '  ', 'b'])),
            '返回值必须是连续下标（旧 array_filter 腿留键洞，json 序列化会把数组变成对象）');
    }
}

/** 只收 CC_CREATE 的监听器（事件计数不受兄弟测试注册的监听器串味）。 */
class BlankCcRecorder implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        if ($event->getType() === ProcessEventTypeEnum::CC_CREATE) {
            $this->events[] = $event;
        }
    }

    public function reset(): void
    {
        $this->events = [];
    }
}

/** 带 __toString 的对象元素（归一函数那一档的哨兵）。 */
class Stringable141
{
    public function __construct(private string $v) {}

    public function __toString(): string
    {
        return $this->v;
    }
}

/**
 * 第三方自实现仓储的替身：两个 cc 写入口**一条判据都不做**（不丢空、不 trim、不判重），
 * 并把每次收到的原始入参留在 `received` 里取证。
 *
 * 为什么需要它（本轮实测教训）：G10 要求①"两层都挡"落地后，行面同时受漏斗与写侧两层保护，
 * 只还原其中一层时另一层会兜住、格子照不出红——把写侧换成这个不归一的替身，漏斗层就成了
 * 唯一在挡的那一层，它的判据是否成立这才真正可观测（c#/node 两栈同病，同一处置）。
 */
class RawCcSpyRepository extends InMemoryProcessRepository
{
    /** @var array<int, array<string, mixed>> 原样落下的行（未经任何归一） */
    public array $rawRows = [];

    /** @var array<int, string[]> 递进写入口 `createCcInstance` 的原始入参（IfAbsent 转调它，故只记一处） */
    public array $received = [];

    public function reset(): void
    {
        $this->rawRows = [];
        $this->received = [];
    }

    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void
    {
        $this->received[] = $actorIds;
        foreach ($actorIds as $actorId) {
            $this->rawRows[] = ['processInstanceId' => (string) $instanceId, 'actorId' => $actorId];
        }
    }

    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array
    {
        $this->createCcInstance($instanceId, $operator, $actorIds);
        // java default 方法的最小档：把全量入参当"实际新建子集"返回
        return $actorIds;
    }

    public function findCcActorIds(int|string $instanceId): array
    {
        $ids = [];
        foreach ($this->rawRows as $row) {
            if ((string) $row['processInstanceId'] === (string) $instanceId) {
                $ids[] = $row['actorId'];
            }
        }
        return $ids;
    }
}
