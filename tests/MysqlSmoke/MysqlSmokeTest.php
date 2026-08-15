<?php

declare(strict_types=1);

namespace Jeeflow\Tests\MysqlSmoke;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
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
