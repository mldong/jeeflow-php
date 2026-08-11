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
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
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
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
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
