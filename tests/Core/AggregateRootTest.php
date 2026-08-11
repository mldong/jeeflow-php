<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * 聚合根基础测试 —— 验证 DDD 充血模型的核心行为
 */
class AggregateRootTest extends TestCase
{
    private function makeDefine(string $id = '100'): array
    {
        return [
            'id' => $id,
            'name' => 'leave',
            'displayName' => '请假流程',
            'type' => 'approval',
            'state' => 1,
            'content' => '{}',
            'version' => 0,
        ];
    }

    public function testCreateInstance(): void
    {
        $define = $this->makeDefine();
        $args = FlowData::of(['f_reason' => '年假', 'f_days' => 5]);
        $instance = ProcessInstance::create($define, 'user1', $args);

        $this->assertSame(ProcessInstanceState::DOING, $instance->getState());
        $this->assertSame('user1', $instance->getOperator());
        $this->assertSame('年假', $instance->getVariables()->get('f_reason'));
        $this->assertSame(5, $instance->getVariables()->get('f_days'));
        $this->assertEmpty($instance->getTasks());
    }

    public function testCreateTaskAndComplete(): void
    {
        $define = $this->makeDefine();
        $instance = ProcessInstance::create($define, 'user1');

        $task = $instance->createTask('node_1', '部门审批', 0, 0, null, ['user2'], 'user1');
        $task->setTaskId('t1');

        $this->assertCount(1, $instance->getDoingTasks());
        $this->assertTrue($task->isDoing());
        $this->assertTrue($task->isAllowed('user2'));
        $this->assertFalse($task->isAllowed('user3'));

        // 完成任务
        $instance->completeTask('t1', 'user2', FlowData::of(['tf_approvalComment' => '同意']));

        $this->assertTrue($task->isFinished());
        $this->assertSame('user2', $task->getActorId());
        $this->assertSame('同意', $task->getVariables()->get('tf_approvalComment'));
        $this->assertEmpty($instance->getDoingTasks());
    }

    public function testWithdraw(): void
    {
        $define = $this->makeDefine();
        $instance = ProcessInstance::create($define, 'user1');

        $task = $instance->createTask('node_1', '审批', 0, 0, null, ['user2'], 'user1');
        $task->setTaskId('t1');

        $instance->withdraw('user1');

        $this->assertSame(ProcessInstanceState::WITHDRAW, $instance->getState());
        $this->assertSame(ProcessTaskState::WITHDRAW, $task->getTaskState());
    }

    public function testFinish(): void
    {
        $define = $this->makeDefine();
        $instance = ProcessInstance::create($define, 'user1');

        $task = $instance->createTask('node_1', '审批', 0, 0, null, ['user2'], 'user1');
        $task->setTaskId('t1');
        $instance->completeTask('t1', 'user2', null);

        $instance->finish();
        $this->assertTrue($instance->isFinished());
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
    }

    public function testAutoAndAdminAllowed(): void
    {
        $define = $this->makeDefine();
        $instance = ProcessInstance::create($define, 'user1');

        $task = $instance->createTask('node_1', '审批', 0, 0, null, ['user2'], 'user1');

        // flow.auto 和 flow.admin 应该被放行
        $this->assertTrue($task->isAllowed(FlowConst::AUTO_ID));
        $this->assertTrue($task->isAllowed(FlowConst::ADMIN_ID));
    }

    public function testCountersignTasks(): void
    {
        $define = $this->makeDefine();
        $instance = ProcessInstance::create($define, 'user1');

        $tasks = $instance->createCountersignTasks(
            'node_1', '会签审批', 0, 1, null,
            ['user2', 'user3', 'user4'], 'user1'
        );

        $this->assertCount(3, $tasks);
        $this->assertCount(3, $instance->getDoingTasks());

        // 每个会签任务只有一个 actor
        $this->assertSame(['user2'], $tasks[0]->getActorIds());
        $this->assertSame(['user3'], $tasks[1]->getActorIds());
        $this->assertSame(['user4'], $tasks[2]->getActorIds());
    }

    public function testInMemoryRepository(): void
    {
        $repo = new InMemoryProcessRepository();
        $define = $this->makeDefine('200');
        $repo->addDefine($define);

        $found = $repo->findDefineById('200');
        $this->assertNotNull($found);
        $this->assertSame('leave', $found['name']);

        $instance = ProcessInstance::create($define, 'user1');
        $repo->saveInstance($instance);
        $this->assertNotNull($instance->getInstanceId());

        $foundInst = $repo->findInstanceById($instance->getInstanceId());
        $this->assertSame($instance, $foundInst);

        $task = $instance->createTask('node_1', '审批', 0, 0, null, ['user2'], 'user1');
        $repo->saveTask($task);
        $this->assertNotNull($task->getTaskId());

        $foundTask = $repo->findTaskById($task->getTaskId());
        $this->assertSame($task, $foundTask);
    }

    public function testCcInstance(): void
    {
        $repo = new InMemoryProcessRepository();
        $repo->createCcInstance('1001', 'user1', ['user5', 'user6']);

        $ccList = $repo->getCcInstances();
        $this->assertCount(2, $ccList);
        $this->assertSame('user5', $ccList[0]['actorId']);
        $this->assertSame('user6', $ccList[1]['actorId']);
    }
}
