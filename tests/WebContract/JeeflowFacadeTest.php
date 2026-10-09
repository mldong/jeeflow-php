<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Tests\Fixture\SimpleExpressionEvaluator;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * JeeflowFacade 集成测试 —— 28 个核心 action
 *
 * 使用 InMemoryRepository，覆盖：
 * - 流程定义（deploy/page/detail/remove/upAndDown/getLastByName/startAndExecute/redeploy）
 * - 流程实例（page/detail/withdraw/approvalRecord/highLight/createCCInstance/updateCCStatus/ccList/getAssigneeTextData）
 * - 流程任务（todoList/doneList/execute/detail/jumpAbleTaskNameList/surrogate/latest）
 * - 异常路径（未知 action、不存在的实例等）
 */
class JeeflowFacadeTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
        // 注册简单事务模板（直接执行）
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    private function deploySimpleFlow(): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $result = $this->facade->flow('processDefine/deploy', [
            'content' => $json,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);
        return $result['data']['processDefineId'];
    }

    // ── 未知 action ──

    public function testUnknownAction(): void
    {
        $result = $this->facade->flow('unknown/action');
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('未知 action', $result['msg']);
    }

    // ── 流程定义 ──

    public function testDeployAndDefineDetail(): void
    {
        $defineId = $this->deploySimpleFlow();
        $this->assertNotEmpty($defineId);

        // detail
        $result = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(0, $result['code']);
        $this->assertEquals('simple', $result['data']['name']);
        $this->assertNotNull($result['data']['jsonObject']);
    }

    public function testDefineDetailNotFound(): void
    {
        $result = $this->facade->flow('processDefine/detail', ['id' => '999']);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('不存在', $result['msg']);
    }

    public function testDefinePage(): void
    {
        $this->deploySimpleFlow();
        $result = $this->facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 10]);
        $this->assertEquals(0, $result['code']);
        $this->assertEquals(1, $result['data']['recordCount']);
        $this->assertCount(1, $result['data']['rows']);
    }

    public function testDefineRemove(): void
    {
        $defineId = $this->deploySimpleFlow();
        $result = $this->facade->flow('processDefine/remove', ['id' => $defineId]);
        $this->assertEquals(0, $result['code']);

        // 确认已删除
        $result = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(99999999, $result['code']);
    }

    public function testDefineUpAndDown(): void
    {
        $defineId = $this->deploySimpleFlow();
        // 停用
        $result = $this->facade->flow('processDefine/upAndDown', ['id' => $defineId, 'state' => 0]);
        $this->assertEquals(0, $result['code']);

        $detail = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(0, $detail['data']['state']);

        // 启用
        $this->facade->flow('processDefine/upAndDown', ['id' => $defineId, 'opType' => 1]);
        $detail = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(1, $detail['data']['state']);
    }

    public function testGetLastByName(): void
    {
        $this->deploySimpleFlow();
        $result = $this->facade->flow('processDefine/getLastByName', ['processDefineName' => 'simple']);
        $this->assertEquals(0, $result['code']);
        $this->assertEquals('simple', $result['data']['name']);

        // 不存在
        $result = $this->facade->flow('processDefine/getLastByName', ['processDefineName' => 'nonexistent']);
        $this->assertEquals(99999999, $result['code']);
    }

    public function testDeployVersionIncrement(): void
    {
        $id1 = $this->deploySimpleFlow();
        $id2 = $this->deploySimpleFlow();
        $this->assertNotEquals($id1, $id2);

        $d1 = $this->facade->flow('processDefine/detail', ['id' => $id1]);
        $d2 = $this->facade->flow('processDefine/detail', ['id' => $id2]);
        $this->assertEquals(0, $d1['data']['version']);
        $this->assertEquals(1, $d2['data']['version']);
    }

    public function testRedeploy(): void
    {
        $defineId = $this->deploySimpleFlow();
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $data = json_decode($json, true);
        $data['displayName'] = '简单流程v2';
        $result = $this->facade->flow('processDefine/redeploy', [
            'processDefineId' => $defineId,
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);

        $detail = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals('简单流程v2', $detail['data']['displayName']);
    }

    // ── startAndExecute + 流程实例 ──

    public function testStartAndExecuteAndInstanceDetail(): void
    {
        $defineId = $this->deploySimpleFlow();
        $result = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
            'f_reason' => '测试',
        ]);
        $this->assertEquals(0, $result['code']);
        $instanceId = $result['data']['processInstanceId'];
        $this->assertNotEmpty($instanceId);

        // instance detail
        $detail = $this->facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertEquals(0, $detail['code']);
        $this->assertEquals($defineId, $detail['data']['processDefineId']);
        $this->assertEquals('user1', $detail['data']['operator']);
        $this->assertArrayHasKey('tasks', $detail['data']);
        $this->assertArrayHasKey('activeTaskList', $detail['data']);
        $this->assertArrayHasKey('formData', $detail['data']);
    }

    public function testInstancePage(): void
    {
        $defineId = $this->deploySimpleFlow();
        $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $result = $this->facade->flow('processInstance/page', ['operator' => 'user1']);
        $this->assertEquals(0, $result['code']);
        $this->assertEquals(1, $result['data']['recordCount']);
    }

    public function testInstanceDetailNotFound(): void
    {
        $result = $this->facade->flow('processInstance/detail', ['id' => '999']);
        $this->assertEquals(99999999, $result['code']);
    }

    // ── 流程任务 ──

    public function testTodoListAndDoneList(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        // simple 流程的审批人是 "leader"
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertEquals(0, $todo['code']);
        $this->assertGreaterThanOrEqual(1, $todo['data']['recordCount']);

        // 执行任务
        $taskId = $todo['data']['rows'][0]['id'];
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId,
            'operator' => 'leader',
            'submitType' => SubmitType::AGREE,
        ]);
        $this->assertEquals(0, $exec['code']);

        // doneList
        $done = $this->facade->flow('processTask/doneList', ['operator' => 'leader']);
        $this->assertEquals(0, $done['code']);
        $this->assertGreaterThanOrEqual(1, $done['data']['recordCount']);
        // 82-8：doneList 行 finishTime 已格式化（yyyy-MM-dd HH:mm:ss 无 T，对齐 Java/Go/Python/Node）
        $row = $done['data']['rows'][0];
        $this->assertNotNull($row['finishTime'] ?? null, 'doneList 行 finishTime 应非空（已办任务）');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $row['finishTime'],
            "doneList finishTime 应 yyyy-MM-dd HH:mm:ss（无 T）: {$row['finishTime']}"
        );
    }

    public function testTaskDetail(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $taskId = $todo['data']['rows'][0]['id'];

        $detail = $this->facade->flow('processTask/detail', ['id' => $taskId, 'operator' => 'leader']);
        $this->assertEquals(0, $detail['code']);
        $this->assertTrue($detail['data']['executable']);
        $this->assertArrayHasKey('taskActorIdList', $detail['data']);
    }

    /**
     * taskDetail performType/taskType 出口数字契约（issues/78）：普通 0 / 会签 1。
     * PHP 出口本就是 ?int，本测试钉契约与 Java 修复后五语言一致（防回归到字符串形态）。
     */
    public function testTaskDetailPerformTypeNumeric(): void
    {
        // 普通流程：task1 performType=0 / taskType=0
        $defineId = $this->deploySimpleFlow();
        $this->facade->flow('processDefine/startAndExecute', ['processDefineId' => $defineId, 'operator' => 'user1']);
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $taskId = $todo['data']['rows'][0]['id'];
        $detail = $this->facade->flow('processTask/detail', ['id' => $taskId, 'operator' => 'leader']);
        $this->assertEquals(0, $detail['code'], json_encode($detail, JSON_UNESCAPED_UNICODE));
        $this->assertIsInt($detail['data']['performType']);
        $this->assertSame(0, $detail['data']['performType'], '普通任务 performType 应=0');
        $this->assertSame(0, $detail['data']['taskType'], '普通任务 taskType 应=0');

        // 会签流程：task1 performType=1
        $json = file_get_contents(jeeflow_flows_dir() . '/06-countersign-sequential.json');
        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertEquals(0, $deploy['code']);
        $this->facade->flow('processDefine/startAndExecute', ['processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1']);
        $csTodo = $this->facade->flow('processTask/todoList', ['operator' => 'userA']);
        $this->assertGreaterThanOrEqual(1, $csTodo['data']['recordCount'], '会签应有进行中任务');
        $csDetail = $this->facade->flow('processTask/detail', ['id' => $csTodo['data']['rows'][0]['id'], 'operator' => 'userA']);
        $this->assertEquals(0, $csDetail['code'], json_encode($csDetail, JSON_UNESCAPED_UNICODE));
        $this->assertIsInt($csDetail['data']['performType']);
        $this->assertSame(1, $csDetail['data']['performType'], "会签任务 performType 应=1（非 'COUNTERSIGN'）");
    }

    public function testTaskLatest(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        $latest = $this->facade->flow('processTask/latest', ['processInstanceId' => $instanceId]);
        $this->assertEquals(0, $latest['code']);
        $this->assertNotNull($latest['data']);
        $this->assertEquals(ProcessTaskState::DOING, $latest['data']['taskState']);
    }

    public function testTaskSurrogate(): void
    {
        $defineId = $this->deploySimpleFlow();
        $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $taskId = $todo['data']['rows'][0]['id'];

        // 加人
        $result = $this->facade->flow('processTask/surrogate', [
            'processTaskId' => $taskId,
            'actorIds' => ['user3'],
        ]);
        $this->assertEquals(0, $result['code']);

        // user3 现在也能看到待办
        $todo3 = $this->facade->flow('processTask/todoList', ['operator' => 'user3']);
        $this->assertGreaterThanOrEqual(1, $todo3['data']['recordCount']);
    }

    public function testJumpAbleTaskNameList(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        $result = $this->facade->flow('processTask/jumpAbleTaskNameList', ['processInstanceId' => $instanceId]);
        $this->assertEquals(0, $result['code']);
        // simple 流程有 task 节点
        $this->assertNotEmpty($result['data']);
    }

    // ── 撤回 ──

    public function testWithdraw(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];
        $doingBefore = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doingBefore, '撤回前应有进行中任务');

        $result = $this->facade->flow('processInstance/withdraw', [
            'id' => $instanceId,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);

        $detail = $this->facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertEquals(ProcessInstanceState::WITHDRAW, $detail['data']['state']);

        // issues/113：原 doing 任务须落 WITHDRAW(30)，不能落 ABANDON(99)。
        // 只断"实例态 + doing 清空"抓不到这个缺陷——go/python/node 三栈正是从这条缝隙漏掉的。
        foreach ($doingBefore as $task) {
            $stored = $this->repo->findTaskById($task->getTaskId());
            $this->assertNotNull($stored, '撤回后任务应仍可读到');
            $this->assertSame(ProcessTaskState::WITHDRAW, $stored->getTaskState(),
                '撤回任务态应=30(WITHDRAW)，99(ABANDON) 是废弃码，两码不得混用');
        }
        $this->assertCount(0, $this->repo->findDoingTasks($instanceId), '撤回后不应再有进行中任务');
    }

    // ── 审批记录 ──

    public function testApprovalRecord(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/approvalRecord', ['id' => $instanceId]);
        $this->assertEquals(0, $result['code']);
        $this->assertIsArray($result['data']);
    }

    // ── approvalRecord 四条口径（issues/154，spec 06 §4.6）──

    /** 口径④ 出口九键（首列 id）＋ id 必须是字符串 ＋ 口径③ ext 不回落实例变量 */
    public function testApprovalRecordNineKeysWithStringId(): void
    {
        $defineId = $this->deploySimpleFlow();
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1', 'bizKey' => 'leave-0001',
        ]);
        $this->assertEquals(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];

        $rec = $this->facade->flow('processInstance/approvalRecord', ['id' => $instanceId]);
        $this->assertEquals(0, $rec['code'], json_encode($rec, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($rec['data'], '审批记录应含 apply/task1 两行');

        $expectKeys = ['id', 'taskName', 'displayName', 'taskType', 'performType',
            'taskState', 'operator', 'finishTime', 'ext'];
        $byName = [];
        foreach ($rec['data'] as $row) {
            // 列集合与列序都照 spec 表（java 侧 LinkedHashMap 同形）：首列就是新增的 id
            $this->assertSame($expectKeys, array_keys($row), 'approvalRecord 出口应恰为九键且 id 打头');
            // issues/154：id **必须**字符串——19 位雪花出 number 会被 JS 截精度，
            // 本栈门面没有 Java 宿主那层 Jackson Long→String 兜底，引擎侧必须自己转
            $this->assertIsString($row['id'], 'id 必须是字符串，不得出 number');
            $this->assertNotSame('', $row['id'], 'id 不得为空串');
            $byName[$row['taskName']] = $row;
        }
        $this->assertArrayHasKey('task1', $byName);
        // 口径③：ext 只出**任务变量**；实例变量 bizKey 不得回落进来
        $ext = $byName['task1']['ext'];
        $extArr = is_array($ext) ? $ext : get_object_vars($ext);
        $this->assertArrayNotHasKey('bizKey', $extArr,
            '口径③：任务变量为空时 ext 出空对象，绝不回落实例变量（两种语义不得共用一个键）');
    }

    /**
     * 口径① 行序**必须** id ASC。本用例刻意把聚合根的任务序打反（模拟 PDO 路
     * `findInstanceById` 那条 ORDER BY create_time 与 id 序不一致的坏形状），
     * 判据是出口仍按 id 升序——排序缺失/排错方向都会红。
     */
    public function testApprovalRecordSortedByIdAscending(): void
    {
        $defineId = $this->deploySimpleFlow();
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];

        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($inst);
        $tasks = $inst->getTasks();
        $this->assertCount(2, $tasks, '前置：apply + task1 两行任务');
        $ids = array_map(fn($t) => (string) $t->getTaskId(), $tasks);
        $ascending = $ids;
        sort($ascending, SORT_NUMERIC);
        $this->assertNotSame($ascending, array_reverse($ascending), '前置：两行 id 互异 ⇒ 打反后有判别力');

        $inst->setTasks(array_reverse($tasks)); // 造坏形状：取数腿给的序 ≠ id 序

        $rec = $this->facade->flow('processInstance/approvalRecord', ['id' => $instanceId]);
        $this->assertEquals(0, $rec['code'], json_encode($rec, JSON_UNESCAPED_UNICODE));
        $this->assertSame($ascending, array_column($rec['data'], 'id'),
            '口径①：approvalRecord 行序必须 id ASC，与取数腿给的原始序无关');
    }

    /** 口径② 实例 id 不存在 ⇒ 空数组（视图端点不因此报错）；highLight 未立此条，本用例不覆盖它 */
    public function testApprovalRecordInstanceNotFoundReturnsEmptyArray(): void
    {
        $rec = $this->facade->flow('processInstance/approvalRecord', ['id' => '9999999999999999999']);
        $this->assertSame(0, $rec['code'], json_encode($rec, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $rec['data'], '口径②：实例不存在出空数组，不得报「流程实例不存在」');
    }

    // ── highLight ──

    public function testHighLight(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $result['code']);
        $this->assertArrayHasKey('activeNodeNames', $result['data']);
        $this->assertArrayHasKey('historyNodeNames', $result['data']);
        $this->assertArrayHasKey('historyEdgeNames', $result['data']);
        $this->assertArrayHasKey('nodeProgress', $result['data']);
    }

    // ── highLight 模型路径补全 + 决策出边求值过滤（issues/153①②，spec 06 §4.6 三条义务）──
    //
    // 上面 testHighLight 只断「四个键存在」——那是本栈第一条 highLight 判据的浅形状，
    // 恒空的 historyEdgeNames 与缺整条模型腿的 historyNodeNames 都能从它底下溜过去
    // （本轮按 owner 要求**不动**那条既有期望值，只在它旁边加真判据）。
    //
    // 夹具 flows/03-decision-expr.json 拓扑（边名即 JSON 里的 edge id）：
    //   start -e0-> apply -e_apply_1-> task1 -e2-> decision1
    //     decision1 -e3(amount>1000)-> task2 -e5-> end
    //     decision1 -e4(amount<=1000)-> task3 -e6-> end

    /** 小额分支（amount=500 走 e4/task3）：真判据——历史边**恰为**求值为 true 的那条路径 */
    public function testHighLightDecisionBranchFilteringLowAmount(): void
    {
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());
        $instanceId = $this->startDecisionFlow(500);

        // 办结 task1（leader）→ 决策落 task3(director)
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertGreaterThanOrEqual(1, $todo['data']['recordCount'], 'leader 应有 task1 待办');
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $todo['data']['rows'][0]['id'],
            'operator' => 'leader', 'submitType' => SubmitType::AGREE, 'amount' => 500,
        ]);
        $this->assertEquals(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));

        // ① 中场（task3 仍是活跃节点）：遇活跃节点停止深入 ⇒ end 与 e6 都还没进高亮
        $mid = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $mid['code'], json_encode($mid, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['task3'], $mid['data']['activeNodeNames'], '决策后活跃节点应恰为 task3');
        $this->assertEqualsCanonicalizing(
            ['e0', 'e_apply_1', 'e2', 'e4'], $mid['data']['historyEdgeNames'],
            '历史边必须恰为走过的 true 边；未走分支 e3（及其下游 e5）不得高亮');
        $this->assertEqualsCanonicalizing(
            ['apply', 'task1', 'decision1'], $mid['data']['historyNodeNames'],
            '历史节点＝任务行腿[apply,task1] ∪ 模型补全腿[decision1]；活跃节点 task3 不入历史');

        // ② 办结 task3 → 走到 end
        $todo2 = $this->facade->flow('processTask/todoList', ['operator' => 'director']);
        $this->assertGreaterThanOrEqual(1, $todo2['data']['recordCount'], 'director 应有 task3 待办');
        $exec2 = $this->facade->flow('processTask/execute', [
            'processTaskId' => $todo2['data']['rows'][0]['id'],
            'operator' => 'director', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertEquals(0, $exec2['code'], json_encode($exec2, JSON_UNESCAPED_UNICODE));

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $edges = $hl['data']['historyEdgeNames'];
        $nodes = $hl['data']['historyNodeNames'];
        $this->assertEqualsCanonicalizing(
            ['e0', 'e_apply_1', 'e2', 'e4', 'e6'], $edges, '走过的边集合应恰为 e0/e_apply_1/e2/e4/e6');
        $this->assertNotContains('e3', $edges, '未走分支 e3 不应高亮');
        $this->assertNotContains('e5', $edges, '未走分支 e5 不应高亮');
        $this->assertNotContains('task2', $nodes, '未走分支上的 task2 不应高亮');
        $this->assertContains('task3', $nodes);
    }

    /** 大额分支（amount=2000 走 e3/task2）：求值必须两档都能判，另一侧的 e4/e6/task3 不得出现 */
    public function testHighLightDecisionBranchFilteringHighAmount(): void
    {
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());
        $instanceId = $this->startDecisionFlow(2000);

        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->facade->flow('processTask/execute', [
            'processTaskId' => $todo['data']['rows'][0]['id'],
            'operator' => 'leader', 'submitType' => SubmitType::AGREE, 'amount' => 2000,
        ]);
        $todo2 = $this->facade->flow('processTask/todoList', ['operator' => 'manager']);
        $this->assertGreaterThanOrEqual(1, $todo2['data']['recordCount'], 'manager 应有 task2 待办');
        $this->facade->flow('processTask/execute', [
            'processTaskId' => $todo2['data']['rows'][0]['id'],
            'operator' => 'manager', 'submitType' => SubmitType::AGREE,
        ]);

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $this->assertEqualsCanonicalizing(
            ['e0', 'e_apply_1', 'e2', 'e3', 'e5'], $hl['data']['historyEdgeNames'],
            '大额侧走过的边应恰为 e0/e_apply_1/e2/e3/e5');
        $this->assertNotContains('e4', $hl['data']['historyEdgeNames'], '未走分支 e4 不应高亮');
        $this->assertNotContains('e6', $hl['data']['historyEdgeNames'], '未走分支 e6 不应高亮');
        $this->assertNotContains('task3', $hl['data']['historyNodeNames'], '未走分支上的 task3 不应高亮');
    }

    /**
     * 模型补全腿（spec 06 §4.6 义务 1）：结束节点**不产生任务行**，只能由「从 start 沿
     * getOutputs() 递归」那一条腿补出来；本栈任务表里也没有边这一列，historyEdgeNames 整条
     * 只能来自模型腿。夹具 flows/01-simple.json：start -e0-> apply -e_apply_1-> task1 -e2-> end。
     */
    public function testHighLightModelLegAddsNonTaskNodes(): void
    {
        $defineId = $this->deploySimpleFlow();
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertEquals(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];

        // 中场：task1 活跃 ⇒ 遇活跃节点停止深入，end 与 e2 都还不该出现
        $mid = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $mid['code'], json_encode($mid, JSON_UNESCAPED_UNICODE));
        $this->assertEqualsCanonicalizing(['apply'], $mid['data']['historyNodeNames'],
            '活跃节点 task1 不入历史，其下游 end 也不该被补出来');
        $this->assertEqualsCanonicalizing(['e0', 'e_apply_1'], $mid['data']['historyEdgeNames'],
            '递归停在活跃节点上 ⇒ e2 尚未走到的边不进高亮');

        // 办结 task1 → 实例结束：end（无任务行的节点）必须由模型腿补出
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $this->assertGreaterThanOrEqual(1, $todo['data']['recordCount'], 'leader 应有 task1 待办');
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $todo['data']['rows'][0]['id'],
            'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertEquals(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $nodes = $hl['data']['historyNodeNames'];
        $this->assertContains('end', $nodes,
            '结束节点不产生任务行 ⇒ 只可能来自模型补全腿（本栈旧形状缺这条腿，此处必红）');
        $this->assertEqualsCanonicalizing(['apply', 'task1', 'end'], $nodes,
            '历史节点＝任务行腿[apply,task1] ∪ 模型补全腿[end]');
        // start 自身不进 history：java collectPath 只把「输出边的目标节点」并进 history，
        // 初始调用传的是 model.getStart() 本身（JeeflowFacade.collectPath 同形），故不钉 start
        $this->assertNotContains('start', $nodes, '与 java/go 基准一致：递归起点节点自身不补进历史');
        $this->assertEqualsCanonicalizing(['e0', 'e_apply_1', 'e2'], $hl['data']['historyEdgeNames'],
            '任务表里没有边列 ⇒ 边名整条来自模型腿');
    }

    /**
     * 降级档（spec 06 §4.6 义务 2 唯一允许的 false 档）：ExpressionEvaluatorInterface 未注册时，
     * 带表达式的出边整档判 false——不收边名也不收目标节点；无表达式的普通边不受影响。
     */
    public function testHighLightWithoutEvaluatorDegradesToNoExprEdges(): void
    {
        // setUp 已 ServiceContext::clear()，此处刻意**不**注册求值器
        $instanceId = $this->startDecisionFlow(500);
        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $edges = $hl['data']['historyEdgeNames'];
        $this->assertNotContains('e3', $edges, '降级档：带表达式的出边一条都不收');
        $this->assertNotContains('e4', $edges, '降级档：带表达式的出边一条都不收');
        $this->assertContains('e0', $edges, '降级档只作用在带 expr 的边上，普通边照收');
        $this->assertNotContains('task2', $hl['data']['historyNodeNames']);
        $this->assertNotContains('task3', $hl['data']['historyNodeNames'],
            '决策节点的出边全被判 false ⇒ 网关下游整条不补全');
    }

    /** 发起 03-decision-expr 流程（apply 由 startAndExecute 自动办结），返回实例 id */
    private function startDecisionFlow(int $amount): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/03-decision-expr.json');
        $this->assertNotFalse($json);
        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertEquals(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'],
            'operator' => 'user1', 'amount' => $amount,
        ]);
        $this->assertEquals(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        return $start['data']['processInstanceId'];
    }

    /**
     * highLight nodeProgress 成员进度回显（issues/41/82-10，五语言对齐 Java/Go/Python）：
     * 会签节点 type=SEQUENTIAL，成员 done 按完成状态逐人标记、active 仅进行中任务首位，
     * 其余未完成成员不带任何标记。
     *
     * 本测试同时钉住两处引擎缺口（此前 PHP 仅断言 nodeProgress 键存在）：
     *  ① findHistoryTasks 排除 DOING → 会签进行中任务全丢（成员/active 无从计算）；
     *  ② buildNodeProgress 无 activeActor 概念（把所有未完成成员都标 active）+ type 只出 PARALLEL。
     */
    public function testHighLightNodeProgress(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/06-countersign-sequential.json');
        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertEquals(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertEquals(0, $startResult['code'], json_encode($startResult, JSON_UNESCAPED_UNICODE));
        $instanceId = $startResult['data']['processInstanceId'];

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertEquals(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $np = $hl['data']['nodeProgress'];

        // 历史节点 apply：发起人 user1 done
        $this->assertArrayHasKey('apply', $np, 'nodeProgress 应含 apply 节点');
        $applyMembers = $np['apply']['members'];
        $this->assertCount(1, $applyMembers);
        $this->assertSame('user1', $applyMembers[0]['id']);
        $this->assertTrue($applyMembers[0]['done'] ?? false, 'apply 发起人应 done');

        // 顺序会签 task1：type=SEQUENTIAL、第一位(userA) active、第二位(userB) 无标记
        $this->assertArrayHasKey('task1', $np, 'nodeProgress 应含会签 task1 节点');
        $this->assertSame('SEQUENTIAL', $np['task1']['type'] ?? null, '顺序会签 type 应=SEQUENTIAL');
        $m1 = $np['task1']['members'];
        $this->assertCount(2, $m1, '会签成员应为 userA+userB');
        $this->assertSame('userA', $m1[0]['id']);
        $this->assertTrue($m1[0]['active'] ?? false, 'userA（进行中首位）应 active');
        $this->assertArrayNotHasKey('done', $m1[0], 'userA 未完成不应有 done');
        $this->assertSame('userB', $m1[1]['id']);
        $this->assertArrayNotHasKey('active', $m1[1], 'userB（未完成非首位）不应 active');
        $this->assertArrayNotHasKey('done', $m1[1], 'userB 未完成不应有 done');

        // 推进会签：userA done → userB active
        $todoA = $this->facade->flow('processTask/todoList', ['operator' => 'userA']);
        $this->assertGreaterThanOrEqual(1, $todoA['data']['recordCount']);
        $execA = $this->facade->flow('processTask/execute', [
            'processTaskId' => $todoA['data']['rows'][0]['id'], 'operator' => 'userA', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertEquals(0, $execA['code'], json_encode($execA, JSON_UNESCAPED_UNICODE));

        $hl2 = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $m2 = $hl2['data']['nodeProgress']['task1']['members'];
        $this->assertTrue($m2[0]['done'] ?? false, 'userA 完成后应 done');
        $this->assertArrayNotHasKey('active', $m2[0], 'userA 完成后不应 active');
        $this->assertTrue($m2[1]['active'] ?? false, 'userB（进行中首位）应 active');
        $this->assertArrayNotHasKey('done', $m2[1], 'userB 未完成不应有 done');

        // 全部完成 → 全部 done，无 active
        $todoB = $this->facade->flow('processTask/todoList', ['operator' => 'userB']);
        $this->assertGreaterThanOrEqual(1, $todoB['data']['recordCount']);
        $execB = $this->facade->flow('processTask/execute', [
            'processTaskId' => $todoB['data']['rows'][0]['id'], 'operator' => 'userB', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertEquals(0, $execB['code'], json_encode($execB, JSON_UNESCAPED_UNICODE));

        $hl3 = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $m3 = $hl3['data']['nodeProgress']['task1']['members'];
        $this->assertTrue($m3[0]['done'] ?? false, 'userA 应 done');
        $this->assertTrue($m3[1]['done'] ?? false, 'userB 应 done');
        $this->assertArrayNotHasKey('active', $m3[1], '完成后不应有 active');
    }

    // ── 抄送 ──

    public function testCreateCCInstanceAndCcList(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        // 手动抄送
        $cc = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId,
            'actorIds' => ['user3', 'user4'],
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $cc['code']);

        // ccList
        $list = $this->facade->flow('processInstance/ccList', ['operator' => 'user3']);
        $this->assertEquals(0, $list['code']);
        $this->assertGreaterThanOrEqual(1, $list['data']['recordCount']);

        // 标记已读
        $read = $this->facade->flow('processInstance/updateCCStatus', [
            'processInstanceId' => $instanceId,
            'operator' => 'user3',
        ]);
        $this->assertEquals(0, $read['code']);
    }

    public function testCreateCCInstanceEmptyActors(): void
    {
        $result = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => '123',
            'actorIds' => [],
            'operator' => 'user1',
        ]);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('不能为空', $result['msg']);
    }

    // ── getAssigneeTextData ──

    public function testGetAssigneeTextData(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/getAssigneeTextData', ['id' => $instanceId]);
        $this->assertEquals(0, $result['code']);
        $this->assertIsArray($result['data']);
    }

    /**
     * spec 06 §4.6 getAssigneeTextData 两条义务（issues/155）：label 八栈严格 `节点显示名:用户id`，
     * includeNodeName=false 时只出用户 id；value 是参与者用户 id。
     *
     * 本用例**注册**一个返回 realName='李四' 的 UserProvider——这是判别力所在：
     * PHP 旧实现走 realName ?: actorId ⇒ 出「上级审批:李四」（八栈里唯一异类），
     * 并派后必须忽略姓名出「上级审批:leader」。不注册 SPI 的话新旧同答案，测不出这条。
     */
    public function testGetAssigneeTextDataLabelUsesUserIdNotRealName(): void
    {
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                return ['userId' => $userId, 'realName' => '李四', 'deptId' => null,
                    'deptName' => null, 'postId' => null, 'postName' => null];
            }
        });

        $defineId = $this->deploySimpleFlow();
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];

        $r = $this->facade->flow('processInstance/getAssigneeTextData', ['id' => $instanceId]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($r['data'], 'task1 的处理人应在文案里');
        foreach ($r['data'] as $item) {
            $this->assertSame(['value', 'label'], array_keys($item), '出口键集合＝value/label');
            $this->assertSame('leader', $item['value'], '义务①：value 是参与者用户 id');
            $this->assertSame('上级审批:leader', $item['label'], '义务②：label 严格 节点显示名:用户id');
            $this->assertStringNotContainsString('李四', $item['label'],
                'label 不得出 realName（PHP 旧异类，已并派为其余七栈形状）');
        }

        // includeNodeName=false ⇒ 只出用户 id
        $r2 = $this->facade->flow('processInstance/getAssigneeTextData', [
            'id' => $instanceId, 'includeNodeName' => false,
        ]);
        $this->assertEquals(0, $r2['code'], json_encode($r2, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($r2['data']);
        foreach ($r2['data'] as $item) {
            $this->assertSame('leader', $item['label'], 'includeNodeName=false 时 label 只出用户 id');
        }
    }

    // ── execute 分发 ──

    public function testExecuteReject(): void
    {
        $defineId = $this->deploySimpleFlow();
        $startResult = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $startResult['data']['processInstanceId'];
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $taskId = $todo['data']['rows'][0]['id'];

        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId,
            'operator' => 'leader',
            'submitType' => SubmitType::REJECT,
        ]);
        $this->assertEquals(0, $exec['code']);

        $detail = $this->facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertEquals(ProcessInstanceState::REJECTED, $detail['data']['state']);
    }

    // ── 多流程定义部署 + 版本管理 ──

    public function testBatchDefineRemove(): void
    {
        $id1 = $this->deploySimpleFlow();
        $id2 = $this->deploySimpleFlow();
        $result = $this->facade->flow('processDefine/remove', ['ids' => [$id1, $id2]]);
        $this->assertEquals(0, $result['code']);

        $page = $this->facade->flow('processDefine/page');
        $this->assertEquals(0, $page['data']['recordCount']);
    }

    // ── processInstance/startAndExecute 别名 ──

    public function testProcessInstanceStartAndExecute(): void
    {
        $defineId = $this->deploySimpleFlow();
        $result = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);
        $this->assertArrayHasKey('processInstanceId', $result['data']);
    }
}
