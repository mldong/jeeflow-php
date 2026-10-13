<?php

declare(strict_types=1);

namespace Jeeflow\Tests\MysqlSmoke;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * issues/191（audit8 R25）· 真库判据：fire 派发时序＝提交之后
 * （spec11 §11.3.1「派发时序对外承诺为'提交之后'」／spec12 §12.3.1「模板接到门面的栈 fire 点必须同步后移」）。
 *
 * 修法＝fire 委托模板可选 `afterCommit(callable)`（method_exists 探测，沿 `inTransaction()` 先例）：
 * 有活动事务且模板实现 afterCommit 时，广播动作交给模板（提交后执行、回滚丢弃）；
 * 否则回落立即广播（无该能力模板＝旧行为）。
 *
 * 三段判据（真库，php 侧自己的 sqlite/mysql 驱动即可——C4-L2 T-2 口径，不需要跨栈门禁）：
 *  ① 成功路径：事件在 COMMIT **之后**到达监听器——回调那一刻用独立连接（pdo2）反查，码 5 的
 *     task 行必须已是 task_state=20（修复前：事务内广播，pdo2 读到 10，本条必红）；
 *  ② 回滚路径：办理中途注入失败 ⇒ 动作整体回滚 ⇒ **监听器零记录**
 *     （修复前：码 5 已在事务内发出，本条必红）；
 *  ③ 回落面：模板未实现 afterCommit（旧形状）⇒ 立即广播，事件照常收到（兼容行为不变）；
 *  ④ 防线：afterCommit 通道自身抛错 ⇒ 回落立即广播，事件不丢、主流程不被打断。
 * 阳性对照：①③④ 证明监听器通道非空转；①② 互为反证（同一监听器，一边收到一边零记录）。
 *
 * 连接口径与 TxTier145Test 同：独立库 `jeeflow_php_e191`（160 MySQL，JEFFLOW_DB_* 可覆盖），不写业务库。
 * 运行：REQUIRE_MYSQL=1 php vendor/phpunit/phpunit/phpunit --filter EventAfterCommit191Test
 */
class EventAfterCommit191Test extends TestCase
{
    private const ISOLATED_DB = 'jeeflow_php_e191';
    private const DEFINE_SIMPLE = '900191';
    private const OPERATOR = 'e191probe';

    private static ?\PDO $pdo = null;
    private static ?\PDO $pdo2 = null;
    private static string $skipReason = '';
    private static int $pkBase = 0;
    private static int $pkSeq = 0;

    private EventAfterCommitFlakyRepo $repo;
    private JeeflowEngine $engine;

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
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pwd, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo->exec('DROP DATABASE IF EXISTS `' . self::ISOLATED_DB . '`');
            $pdo->exec('CREATE DATABASE `' . self::ISOLATED_DB . '` DEFAULT CHARSET utf8mb4');
            $pdo->exec('USE `' . self::ISOLATED_DB . '`');
            $pdo->exec(self::schemaSql());
            self::$pdo = $pdo;

            $pdo2 = new \PDO($dsn, $user, $pwd, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo2->exec('USE `' . self::ISOLATED_DB . '`');
            self::$pdo2 = $pdo2;

            self::$pkBase = (int) (microtime(true) * 1000) * 1000;
            self::insertDefine($pdo);
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
        self::$pdo2 = null;
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
        foreach (['wf_process_task_actor', 'wf_process_task', 'wf_process_instance',
                     'wf_process_cc_instance'] as $t) {
            self::$pdo->exec("DELETE FROM `{$t}`");
        }

        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $gen = static function (): int|string {
            return self::$pkBase + (++self::$pkSeq);
        };
        $idGen = new class($gen) implements IdGeneratorInterface {
            /** @var callable(): int|string */
            private $gen;

            public function __construct(callable $gen)
            {
                $this->gen = $gen;
            }

            public function nextId(): int|string
            {
                return ($this->gen)();
            }
        };

        $this->repo = new EventAfterCommitFlakyRepo(self::$pdo, $idGen);
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    // ─── ① 成功路径：事件在 COMMIT 之后到达监听器 ───────────────────────────

    public function test01SuccessPathDispatchesAfterCommit(): void
    {
        ServiceContext::put(TransactionTemplateInterface::class, new AfterCommitPdoTemplate(self::$pdo));

        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR);
        $iid = (string) $inst->getInstanceId();
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '前置：发起后应有 apply 待办');
        $taskId = (string) $doing[0]->getTaskId();

        $l = new EventAfterCommitRecordingListener(self::$pdo2);
        ProcessEventListenerRegistry::register($l);

