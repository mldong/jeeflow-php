<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/129 · operator「空串与缺键同档」（spec 06-facade.md §2.5）
 *
 * 现场：160 同一份库、同一时刻三档并排探针 —— `{"operator":"user1"}` ⇒ 4 行（正确）、
 * `{"operator":""}` ⇒ **0 行**、`{"operator":"__nobody__"}` ⇒ 0 行（对照档）。
 * 本栈症状与 java/go/node/python/csharp 那五栈不同：那五栈把空串条件整条丢了 ⇒ 读全库；
 * php 没丢条件（{@see \Jeeflow\RepositoryPDO\PdoProcessRepository::buildConditions()} 逐条拼
 * `AND col = ?`，{@see \Jeeflow\Core\Repository\InMemoryProcessRepository::matchCondition()}
 * 直接按值比对），而是拿空串当**真实归属值**去比对 ⇒ 洞在第一层（门面没把空串当缺键），
 * 症状是"我的列表悄悄变空"。
 *
 * 裁定语义：`{"operator":""}`（含全空白串）视同未传，与 unset/null 一并回落 demo 缺省 `user1`。
 * 后端各跑一遍：PDO(SQLite 内存，与线上 MySQL 同一条 SQL 构造路) + InMemory(单测/轻量部署形态)。
 *
 * ⚠️ 边界：issues/114 的「operator 硬必填」出口（withdraw / transfer）**不在**本条管辖内，
 * 空串必须报错、严禁回落 user1 —— {@see testHardRequiredOperatorExitsStayUntouched}。
 */
final class JeeflowFacadeOperatorBlankTest extends TestCase
{
    /**
     * 四个归属出口：[action, 归属谓词列（§2.5 口径表）, 行身份键, 无归属条件时的仓储方法]
     *
     * issues/138：ccList 的谓词列按 §2.5 口径表逐字写作 `cc.actor_id`（此前是 `t.actor_id`，
     * 因为旧行源是 cc 表本身），行身份键按「行形状三要件」第三条改为 `id`（实例 id）——
     * 条文：「行主键键名必须是 **`id`（实例 id）**」，错法 ③ 即「行主键出 `processInstanceId` 而没有 `id`」。
     *
     * @var array<int, array{0:string,1:string,2:string,3:string}>
     */
    private const EXITS = [
        ['processInstance/page', 't.operator', 'id', 'pageInstances'],
        ['processTask/todoList', 'pta.actor_id', 'id', 'pageTodoTasks'],
        ['processTask/doneList', 't.operator', 'id', 'pageDoneTasks'],
        ['processInstance/ccList', 'cc.actor_id', 'id', 'pageCcInstances'],
    ];

    /** 造数据读数：user1 发起 2 条、user2 发起 1 条、全库 3 条（三档互不相等，负向才有鉴别力） */
    private const USER1_ROWS = 2;
    private const USER2_ROWS = 1;
    private const ALL_ROWS = 3;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 第一层：门面归一化（正向三档相等 + 负向不是全库） ──

    public function testBlankOperatorEqualsMissingAndUser1OnPdoSqliteBackend(): void
    {
        [$facade, $repo] = $this->pdoBackend();
        $this->seed($facade, $repo);
        $this->assertBlankMatrix($facade, $repo, 'PDO/SQLite');
    }

    public function testBlankOperatorEqualsMissingAndUser1OnInMemoryBackend(): void
    {
        [$facade, $repo] = $this->inMemoryBackend();
        $this->seed($facade, $repo);
        $this->assertBlankMatrix($facade, $repo, 'InMemory');
    }

