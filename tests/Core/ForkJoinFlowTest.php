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
 * 并行分支合并流程集成测试 —— 04-fork-join.json
 *
 * 流程拓扑: start → apply(applicant) → fork1 → taskA(userA) + taskB(userB) → join1 → end
 */
class ForkJoinFlowTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $flowJson = file_get_contents(jeeflow_flows_dir() . '/04-fork-join.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '4',
            'name' => 'fork-join',
            'displayName' => '并行分支合并流程',
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

    /**
     * 正向：fork 创建两个并行任务，逐个完成后 join 合并，流程结束
     */
    public function testForkJoinFullLifecycle(): void
    {
        // 1. 启动流程 → start → apply(applicant) 自动创建
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('4', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 2. 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->assertNotNull($applyTask, '应找到 apply 任务');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 3. fork 后应有两个并行任务: taskA + taskB
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $doingNames = array_map(fn($t) => $t->getTaskName(), $doingTasks);
        sort($doingNames);
        $this->assertSame(['taskA', 'taskB'], $doingNames, 'fork 后应同时创建 taskA 和 taskB');

        // 4. 完成 taskA → join 不应触发（taskB 仍在进行）
        $taskA = $this->findDoingTask($instance, 'taskA');
        $this->engine->executeProcessTask($taskA->getTaskId(), 'userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), 'taskA 完成后流程应仍在运行（taskB 未完成）');
        $doingAfterA = $instance->getDoingTasks();
        $this->assertCount(1, $doingAfterA, 'taskA 完成后应只剩 taskB');
        $this->assertSame('taskB', $doingAfterA[0]->getTaskName());

        // 5. 完成 taskB → join 触发，流程流转到 end
        $taskB = $doingAfterA[0];
        $this->engine->executeProcessTask($taskB->getTaskId(), 'userB', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '两个分支都完成后流程应已完成');

        // 6. 验证任务历史
        $allTasks = $instance->getTasks();
        $this->assertCount(3, $allTasks, '应有 3 个任务（apply + taskA + taskB）');
        foreach ($allTasks as $t) {
            $this->assertTrue($t->isFinished(), "任务 {$t->getTaskName()} 应已完成");
        }
    }

    /**
     * 负向：先完成 taskB 再完成 taskA，join 同样在最后触发
     */
    public function testForkJoinReverseOrder(): void
    {
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('4', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 先完成 taskB
        $instance = $this->repo->findInstanceById($instanceId);
        $taskB = $this->findDoingTask($instance, 'taskB');
        $this->assertNotNull($taskB);
        $this->engine->executeProcessTask($taskB->getTaskId(), 'userB', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), 'taskB 完成后流程应仍在运行（taskA 未完成）');

        // 再完成 taskA → join 触发
        $instance = $this->repo->findInstanceById($instanceId);
        $taskA = $this->findDoingTask($instance, 'taskA');
        $this->assertNotNull($taskA);
        $this->engine->executeProcessTask($taskA->getTaskId(), 'userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '反向完成后流程应已完成');
    }

    private function findDoingTask(\Jeeflow\Core\Domain\ProcessInstance $instance, string $taskName): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
