<?php

declare(strict_types=1);

namespace Jeeflow\Tests\MysqlSmoke;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\NoOpTransactionTemplate;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Persist\DynamicTableWriter;
use Jeeflow\Persist\Interceptor\PersistPostInterceptor;
use Jeeflow\Persist\PdoDynamicTableWriter;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\RepositoryPDO\PdoValue;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * T1 MySQL 冒烟（1.1.2 发版门票）—— issues/67 / 68 / 69
 *
 * 独立库 jeeflow_php_t1，不写业务库。BIGINT 主键复现 PDO 返回 int。
 *
 * 环境：JEFFLOW_DB_HOST/PORT/USER/PWD（默认 192.168.1.160:3306 root / AGENTS.md 测试密码）
 * SKIP_MYSQL=1     开发机跳过
 * REQUIRE_MYSQL=1  发版机连不上即失败（不要 skip）
 */
class MysqlSmokeTest extends TestCase
{
    private const ISOLATED_DB = 'jeeflow_php_t1';

    private static ?\PDO $pdo = null;
    private static string $skipReason = '';

    private PdoProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    public static function setUpBeforeClass(): void
    {
        if (getenv('SKIP_MYSQL') === '1') {
            self::$skipReason = 'SKIP_MYSQL=1';
            return;
        }

        $host = getenv('JEFFLOW_DB_HOST') ?: '192.168.1.160';
        $port = getenv('JEFFLOW_DB_PORT') ?: '3306';
        $user = getenv('JEFFLOW_DB_USER') ?: 'root';
        $pwd = getenv('JEFFLOW_DB_PWD');
        if ($pwd === false || $pwd === '') {
            $pwd = ($host === '127.0.0.1' || $host === 'localhost') ? '' : '8Eli#gr#AUk';
        }

        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $pwd,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => true,
                ]
            );
            $pdo->exec('DROP DATABASE IF EXISTS `' . self::ISOLATED_DB . '`');
            $pdo->exec('CREATE DATABASE `' . self::ISOLATED_DB . '` DEFAULT CHARSET utf8mb4');
            $pdo->exec('USE `' . self::ISOLATED_DB . '`');
            $pdo->exec(self::schemaSql());
            self::$pdo = $pdo;
        } catch (\PDOException $e) {
            self::$skipReason = 'MySQL 不可用: ' . $e->getMessage();
            if (getenv('REQUIRE_MYSQL') === '1') {
                throw new \RuntimeException(self::$skipReason, 0, $e);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo === null) {
            return;
        }
        try {
            self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::ISOLATED_DB . '`');
        } catch (\PDOException) {
            // ignore
        }
        self::$pdo = null;
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
        $pdo = self::$pdo;
        $this->assertNotNull($pdo);
        $pdo->exec('USE `' . self::ISOLATED_DB . '`');
        $pdo->exec('DELETE FROM wf_process_task_actor');
        $pdo->exec('DELETE FROM wf_process_task');
        $pdo->exec('DELETE FROM wf_process_instance');
        $pdo->exec('DELETE FROM wf_process_cc_instance');
        $pdo->exec('DELETE FROM wf_process_define');
        $pdo->exec('DELETE FROM wf_process_surrogate');
        $pdo->exec('DELETE FROM biz_order');

        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new NoOpTransactionTemplate());

        $this->repo = new PdoProcessRepository($pdo);
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);

        $writer = new PdoDynamicTableWriter($pdo, 'mysql');
        ServiceContext::put(DynamicTableWriter::class, $writer);
        FlowInterceptorRegistry::register(
            PersistPostInterceptor::JAVA_CLASS,
            new PersistPostInterceptor()
        );
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ModelParser::reset();
    }

    /** M1 issues/67：facade page 走生产 SQL（LIMIT 内联），五键齐全 */
    public function testM1PageFiveKeys(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->repo->addDefine([
                'id' => (string) (900000 + $i),
                'name' => "smoke-{$i}",
                'displayName' => "冒烟{$i}",
                'type' => 'approval',
                'state' => 1,
                'content' => '{}',
                'version' => 1,
            ]);
        }
        $result = $this->facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 2]);
        $this->assertSame(0, $result['code'], (string) ($result['msg'] ?? ''));
        $data = $result['data'];
        foreach (['pageNum', 'pageSize', 'recordCount', 'totalPage', 'rows'] as $key) {
            $this->assertArrayHasKey($key, $data, "缺分页键 {$key}");
        }
        $this->assertSame(1, $data['pageNum']);
        $this->assertSame(2, $data['pageSize']);
        $this->assertSame(3, $data['recordCount']);
        $this->assertSame(2, $data['totalPage']);
        $this->assertCount(2, $data['rows']);
        $this->assertIsString($data['rows'][0]['id']);
    }

    /** M2 issues/68：BIGINT 主键 PDO 可能是 int，hydrate 必须是 string */
    public function testM2HydrateStringIds(): void
    {
        $this->addPersistDefine('900010', 'ARCHIVE', false);
        $instance = $this->engine->startProcessInstanceById('900010', 'user1', FlowData::of(['f_title' => 'id']));
        $this->assertIsString($instance->getInstanceId());

        $task = $instance->getDoingTasks()[0];
        $this->assertIsString($task->getTaskId());

        $loaded = $this->repo->findTaskById($task->getTaskId());
        $this->assertNotNull($loaded);
        $this->assertIsString($loaded->getTaskId());
        $this->assertIsString($loaded->getProcessInstanceId());

        $raw = self::$pdo->query(
            'SELECT id FROM wf_process_task WHERE id = ' . (int) $task->getTaskId()
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($raw);
        $this->assertSame($task->getTaskId(), PdoValue::strId($raw['id']));
        if (is_int($raw['id'])) {
            $this->assertSame((string) $raw['id'], $loaded->getTaskId());
        }
    }

    /** M3 issues/69：ARCHIVE 落表；information_schema 列名大小写 */
    public function testM3ArchiveInsert(): void
    {
        $this->addPersistDefine('900020', 'ARCHIVE', false);
        $instance = $this->engine->startProcessInstanceById('900020', 'user1', FlowData::of([
            'f_title' => 'hello',
            'f_amount' => '9',
        ]));
        $apply = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'f_title' => 'hello',
            'f_amount' => '9',
        ]));
        $row = $this->fetchBiz($instance->getInstanceId());
        $this->assertNotNull($row, 'ARCHIVE 同意应 INSERT');
        $this->assertSame('hello', $row['title']);
        $this->assertSame('9', (string) $row['amount']);
        $this->assertSame('user1', $row['apply_user_id']);
    }

    /** M4 issues/69：SYNC 发起 INSERT，办理只改有权限字段 */
    public function testM4SyncFieldPermission(): void
    {
        $this->addPersistDefine('900030', 'SYNC', true);
        $instance = $this->engine->startProcessInstanceById('900030', 'user1', FlowData::of([
            'f_title' => 'orig',
            'f_amount' => '1',
        ]));
        $row = $this->fetchBiz($instance->getInstanceId());
        $this->assertNotNull($row, 'SYNC 发起应 INSERT');
        $this->assertSame('orig', $row['title']);

        $apply = $this->repo->findInstanceById($instance->getInstanceId())->getDoingTasks()[0];
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'f_title' => 'HACKED',
            'f_amount' => '99',
        ]));
        $row = $this->fetchBiz($instance->getInstanceId());
        $this->assertSame('orig', $row['title'], '只读 title 不被办理改掉');
        $this->assertSame('99', (string) $row['amount']);
    }

    /**
     * M5 issues/113：门面 withdraw 须把进行中任务以 30（WITHDRAW）落 MySQL。
     * PHP 此前只有模型层 AggregateRootTest 断过 30，PDO 落库层零覆盖；
     * go/python/node/rust 四栈的缺陷恰好都藏在门面分支与 SQL 回写之间。
     */
    public function testM5WithdrawPersistsTaskState30(): void
    {
        $this->addPersistDefine('900040', 'ARCHIVE', false);
        $instance = $this->engine->startProcessInstanceById('900040', 'user1', FlowData::of(['f_title' => 'w']));
        $doing = $instance->getDoingTasks();
        $this->assertNotEmpty($doing, '撤回前应有进行中任务');

        $r = $this->facade->flow('processInstance/withdraw', [
            'id' => $instance->getInstanceId(),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));

        foreach ($doing as $task) {
            $raw = self::$pdo->query(
                'SELECT task_state FROM wf_process_task WHERE id = ' . (int) $task->getTaskId()
            )->fetch(\PDO::FETCH_ASSOC);
            $this->assertNotFalse($raw);
            $this->assertSame(ProcessTaskState::WITHDRAW, (int) $raw['task_state'],
                '撤回任务落库应=30(WITHDRAW)，99(ABANDON) 是废弃码');
        }
        $this->assertCount(0, $this->repo->findDoingTasks($instance->getInstanceId()), '撤回后不应再有进行中任务');
    }

    /**
     * M6 issues/115：门面 processTask/transfer 须真落 MySQL 行——
     * 参与者表摘原人/加新人、variable 列 JSON 里 tf_transferHistory 是「数组套对象」
     * （六键 camelCase + time 为 yyyy-MM-dd HH:mm:ss 串，中文 reason 过 utf8mb4 往返）、
     * wf_process_task.operator 列**恒 NULL**（契约严禁覆写；写进去会让撤回后的单凭空出现在
     * 被摘走的人的 doneList 里，因为 pageDoneTasks 按 state<>10 AND operator=? 过滤）。
     */
    public function testM6TransferPersistsLedgerAndNeverWritesOperatorColumn(): void
    {
        $this->addPersistDefine('900050', 'ARCHIVE', false);
        $instance = $this->engine->startProcessInstanceById('900050', 'user1', FlowData::of(['f_title' => '转办']));
        $task = $instance->getDoingTasks()[0];
        $taskId = (string) $task->getTaskId();
        $instanceId = (string) $instance->getInstanceId();
        $this->assertSame(['user1'], $this->actorsOf($taskId), '前置：参与者只有发起人');

        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'user1', 'toActor' => 'lisi',
            'reason' => '出差一周 αβγ', 'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($r['data']);

        // 参与者表：摘 user1、加 lisi（同一 taskId）
        $this->assertSame(['lisi'], $this->actorsOf($taskId));
        $raw = self::$pdo->query('SELECT * FROM wf_process_task WHERE id = ' . (int) $taskId)
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($raw);
        $this->assertNull($raw['operator'], 'PDO 路：转办严禁覆写 wf_process_task.operator 列');
        $this->assertSame('user1', $raw['update_user'], '办理人记谁由 update_user 承载');
        $this->assertSame(ProcessTaskState::DOING, (int) $raw['task_state'], '转办不置任务态');

        // variable 列 JSON：数组套对象 + 六键同序 + 中文往返 + time 跨栈格式
        $decoded = json_decode((string) $raw['variable'], true);
        $this->assertSame(7, $decoded['submitType'] ?? null, '留痕① submitType=7 落库');
        $ledger = $decoded['tf_transferHistory'] ?? null;
        $this->assertIsArray($ledger, '留痕② 落库应为 JSON 数组');
        $this->assertCount(1, $ledger);
        $this->assertSame(['submitType', 'fromActor', 'toActor', 'reason', 'time', 'operator'],
            array_keys($ledger[0]));
        $this->assertSame('出差一周 αβγ', $ledger[0]['reason'], '中文 reason 须原样往返（utf8mb4）');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $ledger[0]['time'],
            'time 须 yyyy-MM-dd HH:mm:ss 字符串，不得用 ISO 方言（跨栈同形）');
        $this->assertSame('user1 转办给 lisi（出差一周 αβγ）', $decoded['tf_approvalComment'] ?? null,
            '留痕③ 末跳可读文案主语是 fromActor');
        $this->assertSame('lisi', $decoded['tf_transferTo'] ?? null);
        $this->assertSame('出差一周 αβγ', $decoded['tf_transferReason'] ?? null);

        // 撤回整单后再查 operator 列与 doneList 红线（pageDoneTasks = state<>10 AND operator=?）
        $w = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'lisi']);
        $this->assertSame(0, $w['code'], json_encode($w, JSON_UNESCAPED_UNICODE));
        $after = self::$pdo->query('SELECT operator, task_state, update_user FROM wf_process_task WHERE id = '
            . (int) $taskId)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(ProcessTaskState::WITHDRAW, (int) $after['task_state']);
        $this->assertNull($after['operator'], '撤回后 operator 列仍须 NULL（转办没写过它）');
        $this->assertSame('lisi', $after['update_user']);

        $done = $this->facade->flow('processTask/doneList', ['operator' => 'user1']);
        $this->assertSame(0, $done['code'], json_encode($done, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], array_values(array_filter(
            $done['data']['rows'], fn($row) => (string) ($row['processInstanceId'] ?? '') === $instanceId
        )), '被摘走的 user1 不得在「我已办」凭空看到这条他没办过的单');
        // 转办不存在的任务 / 非进行中：明确报错且不动参与者表
        $bad = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'lisi', 'toActor' => 'wangwu', 'operator' => 'lisi',
        ]);
        $this->assertSame(99999999, $bad['code']);
        $this->assertSame('任务非进行中，不可转办', $bad['msg']);
        $this->assertSame(['lisi'], $this->actorsOf($taskId), '被拒转办不得改参与者表');
    }

    /**
     * M7 issues/116 批次 D：委托代理自动生效必须在**真机 MySQL** 上落进 `wf_process_task_actor` 真行。
     *
     * 断言全部读真表（不是返回码、不是内存集合）：
     * - 正向：发起那一刻的任务（apply，委托 user1→m7lisi）与**办理推进**出的新单（task1，
     *   空 processName 兜底委托 leader→m7wang）都要各多出一行代理人，且原授权人那行仍在；
     * - 负向：窗外 / enabled=0 / 自委托 三种台账行不产生任何 actor 行
     *   （enabled 脏值探针不在此列——本表 `enabled INT`，MySQL 存不进 'abc'，
     *   该判据只在内存仓与 SQLite 弱类型路径上有分叉空间，见 PdoSqliteSurrogateTest）；
     * - 回归：`surrogateAutoApply: false` 关闭后（扩展仓储仍在场）真表回到只有原参与者。
     */
    public function testM7SurrogateAutoApplyLandsOnRealActorRows(): void
    {
        $ext = new \Jeeflow\RepositoryPDO\PdoProcessExtRepository(self::$pdo);
        $facade = new JeeflowFacade($this->engine, $this->repo, $ext);   // 门面构造把仓储桥进 ServiceContext

        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json);
        $deploy = $facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $defineId = (string) $deploy['data']['processDefineId'];
        // 前置：deploy 的 name 不变量（06 §4.5 条款 1.1）——委托台账认的就是库里这一列
        $this->assertSame('simple', (string) self::$pdo->query(
            'SELECT name FROM wf_process_define WHERE id = ' . (int) $defineId)->fetchColumn());

        $seed = function (string $id, string $operator, string $agent, string $pn,
                          ?string $start, ?string $end, int $enabled) use ($ext): void {
            $ext->saveSurrogate(['id' => $id, 'operator' => $operator, 'surrogate' => $agent,
                'processName' => $pn, 'startTime' => $start, 'endTime' => $end, 'enabled' => $enabled]);
        };
        $seed('900060', 'user1', 'm7lisi', 'simple', '2020-01-01 00:00:00', '2099-12-31 23:59:59', 1);
        $seed('900061', 'leader', 'm7wang', '', null, null, 1);                        // 判据①兜底 + ②双侧不限
        $seed('900062', 'leader', 'm7off', 'simple', null, null, 0);                    // 判据④停用
        $seed('900063', 'leader', 'm7late', 'simple', '2099-01-01 00:00:00', null, 1);  // 判据②未到窗
        $seed('900064', 'manager', 'manager', 'simple', null, null, 1);                 // 判据③自委托

        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $applyIds = $this->taskIdsByName($instanceId, 'apply');
        $task1Ids = $this->taskIdsByName($instanceId, 'task1');
        $this->assertCount(1, $applyIds);
        $this->assertCount(1, $task1Ids, '前置：办理推进应落出 task1');
        $this->assertSame(['user1', 'm7lisi'], $this->actorsOf($applyIds[0]),
            '发起任务真表须多出一行代理人（原授权人保留）——Java 首版补写打在空 taskId 上就是没这一行');
        $this->assertSame(['leader', 'm7wang'], $this->actorsOf($task1Ids[0]),
            '推进出的新单真表同样要有代理人行（只挂发起一处会漏这条）');

        foreach (['m7off', 'm7late', 'manager'] as $shouldNot) {
            $cnt = (int) self::$pdo->query('SELECT COUNT(*) FROM wf_process_task_actor WHERE actor_id = '
                . self::$pdo->quote($shouldNot))->fetchColumn();
            $this->assertSame(0, $cnt, "{$shouldNot} 不该出现在参与者真表（停用/窗外/自委托）");
        }

        // 回归：关掉开关（扩展仓储仍在 ServiceContext）→ 真表回到仅原参与者
        $offEngine = new JeeflowEngine($this->repo, surrogateAutoApply: false);
        $offFacade = new JeeflowFacade($offEngine, $this->repo, $ext);
        $start2 = $offFacade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start2['code'], json_encode($start2, JSON_UNESCAPED_UNICODE));
        $inst2 = (string) $start2['data']['processInstanceId'];
        $this->assertSame(['user1'], $this->actorsOf($this->taskIdsByName($inst2, 'apply')[0]));
        $this->assertSame(['leader'], $this->actorsOf($this->taskIdsByName($inst2, 'task1')[0]),
            '关闭后回到"仅台账"行为');
        // 台账不关：processSurrogate/page 仍查得到
        $page = $offFacade->flow('processSurrogate/page', ['m_EQ_operator' => 'leader']);
        $this->assertSame(0, $page['code'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $page['data']['recordCount'], 'leader 名下三条台账（含停用/窗外）');
    }

    private function actorsOf(string $taskId): array
    {
        $stmt = self::$pdo->prepare('SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ? ORDER BY id');
        $stmt->execute([$taskId]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return string[] 同名单步（非会签）通常仅 1 行，按 id 升序 */
    private function taskIdsByName(string $instanceId, string $taskName): array
    {
        $stmt = self::$pdo->prepare('SELECT id FROM wf_process_task WHERE process_instance_id = ?'
            . ' AND task_name = ? ORDER BY id');
        $stmt->execute([$instanceId, $taskName]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function addPersistDefine(string $id, string $persistMode, bool $withPerm): void
    {
        $field = $withPerm
            ? ',"field":{"PERMISSION_f_title":1,"PERMISSION_f_amount":2}'
            : '';
        $json = <<<JSON
{
  "name": "persist_smoke_{$id}",
  "displayName": "persist smoke",
  "persistMode": "{$persistMode}",
  "relTableName": "biz_order",
  "postInterceptors": "com.mldong.jeeflow.persist.interceptor.PersistPostInterceptor",
  "nodes": [
    {"id":"start","type":"snaker:start","x":0,"y":0,"properties":{},"text":{"value":"开始"}},
    {"id":"apply","type":"snaker:task","x":0,"y":0,"properties":{"assignee":"applicant"{$field}},"text":{"value":"申请"}},
    {"id":"end","type":"snaker:end","x":0,"y":0,"properties":{},"text":{"value":"结束"}}
  ],
  "edges": [
    {"id":"e0","sourceNodeId":"start","targetNodeId":"apply","properties":{}},
    {"id":"e1","sourceNodeId":"apply","targetNodeId":"end","properties":{}}
  ]
}
JSON;
        $this->repo->addDefine([
            'id' => $id,
            'name' => "persist_smoke_{$id}",
            'displayName' => 'persist smoke',
            'type' => 'approval',
            'state' => 1,
            'content' => $json,
            'version' => 1,
        ]);
    }

    private function fetchBiz(?string $instanceId): ?array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM biz_order WHERE process_instance_id = ?');
        $stmt->execute([$instanceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function schemaSql(): string
    {
        return <<<'SQL'
CREATE TABLE wf_process_define (
  id BIGINT NOT NULL,
  name VARCHAR(64) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  type VARCHAR(32) NULL,
  state INT NULL,
  content TEXT NULL,
  version INT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_instance (
  id BIGINT NOT NULL,
  parent_id VARCHAR(64) NULL,
  process_define_id BIGINT NULL,
  state INT NULL,
  parent_node_name VARCHAR(100) NULL,
  business_no VARCHAR(64) NULL,
  operator VARCHAR(64) NULL,
  expire_time DATETIME(3) NULL,
  variable TEXT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_task (
  id BIGINT NOT NULL,
  process_instance_id BIGINT NOT NULL,
  task_name VARCHAR(100) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  task_type INT NULL,
  perform_type INT NULL,
  task_state INT NULL,
  operator VARCHAR(64) NULL,
  finish_time DATETIME(3) NULL,
  expire_time DATETIME(3) NULL,
  form_key VARCHAR(100) NULL,
  task_parent_id VARCHAR(64) NULL,
  variable TEXT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_task_actor (
  id VARCHAR(64) NOT NULL,
  process_task_id BIGINT NOT NULL,
  actor_id VARCHAR(64) NOT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_cc_instance (
  id VARCHAR(64) NOT NULL,
  process_instance_id VARCHAR(64) NOT NULL,
  actor_id VARCHAR(64) NOT NULL,
  state INT NULL DEFAULT 0,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_surrogate (
  id BIGINT NOT NULL,
  process_name VARCHAR(100) NULL,
  operator VARCHAR(64) NOT NULL,
  surrogate VARCHAR(64) NOT NULL,
  start_time DATETIME(3) NULL,
  end_time DATETIME(3) NULL,
  enabled INT NULL DEFAULT 1,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_process_surrogate_op (operator),
  KEY idx_process_surrogate_sur (surrogate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE biz_order (
  id BIGINT NOT NULL AUTO_INCREMENT,
  process_instance_id VARCHAR(64) NULL,
  apply_user_id VARCHAR(64) NULL,
  apply_dept_id VARCHAR(64) NULL,
  title VARCHAR(200) NULL,
  amount VARCHAR(64) NULL,
  create_time DATETIME NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME NULL,
  update_user VARCHAR(64) NULL,
  is_deleted INT DEFAULT 0,
  apply_10 INT NULL,
  apply INT NULL,
  end_20 INT NULL,
  end INT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }
}
