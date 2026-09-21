<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/115：removeTaskActor SPI（PDO 仓实现）+ 转办落库形状（SQLite 内存库，不依赖 160 MySQL）
 *
 * 断言全部落在**数据库读回值**上：参与者行、task 行 operator 列、variable 列 JSON。
 * `tf_` 值在 variable 列的序列化形状必须是 `{"tf_transferHistory":[{"submitType":7,...}]}`
 * ——数组套对象、六键 camelCase、time 为 "yyyy-MM-dd HH:mm:ss" 字符串（跨栈同形，
 * 不是时刻对象也不是 ISO 方言）。
 */
class PdoSqliteTransferTest extends TestCase
{
    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  display_name TEXT NOT NULL,
  type TEXT NULL,
  state INTEGER NULL,
  content TEXT NULL,
  version INTEGER NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY,
  parent_id TEXT NULL,
  process_define_id TEXT NULL,
  state INTEGER NULL,
  parent_node_name TEXT NULL,
  business_no TEXT NULL,
  operator TEXT NULL,
  expire_time TEXT NULL,
  variable TEXT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY,
  process_instance_id TEXT NOT NULL,
  task_name TEXT NOT NULL,
  display_name TEXT NOT NULL,
  task_type INTEGER NULL,
  perform_type INTEGER NULL,
  task_state INTEGER NULL,
  operator TEXT NULL,
  finish_time TEXT NULL,
  expire_time TEXT NULL,
  form_key TEXT NULL,
  task_parent_id TEXT NULL,
  variable TEXT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY,
  process_task_id TEXT NOT NULL,
  actor_id TEXT NOT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL
);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY,
  process_instance_id TEXT NOT NULL,
  actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
