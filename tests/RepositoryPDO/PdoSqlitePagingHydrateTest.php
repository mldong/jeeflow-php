<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\RepositoryPDO\PdoProcessExtRepository;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * issues/67 + 68：SQLite 内存库回归（不依赖 160 MySQL）
 *
 * - 分页 SQL 不再 bind LIMIT（空表/有数据都能跑）
 * - INTEGER 主键 hydrate 成 string，避免 setTaskId TypeError
 */
class PdoSqlitePagingHydrateTest extends TestCase
{
    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private PdoProcessExtRepository $extRepo;

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
  id INTEGER PRIMARY KEY,
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
  id INTEGER PRIMARY KEY,
  process_instance_id INTEGER NOT NULL,
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
  process_task_id INTEGER NOT NULL,
  actor_id INTEGER NOT NULL,
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
CREATE TABLE wf_process_design (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  display_name TEXT NOT NULL,
  type TEXT NULL,
  icon TEXT NULL,
  is_deployed INTEGER NULL,
  remark TEXT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_surrogate (
  id INTEGER PRIMARY KEY,
  process_name TEXT NULL,
  operator TEXT NULL,
  surrogate TEXT NULL,
  start_time TEXT NULL,
  end_time TEXT NULL,
  enabled INTEGER NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
SQL);
        $this->repo = new PdoProcessRepository($this->pdo);
        $this->extRepo = new PdoProcessExtRepository($this->pdo);
    }

