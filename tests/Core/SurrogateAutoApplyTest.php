<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Interceptor\NullSurrogateInterceptor;
use Jeeflow\Core\Interceptor\SurrogateInterceptor;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/116 批次 D · 委托代理**运行期自动生效**（引擎内置、默认开启）—— PHP 内存路
 *
 * 契约：`docs/spec/06-facade.md` §4.5 运行期语义条款 1/1.1/1.2/1.3/1.4/2/3/4/5/6 +
 * `docs/spec/05-spi.md`「SurrogateInterceptor（委托生效，内置实现）」+
 * `docs/spec/08-compliance.md` 用例 26。
 *
 * 断言一律落在**读回值**上（`findTaskById()->getActorIds()` 即仓储持久形态 +
 * `processTask/todoList` 走仓储分页读回），不是"返回码 0"，也不是内存里 new 出来的对象。
 * 条款 2 的 ⚠️（Java 首版走 `addTaskActor` 事后补写、打在未分配的 taskId 上静默无效）
 * 由 `tests/RepositoryPDO/PdoSqliteSurrogateTest.php` 在真表 `wf_process_task_actor` 上补证。
 */
class SurrogateAutoApplyTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private InMemoryProcessExtRepository $extRepo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        $this->newHarness();
    }

    /**
     * 建一套干净基建。setUp 只是它的别名——需要"每轮台账互不污染"的循环用例
     * （如 enabled 写侧入参矩阵）每轮都重建一次，否则上一轮留下的委托会串到下一轮。
     */
    private function newHarness(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->extRepo = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        // 门面构造把扩展仓储桥接进 ServiceContext（issues/116：引擎内置行为只认容器里的仓储）
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->extRepo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ═══ 正向：代理人进集合 + 原授权人保留 ═══

    /**
     * 条款 1「覆盖全部建任务路径」+ 条款 2「并入参与者集合本身」+ 条款 1.2「不级联」。
     *
     * multi-task 拓扑：apply(applicant=user1) → task1(leader) → task2(manager) → task3(boss)。
     * 委托 user1→lisi（精确流程名）、leader→wangwu（空流程名兜底）、wangwu→carol（代理人自身委托，不得展开）。
     */
    public function testAgentJoinedOnStartAndOnTransitionWithoutCascade(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        $this->surrogate('user1', 'lisi', 'multi-task');
        $this->surrogate('leader', 'wangwu', '');            // 判据①：空 processName 全流程兜底
        $this->surrogate('wangwu', 'carol', 'multi-task');   // 代理人自身的委托

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        // 发起那一刻建的任务（apply）：原人保留 + 代理人并入，且**不级联**到 lisi 自己的委托
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null, $instanceId, 'apply');
        $this->assertNotNull($apply, '前置：apply 任务应已落库');
        $this->assertSame(['user1', 'lisi'], $this->actorsOf((string) $apply->getTaskId()),
            '发起任务：代理人应随任务一起进参与者集合，原授权人保留');

        // 流转推进出的新单（task1）：只挂发起一处就会漏掉这条（条款 1 实测易犯）
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing, '前置：推进后应停在 task1');
        $task1 = $doing[0];
        $this->assertSame('task1', $task1->getTaskName());
        $this->assertSame(['leader', 'wangwu'], $this->actorsOf((string) $task1->getTaskId()),
            '推进出的新单也应并入代理人（不级联：wangwu→carol 不展开）');
        $this->assertNotContains('carol', $this->actorsOf((string) $task1->getTaskId()),
            '条款 1.2：代理人自身的委托严禁级联展开（环状委托会死循环）');

        // 读回侧：代理人与授权人**任一可办**（两条待办都在）
        $this->assertContains((string) $task1->getTaskId(), $this->todoIds('wangwu'));
        $this->assertContains((string) $task1->getTaskId(), $this->todoIds('leader'),
            '委托不是转办：原授权人待办必须保留');
    }

    /**
     * 条款 1 的独立取证：**办理推进**产出的新单（不是发起那一刻的单）。
     *
     * 只给 task1 的参与者 leader 配委托、apply 的参与者 user1 不配 → 本用例唯一可能的代理人
     * 来源就是 `persistTasks` 那条挂点。挂点若只写在发起处，这里就是红的
     * （回退自证记录的失败断言原文即「推进出的新单也应并入代理人」）。
     */
    public function testAgentJoinedOnTaskCreatedByTransitionOnly(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        $this->surrogate('leader', 'wangwu', 'multi-task');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $apply = $this->taskOf(null, $instanceId, 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1'], $this->actorsOf((string) $apply->getTaskId()),
            '前置：user1 没配委托，发起单不该多出参与者');

        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing);
        $this->assertSame(['leader', 'wangwu'], $this->actorsOf((string) $doing[0]->getTaskId()),
            '推进出的新单也应并入代理人');
        $this->assertContains((string) $doing[0]->getTaskId(), $this->todoIds('wangwu'),
            'wangwu 待办里应能看到这张推进出的单');
    }

    /**
     * 条款 1「覆盖回退」：**ROLLBACK** 退回上一步产生的新待办同样要应用委托。
     *
     * 独立性（3a 的标准）：委托只挂在 `manager` 一人身上，且**不断言中途 task2 的参与者**
     * （那条属"办理推进"路径，另有自己的用例）——故只有 ROLLBACK 这条建单路径失能时，
     * 本用例才红，其它路径失能都动不到它。
     */
    public function testRollbackCreatedTaskAlsoJoinsAgent(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        // 台账延后到 task1 办结之后再配（见下）：委托挂在 task1 的原办结人 leader 身上——
        // issues/121 P2 血缘版回退复活的正是那一行，参与者＝该行办结人，不是执行回退的 manager。

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $t1 = (string) $this->onlyDoing($instanceId)->getTaskId();
        $adv = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t1, 'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $adv['code'], json_encode($adv, JSON_UNESCAPED_UNICODE));
        $t2 = $this->onlyDoing($instanceId);
        $this->assertSame('task2', $t2->getTaskName(), '前置：应已推进到 task2');
        $this->surrogate('leader', 'rAgent', 'multi-task');

        $back = $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $t2->getTaskId(), 'operator' => 'manager',
            'submitType' => SubmitType::ROLLBACK,
        ]);
        $this->assertSame(0, $back['code'], json_encode($back, JSON_UNESCAPED_UNICODE));
        $rollbackTask = $this->onlyDoing($instanceId);
        $this->assertSame('task1', $rollbackTask->getTaskName(), '前置：ROLLBACK 应退回 task1 产生新待办');
        $this->assertSame(['leader', 'rAgent'], $this->actorsOf((string) $rollbackTask->getTaskId()),
            '条款 1：回退（ROLLBACK）复活的行参与者＝该行办结人 leader，并入的是它的代理人（血缘版 issues/121 P2）');
    }

    /**
     * 条款 1「覆盖跳转」：**JUMP**（submitType=4 跳指定节点）产出的新待办同样要应用委托。
     *
     * 与上两条（推进 / 回退）分家：委托只挂在跳转目标节点 task3 的参与者 `boss` 上，
     * 中途节点一律不断言参与者——三条路径各自失能时恰好各自红。
     */
    public function testJumpToNamedNodeAlsoJoinsAgent(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        $this->surrogate('boss', 'jAgent', 'multi-task');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];
        $t1 = (string) $this->onlyDoing($instanceId)->getTaskId();

        $jump = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t1, 'operator' => 'leader',
            'submitType' => SubmitType::JUMP, 'taskName' => 'task3',
        ]);
        $this->assertSame(0, $jump['code'], json_encode($jump, JSON_UNESCAPED_UNICODE));

        $jumped = $this->onlyDoing($instanceId);
        $this->assertSame('task3', $jumped->getTaskName(), '前置：JUMP 应直达 task3 产生新待办');
        $this->assertSame(['boss', 'jAgent'], $this->actorsOf((string) $jumped->getTaskId()),
            '条款 1：跳转（JUMP）产出的新单也要并入代理人');
        $this->assertContains((string) $jumped->getTaskId(), $this->todoIds('jAgent'),
            'jAgent 待办里应能看到这张跳转出的单');
    }

    /**
     * 条款 1.4：同一参与者命中多条生效委托时取 id 最大那条。
     *
     * **夹具刻意打乱 id 插入序**（插入序 2001 → 2003 → 2002，最大 id 卡在中间）：
     * 插入序与 id 序重合时，"取遍历末条"与"取 id 最大"给出同一个答案，用例钉不住
     * （Node 上轮实测发现的空转）。这样排后三种错实现各红一头：
     * 取首条 → sLow、取末条 → sMid、只有取 id 最大 → sHigh。
     */
    public function testMultipleHitsPickMaxIdNotFirstInserted(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'sLow', 'simple', id: '2001');    // 插入序第 1（id 最小）
        $this->surrogate('user1', 'sHigh', 'simple', id: '2003');   // 插入序第 2，但 id 最大 = 唯一正解
        $this->surrogate('user1', 'sMid', 'simple', id: '2002');    // 插入序末条（"取末条"的诱饵）

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1', 'sHigh'], $this->actorsOf((string) $apply->getTaskId()),
            '条款 1.4：多条命中取主键 id 最大（与 SQL 侧 ORDER BY id DESC 同答案），'
            . '既不是插入序首条也不是末条');
    }

    // ═══ 负向：窗外 / enabled=0 / 自委托 都不生效 ═══

    /**
     * issues/123 任务 A/B（内存仓 + 建单落库断言）：`testMultipleHitsPickMaxIdNotFirstInserted`
     * 的延长线——多条并存时**先取 id 最新一条、再由四判据裁决这一条**，所以"最新一条不生效"
     * 就是未命中，**不得**回落到更旧那条生效行（旧形状"先滤生效再取最新"在这里必然答成并入）。
     *
     * 每轮的台账都是「更旧一条窗内 enabled=1」+「更新一条按某判据不生效」，
     * 并配一轮正向对照（最新一条生效 ⇒ 必须并入），防判据写反后负向断言空转。
     */
    public function testNewestRowDecidesAndNeverFallsBackToOlder(): void
    {
        $cases = [
            // [标签, 最新一条的覆盖字段, 最新一条的代理人, 期望是否并入]
            ['A1 最新一条窗外（未到窗）', ['startTime' => '2099-01-01 00:00:00', 'endTime' => null], 'aLate', false],
            ['A2 最新一条窗外（已过期）', ['startTime' => '2000-01-01 00:00:00', 'endTime' => '2000-01-02 00:00:00'], 'aGone', false],
            ['A3 最新一条 enabled=0', ['enabled' => 0], 'aOff', false],
            ['A4 最新一条 enabled=2 脏值', ['enabled' => 2], 'aDirty', false],
            ['A5 最新一条自委托', [], 'user1', false],
            ['B 正向对照：最新一条窗内 enabled=1', [], 'aOn', true],
        ];
        foreach ($cases as $i => [$label, $override, $agent, $merged]) {
            $this->newHarness();          // 每轮重建台账，上一轮的生效行不许串味
            $base = (string) (5100 + $i * 10);
            $newestId = (string) ((int) $base + 2);
            $defineId = $this->deploy('01-simple.json');
            // 更旧的一条：窗内 + enabled=1 —— 旧形状会把它当成"最新命中行"永远并入
            $this->surrogate('user1', 'aOlder', 'simple', id: $base);
            // 更新的一条：按某判据不生效（正向对照组则是生效的）
            $this->surrogate('user1', $agent, 'simple', $override, $newestId);
            // 种子自证：两条都真落库，且新那条 id 更大（否则"不并入"只是数据没进去）
            $this->assertNotNull($this->extRepo->findSurrogateById($base), "{$label}：更旧那条未落库");
            $newest = $this->extRepo->findSurrogateById($newestId);
            $this->assertNotNull($newest, "{$label}：最新那条未落库");
            $this->assertSame($agent, (string) $newest['surrogate'], "{$label}：最新那条落库值不符");

            $start = $this->facade->flow('processDefine/startAndExecute', [
                'processDefineId' => $defineId, 'operator' => 'user1',
            ]);
            $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
            $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
                (string) $start['data']['processInstanceId'], 'apply');
            $this->assertNotNull($apply);
            $actors = $this->actorsOf((string) $apply->getTaskId());
            if ($merged) {
                $this->assertSame(['user1', 'aOn'], $actors,
                    "{$label}：最新一条生效 ⇒ 代理人并入、原授权人保留");
                continue;
            }
            $this->assertSame(['user1'], $actors,
                "{$label}：最新一条不生效 ⇒ 不得并入它，也不得回落到更旧那条 aOlder");
            $this->assertNotContains('aOlder', $actors, "{$label}：回落到了更旧的生效行（issues/123 病灶）");
        }
    }

    /**
     * issues/123 判据 4（内存仓）：精确作用域最新一条停用 ⇒ 同层内不复活更旧那条，
     * 但仍须由那条生效的全流程兜底行接管（条款 1.4 后半句，对齐 Java 既有测试）。
     * 末尾删净精确作用域两条后再发起一次，证明兜底路径本身是活的（上面的"不并入"不是空转）。
     */
    public function testNewestInExactScopeFallsBackToGlobalRow(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'aAll', '', id: '5201');                        // 生效的全流程兜底行
        $this->surrogate('user1', 'aOk', 'simple', id: '5202');                   // 本流程窗内生效（更旧）
        $this->surrogate('user1', 'aOff', 'simple', ['enabled' => 0], '5203');    // 本流程最新一条：停用

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1', 'aAll'], $this->actorsOf((string) $apply->getTaskId()),
            '精确作用域最新一条停用 ⇒ 同层不复活 aOk/aOff，但由全流程兜底行 aAll 接管（条款 1.4）');

        // 正向对照：删净精确作用域两条后，兜底路径必须照常接管
        $this->extRepo->removeSurrogate('5203');
        $this->extRepo->removeSurrogate('5202');
        $start2 = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start2['code'], json_encode($start2, JSON_UNESCAPED_UNICODE));
        $apply2 = $this->taskOf($start2['data']['processTaskId'] ?? null,
            (string) $start2['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply2);
        $this->assertSame(['user1', 'aAll'], $this->actorsOf((string) $apply2->getTaskId()),
            '精确作用域清空后，空 processName 的兜底委托应并入代理人');
    }

    /** 条款 5 判据②③④ 的负向面：一条都不许把任务推出去。 */
    public function testOutOfWindowDisabledAndSelfDelegationNotApplied(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'sOut', 'simple', ['startTime' => '2099-01-01 00:00:00']);
        $this->surrogate('leader', 'eOut', 'simple', ['endTime' => '2000-01-01 00:00:00']);
        $this->surrogate('user1', 'off', 'simple', ['enabled' => 0]);
        $this->surrogate('user1', 'dirty', 'simple', ['enabled' => 'abc']);  // 判据④：脏值不得当启用
        $this->surrogate('user1', 'user1', 'simple');                         // 判据③：自己委托给自己

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null, $instanceId, 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1'], $this->actorsOf((string) $apply->getTaskId()),
            '窗外 / enabled=0 / 脏值 / 自委托一律不生效');

        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing);
        $this->assertSame(['leader'], $this->actorsOf((string) $doing[0]->getTaskId()),
            '窗外（endTime 已过）的委托不得追加 task1 参与者');
        $this->assertCount(0, $this->todoIds('sOut'));
        $this->assertCount(0, $this->todoIds('off'));
        $this->assertCount(0, $this->todoIds('dirty'), '脏值 enabled="abc" 落 0（停用），不得当启用');
    }

    /** 判据② 边界：只给 startTime（endTime 为 null = 该侧不限）在窗内应生效。 */
    public function testHalfOpenWindowIsUnlimitedOnNullSide(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'sHalf', 'simple',
            ['startTime' => '2020-01-01 00:00:00', 'endTime' => null]);

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);
        $this->assertContains('sHalf', $this->actorsOf((string) $apply->getTaskId()),
            'endTime 为 null 表示该侧不限');
    }

    // ═══ 条款 1.3：串行会签只进当一步任务，不扩投票名册 ═══

    /**
     * 串行会签（06：task1 = userA,userB 逐个建单）：userA→agentA、userB→agentB。
     * 每一步的新任务都要生效（条款 1「串行会签每一步推进」），但**投票名册 operatorList 不变**，
     * 否则等于偷偷改了会签票数。
     */
    public function testSerialCountersignJoinsCurrentStepOnlyAndKeepsVoteRoster(): void
    {
        $defineId = $this->deploy('06-countersign-sequential.json');
        $this->surrogate('userA', 'agentA', 'countersign-sequential');
        $this->surrogate('userB', 'agentB', 'countersign-sequential');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $first = $this->onlyDoing($instanceId);
        $this->assertSame('task1', $first->getTaskName());
        $firstId = (string) $first->getTaskId();
        $this->assertSame(['userA', 'agentA'], $this->actorsOf($firstId),
            '串行会签首位任务：代理人只进当一步任务参与者');
        $this->assertSame(['userA', 'userB'], $first->getVariables()->get('operatorList_task1'),
            '条款 1.3：不得扩投票名册（票数不变）');

        // 代理人可办本步（任一可办），办完推进到第二位 → 新任务同样生效
        $next = $this->facade->flow('processTask/execute', [
            'processTaskId' => $firstId, 'operator' => 'agentA',
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $next['code'], json_encode($next, JSON_UNESCAPED_UNICODE));
        $second = $this->onlyDoing($instanceId);
        $this->assertSame(['userB', 'agentB'], $this->actorsOf((string) $second->getTaskId()),
            '串行会签第二步推进出的任务也要应用委托');
        $this->assertSame(['userA', 'userB'], $second->getVariables()->get('operatorList_task1'),
            '第二步同样不得扩投票名册');
        $this->assertSame(['userA', 'agentA'], $this->actorsOf($firstId),
            '已完成任务的历史参与者不被追溯改动');
    }

    /**
     * 条款 1「**串行会签的每一步推进**」：把"第二步推进"单独拎成一条用例。
     *
     * 与 {@link testSerialCountersignJoinsCurrentStepOnlyAndKeepsVoteRoster} 的分工——本条只管
     * 一条路径：委托**只挂在第二步的参与者 userB 上**，第一步 userA 压根没配委托。
     * 于是"串行会签推进建的那张新单"唯一可能红的就是它自己：
     * 首步那条挂点失能 → 上条红、本条绿（userA 无委托，本条首步断言的是"不多出人"）；
     * 本条红 → 只可能是会签推进这条路径漏挂。
     */
    public function testSerialCountersignSecondStepIsItsOwnCreationPath(): void
    {
        $defineId = $this->deploy('06-countersign-sequential.json');
        $this->surrogate('userB', 'bAgent', 'countersign-sequential');   // 只给第二步配委托

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $first = $this->onlyDoing($instanceId);
        $this->assertSame('task1', $first->getTaskName());
        $firstId = (string) $first->getTaskId();
        $this->assertSame(['userA'], $this->actorsOf($firstId),
            '前置：第一步参与者 userA 没配委托，不该多出任何人');

        $next = $this->facade->flow('processTask/execute', [
            'processTaskId' => $firstId, 'operator' => 'userA',
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $next['code'], json_encode($next, JSON_UNESCAPED_UNICODE));

        $second = $this->onlyDoing($instanceId);
        $this->assertNotSame($firstId, (string) $second->getTaskId(), '前置：第二步是新建的一张单');
        $this->assertSame(['userB', 'bAgent'], $this->actorsOf((string) $second->getTaskId()),
            '条款 1：串行会签第二步推进出的任务也要应用委托');
        $this->assertSame(['userA', 'userB'], $second->getVariables()->get('operatorList_task1'),
            '条款 1.3：代理人不得混进投票名册');
    }

    // ═══ 条款 1.1：processName = 模型 name 优先，缺失才回落 wf_process_define.name ═══

    /**
     * 回落那一支：模型未带 name（流程 JSON 顶层无 `name`）、定义行带 name。
     *
     * 缺陷形态是 `$exec->getProcessModel()?->getName() ?? ''` 直接给空串——空串按判据① 只命中
     * 全流程兜底行，于是**该流程自己配的委托一条都查不到**（用户视角=委托静默失效）。
     * 诱饵刻意**不放兜底行**：回落失效时这里必然红。
     */
    public function testBlankModelNameFallsBackToProcessDefineName(): void
    {
        $defineId = $this->addDecoyDefine('decoy-flow', null);
        $this->surrogate('leader', 'dAgent', 'decoy-flow');   // 台账按定义行 name 配

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $this->repo->findDoingTasks((string) $start['data']['processInstanceId']);
        $this->assertCount(1, $doing, '前置：推进到 task1');
        $this->assertSame(['leader', 'dAgent'], $this->actorsOf((string) $doing[0]->getTaskId()),
            '条款 1.1：模型未带 name 时应回落 wf_process_define.name，命中该流程自己的委托');
    }

    /**
     * 优先那一支：define.name 与 model.name **不一致**的诱饵行（06 §4.5 条款 1.1 建议各栈保留）。
     *
     * 钉住"到底取的是哪一个"：认模型 name（契约）→ user1 的委托命中；
     * 若实现改成认定义行 name → 命中的会是 wrongAgent，两条断言同时翻红。
     */
    public function testModelNameWinsOverDefineNameWhenTheyDiffer(): void
    {
        $defineId = $this->addDecoyDefine('wrong-define-name', 'model-only-name');
        $this->surrogate('user1', 'rightAgent', 'model-only-name');      // 认模型 name：应命中
        $this->surrogate('leader', 'wrongAgent', 'wrong-define-name');   // 认定义行 name：不该命中

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $apply = $this->taskOf($start['data']['processTaskId'] ?? null, $instanceId, 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1', 'rightAgent'], $this->actorsOf((string) $apply->getTaskId()),
            '条款 1.1：processName 取的是模型 name，不是定义行 name');

        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing);
        $this->assertSame(['leader'], $this->actorsOf((string) $doing[0]->getTaskId()),
            '诱饵行（按定义行 name 配的委托）不该被命中');
        $this->assertCount(0, $this->todoIds('wrongAgent'));
    }

    // ═══ 条款 5 写侧：enabled 入参落值 + 跨层对拍（门面 save → 台账 → 建任务读侧）═══

    /** 写侧四种入参的落值矩阵：缺键→1、`''`/脏值→0 且不抛错、布尔按 true→1/false→0。 */
    public function testEnabledWriteSideNormalizesWithoutThrowing(): void
    {
        // [入参, 是否省略键, 期望落库值, 说明]
        $cases = [
            [null, true, 1, '缺键 → 契约默认 1'],
            [null, false, 1, '显式 null 同缺键'],
            [1, false, 1, '整数 1'],
            ['1', false, 1, '字符串 "1"'],
            [0, false, 0, '显式 0 不得被默认值吞'],
            ['', false, 0, '空串 → 0（不是缺键，不得落 1）'],
            ['abc', false, 0, '不可解析脏值 → 0，且不得抛异常'],
            ['1abc', false, 0, 'PHP 宽松前缀解析会给 1（=启用），契约要求 0 —— 本栈的坑'],
            ['1x', false, 0, '契约点名的脏值'],
            [true, false, 1, '布尔 true → 1'],
            [false, false, 0, '布尔 false → 0'],
            [2, false, 2, '可解析整数原样落库（读侧非 1 即不生效）'],
        ];
        foreach ($cases as [$input, $omit, $stored, $why]) {
            $args = [
                'processName' => 'simple', 'surrogate' => 'lisi', 'operator' => 'leader',
                'startTime' => '2020-01-01 00:00:00', 'endTime' => '2999-12-31 23:59:59',
            ];
            if (!$omit) $args['enabled'] = $input;
            $save = $this->facade->flow('processSurrogate/save', $args);
            $this->assertSame(0, $save['code'], $why . '：门面不得报错，' . json_encode($save, JSON_UNESCAPED_UNICODE));
            $detail = $this->facade->flow('processSurrogate/detail', ['id' => $save['data']['id']]);
            $this->assertSame(0, $detail['code'], json_encode($detail, JSON_UNESCAPED_UNICODE));
            $this->assertSame($stored, (int) $detail['data']['enabled'], $why . ' 的落库值');
        }

        // update 侧同一套（门面写侧两处入口共用 applySurrogateFields）
        $save = $this->facade->flow('processSurrogate/save', [
            'processName' => 'simple', 'surrogate' => 'lisi', 'operator' => 'leader',
            'startTime' => '2020-01-01 00:00:00', 'endTime' => '2999-12-31 23:59:59', 'enabled' => 1,
        ]);
        $upd = $this->facade->flow('processSurrogate/update', [
            'id' => $save['data']['id'], 'processName' => 'simple', 'surrogate' => 'lisi',
            'startTime' => '2020-01-01 00:00:00', 'endTime' => '2999-12-31 23:59:59', 'enabled' => '1abc',
        ]);
        $this->assertSame(0, $upd['code'], json_encode($upd, JSON_UNESCAPED_UNICODE));
        $detail = $this->facade->flow('processSurrogate/detail', ['id' => $save['data']['id']]);
        $this->assertSame(0, $detail['data']['enabled'], 'update 传脏值同样落 0');
    }

    /**
     * 跨层对拍（条款 5 尾句 / 条款 6）：**写侧落 0 的行，读侧必须查不到生效委托**；
     * 落 1 的必须查到。门面 save → 内存台账 → 引擎建任务，三层同一条数据同一个结论。
     */
    public function testEnabledWriteSideAgreesWithReadSideAcrossLayers(): void
    {
        // [enabled 入参, 省略键?, 期望落库, 期望读侧是否生效]
        $cases = [
            [null, true, 1, true],
            [1, false, 1, true],
            ['1', false, 1, true],
            [true, false, 1, true],
            [0, false, 0, false],
            ['', false, 0, false],
            ['abc', false, 0, false],
            ['1abc', false, 0, false],
            [false, false, 0, false],
            [2, false, 2, false],   // 写侧原样落 2，读侧"只有 1 生效"→ 不生效
        ];
        foreach ($cases as $i => [$input, $omit, $stored, $effective]) {
            $this->newHarness();                       // 每轮一套干净台账，避免上一轮的生效行串味
            $label = is_bool($input) ? var_export($input, true) : var_export($input, true);
            $defineId = $this->deploy('01-simple.json');
            $args = [
                'processName' => 'simple', 'surrogate' => 'agent' . $i, 'operator' => 'leader',
                'startTime' => '2020-01-01 00:00:00', 'endTime' => '2999-12-31 23:59:59',
            ];
            if (!$omit) $args['enabled'] = $input;
            $save = $this->facade->flow('processSurrogate/save', $args);
            $this->assertSame(0, $save['code'], json_encode($save, JSON_UNESCAPED_UNICODE));
            $detail = $this->facade->flow('processSurrogate/detail', ['id' => $save['data']['id']]);
            $this->assertSame($stored, (int) $detail['data']['enabled'], "写侧落值：enabled={$label}");

            $start = $this->facade->flow('processDefine/startAndExecute', [
                'processDefineId' => $defineId, 'operator' => 'user1',
            ]);
            $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
            $doing = $this->repo->findDoingTasks((string) $start['data']['processInstanceId']);
            $this->assertCount(1, $doing, '前置：推进到 task1');
            $actors = $this->actorsOf((string) $doing[0]->getTaskId());
            $this->assertSame($effective ? ['leader', 'agent' . $i] : ['leader'], $actors,
                "跨层对拍：写侧落 {$stored} 的行，读侧生效=" . var_export($effective, true)
                . "（enabled={$label}）");
            $this->assertCount($effective ? 1 : 0, $this->todoIds('agent' . $i),
                "读侧待办数须与写侧落值一致（enabled={$label}）");
        }
    }

    // ═══ 回归：缺席 / 报错 / 关闭 / 加签 / 幂等 ═══

    /** 条款 4：未配置扩展仓储时静默跳过——建单不得被打断，参与者保持原样。 */
    public function testMissingExtRepositoryDoesNotBreakTaskCreation(): void
    {
        ServiceContext::clear();                                  // 无仓储、无容器注册
        $repo = new InMemoryProcessRepository();
        $engine = new JeeflowEngine($repo);
        $facade = new JeeflowFacade($engine, $repo);              // extRepository = null
        $defineId = $this->deployWith($facade, '02-multi-task.json');

        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $repo->findDoingTasks((string) $start['data']['processInstanceId']);
        $this->assertCount(1, $doing, '缺扩展仓储属正常部署形态，建单照常');
        $this->assertSame(['leader'], $doing[0]->getActorIds());
    }

    /** 条款 4：仓储自身报错（如表未建）只记日志，不打断建单。 */
    public function testFailingExtRepositoryIsLoggedNotThrown(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $throwing = new class implements ProcessExtRepositoryInterface {
            public function getSurrogate(string $operator, string $processName, ?string $time = null): ?array
            {
                throw new \RuntimeException('表不存在');
            }
            public function pageDesigns(\Jeeflow\Core\Spi\PageQuery $q): \Jeeflow\Core\Spi\PageResult { return new \Jeeflow\Core\Spi\PageResult(1, 10, 0, []); }
            public function findDesignById(int|string $id): ?array { return null; }
            public function saveDesign(array $d): string { return '1'; }
            public function updateDesign(array $d): void {}
            public function saveDesignHis(int|string $id, string $c, ?string $o = null): void {}
            public function findLatestDesignHis(int|string $id): ?array { return null; }
            public function findDesignHisList(int|string $id): array { return []; }
            public function removeDesign(int|string $id): void {}
            public function updateDesignDeployed(int|string $id, int $s): void {}
            public function listDesignsByType(): array { return []; }
            public function pageSurrogates(\Jeeflow\Core\Spi\PageQuery $q): \Jeeflow\Core\Spi\PageResult { return new \Jeeflow\Core\Spi\PageResult(1, 10, 0, []); }
            public function findSurrogateById(int|string $id): ?array { return null; }
            public function saveSurrogate(array $s): string { return '1'; }
            public function updateSurrogate(array $s): void {}
            public function removeSurrogate(int|string $id): void {}
        };
        $repo = new InMemoryProcessRepository();
        $engine = new JeeflowEngine($repo, extRepository: $throwing);
        $facade = new JeeflowFacade($engine, $repo, $throwing);
        $defineId = $this->deployWith($facade, '01-simple.json');

        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $repo->findDoingTasks((string) $start['data']['processInstanceId']);
        $this->assertSame(['leader'], $doing[0]->getActorIds(), '报错时零追加，但建单照常');
    }

    /** 条款 3：构造参数关闭 → 回到"仅台账"（委托照存照查，建任务不再应用）。 */
    public function testDisabledViaConstructorFallsBackToLedgerOnly(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $repo = new InMemoryProcessRepository();
        $ext = new InMemoryProcessExtRepository();
        $engine = new JeeflowEngine($repo, surrogateAutoApply: false);
        $facade = new JeeflowFacade($engine, $repo, $ext);
        $this->assertFalse($engine->isSurrogateAutoApply());

        $defineId = $this->deployWith($facade, '01-simple.json');
        $ext->saveSurrogate(['id' => '7001', 'operator' => 'leader', 'surrogate' => 'sOff',
            'processName' => 'simple', 'enabled' => 1,
            'startTime' => '2020-01-01 00:00:00', 'endTime' => '2099-01-01 00:00:00']);

        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $repo->findDoingTasks((string) $start['data']['processInstanceId']);
        $this->assertSame(['leader'], $doing[0]->getActorIds(), '关闭后不得追加代理人');

        // 台账侧不受影响：仍能查到那条委托（"仅台账"语义）
        $page = $facade->flow('processSurrogate/page', ['m_EQ_operator' => 'leader']);
        $this->assertSame(0, $page['code'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $page['data']['recordCount'], '关闭只关运行期应用，台账不关');
    }

    /** 条款 3：注册空实现（NullSurrogateInterceptor）式关闭——不改引擎开关。 */
    public function testNullApplierRegistrationDisablesAutoApply(): void
    {
        ServiceContext::put(SurrogateInterceptor::CONTEXT_KEY, new NullSurrogateInterceptor());
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'sNull', 'simple');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1'], $this->actorsOf((string) $apply->getTaskId()));
        $this->assertTrue($this->engine->isSurrogateAutoApply(),
            '空实现路径不改引擎开关（开关仍为 true，行为由注册的 applier 决定）');
    }

    /** 回归：加签（processTask/surrogate）仍是纯追加，且与自动生效叠加不重复。 */
    public function testManualAddCandidateStillOnlyAppends(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('leader', 'wangwu', 'simple');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];
        $taskId = (string) $this->onlyDoing($instanceId)->getTaskId();
        $this->assertSame(['leader', 'wangwu'], $this->actorsOf($taskId));

        $add = $this->facade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => ['user3']]);
        $this->assertSame(0, $add['code'], json_encode($add, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['leader', 'wangwu', 'user3'], $this->actorsOf($taskId),
            '加签只追加：原人与自动生效的代理人都不被摘掉');

        // 加签加的就是代理人 → 不产生重复行
        $dup = $this->facade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => ['wangwu']]);
        $this->assertSame(0, $dup['code'], json_encode($dup, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['leader', 'wangwu', 'user3'], $this->actorsOf($taskId));
    }

    /** 幂等 + 非待办不追加：apply() 对同一任务重复调用零副作用（自定义注册叠加时靠它兜底）。 */
    public function testApplyIsIdempotentAndSkipsNonDoingTasks(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'lisi', 'simple');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);

        $applier = new SurrogateInterceptor($this->extRepo);
        $applier->apply($apply, 'simple');
        $applier->apply($apply, 'simple');
        $this->assertSame(['user1', 'lisi'], $this->actorsOf((string) $apply->getTaskId()),
            '重复 apply 不得产生重复参与者');

        $apply->setTaskState(\Jeeflow\Core\Enum\ProcessTaskState::FINISHED);
        $this->surrogate('lisi', 'carol', 'simple');
        $applier->apply($apply, 'simple');
        $this->assertSame(['user1', 'lisi'], $this->actorsOf((string) $apply->getTaskId()),
            '非 DOING（历史）任务不追加代理人');
    }

    /** 台账端到端：processSurrogate/save 配的委托，下一张单即自动生效（用户视角的白拿）。 */
    public function testLedgerSavedSurrogateAppliesToNextInstance(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $save = $this->facade->flow('processSurrogate/save', [
            'processName' => 'simple', 'surrogate' => 'lisi', 'operator' => 'leader',
            'startTime' => '2020-01-01 00:00:00', 'endTime' => '2099-12-31 23:59:59',
        ]);
        $this->assertSame(0, $save['code'], json_encode($save, JSON_UNESCAPED_UNICODE));

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $taskId = (string) $this->onlyDoing((string) $start['data']['processInstanceId'])->getTaskId();
        $this->assertSame(['leader', 'lisi'], $this->actorsOf($taskId));
        $this->assertContains($taskId, $this->todoIds('lisi'));
        // 台账默认 enabled=1（契约 processSurrogate/save 缺省）
        $detail = $this->facade->flow('processSurrogate/detail', ['id' => $save['data']['id']]);
        $this->assertSame(1, $detail['data']['enabled']);
    }

    // ── 辅助 ──

    private function deploy(string $file): string
    {
        return $this->deployWith($this->facade, $file);
    }

    private function deployWith(JeeflowFacade $facade, string $file): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/' . $file);
        $this->assertNotFalse($json);
        $deploy = $facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        return (string) $deploy['data']['processDefineId'];
    }

    /**
     * 造条款 1.1 的诱饵定义行。
     *
     * 必须绕开 `processDefine/deploy`：deploy 执行的是 `def.setName(model.getName())`，
     * 定义行 name 与模型 name 永远一致，也就永远测不出实现到底取的哪一个。
     *
     * @param string      $defineName  写进 `wf_process_define.name`
     * @param string|null $contentName 写进 content 顶层 `name`；null = **删掉该键**（模型未带 name）
     */
    private function addDecoyDefine(string $defineName, ?string $contentName): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        if ($contentName === null) {
            unset($data['name']);
        } else {
            $data['name'] = $contentName;
        }
        $content = json_encode($data, JSON_UNESCAPED_UNICODE);

        // 诱饵自检：模型 name 须真的等于预期（诱饵不成立 = 用例空转，先证自己下的套）
        $this->assertSame($contentName ?? '', ModelParser::parse($content)->getName(),
            '诱饵前置：解析出的模型 name 与预期不符，本用例等于没测');

        $id = (string) $this->repo->getIdGenerator()->nextId();
        $this->repo->addDefine([
            'id' => $id, 'name' => $defineName, 'displayName' => '诱饵定义', 'type' => 'approval',
            'state' => 1, 'version' => 0, 'content' => $content,
            'createUser' => 'user1', 'updateUser' => 'user1',
        ]);
        $define = $this->repo->findDefineById($id);
        $this->assertNotNull($define, '诱饵前置：定义行须真的落库');
        $this->assertSame($defineName, $define['name'], '诱饵前置：定义行 name 须与模型 name 可区分');
        return $id;
    }

    /** 直接写台账（可控 id / 脏值 / null 窗口），绕开门面默认值 */
    private function surrogate(string $operator, string $agent, string $processName,
                               array $extra = [], ?string $id = null): void
    {
        $this->extRepo->saveSurrogate(array_merge([
            'operator' => $operator,
            'surrogate' => $agent,
            'processName' => $processName,
            'enabled' => 1,
            'startTime' => '2020-01-01 00:00:00',
            'endTime' => '2099-12-31 23:59:59',
        ], $extra, $id === null ? [] : ['id' => $id]));
    }

    /** 读回仓储里的任务（内存仓即本模式的持久形态） */
    private function actorsOf(string $taskId): array
    {
        $task = $this->repo->findTaskById($taskId);
        $this->assertNotNull($task, "任务 {$taskId} 应已落库可读");
        return $task->getActorIds();
    }

    /** 门面读回某人待办 id 列表 */
    private function todoIds(string $operator): array
    {
        $r = $this->facade->flow('processTask/todoList', ['operator' => $operator]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return array_map(strval(...), array_column($r['data']['rows'], 'id'));
    }

    private function onlyDoing(string $instanceId): ProcessTask
    {
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing, '前置：应恰好 1 个进行中任务');
        return $doing[0];
    }

    /** processTaskId 可能是刚执行完的 apply（门面回传），也可能已被推进替换 → 按任务名兜底找 */
    private function taskOf(mixed $taskId, string $instanceId, string $taskName): ?ProcessTask
    {
        $id = $taskId === null ? null : (string) $taskId;
        if ($id !== null) {
            $task = $this->repo->findTaskById($id);
            if ($task !== null && $task->getTaskName() === $taskName) return $task;
        }
        foreach ($this->repo->findHistoryTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
