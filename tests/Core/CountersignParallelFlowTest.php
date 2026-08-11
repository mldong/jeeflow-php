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
 * 并行会签流程集成测试 —— 05-countersign-parallel.json
 *
 * 流程拓扑: start → apply(applicant) → task1(userA,userB,userC 并行会签) → end
 */
class CountersignParallelFlowTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/05-countersign-parallel.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '5',
            'name' => 'countersign-parallel',
            'displayName' => '并行会签流程',
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
     * 正向：3 人会签全部通过后流程结束
     */
    public function testAllCountersignComplete(): void
    {
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('5', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 1. 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->assertNotNull($applyTask);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 2. 会签应创建 3 个任务
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $this->assertCount(3, $doingTasks, '会签应创建 3 个并行任务');

        $doingNames = array_map(fn($t) => $t->getTaskName(), $doingTasks);
        $this->assertSame(['task1', 'task1', 'task1'], $doingNames, '所有任务名应为 task1');

        // 收集所有任务 ID
        $taskIds = array_map(fn($t) => $t->getTaskId(), $doingTasks);

        // 3. 完成第一个 → 流程不应结束
        $this->engine->executeProcessTask($taskIds[0], 'userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), '只完成 1/3 流程应仍在运行');
        $this->assertCount(2, $instance->getDoingTasks(), '应剩 2 个进行中任务');

        // 4. 完成第二个 → 流程仍不应结束
        $this->engine->executeProcessTask($taskIds[1], 'userB', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), '只完成 2/3 流程应仍在运行');
        $this->assertCount(1, $instance->getDoingTasks(), '应剩 1 个进行中任务');

        // 5. 完成第三个 → 会签完成，流程结束
        $this->engine->executeProcessTask($taskIds[2], 'userC', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '3/3 完成后流程应已完成');

        // 6. 验证任务总数（apply + 3 个会签 = 4）
        $allTasks = $instance->getTasks();
        $this->assertCount(4, $allTasks, '应有 4 个任务（apply + 3 会签）');
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
