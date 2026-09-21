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
        ServiceContext::clear();
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

    /** 条款 1「覆盖跳转/驳回」：ROLLBACK 退回上一步产生的新待办同样要应用委托。 */
    public function testRollbackCreatedTaskAlsoJoinsAgent(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        $this->surrogate('manager', 'mAgent', 'multi-task');

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
        $t2 = (string) $this->onlyDoing($instanceId)->getTaskId();
        $this->assertSame(['manager', 'mAgent'], $this->actorsOf($t2),
            '前置：办理推进出的 task2（参与者 manager）已并入代理人');

        $back = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t2, 'operator' => 'manager', 'submitType' => SubmitType::ROLLBACK,
        ]);
        $this->assertSame(0, $back['code'], json_encode($back, JSON_UNESCAPED_UNICODE));
        $rollbackTask = $this->onlyDoing($instanceId);
        $this->assertSame('task1', $rollbackTask->getTaskName(), '前置：ROLLBACK 应退回 task1 产生新待办');
        $this->assertSame(['manager', 'mAgent'], $this->actorsOf((string) $rollbackTask->getTaskId()),
            '跳转/驳回产出的新单也要并入代理人（参与者=退回操作人 manager 的委托）');
    }

    /** 条款 1.4：同一参与者命中多条生效委托时取 id 最大那条（内存仓不得"取遍历首条"）。 */
    public function testMultipleHitsPickMaxIdNotFirstInserted(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->surrogate('user1', 'sOld', 'simple', id: '2001');
        $this->surrogate('user1', 'sNew', 'simple', id: '2002');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $apply = $this->taskOf($start['data']['processTaskId'] ?? null,
            (string) $start['data']['processInstanceId'], 'apply');
        $this->assertNotNull($apply);
        $this->assertSame(['user1', 'sNew'], $this->actorsOf((string) $apply->getTaskId()),
            '条款 1.4：多条命中取主键 id 最大（与 SQL 侧 ORDER BY id DESC 同答案）');
    }

    // ═══ 负向：窗外 / enabled=0 / 自委托 都不生效 ═══

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
