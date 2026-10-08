<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessExtRepository;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/116 批次 D · 委托代理（PHP SQL 路，SQLite 内存库，不依赖 160 MySQL）
 *
 * 两件事都在这里钉住：
 *
 * 1. **双仓四判据同答案**（08-compliance 用例 27 / 06 §4.5 条款 6）：同一份委托数据分别灌进
 *    内存仓与 SQL 仓，探针（多条命中取 id 最大 / 空 processName 兜底 / enabled=0 /
 *    窗外 / 自委托过滤 / enabled 脏值 / 半开窗口）逐条断言两仓给出**同一个**代理人。
 *    这正是 issues/116 §5 记的 PHP 分叉点（自委托过滤两仓都没有、内存仓多条命中"取遍历首条"）。
 *    **夹具的 id 插入序刻意打乱**（最大那条卡在中间）：插入序与 id 序重合时"取遍历末条"与
 *    "取 id 最大"同答案，条款 1.4 就钉不住（Node 上轮实测发现的空转）。
 *
 * 2. **自动生效打在真表上**（06 §4.5 条款 2 的 ⚠️）：断言读的是 `wf_process_task_actor`
 *    的**真行**，不是返回码、不是内存集合——Java 首版走 `addTaskActor` 补写、挂在 taskId
 *    分配之前，能力看起来实现了而实际一单都没代理出去，只有读真表能证伪。
 */
