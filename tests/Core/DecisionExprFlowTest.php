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
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Tests\Fixture\SimpleExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * 决策表达式流程集成测试 —— 03-decision-expr.json
 *
 * 流程拓扑: start → apply(applicant) → task1(leader) → decision1
 *   ├── amount > 1000  → task2(manager) → end
 *   └── amount <= 1000 → task3(director) → end
 */
class DecisionExprFlowTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/03-decision-expr.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '3',
            'name' => 'decision-expr',
            'displayName' => '决策表达式流程',
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
     * 大额分支：amount=2000 > 1000 → 走 task2（经理审批）
     */
    public function testHighAmountGoesToManager(): void
    {
        $args = FlowData::of(['amount' => 2000]);
        $instance = $this->engine->startProcessInstanceById('3', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 1. 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->assertNotNull($applyTask);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 2. 完成 task1（填写报销单），携带金额变量
        $instance = $this->repo->findInstanceById($instanceId);
        $task1 = $this->findDoingTask($instance, 'task1');
        $this->assertNotNull($task1, '应找到 task1（填写报销单）');
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'amount' => 2000,
        ]));

        // 3. 决策后应走 task2（经理审批），而非 task3
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $this->assertCount(1, $doingTasks, '决策后应只有一个进行中任务');
        $this->assertSame('task2', $doingTasks[0]->getTaskName(), '大额应走经理审批');

        // 4. 完成 task2 → 流程结束
        $this->engine->executeProcessTask($doingTasks[0]->getTaskId(), 'manager', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
    }

    /**
     * 小额分支：amount=500 <= 1000 → 走 task3（总监审批）
     */
    public function testLowAmountGoesToDirector(): void
    {
        $args = FlowData::of(['amount' => 500]);
        $instance = $this->engine->startProcessInstanceById('3', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 1. 完成 apply
        $applyTask = $this->findDoingTask($instance, 'apply');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 2. 完成 task1
        $instance = $this->repo->findInstanceById($instanceId);
        $task1 = $this->findDoingTask($instance, 'task1');
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'amount' => 500,
        ]));

        // 3. 决策后应走 task3（总监审批）
        $instance = $this->repo->findInstanceById($instanceId);
        $doingTasks = $instance->getDoingTasks();
        $this->assertCount(1, $doingTasks);
        $this->assertSame('task3', $doingTasks[0]->getTaskName(), '小额应走总监审批');

        // 4. 完成 task3 → 流程结束
        $this->engine->executeProcessTask($doingTasks[0]->getTaskId(), 'director', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
    }

    private function findDoingTask(\Jeeflow\Core\Domain\ProcessInstance $instance, string $taskName): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
