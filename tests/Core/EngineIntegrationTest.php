<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 引擎集成测试 —— 加载 01-simple.json 跑通完整流程
 */
class EngineIntegrationTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        // 注册 01-simple.json 流程定义
        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $this->assertNotFalse($flowJson, '01-simple.json 必须存在（共享流程定义）');
        $this->repo->addDefine([
            'id' => '1',
            'name' => 'simple',
            'displayName' => '简单审批流程',
            'type' => 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    public function testModelParser(): void
    {
        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $model = ModelParser::parse($flowJson);

        $this->assertSame('simple', $model->getName());
        $this->assertSame('简单审批流程', $model->getDisplayName());
        $this->assertSame('approval', $model->getType());
        $this->assertNotNull($model->getStart());
        $this->assertCount(4, $model->getNodes()); // start, apply, task1, end
        $this->assertCount(2, $model->getTasks()); // apply, task1
    }

    public function testStartProcess(): void
    {
        $define = $this->repo->findDefineById('1');
        $this->assertNotNull($define);

        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);

        $this->assertNotNull($instance->getInstanceId());
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState());
        $this->assertSame('user1', $instance->getOperator());

        // 01-simple: start → apply(assignee=applicant) → task1(assignee=leader) → end
        // 启动后 apply 节点应该创建任务（assignee=applicant → user1）
        $tasks = $instance->getTasks();
        $this->assertNotEmpty($tasks, '启动后应至少创建一个任务');
    }

    public function testFullFlowLifecycle(): void
    {
        // 启动流程
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 查找 apply 任务（assignee=applicant → user1）
        $doingTasks = $instance->getDoingTasks();
        $this->assertNotEmpty($doingTasks, '启动后应有进行中任务');

        $applyTask = null;
        foreach ($doingTasks as $t) {
            if ($t->getTaskName() === 'apply') {
                $applyTask = $t;
                break;
            }
        }
        $this->assertNotNull($applyTask, '应找到 apply 任务');

        // 完成 apply 任务（user1 提交申请）
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $newTasks = $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // apply 完成后应流转到 task1（assignee=leader）
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $this->assertNotEmpty($doingTasks, '完成 apply 后应有新任务');

        $task1 = null;
        foreach ($doingTasks as $t) {
            if ($t->getTaskName() === 'task1') {
                $task1 = $t;
                break;
            }
        }
        $this->assertNotNull($task1, '应找到 task1（上级审批）');

        // 完成 task1（leader 审批通过）
        $agreeArgs = FlowData::create();
        $agreeArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', $agreeArgs);

        // task1 完成后流程应结束
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '流程应已完成');
    }

    public function testRejectFlow(): void
    {
        // 启动流程
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 完成 apply
        $doingTasks = $instance->getDoingTasks();
        $applyTask = null;
        foreach ($doingTasks as $t) {
            if ($t->getTaskName() === 'apply') {
                $applyTask = $t;
                break;
            }
        }
        $this->assertNotNull($applyTask);

        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // 查找 task1
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $task1 = null;
        foreach ($doingTasks as $t) {
            if ($t->getTaskName() === 'task1') {
                $task1 = $t;
                break;
            }
        }
        $this->assertNotNull($task1);

        // 驳回（executeAndJumpToEnd）
        $rejectArgs = FlowData::create();
        $rejectArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::REJECT);
        $this->engine->executeAndJumpToEnd($task1->getTaskId(), 'leader', $rejectArgs);

        // 流程应被驳回
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::REJECTED, $instance->getState(), '流程应被驳回');
    }

    public function testWithdrawFlow(): void
    {
        // 启动流程
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 撤回
        $instance->withdraw('user1');
        $this->repo->updateInstance($instance);

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::WITHDRAW, $instance->getState(), '流程应已撤回');
    }
}