class PdoSqliteSurrogateTest extends TestCase
{
    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private PdoProcessExtRepository $pdoExt;
    private InMemoryProcessExtRepository $memExt;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    private const AT = '2026-08-15 12:00:00';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(self::schema());

        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });

        $this->repo = new PdoProcessRepository($this->pdo);
        $this->pdoExt = new PdoProcessExtRepository($this->pdo);
        $this->memExt = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->pdoExt);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ═══ 1. 双仓四判据同答案（用例 27）═══

    public function testFourCriteriaGiveSameAnswerInBothRepositories(): void
    {
        // 同一份数据分别灌两仓（内存仓 camelCase 行 / PDO 仓 snake_case 列，由各自 saveSurrogate 落）。
        // 注意：op1 组内**不放**空 processName 的兜底行——否则按判据① 它会兜住所有精确未命中的流程，
        // 负向探针就失去意义了（首轮实测正是这样：flowB 停用却被兜底行接走）。兜底行单独挂 op3。
        $rows = [
            // [id,    授权人, 流程,    代理人, start,               end,                enabled]
            // ⚠ flowA 组的**插入序刻意与 id 序错开**（3001 → 3003 → 3002，最大 id 卡在中间）：
            // 插入序与 id 序重合时，"取遍历末条"与"取 id 最大"给同一个答案，条款 1.4 钉不住
            // （Node 上轮实测发现的空转）。这样排后：取首条→sA1、取末条→sA2、取 id 最大→sA3。
            ['3001', 'op1', 'flowA', 'sA1', '2020-01-01 00:00:00', '2099-12-31 23:59:59', 1],
            ['3003', 'op1', 'flowA', 'sA3', null,                   null,                  1], // 多条命中：id 最大的一条
            ['3002', 'op1', 'flowA', 'sA2', null,                   null,                  1], // 插入序末条（诱饵）
            ['3004', 'op1', 'flowB', 'sB',   null,                  null,                  0], // 停用
            ['3005', 'op1', 'flowC', 'sC',   '2099-01-01 00:00:00', null,                  1], // 未到窗
            ['3006', 'op1', 'flowD', 'op1',  null,                  null,                  1], // 自委托（自己委托给自己）
            ['3007', 'op1', 'flowE', 'sE',   null,                  null,                 'abc'], // 脏值 enabled
            ['3008', 'op1', 'flowF', 'sF',   null,                  '2099-12-31 23:59:59',  1], // 半开窗（start 不限）
            ['3009', 'op1', 'flowG', 'sG',   '2000-01-01 00:00:00', null,                   1], // 半开窗（end 不限）
            ['3010', 'op1', 'flowH', '',     null,                  null,                   1], // 代理人为空串
            ['3011', 'op1', 'flowI', 'sI',   null,                  '2020-01-01 00:00:00',   1], // 已过窗
            ['3012', 'op3', '',      'sAll', null,                  null,                   1], // 判据①：空 processName 全流程兜底
        ];
        foreach ($rows as [$id, $operator, $pn, $agent, $start, $end, $enabled]) {
            $row = ['id' => $id, 'processName' => $pn, 'operator' => $operator, 'surrogate' => $agent,
                    'startTime' => $start, 'endTime' => $end, 'enabled' => $enabled];
            $this->memExt->saveSurrogate($row);
            $this->pdoExt->saveSurrogate($row);
        }

        $probes = [
            // [授权人, 流程名, 期望代理人（null = 不生效）, 判据说明]
            ['op1', 'flowA', 'sA3', '条款 1.4 多条命中取 id 最大（既不取遍历首条也不取末条，插入序已打乱）'],
            ['op1', 'flowB', null, '判据④ enabled=0 不生效'],
            ['op1', 'flowC', null, '判据② startTime 未到窗不生效'],
            ['op1', 'flowI', null, '判据② endTime 已过窗不生效'],
            ['op1', 'flowD', null, '判据③ 自委托 surrogate <> operator 必须过滤'],
            ['op1', 'flowE', null, '判据④ enabled 脏值不得当启用'],
            ['op1', 'flowF', 'sF', '判据② start_time 为 NULL = 该侧不限'],
            ['op1', 'flowG', 'sG', '判据② end_time 为 NULL = 该侧不限'],
            ['op1', 'flowH', null, '代理人为空串的行不生效（无意义台账行）'],
            ['op1', 'flowZ', null, '一条都没配的流程'],
            ['op3', 'flowAny', 'sAll', '判据① 空 processName = 全部流程兜底'],
            ['op3', '',        'sAll', '判据① 当前流程名为空时直接命中兜底行'],
        ];

        foreach ($probes as [$operator, $pn, $expect, $why]) {
            $mem = $this->memExt->getSurrogate($operator, $pn, self::AT);
            $sql = $this->pdoExt->getSurrogate($operator, $pn, self::AT);
            $memAgent = $mem === null ? null : (string) ($mem['surrogate'] ?? '');
            $sqlAgent = $sql === null ? null : (string) ($sql['surrogate'] ?? '');
            $memAgent = $memAgent === '' ? null : $memAgent;
            $sqlAgent = $sqlAgent === '' ? null : $sqlAgent;

            $this->assertSame($expect, $memAgent, "内存仓：{$operator}/{$pn} 应为 " . var_export($expect, true) . "（{$why}）");
            $this->assertSame($expect, $sqlAgent, "SQL 仓：{$operator}/{$pn} 应为 " . var_export($expect, true) . "（{$why}）");
            $this->assertSame($memAgent, $sqlAgent, "双仓必须同答案（{$why}）：{$operator}/{$pn}");
        }
    }

    /** 全流程兜底不该压过精确命中：flowA 同时有精确行与兜底行时取精确行（同一条码在两仓同形）。 */
    public function testExactProcessNameWinsOverFullFlowFallbackInBothRepos(): void
    {
        foreach (['3101' => 'sExact', '3102' => 'sAny'] as $id => $agent) {
            $row = ['id' => $id, 'operator' => 'op2', 'surrogate' => $agent, 'enabled' => 1,
                    'processName' => $agent === 'sAny' ? '' : 'flowP'];
            $this->memExt->saveSurrogate($row);
            $this->pdoExt->saveSurrogate($row);
        }
        // 兜底行 id 更大（3102 > 3101）——若实现按"id 最大"跨越分组，就会错取 sAny
        $this->assertSame('sExact', $this->memExt->getSurrogate('op2', 'flowP', self::AT)['surrogate'] ?? null);
        $this->assertSame('sExact', $this->pdoExt->getSurrogate('op2', 'flowP', self::AT)['surrogate'] ?? null);
    }

    /**
     * issues/123 任务 A/B（双仓同答案）：**先**按主键 id 取该作用域内**最新一条**，
     * **再**由四判据裁决这一条；同层内不生效即"未命中"（不回落更旧那条），
     * 但精确作用域判否后仍要看全流程作用域的最新一条（条款 1.4 后半句）。
     *
     * 旧形状（SQL/内存都先按 enabled + 时间窗 + 自委托过滤，剩下的才取最新）在下面每个
     * 负向探针上都会答成"更旧那条生效行"——同一授权人历史上只要留过一条窗内 enabled=1 的记录，
     * 用户之后新建的窗外/停用/脏值/自委托记录就全都判不动它（13 栈 L2-17/L2-18 全红的病灶）。
     *
     * ⚠️ 插入序刻意排成「生效行 → 裁决行(id 最大) → 生效行」：id 序与插入序重合时
     * "取遍历末条"的实现会因末条恰好是裁决行而跟着夹具一起绿（条款 1.4 那条老注释记的空转）。
     */
    public function testNewestRowDecidesAndNeverFallsBackInBothRepos(): void
    {
        $on  = ['2020-01-01 00:00:00', '2099-12-31 23:59:59'];   // 窗内（覆盖 AT）
        $rows = [
            // [id, 授权人, 流程, 代理人, start, end, enabled]
            // ── A 组：同作用域内"最新一条不生效"⇒ 不得回落到更旧的生效行 ──
            ['3201', 'opW', 'flowW', 'wOlder1', $on[0], $on[1], 1],
            ['3203', 'opW', 'flowW', 'wNewest', '2099-01-01 00:00:00', null, 1], // 最新：窗外（未到）
            ['3202', 'opW', 'flowW', 'wOlder2', $on[0], $on[1], 1],

            ['3211', 'opD', 'flowD', 'dOlder1', $on[0], $on[1], 1],
            ['3213', 'opD', 'flowD', 'dNewest', $on[0], $on[1], 0],              // 最新：enabled=0
            ['3212', 'opD', 'flowD', 'dOlder2', $on[0], $on[1], 1],

            ['3221', 'opX', 'flowX', 'xOlder1', $on[0], $on[1], 1],
            ['3223', 'opX', 'flowX', 'xNewest', $on[0], $on[1], 2],              // 最新：脏值 2
            ['3222', 'opX', 'flowX', 'xOlder2', $on[0], $on[1], 1],

            ['3231', 'opS', 'flowS', 'sOlder1', $on[0], $on[1], 1],
            ['3233', 'opS', 'flowS', 'opS', $on[0], $on[1], 1],                  // 最新：自委托
            ['3232', 'opS', 'flowS', 'sOlder2', $on[0], $on[1], 1],

            ['3241', 'opE', 'flowE', 'eOlder1', $on[0], $on[1], 1],
            ['3243', 'opE', 'flowE', '', $on[0], $on[1], 1],                     // 最新：代理人为空
            ['3242', 'opE', 'flowE', 'eOlder2', $on[0], $on[1], 1],

            // ── G：跨作用域不回落（精确作用域有记录就由它裁决，哪怕兜底行 id 更大且生效）──
            ['3251', 'opG', 'flowG', 'gOlder1', $on[0], $on[1], 1],
            ['3253', 'opG', 'flowG', 'gNewestOff', $on[0], $on[1], 0],
            ['3252', 'opG', 'flowG', 'gOlder2', $on[0], $on[1], 1],
            ['3254', 'opG', '', 'gGlobal', $on[0], $on[1], 1],                   // 生效的兜底行，id 最大

            // ── H：兜底作用域内同样"最新一条裁决、不回落更旧" ──
            ['3261', 'opH', '', 'hOlder1', $on[0], $on[1], 1],
            ['3263', 'opH', '', 'hNewestOff', $on[0], $on[1], 0],
            ['3262', 'opH', '', 'hOlder2', $on[0], $on[1], 1],

            // ── B：正向对照（判据写反成"恒不命中"时这组立刻红）──
            ['3271', 'opP', 'flowP', 'pAgent', $on[0], $on[1], 1],               // 只有一条窗内 enabled=1
            ['3281', 'opN', 'flowN', 'nDisabled', $on[0], $on[1], 0],            // 更旧两条不生效
            ['3283', 'opN', 'flowN', 'nNewest', $on[0], $on[1], 1],              // 最新一条生效 ⇒ 命中它
            ['3282', 'opN', 'flowN', 'nExpired', '2000-01-01 00:00:00', '2000-01-02 00:00:00', 1],
        ];
        foreach ($rows as [$id, $operator, $pn, $agent, $start, $end, $enabled]) {
            $row = ['id' => $id, 'processName' => $pn, 'operator' => $operator, 'surrogate' => $agent,
                    'startTime' => $start, 'endTime' => $end, 'enabled' => $enabled];
            $this->memExt->saveSurrogate($row);
            $this->pdoExt->saveSurrogate($row);
        }
        // 种子自证：20 条全落两仓（"未命中"类期望不能因数据没进去而空转）
        foreach ($rows as [$id]) {
            $this->assertNotNull($this->memExt->findSurrogateById($id), "内存仓种子未落库 id={$id}");
            $this->assertNotNull($this->pdoExt->findSurrogateById($id), "SQL 仓种子未落库 id={$id}");
        }

        $probes = [
            // [授权人, 流程名, 判定时刻, 期望代理人（null=不命中）, 判据说明]
            ['opW', 'flowW', self::AT, null, 'A1 最新一条窗外 ⇒ 不命中、不回落到更旧生效行'],
            ['opD', 'flowD', self::AT, null, 'A2 最新一条 enabled=0 ⇒ 不命中、不回落'],
            ['opX', 'flowX', self::AT, null, 'A3 最新一条 enabled=2 脏值 ⇒ 不命中、不回落'],
            ['opS', 'flowS', self::AT, null, 'A4 最新一条自委托 ⇒ 不命中、不回落'],
            ['opE', 'flowE', self::AT, null, 'A5 最新一条代理人为空 ⇒ 不命中、不回落'],
            ['opG', 'flowG', self::AT, 'gGlobal', 'G 精确作用域最新一条停用 ⇒ 由生效的兜底行接管（条款 1.4 后半句）'],
            ['opH', 'flowAny', self::AT, null, 'H 兜底作用域最新一条停用 ⇒ 不命中、不回落更旧兜底行'],
            ['opP', 'flowP', self::AT, 'pAgent', 'B 正向：作用域内只有一条窗内 enabled=1 ⇒ 命中'],
            ['opN', 'flowN', self::AT, 'nNewest', 'J 正向：最新一条生效、更旧两条不生效 ⇒ 命中最新那条'],
            ['opW', 'flowW', '2099-06-01 00:00:00', 'wNewest', '同数据按未来时刻查询 ⇒ 那条未来窗口记录生效（证明"窗外"是真判据而非恒不命中）'],
        ];
        foreach ($probes as [$operator, $pn, $at, $expect, $why]) {
            $mem = $this->memExt->getSurrogate($operator, $pn, $at);
            $sql = $this->pdoExt->getSurrogate($operator, $pn, $at);
            $memAgent = $mem === null ? null : (string) ($mem['surrogate'] ?? '');
            $sqlAgent = $sql === null ? null : (string) ($sql['surrogate'] ?? '');
            $memAgent = $memAgent === '' ? null : $memAgent;
            $sqlAgent = $sqlAgent === '' ? null : $sqlAgent;
            $this->assertSame($expect, $memAgent, "内存仓（{$why}）：{$operator}/{$pn}@{$at}");
            $this->assertSame($expect, $sqlAgent, "SQL 仓（{$why}）：{$operator}/{$pn}@{$at}");
            $this->assertSame($memAgent, $sqlAgent, "双仓必须同答案（{$why}）：{$operator}/{$pn}@{$at}");
        }
    }

    // ═══ 2. 自动生效打在 wf_process_task_actor 真行上（条款 2 铁证）═══

    /**
     * 发起 + 办理推进两条路径都要落真表：断言读的是 `SELECT actor_id FROM wf_process_task_actor`。
     * 回退自证（注掉 `JeeflowEngine::applySurrogate` 那一步）时本用例即红，
     * 失败断言原文形如「apply 任务的 wf_process_task_actor 真行须含代理人」。
     */
    public function testAutoApplyLandsOnRealActorRowsOnStartAndOnTransition(): void
    {
        $defineId = $this->deploy('02-multi-task.json');
        $this->seed('4001', 'multi-task', 'user1', 'lisi');
        $this->seed('4002', '', 'leader', 'wangwu');   // 空流程名兜底，覆盖推进出的新单

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $this->pdo->query('SELECT id FROM wf_process_instance LIMIT 1')
            ->fetchColumn();

        $applyIds = $this->taskIdsByName($instanceId, 'apply');
        $this->assertCount(1, $applyIds, '前置：apply 任务应落库 1 行');
        $this->assertSame(['user1', 'lisi'], $this->actorsOf($applyIds[0]),
            '发起任务的 wf_process_task_actor 真行须含代理人（原人保留）');

        $task1Ids = $this->taskIdsByName($instanceId, 'task1');
        $this->assertCount(1, $task1Ids, '前置：推进出的 task1 应落库 1 行');
        $this->assertSame(['leader', 'wangwu'], $this->actorsOf($task1Ids[0]),
            '推进出的新单同样要落代理人（只挂发起一处就漏在这里）');

        // 待办读回：代理人与原人都能看到这条单
        $todoAgent = $this->facade->flow('processTask/todoList', ['operator' => 'wangwu']);
        $this->assertSame(0, $todoAgent['code'], json_encode($todoAgent, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$task1Ids[0]], array_map(strval(...), array_column($todoAgent['data']['rows'], 'id')));
        $todoOwner = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertCount(1, $todoOwner['data']['rows'], '委托不是转办：原授权人待办保留');

        // 再推进一格（task2=manager，无委托）→ 参与者零改动，证明没把无关人卷进来
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $task1Ids[0], 'operator' => 'wangwu', 'submitType' => 1,
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));
        $task2 = $this->taskIdsByName($instanceId, 'task2');
        $this->assertSame(['manager'], $this->actorsOf($task2[0]));
    }

    /** 负向：窗外 / 自委托 / 停用 / 脏值——真表一行都不许多写。 */
    public function testNegativeCriteriaWriteNoExtraActorRows(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $this->seed('4101', 'simple', 'user1', 'sOut', startTime: '2099-01-01 00:00:00');
        $this->seed('4102', 'simple', 'leader', 'leader');               // 自委托
        $this->seed('4103', 'simple', 'leader', 'sOff', enabled: 0);
        $this->seed('4104', 'simple', 'leader', 'sDirty', enabled: 'abc');

        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $this->pdo->query('SELECT id FROM wf_process_instance LIMIT 1')->fetchColumn();
        foreach (['apply' => ['user1'], 'task1' => ['leader']] as $node => $expect) {
            foreach ($this->taskIdsByName($instanceId, $node) as $tid) {
                $this->assertSame($expect, $this->actorsOf($tid), "{$node} 窗外/自委托/停用/脏值一律不落代理人行");
            }
        }
        foreach (['sOut', 'sOff', 'sDirty'] as $agent) {
            $this->assertCount(0, $this->facade->flow('processTask/todoList', ['operator' => $agent])['data']['rows'],
                "{$agent} 不该看到任何待办");
        }
    }

    /** 条款 3：`surrogateAutoApply=false` 关闭后回到"仅台账"——委托照存照查，真表不多写行。 */
    public function testSwitchOffLeavesLedgerButWritesNoActorRows(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json);
        $repo = new PdoProcessRepository($this->pdo);
        $ext = new PdoProcessExtRepository($this->pdo);
        $facade = new JeeflowFacade(new JeeflowEngine($repo, surrogateAutoApply: false), $repo, $ext);

        $d = $facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $d['code'], json_encode($d, JSON_UNESCAPED_UNICODE));
        $ext->saveSurrogate(['id' => '4105', 'operator' => 'leader', 'surrogate' => 'sOn',
            'processName' => 'simple', 'enabled' => 1]);

        $s = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $d['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $s['code'], json_encode($s, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $this->pdo->query('SELECT id FROM wf_process_instance LIMIT 1')->fetchColumn();
        foreach ($this->taskIdsByName($instanceId, 'task1') as $tid) {
            $this->assertSame(['leader'], $this->actorsOf($tid), '关闭后真表不得多写代理人行');
        }

        // 台账侧不受影响：委托仍查得到、门面 detail 仍读得出
        // issues/152 ②：page 的归属列由门面注入（t.operator EQ operatorOf），查 leader 名下
        // 台账要显式带 operator=leader；m_EQ_operator 保留，两档同值不互相抵消
        $page = $facade->flow('processSurrogate/page', ['operator' => 'leader', 'm_EQ_operator' => 'leader']);
        $this->assertSame(0, $page['code'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $page['data']['recordCount'], '关闭只关运行期应用，台账不关');
    }

    /**
     * 写侧判据的 SQL 路 + 跨层对拍（06 §4.5 条款 5「写侧」/条款 6）。
     *
     * 三件事一条用例钉住：
     * 1. `enabled` 传 `''` / `'abc'` / `'1abc'` 之类经门面 save 落 PDO **不得抛错**
     *    （MySQL 严格模式对 `'abc'`→INT 直接 1366，非严格模式又会把 `'1abc'` 隐式转成 **1**=启用，
     *    两种都跟契约相反）——落库前按契约归一为整数，绑参就不再触发隐式转换；
     * 2. 落库值：缺键/`'1'`/`true` → 1，`''`/脏值/`false`/`0` → 0；
     * 3. **读写同一套语义**：写侧落 0 的行，读侧（真表 `wf_process_task_actor` 的参与者行）
     *    必须查不到生效委托；落 1 的必须查到。
     */
    public function testEnabledWriteSideIsNormalizedOnPdoAndAgreesWithReadSide(): void
    {
        $defineId = $this->deploy('01-simple.json');
        // [入参, 期望落库, 期望读侧生效]
        $cases = [
            ['', 0, false], ['abc', 0, false], ['1abc', 0, false], ['1x', 0, false],
            ['1', 1, true], [1, 1, true], [true, 1, true],
            [false, 0, false], [0, 0, false], [2, 2, false],
        ];
        foreach ($cases as $i => [$input, $stored, $effective]) {
            $this->pdo->exec('DELETE FROM wf_process_surrogate');   // 每轮一条台账，避免上一轮的生效行串味
            $agent = 'pwAgent' . $i;
            $label = var_export($input, true);

            $save = $this->facade->flow('processSurrogate/save', [
                'processName' => 'simple', 'surrogate' => $agent, 'operator' => 'leader',
                'startTime' => '2020-01-01 00:00:00', 'endTime' => '2099-12-31 23:59:59',
                'enabled' => $input,
            ]);
            $this->assertSame(0, $save['code'],
                "PDO 写侧 enabled={$label} 不得抛错（门面须吞成失败信封之外正常落库）："
                . json_encode($save, JSON_UNESCAPED_UNICODE));
            $id = (string) $save['data']['id'];

            $detail = $this->facade->flow('processSurrogate/detail', ['id' => $id]);
            $this->assertSame($stored, (int) $detail['data']['enabled'], "门面回显 enabled={$label}");
            $stmt = $this->pdo->prepare('SELECT enabled FROM wf_process_surrogate WHERE id = ?');
            $stmt->execute([$id]);
            $this->assertSame($stored, (int) $stmt->fetchColumn(),
                "真表列值 enabled={$label} 须按契约归一（PDO 侧挡住 MySQL 隐式转换）");

            $start = $this->facade->flow('processDefine/startAndExecute', [
                'processDefineId' => $defineId, 'operator' => 'user1',
            ]);
            $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
            $instanceId = (string) $start['data']['processInstanceId'];
            $task1 = $this->taskIdsByName($instanceId, 'task1');
            $this->assertCount(1, $task1, '前置：task1 应落库 1 行');
            $this->assertSame($effective ? ['leader', $agent] : ['leader'], $this->actorsOf($task1[0]),
                "跨层对拍 enabled={$label}：写侧落 {$stored} → 读侧生效=" . var_export($effective, true));
        }
    }

    /**
     * issues/130 案 A 的**另一半**：判据④只认整数 1 之后，SQL 路的「驱动字符串化」必须照常通。
     *
     * 现网 `enabled` 是 INT/tinyint(1) 列，而 PDO 在缓冲查询下（mysqlnd 默认行为；本仓 MySQL 套件
     * 显式 `ATTR_EMULATE_PREPARES => true`）把数值列**一律回读成 PHP 字符串**。旧实现靠读侧 `(int)`
     * 强转兜住这一步，案 A 把接受集合收窄到整数之后兜不住了 ⇒ 还原动作挪到**驱动边界**
     * （`newestSurrogateInScope` 交判据前调 `SurrogateRule::hydrateEnabled()`，对齐 Java `rs.getInt` /
     * Go `Scan(&int)` / C# `GetFieldValue<int>`）。真 MySQL 那格在本机恒 skip，不补这一格的话
     * "收窄"会让 MySQL 宿主的委托**整体判废且零告警**，所以这里用 `ATTR_STRINGIFY_FETCHES`
     * 在 SQLite 上等价复现"驱动给串"的形态。
     *
     * 同用例钉住边界**不是**把 `(int)` 换个地方做：真表里是文本的脏值（`'abc'`，SQLite 整数列
     * 存不进去会原样回读文本）仍判停用；且两仓同答案（用例 27）不因收窄而破。
     */
    public function testDriverStringifiedEnabledIsHydratedAtTheRepositoryBoundary(): void
    {
        $this->seed('4301', 'flowStr', 'opStr', 'sStrOne');            // 写侧归一 ⇒ 真表落整数 1
        $this->pdo->exec("INSERT INTO wf_process_surrogate (id, process_name, operator, surrogate, enabled)
            VALUES ('4302','flowTxt','opTxt','sTxtOff','abc')");       // 绕过写侧：真表里就是文本脏值

        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $hit = $this->pdoExt->getSurrogate('opStr', 'flowStr', self::AT);
        $this->assertNotNull($hit, '驱动把 INT 列回读成字符串 \'1\' 时 SQL 路仍须命中'
            . '（案 A 的驱动边界归一；漏归一＝MySQL 宿主委托整体判废）');
        $this->assertSame('sStrOne', (string) $hit['surrogate']);
        $this->assertSame(1, $hit['enabled'], '交判据④之前，行里的 enabled 须已在驱动边界还原成 PHP 整数');

        $dirty = $this->pdoExt->getSurrogate('opTxt', 'flowTxt', self::AT);
        $this->assertNull($dirty, '边界只还原规范整数串：真表列值是文本 \'abc\' 仍判停用'
            . '（不是把 (int) 强转换个地方做）');

        // 用例 27 不受影响：同一份台账在内存仓给出同一个结论
        $mem = $this->memExt->getSurrogate('opStr', 'flowStr', self::AT);
        $this->assertNotNull($mem, '双仓同答案：内存仓该档同样命中');
        $this->assertSame((string) $hit['surrogate'], (string) $mem['surrogate']);
    }

    // ═══ issues/152 ② · 委托分页归属不变式的第二层（绕过门面直调仓储，双仓同答案）═══

    /**
     * issues/152 ②（spec 06 §4.5「归属不变式」仓储层 + 条款 6「内存仓与 SQL 仓同答案」）：
     * 绕过门面直接调 `pageSurrogates`，落在归属列 `t.operator` 上的 EQ 条件值为空
     * （null / 空串 / 全空白）⇒ **空页**，绝不允许"这条条件不加"退化成全库台账。
     *
     * 两仓共用这一条用例：PDO 仓出 `AND 1=0`（SQLite 内存库，不依赖 160 MySQL），
     * 内存仓判该行不命中——同一份数据两个答案即缺陷（issues/117 场景 27 立过法的形状）。
     * 哨兵：非归属列（t.process_name）的空值仍按"没填"放行，收紧只落在归属列上。
     */
    public function testBlankOwnershipOnSurrogatePageIsEmptyPageInBothRepositories(): void
    {
        $this->seed('4501', 'flowOwn', 'opOwn', 'sOwn');
        $this->seed('4502', 'flowOwn', 'opOther', 'sOther');

        $blankOwnership = function (mixed $value): PageQuery {
            $q = new PageQuery(1, 50);
            $q->add('t.operator', 'EQ', $value);
            return $q;
        };

        // 对照：真实归属值照常过滤（两仓各 1 行）——否则下面的 0 行是恒真空
        $this->assertCount(1, $this->pdoExt->pageSurrogates($blankOwnership('opOwn'))->getRows(),
            'SQL 仓对照：真实归属列必须出行');
        $this->assertCount(1, $this->memExt->pageSurrogates($blankOwnership('opOwn'))->getRows(),
            '内存仓对照：真实归属列必须出行（与 SQL 仓同答案）');

        foreach ([['null', null], ['空串', ''], ['全空白', "  \t "]] as [$label, $value]) {
            $sqlPage = $this->pdoExt->pageSurrogates($blankOwnership($value));
            $memPage = $this->memExt->pageSurrogates($blankOwnership($value));
            $this->assertSame([], $sqlPage->getRows(), "SQL 仓：t.operator 遇 {$label} ⇒ 空页，不得全库");
            $this->assertSame(0, $sqlPage->getRecordCount(), "SQL 仓：{$label} 档 total 也要归 0");
            $this->assertSame([], $memPage->getRows(), "内存仓：t.operator 遇 {$label} ⇒ 空页（与 SQL 仓同答案，条款 6）");
            $this->assertSame(0, $memPage->getRecordCount(), "内存仓：{$label} 档 total 也要归 0");
        }

        // 哨兵：可选过滤列的空值放行不动（两仓同样仍放行 ⇒ 2 行）
        // 缺失档同判之后，可选过滤的哨兵要挂在**归属有效**那一形上
        // （两仓同样：process_name 传空串仍按"没填"，opOwn 名下 1 行照出；
        //  若有人把"归属空值即空页"推广到整条 WHERE，这里就从 1 变 0）。
        $optional = new PageQuery(1, 50);
        $optional->add('t.process_name', 'EQ', '');
        $optional->add('t.operator', 'EQ', 'opOwn');
        $this->assertCount(1, $this->pdoExt->pageSurrogates($optional)->getRows(),
            '哨兵：t.process_name（非归属）传空串仍按"没填"处理');
        $this->assertCount(1, $this->memExt->pageSurrogates($optional)->getRows(),
            '哨兵：内存仓同答案——可选过滤没被一起收进空页');
        // 缺失档：整条归属条件都没给 ⇒ 空页（spec 06 §2.5 归属条件必填，缺失与空值同判）
        $this->assertCount(0, $this->pdoExt->pageSurrogates(new PageQuery(1, 50))->getRows(),
            'SQL 仓缺失档 ⇒ 空页，不得读全库两条');
        $this->assertCount(0, $this->memExt->pageSurrogates(new PageQuery(1, 50))->getRows(),
            '内存仓缺失档同答案');
        // 哨兵：只收 EQ，归属列 NE + 空值不收紧（对齐 java 那句只判 "EQ"）
        $ne = new PageQuery(1, 50);
        $ne->add('t.operator', 'NE', '');
        $ne->add('t.operator', 'EQ', 'opOwn');   // 缺归属会被缺失档拦掉 ⇒ 这格必须给有效归属才照得见 NE 那半
        $this->assertCount(1, $this->pdoExt->pageSurrogates($ne)->getRows(),
            '哨兵：归属列 NE + 空值不收紧（本次只收 EQ）');
    }

    // ── 辅助 ──

    private function deploy(string $file): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/' . $file);
        $this->assertNotFalse($json);
        $r = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return (string) $r['data']['processDefineId'];
    }

    private function seed(string $id, string $processName, string $operator, string $agent,
                          ?string $startTime = '2020-01-01 00:00:00',
                          ?string $endTime = '2099-12-31 23:59:59', mixed $enabled = 1): void
    {
        $row = ['id' => $id, 'processName' => $processName, 'operator' => $operator,
                'surrogate' => $agent, 'startTime' => $startTime, 'endTime' => $endTime,
                'enabled' => $enabled];
        $this->pdoExt->saveSurrogate($row);
        $this->memExt->saveSurrogate($row);
    }

    /** @return string[] */
    private function taskIdsByName(string $instanceId, string $taskName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM wf_process_task WHERE process_instance_id = ? AND task_name = ? ORDER BY id');
        $stmt->execute([$instanceId, $taskName]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** 读回真表：wf_process_task_actor 的 actor_id 行（按插入序 id） */
    private function actorsOf(string $taskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ? ORDER BY id');
        $stmt->execute([$taskId]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function schema(): string
    {
        return <<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, display_name TEXT NOT NULL, type TEXT NULL,
  state INTEGER NULL, content TEXT NULL, version INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL
);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY, parent_id TEXT NULL, process_define_id TEXT NULL, state INTEGER NULL,
  parent_node_name TEXT NULL, business_no TEXT NULL, operator TEXT NULL, expire_time TEXT NULL,
  variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL
);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, task_name TEXT NOT NULL,
  display_name TEXT NOT NULL, task_type INTEGER NULL, perform_type INTEGER NULL, task_state INTEGER NULL,
  operator TEXT NULL, finish_time TEXT NULL, expire_time TEXT NULL, form_key TEXT NULL,
  task_parent_id TEXT NULL, variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL
);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY, process_task_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  create_time TEXT NULL, create_user TEXT NULL
);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL
);
CREATE TABLE wf_process_surrogate (
  id TEXT NOT NULL PRIMARY KEY, process_name TEXT NULL, operator TEXT NULL, surrogate TEXT NULL,
  start_time TEXT NULL, end_time TEXT NULL, enabled INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL
);
SQL;
    }
}