    /**
     * 写侧两个缺省点同档归一（发起 / 办理）：空串不得被当成真实办理人写进单据，
     * 否则"我发起的/我已办的"两头都看不见这张自己刚办的单。
     */
    public function testWriteSideBlankOperatorFallsBackToUser1(): void
    {
        [$facade, $repo] = $this->pdoBackend();
        $defineId = $this->deploy($facade);

        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => '',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        $detail = $facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertSame(0, $detail['code'], json_encode($detail, JSON_UNESCAPED_UNICODE));
        $this->assertSame('user1', $detail['data']['operator'],
            'issues/129：startAndExecute 传空串 ⇒ 发起人须归一为 user1（而不是把 "" 落库）');

        $doing = $repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：应有进行中任务');
        $taskId = (string) $doing[0]->getTaskId();
        $repo->addTaskActor($taskId, ['user1']);

        $exec = $facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => '', 'submitType' => 1,
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));
        $this->assertContains($taskId, $this->idsOf($facade, 'processTask/doneList', 'id', 'user1'),
            'issues/129：execute 传空串 ⇒ 办理人须归一为 user1，办结行落进 user1 的已办档');
    }

    /**
     * issues/114 的「operator 硬必填」严禁被"顺手统一"：空串必须报错，不得静默回落 user1。
     * 归一化只收在 operatorOf() 这一个 helper 上，必填出口不接它。
     */
    public function testHardRequiredOperatorExitsStayUntouched(): void
    {
        [$facade, $repo] = $this->pdoBackend();
        $defineId = $this->deploy($facade);
        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        foreach (['' => '空串', '   ' => '全空白'] as $blank => $label) {
            $withdraw = $facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => $blank]);
            $this->assertSame(99999999, $withdraw['code'], "withdraw {$label} 仍须报错（issues/114）");
            $this->assertSame('operator 必填', $withdraw['msg'], "withdraw {$label} 文案须逐字对齐跨栈");
        }
        $transfer = $facade->flow('processTask/transfer', [
            'processTaskId' => '1', 'fromActor' => 'user1', 'toActor' => 'user2', 'operator' => '',
        ]);
        $this->assertSame(99999999, $transfer['code'], 'transfer 空 operator 仍须报错（issues/114）');
        $this->assertSame('operator 必填', $transfer['msg']);

        // 反向确认挡住的是"空串"本身：实例仍进行中，没被缺省的 user1 静默撤回
        $this->assertSame(ProcessInstanceState::DOING,
            $repo->findInstanceById($instanceId)?->getState(),
            'issues/114：空 operator 严禁回落 user1 把单子撤走');
        $this->assertNotEmpty($repo->findDoingTasks($instanceId), '被拒后进行中任务不得被改写');
    }

    // ── 断言矩阵 ──

    private function assertBlankMatrix(JeeflowFacade $facade, ProcessRepositoryInterface $repo, string $backend): void
    {
        foreach (self::EXITS as [$action, $column, $idKey, $noCondMethod]) {
            $label = "{$backend} · {$action}（归属谓词 {$column}）";

            $user1 = $this->idsOf($facade, $action, $idKey, 'user1');
            $user2 = $this->idsOf($facade, $action, $idKey, 'user2');
            $nobody = $this->idsOf($facade, $action, $idKey, '__nobody__');
            $blank = $this->idsOf($facade, $action, $idKey, '');
            $space = $this->idsOf($facade, $action, $idKey, " \t\n ");
            $missing = $this->idsOfWithoutOperator($facade, $action, $idKey);

            // 前置：user1 档非空，否则后面全变成"0 == 0"的自等假绿
            $this->assertCount(self::USER1_ROWS, $user1, $label . ' 前置读数应为 ' . self::USER1_ROWS);

            // 正向：空串 / 全空白 / 缺键三档 == 显式 user1 档
            $this->assertSame($user1, $blank, $label . ' 空串档须等于 user1 档（issues/129）');
            $this->assertSame($user1, $space, $label . ' 全空白档须等于 user1 档（issues/129）');
            $this->assertSame($user1, $missing, $label . ' 缺键档须等于 user1 档');
            $this->assertNotEmpty($blank, $label . ' 空串档悄悄变空页 = issues/129 的本栈症状');

            // 负向：既不是全库，也不是对照用户，也不是空集
            $all = $this->idsOfRepo($repo, $noCondMethod, $idKey);
            $this->assertCount(self::ALL_ROWS, $all, $label . ' 全库读数（无归属条件）应为 ' . self::ALL_ROWS);
            $this->assertNotEquals($all, $blank, $label . ' 空串档读成了全库（归属过滤被丢弃）');
            $this->assertCount(self::USER2_ROWS, $user2, $label . ' 对照用户读数');
            $this->assertNotEquals($user2, $blank, $label . ' 空串档不得等于对照用户档');
            $this->assertSame([], $nobody, $label . ' 不存在的人应为空集（对照档）');
        }
    }

    // ── 后端装配 ──

    /** @return array{0:JeeflowFacade,1:ProcessRepositoryInterface} */
    private function pdoBackend(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, display_name TEXT NOT NULL, type TEXT NULL,
  state INTEGER NULL, content TEXT NULL, version INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY, parent_id TEXT NULL, process_define_id TEXT NULL, state INTEGER NULL,
  parent_node_name TEXT NULL, business_no TEXT NULL, operator TEXT NULL, expire_time TEXT NULL,
  variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL, update_time TEXT NULL,
  update_user TEXT NULL);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, task_name TEXT NOT NULL,
  display_name TEXT NOT NULL, task_type INTEGER NULL, perform_type INTEGER NULL, task_state INTEGER NULL,
  operator TEXT NULL, finish_time TEXT NULL, expire_time TEXT NULL, form_key TEXT NULL,
  task_parent_id TEXT NULL, variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY, process_task_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  create_time TEXT NULL, create_user TEXT NULL);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
