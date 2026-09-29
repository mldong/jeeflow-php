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
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 抄送写侧判重＝幂等空操作（issues/141 G2 · PHP 栈，内存仓一路 ＋ 引擎/门面两条腿）。
 *
 * 立法逐字依据＝spec 06-facade.md §4「写侧判重＝幂等空操作（owner 2026-09-29 拍）」＋
 * spec 11.2 原则 1「码值表达发生了什么事实」。同一 `(实例, 被抄送人)` 已存在 cc 行时，
 * 再次抄送必须：①不新增行 ②不重置未读状态 ③不更新原行时间 ④**不 fire CC_CREATE（码 4）**。
 * 逐人 fire 的入参换成**实际新建的子集**，子集为空整支不 fire（不空转、也不照旧全量 fire）。
 * 查询侧不引入 DISTINCT、历史重复行不清理（owner 明确接受既成事实），故这里只钉写侧。
 *
 * 判重义务覆盖三条入口（spec §11.7「三条入口共用一支」）：发起 `f_ccActors`、办理
 * `tf_ccActors`（同走 `JeeflowEngine::handleCcActors`）、门面手动 `processInstance/createCCInstance`。
 * SQL 仓一路见 `Jeeflow\Tests\RepositoryPDO\PdoSqliteCcWriteIdempotentTest`，
 * 两仓必须给同一个答案（issues/117 场景 27 那把尺子）。
 *
 * ⚠️ 本仓 cc 读写在 issues/138 刚统一成"行来自实例表、operator＝流程发起人"——那一档形状
 * 由 `CcListRowShapeTest` 钉住，本件只加写侧判重，不回退它。
 */