    public function testPageDefinesDoesNotBindLimit(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->repo->addDefine([
                'id' => (string) $i,
                'name' => "flow-$i",
                'displayName' => "流程$i",
                'type' => 'approval',
                'state' => 1,
                'content' => '{}',
                'version' => 1,
            ]);
        }
        $page = $this->repo->pageDefines(new PageQuery(1, 2));
        $this->assertSame(3, $page->getRecordCount());
        $this->assertCount(2, $page->getRows());
        $this->assertIsString($page->getRows()[0]['id']);
    }

    public function testHydrateTaskCastsIntegerPrimaryKey(): void
    {
        $this->pdo->exec("INSERT INTO wf_process_instance (id, process_define_id, state, operator, variable, create_user)
            VALUES (1001, 'def-1', 10, 'u1', '{}', 'u1')");
        $this->pdo->exec("INSERT INTO wf_process_task
            (id, process_instance_id, task_name, display_name, task_type, perform_type, task_state, operator, variable, create_user)
            VALUES (1001, 1001, 'apply', '申请', 0, 0, 10, 'u1', '{}', 'u1')");
        $this->pdo->exec("INSERT INTO wf_process_task_actor (id, process_task_id, actor_id) VALUES ('a1', 1001, 1001)");

        $task = $this->repo->findTaskById(1001);
        $this->assertNotNull($task);
        $this->assertSame('1001', $task->getTaskId());
        $this->assertSame('1001', $task->getProcessInstanceId());
        $this->assertSame(['1001'], $task->getActorIds());

        $inst = $this->repo->findInstanceById(1001);
        $this->assertNotNull($inst);
        $this->assertSame('1001', $inst->getInstanceId());
        $this->assertCount(1, $inst->getTasks());
        $this->assertSame('1001', $inst->getTasks()[0]->getTaskId());
    }

    public function testPageDesignsInlinesLimitAndStringifiesId(): void
    {
        $this->pdo->exec("INSERT INTO wf_process_design (id, name, display_name, create_time)
            VALUES (1001, 'd1', '设计1', '2026-08-15 00:00:00')");
        $page = $this->extRepo->pageDesigns(new PageQuery(1, 5));
        $this->assertSame(1, $page->getRecordCount());
        $this->assertSame('1001', $page->getRows()[0]['id']);
    }

    // issues/77：委托 save→findSurrogateById→updateSurrogate 往返（camelCase 入参 ↔ snake_case 列）
    public function testSurrogateFindAndUpdateRoundTrip(): void
    {
        $id = $this->extRepo->saveSurrogate([
            'processName' => 'leave',
            'operator' => 'zhangsan',
            'surrogate' => 'lisi',
            'startTime' => '2026-08-01 00:00:00',
            'endTime' => '2026-08-31 23:59:59',
            'enabled' => 1,
        ]);

        $found = $this->extRepo->findSurrogateById($id);
        $this->assertNotNull($found);
        $this->assertSame('leave', $found['process_name']);
        $this->assertSame('lisi', $found['surrogate']);
        $this->assertSame('2026-08-01 00:00:00', $found['start_time']);

        $this->extRepo->updateSurrogate([
            'id' => $id,
            'surrogate' => 'wangwu',
            'startTime' => '2026-09-01 00:00:00',
            'endTime' => '2026-09-30 23:59:59',
            'enabled' => 0,
            'updateUser' => 'zhangsan',
        ]);

        $found = $this->extRepo->findSurrogateById($id);
        $this->assertSame('wangwu', $found['surrogate']);
        $this->assertSame(0, (int) $found['enabled']);
        $this->assertSame('2026-09-01 00:00:00', $found['start_time']);
        // 未传的 operator 保持原值
        $this->assertSame('zhangsan', $found['operator']);
        // 负向：不存在的 id
        $this->assertNull($this->extRepo->findSurrogateById('no-such-id'));
    }

    /**
     * issues/82-7：委托分页 m_ 条件（PDO 路径，SQLite）—— buildSurrogateConditions 白名单 WHERE
     * m_IN_processName / m_EQ_enabled（对齐 Java/Go/Python/Node 基准）
     */
    public function testPageSurrogatesInAndEqConditions(): void
    {
        // 3 条委托：leave(启用) / overtime(启用) / sick(停用)
        foreach ([['leave', 1], ['overtime', 1], ['sick', 0]] as [$name, $enabled]) {
            $this->extRepo->saveSurrogate([
                'operator' => 'zhangsan',
                'surrogate' => 'agent-' . $name,
                'processName' => $name,
                'enabled' => $enabled,
            ]);
        }

        // 无过滤：3 条
        $p0 = $this->extRepo->pageSurrogates(new PageQuery(1, 10));
        $this->assertSame(3, $p0->getRecordCount());

        // m_IN_processName：IN 列表命中 2 条
        $qIn = new PageQuery(1, 10);
        $qIn->add('t.process_name', 'IN', ['leave', 'overtime']);
        $pIn = $this->extRepo->pageSurrogates($qIn);
        $this->assertSame(2, $pIn->getRecordCount());
        $names = array_map(fn($r) => $r['process_name'], $pIn->getRows());
        $this->assertContains('leave', $names);
        $this->assertContains('overtime', $names);

        // m_EQ_enabled：启用过滤命中 2 条
        $qEq = new PageQuery(1, 10);
        $qEq->add('t.enabled', 'EQ', 1);
        $pEq = $this->extRepo->pageSurrogates($qEq);
        $this->assertSame(2, $pEq->getRecordCount());

        // m_IN + m_EQ 组合：sick/overtime 中仅启用 → 1 条
        $qCombo = new PageQuery(1, 10);
        $qCombo->add('t.process_name', 'IN', ['sick', 'overtime']);
        $qCombo->add('t.enabled', 'EQ', 1);
        $pCombo = $this->extRepo->pageSurrogates($qCombo);
        $this->assertSame(1, $pCombo->getRecordCount());
        $this->assertSame('overtime', $pCombo->getRows()[0]['process_name']);

        // 负向：IN 全不命中 / EQ 无匹配 / 白名单外列忽略
        $qNone = new PageQuery(1, 10);
        $qNone->add('t.process_name', 'IN', ['none1', 'none2']);
        $this->assertSame(0, $this->extRepo->pageSurrogates($qNone)->getRecordCount());
        $qEq2 = new PageQuery(1, 10);
        $qEq2->add('t.enabled', 'EQ', 2);
        $this->assertSame(0, $this->extRepo->pageSurrogates($qEq2)->getRecordCount());
        $qBadCol = new PageQuery(1, 10);
        $qBadCol->add('t.nonexistent_col', 'EQ', 'x'); // 白名单外 → 忽略，仍 3 条
        $this->assertSame(3, $this->extRepo->pageSurrogates($qBadCol)->getRecordCount());
    }

    /**
     * issues/82-12：委托生效判断——时间窗 startTime/endTime + enabled 过滤
     * （PDO 路径，SQLite：验证 WHERE enabled=1 + start_time/end_time IS NULL 兜底）
     */
    public function testGetSurrogateEffectiveWindowAndEnabled(): void
    {
        $op = 'winop';

        $save = function (string $agent, string $pn, array $extra = []) use ($op): void {
            $this->extRepo->saveSurrogate(array_merge([
                'operator' => $op,
                'surrogate' => $agent,
                'processName' => $pn,
                'enabled' => 1,
            ], $extra));
        };

        // A 在窗（2026-08-01 ~ 08-31）
        $save('sA', 'winA', ['startTime' => '2026-08-01 00:00:00', 'endTime' => '2026-08-31 23:59:59']);
        // B 未到（2026-09-01 起）
        $save('sB', 'winB', ['startTime' => '2026-09-01 00:00:00']);
        // C 已过（07-31 止）
        $save('sC', 'winC', ['endTime' => '2026-07-31 23:59:59']);
        // D 无窗但停用（enabled=0）
        $save('sD', 'winD', ['enabled' => 0]);
        // E 无窗且启用（enabled=1）
        $save('sE', 'winE');

        $at = '2026-08-15 12:00:00';
        $hit = $this->extRepo->getSurrogate($op, 'winA', $at);
        $this->assertNotNull($hit, '在窗委托应生效');
        $this->assertSame('sA', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winB', $at), '未到窗委托不应生效');
        $this->assertNull($this->extRepo->getSurrogate($op, 'winC', $at), '已过窗委托不应生效');
        $this->assertNull($this->extRepo->getSurrogate($op, 'winD', $at), 'enabled=0 不应生效');
        $hit = $this->extRepo->getSurrogate($op, 'winE', $at);
        $this->assertNotNull($hit, '无窗启用委托应生效（NULL=不限）');
        $this->assertSame('sE', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winZ', $at), '无匹配流程应返回 null');

        // 换时间验证窗口边界随时间变化：B 在 9 月生效、A 在 9 月失效
        $atSep = '2026-09-15 12:00:00';
        $hit = $this->extRepo->getSurrogate($op, 'winB', $atSep);
        $this->assertNotNull($hit, '9 月：B 进入窗口应生效');
        $this->assertSame('sB', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winA', $atSep), '9 月：A 已出窗口不应生效');
    }
}