SQL);
        $repo = new PdoProcessRepository($pdo);
        return [new JeeflowFacade(new JeeflowEngine($repo), $repo), $repo];
    }

    /** @return array{0:JeeflowFacade,1:ProcessRepositoryInterface} */
    private function inMemoryBackend(): array
    {
        $repo = new InMemoryProcessRepository();
        return [new JeeflowFacade(new JeeflowEngine($repo), $repo), $repo];
    }

    // ── 造数据 ──

    private function deploy(JeeflowFacade $facade): string
    {
        $deploy = $facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        return (string) $deploy['data']['processDefineId'];
    }

    /**
     * 01-simple：start → apply(applicant，由发起人自动办结) → task1(assignee=leader，进行中)。
     * 于是每起一单天然产出一行"发起人已办" + 一行"leader 待办"；待办再挂上发起人自己，
     * 抄送也发给发起人 —— 四张出口在 user1/user2 两档上各有非空行集，且两档行数不相等。
     */
    private function seed(JeeflowFacade $facade, ProcessRepositoryInterface $repo): void
    {
        $defineId = $this->deploy($facade);
        foreach ([['user1', self::USER1_ROWS], ['user2', self::USER2_ROWS]] as [$user, $times]) {
            for ($i = 0; $i < $times; $i++) {
                $start = $facade->flow('processDefine/startAndExecute', [
                    'processDefineId' => $defineId, 'operator' => $user,
                ]);
                $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
                $instanceId = (string) $start['data']['processInstanceId'];
                $doing = $repo->findDoingTasks($instanceId);
                $this->assertNotEmpty($doing, "前置：{$user} 第 {$i} 单应有进行中任务");
                $repo->addTaskActor((string) $doing[0]->getTaskId(), [$user]);
                $repo->createCcInstance($instanceId, $user, [$user]);
            }
        }
    }

    // ── 取数 ──

    /** @return string[] 排序后的行身份（数组相等即行集相等，避免 ORDER BY 同秒抖动） */
    private function idsOf(JeeflowFacade $facade, string $action, string $idKey, string $operator): array
    {
        return $this->rows($facade, $action, ['operator' => $operator], $idKey);
    }

    /** @return string[] */
    private function idsOfWithoutOperator(JeeflowFacade $facade, string $action, string $idKey): array
    {
        return $this->rows($facade, $action, [], $idKey);
    }

    /** @return string[] 绕过门面：仓储上不加任何归属条件时的全库读数 */
    private function idsOfRepo(ProcessRepositoryInterface $repo, string $method, string $idKey): array
    {
        $page = $repo->{$method}(new PageQuery(1, 50));
        return $this->extract($page->getRows(), $idKey, $method);
    }

    /** @param array<string, mixed> $extra @return string[] */
    private function rows(JeeflowFacade $facade, string $action, array $extra, string $idKey): array
    {
        $result = $facade->flow($action, ['pageSize' => 50] + $extra);
        $this->assertSame(0, $result['code'], $action . ' 返回失败: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        return $this->extract($result['data']['rows'] ?? [], $idKey, $action);
    }

    /** @return string[] */
    private function extract(array $rows, string $idKey, string $where): array
    {
        $ids = [];
        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey($idKey, $row, "{$where} 第 {$i} 行缺身份键 {$idKey}");
            $ids[] = (string) $row[$idKey];
        }
        sort($ids);
        return $ids;
    }
}