final class CcWriteIdempotentTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private RecordingCcListener $ccListener;

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
        $this->repo->addDefine([
            'id' => '1', 'name' => 'simple', 'displayName' => '简单审批流程', 'type' => 'approval',
            'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);

        $this->ccListener = new RecordingCcListener();
        ProcessEventListenerRegistry::register($this->ccListener);
    }

    protected function tearDown(): void
    {
        ProcessEventListenerRegistry::clear();
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ── 夹具辅助 ──

    private function startInstance(?string $fCcActors = null): string
    {
        $args = FlowData::create();
        if ($fCcActors !== null) {
            $args->set(FlowConst::CC_ACTORS_START, $fCcActors);
        }
        $inst = $this->engine->startProcessInstanceById('1', 'zhangsan', $args);
        $instanceId = (string) $inst->getInstanceId();
        $this->assertNotSame('', $instanceId, '前置：实例必须已落库');
        return $instanceId;
    }

    /** 门面手动抄送腿（第三条入口）。 */
    private function manualCc(string $instanceId, string ...$actorIds): void
    {
        $resp = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId,
            'operator' => 'zhangsan',
            'actorIds' => $actorIds,
        ]);
        $this->assertSame(0, $resp['code'], '手动抄送应成功: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int, array<string, mixed>> 某实例的 cc 行（按落库顺序） */
    private function ccRows(string $instanceId): array
    {
        return array_values(array_filter(
            $this->repo->getCcInstances(),
            fn(array $row) => (string) $row['processInstanceId'] === $instanceId,
        ));
    }

    /** @return string[] 某实例已有 cc 行的 actorId 序列（走 SPI 读侧，不是测试专用后门） */
    private function ccActorIds(string $instanceId): array
    {
        return $this->repo->findCcActorIds($instanceId);
    }

    private function ccRow(string $instanceId, string $actorId): ?array
    {
        foreach ($this->ccRows($instanceId) as $row) {
            if ((string) $row['actorId'] === $actorId) return $row;
        }
        return null;
    }

    /** @return string[] 捕获到的 CC_CREATE 事件的 ccActorId 序列（保持 fire 顺序） */
    private function firedCcActorIds(): array
    {
        return array_map(fn(ProcessEvent $e) => (string) $e->getCcActorId(), $this->ccListener->ccCreateEvents());
    }

    /**
     * 等到墙钟跨过一秒刻度——本栈 cc 行的时间是 `date('Y-m-d H:i:s')`（秒精度），
     * 不像 java 的 LocalDateTime 有纳秒，`usleep(10)` 那种 tick 在 php 分不出"刷新/没刷新"。
     * 跨秒之后，任何"重复抄送把时间刷成 now"的实现都必然留下可断言的差值。
     */
    private static function awaitSecondBoundary(): void
    {
        $t0 = date('Y-m-d H:i:s');
        $deadline = microtime(true) + 2.0;
        while (date('Y-m-d H:i:s') === $t0 && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertNotSame($t0, date('Y-m-d H:i:s'),
            '前置：必须跨过秒刻度，否则"原行时间未刷新"那一档没有鉴别力');
    }

    // ═══ 正向对照：全新的一次抄送照旧建行＋逐人 fire ═══

    public function testFirstCcStillCreatesRowsAndFiresPerActor(): void
    {
        $instanceId = $this->startInstance();

        $this->manualCc($instanceId, '6101', '6102');

        $this->assertSame(['6101', '6102'], $this->ccActorIds($instanceId), '全新抄送应逐人落行');
        $this->assertCount(2, $this->ccRows($instanceId), '全新抄送应落 2 行');
        $this->assertSame(['6101', '6102'], $this->firedCcActorIds(),
            '全新抄送应逐人 fire CC_CREATE（码 4），顺序与入参一致');
        foreach ($this->ccListener->ccCreateEvents() as $e) {
            $this->assertSame($instanceId, (string) $e->getSourceId(), '码 4 的 sourceId 应为 instanceId');
        }
        $row = $this->ccRow($instanceId, '6101');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['state'], '新行应是未读（state=0）');
    }

    // ═══ 四档：重复抄送是幂等空操作 ═══

    /** ①不新增行 ＋ ④不 fire 码 4：手动腿连发两次同一个人。 */
    public function testRepeatCcForSameActorAddsNoRowAndFiresNothing(): void
    {
        $instanceId = $this->startInstance();
        $this->manualCc($instanceId, '6201');
        $this->assertSame(['6201'], $this->ccActorIds($instanceId), '首次抄送落 1 行');
        $this->assertCount(1, $this->ccListener->ccCreateEvents(), '首次抄送 fire 1 次');

        $this->ccListener->reset();
        $this->manualCc($instanceId, '6201');

        $this->assertSame(['6201'], $this->ccActorIds($instanceId), '①重复抄送不得新增行');
        $this->assertCount(1, $this->ccRows($instanceId), '①重复抄送后行数仍是 1');
        $this->assertSame([], $this->ccListener->ccCreateEvents(),
            '④没发生创建就不得发码 4（spec 11.2 原则 1「码=事实」）');
    }

    /** ②不重置未读：先置已读，再重复抄送，state 必须仍是已读。 */
    public function testRepeatCcDoesNotResetUnreadState(): void
    {
        $instanceId = $this->startInstance();
        $this->manualCc($instanceId, '6301');
        $read = $this->facade->flow('processInstance/updateCCStatus', [
            'processInstanceId' => $instanceId, 'operator' => '6301',
        ]);
        $this->assertSame(0, $read['code'], json_encode($read, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->ccRow($instanceId, '6301')['state'], '置读后 state 应为 1');

        $this->manualCc($instanceId, '6301');

        $this->assertSame(1, $this->ccRow($instanceId, '6301')['state'],
            '②重复抄送不得把已读抹回未读（owner 2026-09-29 明确"不需要重置"）');
        $this->assertCount(1, $this->ccRows($instanceId),
            '②配套：也不得冒出第二条未读新行（只看第一行的 state 会放过"追加一行"的假修）');
    }

    /** ③不更新原行时间：createTime 与 updateTime 逐字不变（未读状态也一样保持）。 */
    public function testRepeatCcDoesNotTouchOriginalRowTimes(): void
    {
        $instanceId = $this->startInstance();
        $this->manualCc($instanceId, '6401');
        $created = $this->ccRow($instanceId, '6401');
        $this->assertNotNull($created, '前置：cc 行应已建');
        $this->assertArrayHasKey('createTime', $created, 'cc 行须带 create_time 列（对齐 wf_process_cc_instance）');
        $this->assertArrayHasKey('updateTime', $created, 'cc 行须带 update_time 列（对齐 wf_process_cc_instance）');
        $createTime = $created['createTime'];
        $this->assertSame($createTime, $created['updateTime'], '建行时两列同值');

        self::awaitSecondBoundary();
        // 先证明 update_time 是**会动**的列：已读要刷它（否则下面的"没被刷新"是恒真空、毫无鉴别力）
        $this->facade->flow('processInstance/updateCCStatus', [
            'processInstanceId' => $instanceId, 'operator' => '6401',
        ]);
        $read = $this->ccRow($instanceId, '6401');
        $this->assertNotSame($createTime, $read['updateTime'],
            '前置哨兵：updateCcStatus 确实会刷 update_time（本栈与 PDO 仓 `SET state=1, update_time=?` 同形）');

        self::awaitSecondBoundary();
        $this->manualCc($instanceId, '6401');

        $after = $this->ccRow($instanceId, '6401');
        $this->assertSame($createTime, $after['createTime'], '③重复抄送不得刷新原行 createTime');
        $this->assertSame($read['updateTime'], $after['updateTime'], '③重复抄送不得刷新原行 updateTime');
        $this->assertCount(1, $this->ccRows($instanceId), '③之后仍只有一行');
    }

    /** ④的子集档：第二次同时给「已知人＋新人」⇒ 只为新人建行、只为新人 fire 一次。 */
    public function testRepeatCcFiresOnlyForNewlyCreatedSubset(): void
    {
        $instanceId = $this->startInstance();
        $this->manualCc($instanceId, '6501', '6502');
        $this->assertSame(['6501', '6502'], $this->ccActorIds($instanceId), '首轮 2 行');
        $this->assertSame(['6501', '6502'], $this->firedCcActorIds(), '首轮 fire 2 次');

        $this->ccListener->reset();
        $this->manualCc($instanceId, '6501', '6503');

        $this->assertSame(['6503'], $this->firedCcActorIds(),
            '逐人 fire 的入参应是实际新建的子集（第二次只抄新人时事件里只出现新人）');
        $this->assertSame(['6501', '6502', '6503'], $this->ccActorIds($instanceId),
            '实际新建的 cc 行也应只多那一行');
    }

    /** 同一次调用里重复给同一个人 ⇒ 也按幂等处理（一行一次提醒）。 */
    public function testDuplicateWithinOneCallCollapses(): void
    {
        $instanceId = $this->startInstance();

        $this->manualCc($instanceId, '6601', '6601');

        $this->assertSame(['6601'], $this->ccActorIds($instanceId),
            '同一次调用内的重复不应新增第二行');
        $this->assertCount(1, $this->ccRows($instanceId), '同一次调用内的重复只落一行');
        $this->assertSame(['6601'], $this->firedCcActorIds(), '同一次调用内的重复只 fire 一次');
    }

    /** SPI 直测：createCcInstanceIfAbsent 的返回值就是"实际新建子集"。 */
    public function testCreateCcInstanceIfAbsentReturnsTheCreatedSubset(): void
    {
        $instanceId = $this->startInstance();

        $first = $this->repo->createCcInstanceIfAbsent($instanceId, 'zhangsan', ['7001', '7002']);
        $this->assertSame(['7001', '7002'], $first, '全新的人 ⇒ 全量都是"实际新建"');

        $second = $this->repo->createCcInstanceIfAbsent($instanceId, 'zhangsan', ['7001', '7002', '7003']);
        $this->assertSame(['7003'], $second, '第二次只新建那一个新人');

        $third = $this->repo->createCcInstanceIfAbsent($instanceId, 'zhangsan', ['7001', '7002']);
        $this->assertSame([], $third, '全是已知人 ⇒ 子集为空，调用方整支不 fire');
        $this->assertCount(3, $this->ccRows($instanceId), '三次调用后仍是 3 行');
    }

    // ═══ 引擎腿（f_ccActors ／ tf_ccActors）同一条判据 ═══

    /** 办理腿与发起腿重叠的那个人不得再建行、不得再 fire；新人照旧。 */
    public function testEngineCcLegsShareTheSameDedupRule(): void
    {
        $instanceId = $this->startInstance('7101');
        $this->assertSame(['7101'], $this->ccActorIds($instanceId), '发起腿落 1 行');
        $this->assertCount(1, $this->ccListener->ccCreateEvents(), '发起腿 fire 1 次');

        $this->ccListener->reset();
        $apply = $this->findDoingTask($instanceId, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'zhangsan', FlowData::create()
            ->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY)
            ->set(FlowConst::CC_ACTORS, '7101,7102'));

        $this->assertSame(['7101', '7102'], $this->ccActorIds($instanceId),
            '办理腿只为新人 7102 建行（7101 已有行）——f_ 与 tf_ 两腿共用同一条判据');
        $this->assertSame(['7102'], $this->firedCcActorIds(), '办理腿只 fire 实际新建的子集');
    }

    /**
     * 两形态入参（数组逐元素 / 逗号串）共用同一条判重腿：
     * 发起腿给数组、办理腿给逗号串，重叠的人仍只有一行、只 fire 一次。
     */
    public function testStringAndCollectionFormsShareTheDedupRule(): void
    {
        $instanceId = (string) $this->engine
            ->startProcessInstanceById('1', 'zhangsan',
                FlowData::create()->set(FlowConst::CC_ACTORS_START, ['7201', '7202']))
            ->getInstanceId();
        $this->assertSame(['7201', '7202'], $this->ccActorIds($instanceId), '数组形态照旧逐人建行');
        $this->assertSame(['7201', '7202'], $this->firedCcActorIds(), '数组形态照旧逐人 fire');

        $this->ccListener->reset();
        $apply = $this->findDoingTask($instanceId, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'zhangsan', FlowData::create()
            ->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY)
            ->set(FlowConst::CC_ACTORS, '7201,7203'));

        $this->assertSame(['7201', '7202', '7203'], $this->ccActorIds($instanceId),
            '逗号串形态与数组形态判重同一条');
        $this->assertSame(['7203'], $this->firedCcActorIds(), '两形态混用也只为新人 fire');
    }

    /** 反向哨兵：判重不得把"没抄送过的人"也吃掉——不同实例上的同一个人各自建行。 */
    public function testDedupIsScopedToInstanceNotGlobal(): void
    {
        $first = $this->startInstance();
        $second = $this->startInstance();
        $this->assertNotSame($first, $second, '前置：两个实例 id 必须不同（判重作用域是按实例）');
        $this->ccListener->reset();

        $this->manualCc($first, '6701');
        $this->manualCc($second, '6701');

        $this->assertSame(['6701'], $this->ccActorIds($first), '实例一应有自己的 cc 行');
        $this->assertSame(['6701'], $this->ccActorIds($second), '实例二不受实例一影响，同一个人照样建行');
        $this->assertCount(2, $this->ccListener->ccCreateEvents(), '两个实例各 fire 一次');
    }

    /** 查询侧不引入去重（owner 拍「接受既成事实」）：库里已有的历史重复行照旧逐行放出。 */
    public function testQuerySideDoesNotDedupLegacyRows(): void
    {
        $instanceId = $this->startInstance();
        // 造历史脏行：绕过判重直插两条同 (实例, 人) 的行，模拟 G2 之前留下的既成事实
        $this->injectLegacyDuplicateRows($instanceId, '6801');

        $this->assertCount(2, $this->ccRows($instanceId),
            '查询侧不加 DISTINCT：历史重复行必须照旧放出两行（G2 只保证今后不再新增）');
        // 再抄一次同一个人：判重在写侧生效，脏行保持 2 条不被追加
        $this->manualCc($instanceId, '6801');
        $this->assertCount(2, $this->ccRows($instanceId), '写侧判重也不得把脏行当"要清理"的对象——只跳过新增');
    }

    /**
     * 绕过仓储写侧判重、直插两条重复行（用测试自带的旁路写入，模拟 G2 落库前的历史脏数据）。
     */
    private function injectLegacyDuplicateRows(string $instanceId, string $actorId): void
    {
        $seed = function (array $row): void {
            (function () use ($row): void {
                $this->ccInstances[] = $row;
            })->call($this->repo);
        };
        $now = date('Y-m-d H:i:s');
        $seed(['processInstanceId' => $instanceId, 'actorId' => $actorId, 'state' => 0,
               'createUser' => 'zhangsan', 'createTime' => $now, 'updateTime' => $now]);
        $seed(['processInstanceId' => $instanceId, 'actorId' => $actorId, 'state' => 0,
               'createUser' => 'zhangsan', 'createTime' => $now, 'updateTime' => $now]);
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
}

/** 只收 CC_CREATE 的监听器（事件计数不受兄弟事件串味）。 */
class RecordingCcListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        if ($event->getType() === ProcessEventTypeEnum::CC_CREATE) {
            $this->events[] = $event;
        }
    }

    /** @return ProcessEvent[] */
    public function ccCreateEvents(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