SQL);
        ServiceContext::clear();
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        $this->repo = new PdoProcessRepository($this->pdo);
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── SPI：removeTaskActor 必须是接口必选方法（PHP 曾整条缺失） ──

    public function testRemoveTaskActorIsDeclaredOnSpi(): void
    {
        $ref = new \ReflectionClass(\Jeeflow\Core\Spi\ProcessRepositoryInterface::class);
        $this->assertTrue($ref->hasMethod('removeTaskActor'),
            'SPI 面必须有 removeTaskActor（规范 05：八语言 SPI 必选，含内存仓 + SQL 仓两实现）');
        $m = $ref->getMethod('removeTaskActor');
        $this->assertSame('void', (string) $m->getReturnType());
        $this->assertSame(['taskId', 'actorIds'], array_map(
            fn($p) => $p->getName(), $m->getParameters()));
        foreach ([PdoProcessRepository::class, InMemoryProcessRepository::class] as $impl) {
            $this->assertTrue(method_exists($impl, 'removeTaskActor'), "{$impl} 须实现 removeTaskActor");
        }
    }

    // ── SPI：removeTaskActor 按人摘行，绝不清空 ──

    public function testRemoveTaskActorOnlyDeletesGivenActors(): void
    {
        $this->repo->addTaskActor('t-1', ['leader', 'user2', 'user3']);
        $this->assertSame(['leader', 'user2', 'user3'], $this->actorsOf('t-1'));

        $this->repo->removeTaskActor('t-1', ['leader']);
        $this->assertSame(['user2', 'user3'], $this->actorsOf('t-1'),
            '只删传入那一行，同任务其余参与人一行不动');

        // 空列表不得退化成"清空该任务全部参与者"
        $this->repo->removeTaskActor('t-1', []);
        $this->assertSame(['user2', 'user3'], $this->actorsOf('t-1'));

        // 只摘该任务下的人：别的任务同名参与人不受影响
        $this->repo->addTaskActor('t-2', ['leader', 'user9']);
        $this->repo->removeTaskActor('t-1', ['user2']);
        $this->assertSame(['leader', 'user9'], $this->actorsOf('t-2'), '不得跨任务摘人');
        $this->assertSame(['user3'], $this->actorsOf('t-1'));

        // 摘不存在的参与人：静默无操作（门面在调用前已用 msg 拦住）
        $this->repo->removeTaskActor('t-1', ['ghost']);
        $this->assertSame(['user3'], $this->actorsOf('t-1'));
    }

    // ── 门面 transfer：PDO 仓落库形状 ──

    public function testTransferPersistsLedgerJsonAndKeepsOperatorColumnNull(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertSame(['leader'], $this->actorsOf($taskId));

        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => '出差一周', 'operator' => 'leader',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));

        // 参与者表：摘 leader 加 lisi（同一 taskId，任务不新建）
        $this->assertSame(['lisi'], $this->actorsOf($taskId));
        $this->assertCount(1, $this->repo->findDoingTasks($instanceId));

        $raw = $this->taskRow($taskId);
        // 红线：进行中任务 operator（actor_id）列恒无值——转办严禁写它
        $this->assertNull($raw['operator'], 'PDO 路：转办不得覆写 wf_process_task.operator 列');
        $this->assertSame('leader', $raw['update_user'], '办理人记谁由 update_user 承载');
        $this->assertSame(ProcessTaskState::DOING, (int) $raw['task_state']);

        // variable 列 JSON 形状：数组套对象 + 六键 camelCase + time 为 yyyy-MM-dd HH:mm:ss 串
        $decoded = json_decode((string) $raw['variable'], true);
        $this->assertSame(7, $decoded['submitType']);
        $this->assertIsArray($decoded['tf_transferHistory'], '账本落库应是 JSON 数组');
        $this->assertCount(1, $decoded['tf_transferHistory']);
        $this->assertSame(['submitType', 'fromActor', 'toActor', 'reason', 'time', 'operator'],
            array_keys($decoded['tf_transferHistory'][0]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $decoded['tf_transferHistory'][0]['time']);
        $this->assertSame('lisi', $decoded['tf_transferTo']);
        $this->assertSame('出差一周', $decoded['tf_transferReason']);
        $this->assertSame('leader 转办给 lisi（出差一周）', $decoded['tf_approvalComment']);
        $this->assertStringContainsString('"tf_transferHistory":[{', (string) $raw['variable'],
            '落库串形应为数组套对象（跨栈同形）');

        // 仓储读回 + 门面出口读回
        $task = $this->repo->findTaskById($taskId);
        $this->assertNotNull($task);
        $this->assertSame(['lisi'], $task->getActorIds());
        $this->assertCount(1, $task->getVariables()->get('tf_transferHistory'));
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'lisi']);
        $this->assertSame(0, $todo['code'], json_encode($todo, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$taskId], array_column($todo['data']['rows'], 'id'));
        $this->assertCount(0, $this->facade->flow('processTask/todoList', ['operator' => 'leader'])['data']['rows'],
            'PDO 路：A 的待办应消失');

        // 审批记录（PDO 路 ext 取实例变量，账本仍可在 variable 出口读到）
        $rec = $this->facade->flow('processInstance/approvalRecord', ['id' => $instanceId]);
        $this->assertSame(0, $rec['code'], json_encode($rec, JSON_UNESCAPED_UNICODE));
        $line = null;
        foreach ($rec['data'] as $l) {
            if (($l['taskName'] ?? '') === 'task1') $line = $l;
        }
        $this->assertNotNull($line);
        $this->assertSame(7, $line['variable']['submitType'] ?? null,
            'PDO 路 approvalRecord.variable 应读作 submitType=7');
        $this->assertCount(1, $line['variable']['tf_transferHistory'] ?? []);
    }

    /** 转办 → 撤回：PDO 路任务态落 30、update_user 回写，且 leader 的 doneList 不凭空多单 */
    public function testTransferThenWithdrawOnPdo(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi', 'operator' => 'leader',
        ])['code']);

        $w = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'lisi']);
        $this->assertSame(0, $w['code'], json_encode($w, JSON_UNESCAPED_UNICODE));

        $raw = $this->taskRow($taskId);
        $this->assertSame(ProcessTaskState::WITHDRAW, (int) $raw['task_state'], '撤回落库应=30');
        $this->assertSame('lisi', $raw['update_user']);
        $this->assertNull($raw['operator'], '撤回后 operator 列仍须无值（转办没写过它）');
        $this->assertSame('lisi', $this->instanceRow($instanceId)['update_user'], '实例 update_user 回写真实撤回人');

        // 红线：leader 被摘走、从没办过这单 → state<>10 AND operator=? 不该命中他
        $done = $this->facade->flow('processTask/doneList', ['operator' => 'leader']);
        $this->assertSame(0, $done['code'], json_encode($done, JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, $done['data']['rows'], 'leader 的 doneList 不得凭空出现这条单');
        // 账本活到撤回后
        $this->assertCount(1, json_decode((string) $raw['variable'], true)['tf_transferHistory'] ?? []);
    }

    /** 缺 operator 的撤回/转办在 PDO 路同样被硬拦（不落库） */
    public function testWithdrawAndTransferRequireOperatorOnPdo(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $w = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId]);
        $this->assertSame(99999999, $w['code']);
        $this->assertSame('operator 必填', $w['msg']);
        $this->assertSame(ProcessInstanceState::DOING, (int) $this->instanceRow($instanceId)['state']);

        $t = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
        ]);
        $this->assertSame('operator 必填', $t['msg']);
        $this->assertSame(['leader'], $this->actorsOf($taskId), '被拒的转办不得动参与者表');
    }

    // ── 辅助 ──

    private function startSimple(): array
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：SQLite 路应起出进行中任务');
        return [$instanceId, (string) $doing[0]->getTaskId()];
    }

    private function actorsOf(string $taskId): array
    {
        $stmt = $this->pdo->prepare('SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ? ORDER BY id');
        $stmt->execute([$taskId]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function taskRow(string $taskId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE id = ?');
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "任务行 {$taskId} 应存在");
        return $row;
    }

    private function instanceRow(string $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_instance WHERE id = ?');
        $stmt->execute([$instanceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "实例行 {$instanceId} 应存在");
        return $row;
    }
}