        $this->repo->resetInject();
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $args->set(FlowConst::CC_ACTORS, 'e191-cc');
        $this->engine->executeProcessTask($taskId, self::OPERATOR, $args);

        $names = array_map(fn(ProcessEvent $e) => $e->getType()->name, $l->events);
        $this->assertSame(['TASK_COMPLETE', 'CC_CREATE', 'PROCESS_TASK_START'], $names,
            '派发顺序＝事务内 fire 顺序（延迟后按注册序保持）: ' . implode(',', $names));

        // 核心判据：码 5 回调那一刻，独立连接必须已读到"办结已提交"（20）——
        // 修复前事件在事务内广播，这一刻 pdo2 读到 10 ⇒ 必红
        $this->assertSame(20, $l->taskStateOnCompleteOtherConn,
            '派发必须在 COMMIT 之后：回调那一刻独立连接反查 task_state 须已是 20（实得 '
            . var_export($l->taskStateOnCompleteOtherConn, true) . '）');

        // 数据面照常：任务办结 + 新待办产生（独立连接核）
        $this->assertSame(20, $this->scalar('SELECT task_state FROM wf_process_task WHERE id = ?',
            [$taskId], self::$pdo2));
        $this->assertCount(1, $this->repo->findDoingTasks($iid), '流转到 task1 产生新待办');
    }

    // ─── ② 回滚路径：事件零派发 ─────────────────────────────────────────────

    public function test02RollbackPathDispatchesNothing(): void
    {
        ServiceContext::put(TransactionTemplateInterface::class, new AfterCommitPdoTemplate(self::$pdo));

        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR);
        $iid = (string) $inst->getInstanceId();
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '前置：发起后应有 apply 待办');
        $taskId = (string) $doing[0]->getTaskId();

        $l = new EventAfterCommitRecordingListener(self::$pdo2);
        ProcessEventListenerRegistry::register($l);

        $this->repo->resetInject(failCc: 1);
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $args->set(FlowConst::CC_ACTORS, 'e191-cc');
        $thrown = null;
        try {
            $this->engine->executeProcessTask($taskId, self::OPERATOR, $args);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, '注入失败必须外抛（注入点命中）');
        $this->assertSame([], $l->events,
            '回滚场景事件必须零派发（修复前：码 5 TASK_COMPLETE 已在事务内发出——本条必红）；实收='
            . implode(',', array_map(fn(ProcessEvent $e) => $e->getType()->name, $l->events)));

        // 回滚生效（独立连接核）
        $this->assertSame(10, $this->scalar('SELECT task_state FROM wf_process_task WHERE id = ?',
            [$taskId], self::$pdo2), '任务行必须回到 DOING(10)');
        $this->assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM wf_process_cc_instance WHERE process_instance_id = ?', [$iid], self::$pdo2),
            'cc 行零残留');
    }

    // ─── ③ 回落面：模板未实现 afterCommit ⇒ 立即广播（旧行为兼容）──────────

    public function test03LegacyTemplateFallsBackToImmediateBroadcast(): void
    {
        ServiceContext::put(TransactionTemplateInterface::class, new LegacyPdoTemplate(self::$pdo));

        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR);
        $iid = (string) $inst->getInstanceId();
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '前置：发起后应有 apply 待办');
        $taskId = (string) $doing[0]->getTaskId();

        $l = new EventAfterCommitRecordingListener(self::$pdo2);
        ProcessEventListenerRegistry::register($l);

        $this->repo->resetInject();
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $args->set(FlowConst::CC_ACTORS, 'e191-cc');
        $this->engine->executeProcessTask($taskId, self::OPERATOR, $args);

        $this->assertNotEmpty($l->events,
            '无 afterCommit 能力的模板必须回落立即广播（旧行为兼容，事件不丢）');
        $this->assertContains('TASK_COMPLETE',
            array_map(fn(ProcessEvent $e) => $e->getType()->name, $l->events), '码 5 照常收到');
    }

    // ─── ④ 防线：afterCommit 通道抛错 ⇒ 回落立即广播（事件不丢、主流程不被打断）────

    public function test04AfterCommitChannelFailureFallsBack(): void
    {
        ServiceContext::put(TransactionTemplateInterface::class, new ThrowingAfterCommitPdoTemplate(self::$pdo));

        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR);
        $iid = (string) $inst->getInstanceId();
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '前置：发起后应有 apply 待办');
        $taskId = (string) $doing[0]->getTaskId();

        $l = new EventAfterCommitRecordingListener(self::$pdo2);
        ProcessEventListenerRegistry::register($l);

        $this->repo->resetInject();
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $args->set(FlowConst::CC_ACTORS, 'e191-cc');
        // 通道故障不得打断主流程：动作照常成功返回
        $this->engine->executeProcessTask($taskId, self::OPERATOR, $args);

        $this->assertNotEmpty($l->events, 'afterCommit 通道抛错必须回落立即广播（事件不丢）');
        $this->assertContains('TASK_COMPLETE',
            array_map(fn(ProcessEvent $e) => $e->getType()->name, $l->events), '码 5 照常收到');
        $this->assertSame(20, $this->scalar('SELECT task_state FROM wf_process_task WHERE id = ?',
            [$taskId], self::$pdo2), '动作数据照常提交');
    }

    private function scalar(string $sql, array $args = [], ?\PDO $pdo = null): mixed
    {
        $stmt = ($pdo ?? self::$pdo)->prepare($sql);
        $stmt->execute($args);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    private static function insertDefine(\PDO $pdo): void
    {
        $simple = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        if ($simple === false) {
            throw new \RuntimeException('flows/01-simple.json 读不到：' . jeeflow_flows_dir());
        }
        $stmt = $pdo->prepare('DELETE FROM wf_process_define WHERE id = ?');
        $stmt->execute([self::DEFINE_SIMPLE]);
        $ins = $pdo->prepare('INSERT INTO wf_process_define (id, name, display_name, type, state,'
            . ' content, version, create_time, create_user, update_time, update_user)'
            . ' VALUES (?,?,?,?,1,?,1, NOW(3), ?, NOW(3), ?)');
        $ins->execute([self::DEFINE_SIMPLE, 'e191_simple', 'R25 判据简单流', 'approval',
            $simple, 'e191probe', 'e191probe']);
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
SQL;
    }
}

