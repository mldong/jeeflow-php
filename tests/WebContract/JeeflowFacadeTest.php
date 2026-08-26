<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
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

        $result = $this->facade->flow('processInstance/withdraw', [
            'id' => $instanceId,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);

        $detail = $this->facade->flow('processInstance/detail', ['id' => $instanceId]);
        $this->assertEquals(ProcessInstanceState::WITHDRAW, $detail['data']['state']);
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
