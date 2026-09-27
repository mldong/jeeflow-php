<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/124 · 列表行变量出口（SQLite 内存库，不依赖 160 MySQL）
 *
 * 现场：门面侧 988b7ee 已把 detail 族的 variable/variables 摘掉，但 todoList/doneList 这类
 * 行由 PdoProcessRepository::pagedTaskQuery 直接投影、不经门面，于是 php 成了八栈里唯一
 * 还在出口带 `variable`（任务变量原串）+ `instanceVariable`（实例变量原串）的栈
 * ——线上只读探针实证见 jeeflow-hub/docs/runtime-probe-2026-09-27-八栈线上出口形状抽样.md §一.1。
 *
 * 判据（spec 06-facade.md:349-351）：变量在任务行的唯一出口是 ext（任务变量，空回退实例变量）
 * 与 instanceExt（实例变量）；原串不是契约字段；变量为空时出空对象 {}，不出缺键 / null / []。
 * 与跨栈门禁格 L2-26 同源同判据；本件钉引擎，L2-26 钉出口，两层各管一段漂移。
 */
final class PdoSqliteTaskRowExitTest extends TestCase
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

    /**
     * 正向 + 回归：列表行与 detail 的 tasks[] 行都不许出现变量原串；契约键按各自条文钉
     * （列表行 ext + instanceExt，详情行只有 ext——见 assertExitShape 的分档依据）。
     *
     * 三条出口各扫一次是有原因的：修复前 variable 只在 pagedTaskQuery（todoList/doneList）里，
     * detail 族早被门面摘过 ⇒ 只扫 detail 会绿，只扫列表又钉不住门面那半。
     */
    public function testNoRawVariableKeysOnListAndDetailRows(): void
    {
        [$instanceId, $taskId] = $this->startSimple();

        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertSame(0, $todo['code'], json_encode($todo, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($todo['data']['rows'], '前置：leader 应有 1 条待办');
        $this->assertExitShape($todo['data']['rows'], 'processTask/todoList', true);

        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'submitType' => 1,
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));

        $done = $this->facade->flow('processTask/doneList', ['operator' => 'leader']);
        $this->assertSame(0, $done['code'], json_encode($done, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($done['data']['rows'], '前置：办结后 leader 应有已办行（零行则本件恒真空）');
        $this->assertExitShape($done['data']['rows'], 'processTask/doneList', true);

        $det = $this->facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertSame(0, $det['code'], json_encode($det, JSON_UNESCAPED_UNICODE));
        // 详情行按 Java 参考形状只钉 ext（instanceExt 是列表行的增量条文），但禁键一样适用
        $this->assertExitShape($det['data']['tasks'] ?? [], 'processInstance/detail.tasks', false);
    }

    /**
     * 鉴别力格：ext 必须真的读任务变量，不是 instanceExt 的副本。
     *
     * 旧实现 `'ext' => $instanceExt`（把任务变量整格写成实例变量）在这一格必红；
     * 而"任务变量非空 ⇒ 不回退"这条又反过来钉住 issues/82-3 + issues/121 P1 的回退判据
     * ——新建任务的 ext 只有引擎控制键 isFirstTaskNode，那不算"非空"，仍须回退实例变量。
     */
    public function testExtReadsTaskVarsAndFallsBackOnlyWhenTheyAreEmpty(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $instExt = $this->asArr($this->facade->flow('processInstance/detail', ['id' => $instanceId])['data']['ext']);

        $before = $this->rowOf('processTask/todoList', 'leader', $taskId);
        $this->assertSame($instExt, $this->asArr($before['ext']),
            '任务变量仅剩引擎控制键 ⇒ ext 应回退实例变量（issues/82-3 / 121 P1）');
        $this->assertSame($instExt, $this->asArr($before['instanceExt']), 'instanceExt 恒为实例变量');

        $tr = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => '出口形状用', 'operator' => 'leader',
        ]);
        $this->assertSame(0, $tr['code'], json_encode($tr, JSON_UNESCAPED_UNICODE));

        $after = $this->rowOf('processTask/todoList', 'lisi', $taskId);
        $ext = $this->asArr($after['ext']);
        $this->assertSame(7, $ext['submitType'] ?? null, 'ext 应是任务变量（转办把 submitType=7 写在任务上）');
        $this->assertSame('lisi', $ext['tf_transferTo'] ?? null);
        $this->assertArrayNotHasKey('tf_transferTo', $this->asArr($after['instanceExt']),
            '任务级账本严禁混进 instanceExt');
        $this->assertNotSame($instExt, $ext, '任务变量非空后 ext 与实例变量必须分家（否则说明 ext 是副本）');
    }

    /** 空变量出口形状：出空对象而不是空数组（PHP 空数组直出会被序列化成 JSON 数组，违 spec"出空对象"）。 */
    public function testEmptyVariableExitsAreObjectsNotArrays(): void
    {
        [$instanceId] = $this->startSimple();
        $this->repo->createCcInstance($instanceId, 'leader', ['lisi']);
        $cc = $this->facade->flow('processInstance/ccList', ['operator' => 'lisi']);
        $this->assertSame(0, $cc['code'], json_encode($cc, JSON_UNESCAPED_UNICODE));
        $rows = $cc['data']['rows'] ?? [];
        $this->assertNotEmpty($rows, '前置：ccList 应有行（零行则本件恒真空）');
        foreach ($rows as $r) {
            foreach (['ext', 'instanceExt'] as $k) {
                $this->assertTrue(array_key_exists($k, $r), "ccList 行缺 {$k} 键");
                if ($this->asArr($r[$k]) === []) {
                    $this->assertInstanceOf(\stdClass::class, $r[$k],
                        "{$k} 为空时必须出空对象而不是空数组（spec 06:351）");
                }
            }
        }
    }

    // ── 辅助 ──

    /**
     * 一条出口的逐行判据。
     *
     * $needInstanceExt 分档是有依据的：spec 把 instanceExt 写在 todoList/doneList 的行增量里
     * （06-facade.md:501-506），而 processInstance/detail 的 tasks[] 行按 Java 参考实现
     * （JeeflowFacade:307-319 = taskVo + ext）**没有** instanceExt。八栈同形 ⇒ 详情行只钉 ext，
     * 拿列表行的条文去要求详情行，会把"跟 Java 一致"的实现判成红。
     */
    private function assertExitShape(array $rows, string $where, bool $needInstanceExt): void
    {
        foreach ($rows as $i => $row) {
            foreach (['variable', 'variables', 'instanceVariable', 'instanceVariables'] as $bad) {
                $this->assertArrayNotHasKey($bad, $row,
                    "{$where} 第 {$i} 行出现变量原串 {$bad}（spec 06:349-350：门面出口只留 ext/instanceExt）");
            }
            $keys = $needInstanceExt ? ['ext', 'instanceExt'] : ['ext'];
            foreach ($keys as $k) {
                $this->assertTrue(array_key_exists($k, $row), "{$where} 第 {$i} 行缺契约键 {$k}");
                $this->assertNotSame([], $row[$k],
                    "{$where} 第 {$i} 行的 {$k} 出了空数组，应出空对象（spec 06:351）");
            }
        }
    }

    private function rowOf(string $action, string $operator, string $taskId): array
    {
        $r = $this->facade->flow($action, ['operator' => $operator]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ($r['data']['rows'] as $row) {
            if ((string) ($row['id'] ?? '') === $taskId) return $row;
        }
        $this->fail("{$action}(operator={$operator}) 未取到任务 {$taskId}");
    }

    /** ⇒ [instanceId, taskId]：与 PdoSqliteTransferTest::startSimple 同形状（同一条起单路）。 */
    private function startSimple(): array
    {
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'), 'operator' => 'user1',
        ]);
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

    private function asArr(mixed $v): array
    {
        return is_object($v) ? get_object_vars($v) : (array) ($v ?? []);
    }
}
