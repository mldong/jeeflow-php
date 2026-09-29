<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Handler\CustomHandlerRegistry;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\Tests\Fixture\ExecutingCustomHandler;
use PHPUnit\Framework\TestCase;

/**
 * 记录类节点（`snaker:custom`）历史行的 **SQL 落库腿** —— issues/142 A 批 ·
 * spec 02-flow-definition.md §6.2 第 1 条（SQLite 内存库，不依赖 160 MySQL）。
 *
 * 本案第 1 条硬要求逐字是：「"落历史行"＝ `wf_process_task` 里**查得到**那一行
 * （`task_state=20`、参与者＝当前操作人、`task_parent_id` 与行级首节点标记照建单不变量走），
 * **只在聚合内存对象里 append 一条不算做到**。」
 *
 * 为什么必须单开这一件（而不是靠 `tests/Core/CustomNodeRecordLegTest` 就收工）：
 * 基准侧 java 犯的正是"形状全对、但那一条 DONE 行永远进不了库"——`persistTasks` 只保存
 * `exec.getProcessTaskList()`，`updateInstance` 的级联又只 UPDATE `taskId != null` 的行，
 * 而 java `ProcessTask.create` 从不赋 taskId；java 自家 `test08CustomNode` 对历史行零断言
 * ⇒ 那个洞测试照不出来。内存仓传对象引用，**照不出"没落库"这类病**（本仓
 * `InstanceEndEventTimingTest` 的注释早就记下同一条教训），只有 SQL 仓读真表能证伪。
 *
 * 判据形状：断言全部打在**裸 SQL SELECT 出来的那一行**上，并且用**第二个仓储实例**
 * 复用同一条连接再读一次（跨对象可见 ⇒ 证据在库里，不在某个 PHP 对象的数组里）。
 */
final class PdoSqliteCustomNodeHistoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private JeeflowEngine $engine;
    private CustomHandlerRegistry $handlers;
    private ?string $logFile = null;
    private string|false $savedErrorLog = false;

    /** start → apply(task, applicant) → custom1 → audit(task, leader) → end */
    private const CUSTOM_FLOW = <<<'JSON'
{"name":"custom-pdo","displayName":"记录类节点SQL路","type":"approval","nodes":[
 {"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},
 {"id":"apply","type":"snaker:task","x":240,"y":200,"properties":{"assignee":"applicant","taskType":0,"performType":0},"text":{"value":"发起申请"}},
 {"id":"custom1","type":"snaker:custom","x":380,"y":200,"properties":{"clazz":"exec.handler","methodName":"execute","val":"customResult"},"text":{"value":"通知外部系统"}},
 {"id":"audit","type":"snaker:task","x":520,"y":200,"properties":{"assignee":"leader","taskType":0,"performType":0},"text":{"value":"复核"}},
 {"id":"end","type":"snaker:end","x":680,"y":200,"properties":{},"text":{"value":"结束"}}],
 "edges":[
  {"id":"e1","sourceNodeId":"start","targetNodeId":"apply","properties":{}},
  {"id":"e2","sourceNodeId":"apply","targetNodeId":"custom1","properties":{}},
  {"id":"e3","sourceNodeId":"custom1","targetNodeId":"audit","properties":{}},
  {"id":"e4","sourceNodeId":"audit","targetNodeId":"end","properties":{}}]}
JSON;

    /** 同一条流，但 custom1 的 clazz 指向一个没注册的名字（§6.2 第 2 条：不炸 + 照常落库 + 续流）*/
    private const CUSTOM_UNREGISTERED_FLOW = '{"name":"custom-pdo-unreg","displayName":"记录类节点SQL路(未注册)",'
        . '"type":"approval","nodes":['
        . '{"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},'
        . '{"id":"apply","type":"snaker:task","x":240,"y":200,"properties":{"assignee":"applicant","taskType":0,"performType":0},"text":{"value":"发起申请"}},'
        . '{"id":"custom1","type":"snaker:custom","x":380,"y":200,"properties":{"clazz":"com.example.NotRegistered"},"text":{"value":"通知外部系统"}},'
        . '{"id":"end","type":"snaker:end","x":680,"y":200,"properties":{},"text":{"value":"结束"}}],'
        . '"edges":['
        . '{"id":"e1","sourceNodeId":"start","targetNodeId":"apply","properties":{}},'
        . '{"id":"e2","sourceNodeId":"apply","targetNodeId":"custom1","properties":{}},'
        . '{"id":"e3","sourceNodeId":"custom1","targetNodeId":"end","properties":{}}]}';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
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
SQL);

        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        ModelParser::reset();

        $this->repo = new PdoProcessRepository($this->pdo);
        $this->engine = new JeeflowEngine($this->repo);
        $this->handlers = new CustomHandlerRegistry();
        ServiceContext::put(CustomHandlerRegistry::class, $this->handlers);

        $this->logFile = sys_get_temp_dir() . '/jeeflow-custom-pdo-' . getmypid()
            . '-' . spl_object_id($this) . '.log';
        @unlink($this->logFile);
        $this->savedErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->savedErrorLog !== false && $this->savedErrorLog !== '') {
            ini_set('error_log', (string) $this->savedErrorLog);
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        ServiceContext::clear();
        ModelParser::reset();
    }

    /**
     * 主判据：办理 apply 后，`wf_process_task` 里**查得到**那条 `task_state=20` 的历史行，
     * 列值逐项对齐 §6.2 第 1 条（参与者＝当前操作人、task_parent_id、行级首节点标记），
     * 并且用一个**新建的仓储实例**复用同一条连接还能读到 ⇒ 证据在库里，不在 PHP 对象的数组里。
     *
     * 摘掉落库腿（把 `persistHistoryTasks` 的 saveTask 去掉）这一格必红：
     * 聚合根 `instance->tasks` 里那条依然在（内存/引用照得出来），库里一行都没有。
     */
    public function testHistoryRowIsReadableFromSqlTable(): void
    {
        $this->handlers->register('exec.handler', new ExecutingCustomHandler('PAY-SQL'));
        [$instanceId, $applyTaskId] = $this->startAndFirstTask(self::CUSTOM_FLOW, 'd-custom');

        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $rows = $this->query(
            'SELECT * FROM wf_process_task WHERE process_instance_id = ? AND task_name = ?',
            [$instanceId, 'custom1']
        );
        $this->assertCount(1, $rows, '历史行必须真的在 wf_process_task 里（只在聚合内存对象里 append 不算做到）');
        $row = $rows[0];
        $this->assertSame(20, (int) $row['task_state'], '记录类节点落 DONE(20)，不是 DOING(10)');
        $this->assertSame('通知外部系统', $row['display_name']);
        $this->assertSame('user1', $row['operator'], '参与者＝当前操作人（留痕主体）');
        $this->assertNotSame('', (string) $row['finish_time'], 'DONE 行要有 finish_time');
        $this->assertSame((string) $applyTaskId, (string) $row['task_parent_id'],
            '建单不变量一：task_parent_id 照办掉的那一行');
        $this->assertStringContainsString('"isFirstTaskNode":false', (string) $row['variable'],
            '建单不变量二：行级首节点标记随 variable 落库');
        $this->assertNull($row['form_key'], '记录类行无 form');
        $this->assertNull($row['task_type'], '记录类行无 taskType');
        $this->assertNull($row['expire_time'], '记录类节点没有 expireTime 属性，这一列保持 NULL（不造默认值）');

        $actors = $this->query('SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ?',
            [(string) $row['id']]);
        $this->assertCount(1, $actors, '历史行的参与者要随任务写进 wf_process_task_actor（saveTask 那条腿）');
        $this->assertSame('user1', (string) $actors[0]['actor_id']);

        // 跨对象可见：新建一个仓储实例（同一条连接）再读一次
        $fresh = new PdoProcessRepository($this->pdo);
        $readBack = $fresh->findTaskById((string) $row['id']);
        $this->assertNotNull($readBack, '第二个仓储实例必须能按 taskId 读回那一行');
        $this->assertSame(ProcessTaskState::FINISHED, $readBack->getTaskState());
        $this->assertNotEmpty($fresh->findHistoryTasks($instanceId), 'findHistoryTasks 也读得到（门面已办/历史族的读路）');

        // 令牌沿出边继续流转：custom1 之后还有 audit 这一条待办（真落在库里）
        $doing = $this->query('SELECT task_name FROM wf_process_task WHERE process_instance_id = ? AND task_state = 10',
            [$instanceId]);
        $this->assertSame(['audit'], array_map(fn(array $r) => (string) $r['task_name'], $doing),
            '待办只有下游那一条 audit —— custom1 不许凭空多一条待办（§6.1 硬结论 2）');
    }

    /**
     * `clazz` 未注册（SQL 路）：不抛异常、那一行照样进库、流程继续走到 end 并把实例落成 20。
     * §6.2 第 2 条把"记日志但停在原地"也列为违反本条 ⇒ 三件事都要断。
     */
    public function testUnregisteredClazzStillWritesHistoryRowOnSqlPath(): void
    {
        [$instanceId, $applyTaskId] = $this->startAndFirstTask(self::CUSTOM_UNREGISTERED_FLOW, 'd-custom-unreg');

        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create()); // 不抛即合格

        $rows = $this->query(
            'SELECT task_state FROM wf_process_task WHERE process_instance_id = ? AND task_name = ?',
            [$instanceId, 'custom1']
        );
        $this->assertCount(1, $rows, 'clazz 配错也要真落库，不能只记日志');
        $this->assertSame(20, (int) $rows[0]['task_state']);
        $this->assertStringContainsString('未注册处理器', $this->logTail(), '实际文案=' . $this->logTail());

        $inst = $this->query('SELECT state FROM wf_process_instance WHERE id = ?', [$instanceId]);
        $this->assertSame(ProcessInstanceState::FINISHED, (int) $inst[0]['state'],
            '实例必须走到终点（库里那一行是 20，不是停在 10）');
    }

    // ── 辅助 ──

    /** @return array{0:string,1:string} [instanceId, 发起待办 id] */
    private function startAndFirstTask(string $json, string $defineId): array
    {
        $this->repo->addDefine(['id' => $defineId, 'name' => 'custom-pdo', 'displayName' => '记录类节点SQL路',
            'type' => 'approval', 'state' => 1, 'content' => $json, 'version' => 1]);
        $instance = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $instanceId = (string) $instance->getInstanceId();
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '夹具前提：发起后应有待办（SQL 路）');
        return [$instanceId, (string) $doing[0]->getTaskId()];
    }

    /** @param array<int,string|int> $params  @return array<int,array<string,mixed>> */
    private function query(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function logTail(): string
    {
        return is_file((string) $this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