/**
 * 真模板（demo/laravel 修后同款语义）：required＝begin/commit/rollback；
 * afterCommit＝提交后执行、回滚丢弃、无事务立即执行（issues/191 契约）。
 */
class AfterCommitPdoTemplate implements TransactionTemplateInterface
{
    /** @var callable[] */
    private array $afterCommitCallbacks = [];

    public function __construct(private \PDO $pdo)
    {
    }

    public function required(callable $action): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $action();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->afterCommitCallbacks = [];
            $this->pdo->rollBack();
            throw $e;
        }
        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function afterCommit(callable $callback): void
    {
        if ($this->pdo->inTransaction()) {
            $this->afterCommitCallbacks[] = $callback;
            return;
        }
        $callback();
    }
}

/** afterCommit 通道故障模板：注册即抛（验证引擎的回落防线）。 */
final class ThrowingAfterCommitPdoTemplate extends AfterCommitPdoTemplate
{
    public function afterCommit(callable $callback): void
    {
        throw new \RuntimeException('injected: afterCommit channel broken');
    }
}

/** 旧形状模板：只有 required/inTransaction，无 afterCommit ⇒ 引擎必须回落立即广播。 */
final class LegacyPdoTemplate implements TransactionTemplateInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function required(callable $action): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $action();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}

/** 事件记录监听器：收码 5 时用独立连接反查 task 行（派发时序的核心证据）。 */
final class EventAfterCommitRecordingListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];
    public ?int $taskStateOnCompleteOtherConn = null;

    public function __construct(private \PDO $otherConn)
    {
    }

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
        if ($event->getType() === ProcessEventTypeEnum::TASK_COMPLETE && $event->getSourceId() !== null) {
            $st = $this->otherConn->prepare('SELECT task_state FROM wf_process_task WHERE id = ?');
            $st->execute([$event->getSourceId()]);
            $v = $st->fetchColumn();
            $this->taskStateOnCompleteOtherConn = $v === false ? null : (int) $v;
        }
    }
}

/** 注入失败（第 N 次 cc 调用抛错）；其余透传真仓储 */
final class EventAfterCommitFlakyRepo extends PdoProcessRepository
{
    public int $failCc = 0;
    public int $ccCalls = 0;

    public function resetInject(int $failCc = 0): void
    {
        $this->failCc = $failCc;
        $this->ccCalls = 0;
    }

    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array
    {
        $this->ccCalls++;
        if ($this->failCc !== 0 && $this->ccCalls === $this->failCc) {
            throw new \RuntimeException('injected: cc write failed mid-action');
        }
        return parent::createCcInstanceIfAbsent($instanceId, $operator, $actorIds);
    }
}
