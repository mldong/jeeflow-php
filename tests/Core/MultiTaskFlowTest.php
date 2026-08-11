<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 多级审批流程集成测试 —— 02-multi-task.json
 */
class MultiTaskFlowTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/02-multi-task.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '2',
            'name' => 'multi-task',
            'displayName' => '多级审批流程',
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

    public function testMultiTaskFullLifecycle(): void
    {
        // start → apply(user1) → task1(leader) → task2(manager) → task3(boss) → end
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('2', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 1. 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->assertNotNull($applyTask, '应找到 apply 任务');
        $submitArgs = FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // 2. 完成 task1
        $instance = $this->repo->findInstanceById($instanceId);
        $task1 = $this->findDoingTask($instance, 'task1');
        $this->assertNotNull($task1, '应找到 task1（上级审批）');
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        // 3. 完成 task2
        $instance = $this->repo->findInstanceById($instanceId);
        $task2 = $this->findDoingTask($instance, 'task2');
        $this->assertNotNull($task2, '应找到 task2（经理审批）');
        $this->engine->executeProcessTask($task2->getTaskId(), 'manager', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        // 4. 完成 task3
        $instance = $this->repo->findInstanceById($instanceId);
        $task3 = $this->findDoingTask($instance, 'task3');
        $this->assertNotNull($task3, '应找到 task3（总监审批）');
        $this->engine->executeProcessTask($task3->getTaskId(), 'boss', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        // 5. 流程应完成
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '四级审批流程应已完成');

        // 验证任务历史
        $allTasks = $instance->getTasks();
        $this->assertCount(4, $allTasks, '应有 4 个任务（apply + task1 + task2 + task3）');
        foreach ($allTasks as $t) {
            $this->assertTrue($t->isFinished(), "任务 {$t->getTaskName()} 应已完成");
        }
    }

    private function findDoingTask(\Jeeflow\Core\Domain\ProcessInstance $instance, string $taskName): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
