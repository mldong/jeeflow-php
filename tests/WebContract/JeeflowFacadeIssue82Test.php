<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/82 切片：门面字段级/负向断言（对齐 Java 1912456，五语言全覆盖）
 *
 * - 82-3：列表行 instanceExt 容器 —— 内存 taskToRow 补 instanceExt + ext 空回退实例变量
 *         （对齐 Java taskRowToMap / PDO pagedTaskQuery；门面 pass-through，仓储必须出契约）
 * - 82-5：task detail 任务级 ext.isFirstTaskNode（前端 detail.vue 双兜底 record.ext?.isFirstTaskNode）
 * - 82-6：实例列表 m_pd_LIKE_name 按编码搜 —— 内存仓储 pd. 前缀/snake_case 列映射修复
 *         （旧实现剥别名后按裸键取，pd.name→name / display_name→displayName 失配，
 *          与 Java in-memory pd./t. 白名单映射对齐）
 * - 负向：define/instance/design/task 按 id 查不存在 → 99999999 + 明确 msg
 *
 * 流程 JSON 用本仓 flows/ 共享 fixtures（01-simple，编辑源 jeeflow-java）。
 */
class JeeflowFacadeIssue82Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private InMemoryProcessExtRepository $extRepo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->extRepo = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->extRepo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 辅助 ──

    private function deploySimpleFlow(): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json, '缺少流程 fixture: 01-simple.json');
        $r = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'zhangsan']);
        $this->assertSame(0, $r['code'], 'deploy 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processDefineId'];
    }

    private function doingTaskId(string $instanceId, string $taskName): string
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) {
                return (string) $t->getTaskId();
            }
        }
        return '';
    }

    /**
     * issues/82-3：todoList 行 instanceExt 容器（实例变量对象）+ ext 空回退实例变量
     */
    public function testTaskRowInstanceExtContainer(): void
    {
        $defineId = $this->deploySimpleFlow();
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'zhangsan', 'f_applyReason' => 'unit-test',
        ]);
        $this->assertSame(0, $r1['code'], 'startAndExecute 失败: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));

        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertSame(0, $todo['code'], json_encode($todo, JSON_UNESCAPED_UNICODE));
        $rows = $todo['data']['rows'];
        $this->assertGreaterThanOrEqual(1, count($rows), 'todoList 应有行');
        $row = $rows[0];

        $this->assertIsArray($row['instanceExt'] ?? null, '任务行应含 instanceExt 容器: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
        $this->assertSame('unit-test', $row['instanceExt']['f_applyReason'], 'instanceExt 应含实例变量 f_applyReason');
        // task1 无任务变量 → ext 空回退实例变量（对齐 Java taskRowToMap）
        $this->assertIsArray($row['ext'] ?? null, '任务行应含 ext 容器: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
        $this->assertSame('unit-test', $row['ext']['f_applyReason'], 'ext 空时应回退实例变量');
        $this->assertNotNull($row['version'] ?? null, '任务行应含 version: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    /**
     * issues/82-5：task detail 任务级 ext.isFirstTaskNode（对齐 Java 1912456）
     * 场景 1：startAndExecute 自动完成 apply → 剩 task1（DOING，非首节点）→ false
     * 场景 2：直接启动（不自动完成 apply）→ apply 为首任务节点且 DOING → true
     */
    public function testTaskDetailExtIsFirstTaskNode(): void
    {
        // 场景 1：false
        $defineId = $this->deploySimpleFlow();
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'zhangsan',
        ]);
        $this->assertSame(0, $r1['code'], json_encode($r1, JSON_UNESCAPED_UNICODE));
        $instanceId = $r1['data']['processInstanceId'];
        $task1Id = $this->doingTaskId($instanceId, 'task1');
        $this->assertNotSame('', $task1Id, '应有 task1 进行中任务');

        $r = $this->facade->flow('processTask/detail', ['id' => $task1Id, 'operator' => 'leader']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertIsArray($r['data']['ext'] ?? null, 'task detail 应含 ext 容器: ' . json_encode($r['data'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse($r['data']['ext']['isFirstTaskNode'], 'task1 非首任务节点，ext.isFirstTaskNode 应为 false: '
            . json_encode($r['data']['ext'], JSON_UNESCAPED_UNICODE));

        // 场景 2：true（独立 repo 直接启动，不走 startAndExecute 的自动完成）
        $repo2 = new InMemoryProcessRepository();
        $engine2 = new JeeflowEngine($repo2);
        $facade2 = new JeeflowFacade($engine2, $repo2);
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $r0 = $facade2->flow('processDefine/deploy', ['content' => $json, 'operator' => 'zhangsan']);
        $this->assertSame(0, $r0['code'], json_encode($r0, JSON_UNESCAPED_UNICODE));
        $inst2 = $engine2->startProcessInstanceById($r0['data']['processDefineId'], 'zhangsan');
        $applyId = '';
        foreach ($repo2->findDoingTasks($inst2->getInstanceId()) as $t) {
            if ($t->getTaskName() === 'apply') {
                $applyId = (string) $t->getTaskId();
            }
        }
        $this->assertNotSame('', $applyId, 'apply 应为进行中任务');

        $r2 = $facade2->flow('processTask/detail', ['id' => $applyId, 'operator' => 'zhangsan']);
        $this->assertSame(0, $r2['code'], json_encode($r2, JSON_UNESCAPED_UNICODE));
        $this->assertIsArray($r2['data']['ext'] ?? null, json_encode($r2['data'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($r2['data']['ext']['isFirstTaskNode'], 'apply 为首任务节点且 DOING，ext.isFirstTaskNode 应为 true: '
            . json_encode($r2['data']['ext'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * issues/82-6：m_ 前缀查询（含 m_pd_LIKE_name 按编码搜）—— 内存仓储列映射对齐 Java
     */
    public function testInstancePageMQueryName(): void
    {
        $defineId = $this->deploySimpleFlow();
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'zhangsan',
        ]);
        $this->assertSame(0, $r1['code'], json_encode($r1, JSON_UNESCAPED_UNICODE));

        // m_pd_LIKE_name：按流程编码搜（别名 pd → pd.name）
        $r = $this->facade->flow('processInstance/page', ['operator' => 'zhangsan', 'm_pd_LIKE_name' => 'simple']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $r['data']['rows'], 'm_pd_LIKE_name 应命中 01-simple 实例: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $r = $this->facade->flow('processInstance/page', ['operator' => 'zhangsan', 'm_pd_LIKE_name' => 'zzz']);
        $this->assertCount(0, $r['data']['rows'], 'm_pd_LIKE_name 不应命中: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

        // m_pd_LIKE_displayName：按显示名搜（回归：此前同样失配的 snake_case 列）
        $r = $this->facade->flow('processInstance/page', ['operator' => 'zhangsan', 'm_pd_LIKE_displayName' => '简单']);
        $this->assertCount(1, $r['data']['rows'], 'm_pd_LIKE_displayName 应命中: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $r = $this->facade->flow('processInstance/page', ['operator' => 'zhangsan', 'm_pd_LIKE_displayName' => 'zzz']);
        $this->assertCount(0, $r['data']['rows'], 'm_pd_LIKE_displayName 不应命中');

        // m_t_LIKE_displayName：任务列表（别名 t → t.display_name）
        $r = $this->facade->flow('processTask/todoList', ['operator' => 'leader', 'm_t_LIKE_displayName' => '审批']);
        $this->assertCount(1, $r['data']['rows'], 'm_t_LIKE_displayName 应命中待办: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $r = $this->facade->flow('processTask/todoList', ['operator' => 'leader', 'm_t_LIKE_displayName' => 'zzz']);
        $this->assertCount(0, $r['data']['rows'], 'm_t_LIKE_displayName 不应命中');

        // m_LIKE_name：定义列表（无别名 → 默认主表 t.name）
        $r = $this->facade->flow('processDefine/page', ['m_LIKE_name' => 'simple']);
        $this->assertCount(1, $r['data']['rows'], 'm_LIKE_name 应命中 01-simple 定义: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    /**
     * issues/82 负向：按 id 查"记录不存在" → 99999999 + 明确 msg（PHP 6 处模板，五语言移植源）
     */
    public function testDetailByIdNotFound(): void
    {
        $r = $this->facade->flow('processDefine/detail', ['id' => '9999999999999999999']);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('流程定义不存在', $r['msg'], $r['msg']);

        $r = $this->facade->flow('processInstance/detail', ['id' => '9999999999999999999']);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('流程实例不存在', $r['msg'], $r['msg']);

        $r = $this->facade->flow('processDesign/detail', ['id' => '9999999999999999999']);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('设计不存在', $r['msg'], $r['msg']);

        $r = $this->facade->flow('processTask/detail', ['id' => '9999999999999999999', 'operator' => 'leader']);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('任务不存在', $r['msg'], $r['msg']);
    }
}
