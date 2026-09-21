<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
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
 *    内存仓与 SQL 仓，七条探针（多条命中取 id 最大 / 空 processName 兜底 / enabled=0 /
 *    窗外 / 自委托过滤 / enabled 脏值 / 半开窗口）逐条断言两仓给出**同一个**代理人。
 *    这正是 issues/116 §5 记的 PHP 分叉点（自委托过滤两仓都没有、内存仓多条命中"取遍历首条"）。
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
            ['3001', 'op1', 'flowA', 'sA1', '2020-01-01 00:00:00', '2099-12-31 23:59:59', 1],
            ['3002', 'op1', 'flowA', 'sA2', null,                   null,                  1], // 与 3001 同流程多条命中
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
            ['op1', 'flowA', 'sA2', '条款 1.4 多条命中取 id 最大（内存仓不得取遍历首条）'],
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
        $page = $facade->flow('processSurrogate/page', ['m_EQ_operator' => 'leader']);
        $this->assertSame(0, $page['code'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $page['data']['recordCount'], '关闭只关运行期应用，台账不关');
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
