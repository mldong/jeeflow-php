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
 * issues/165 · 并行会签门控「裸名」判据——`#nrOfCompletedInstances>=2`（文档/设计器形状）
 * 在生产求值器形状上必须真能放行。
 *
 * 改前形状：buildCountersignVars 只挂前缀键 `csv_<node>_nrOf*`，生产 WfExpressionEvaluator
 * 的 `#` 臂精确查表查不到裸名 ⇒ 恒 false（java 活栈实测 3/3 全办完仍停住，php 同形代码读）。
 * 夹具 SimpleExpressionEvaluator 的后缀桥已按生产形状拆除（issues/165 拍板附案）——
 * 本类判据只有在 handler 真挂了裸名时才可能绿。
 *
 * 夹具＝内联三成员并行会签（applicant → cs1(userA,userB,userC) → end），不动共享 flows/。
 */
class CountersignBareNameI165Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        // 生产形状求值器（#裸名精确查表）；laravel 壳的 WfExpressionEvaluator 同形状
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    private function registerInline(string $name, string $condition): void
    {
        $flowJson = json_encode([
            'name' => $name,
            'displayName' => '裸名会签(165夹具)',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200,
                 'properties' => ['width' => 50, 'height' => 50], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 200, 'y' => 200,
                 'properties' => ['width' => 100, 'height' => 50, 'form' => 'apply-form',
                     'assignee' => 'applicant', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'cs1', 'type' => 'snaker:task', 'x' => 350, 'y' => 200,
                 'properties' => ['width' => 120, 'height' => 60, 'form' => 'countersign-form',
                     'assignee' => 'userA,userB,userC', 'taskType' => 0, 'performType' => '1',
                     'countersignType' => 'PARALLEL',
                     'countersignCompletionCondition' => $condition],
                 'text' => ['value' => '会签审批']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 600, 'y' => 200,
                 'properties' => ['width' => 50, 'height' => 50], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'cs1', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'cs1', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ]);
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => 'i165',
            'name' => $name,
            'displayName' => '裸名会签(165夹具)',
            'type' => 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);
    }

    public function testBareNameReleasesAtThreshold(): void
    {
        $this->registerInline('i165-bare', '#nrOfCompletedInstances>=2');
        $instance = $this->engine->startProcessInstanceById('i165', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $instance = $this->repo->findInstanceById($instanceId);
        $doing = $instance->getDoingTasks();
        $this->assertCount(3, $doing, '会签应创建 3 个并行任务');
        // 建单序＝userA,userB,userC：按下标配对操作人（与 CountersignParallelFlowTest 同法）
        $ids = array_map(fn($t) => $t->getTaskId(), $doing);

        $this->engine->executeProcessTask($ids[0], 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), '1/3 不得放行');

        $this->engine->executeProcessTask($ids[1], 'userB',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(),
            '2/3 必须放行（裸名形状）——改前 3/3 全办完也停住');
    }

    public function testPrefixKeyStillReleases(): void
    {
        // 前缀键形状（引擎内部命名）保留兼容
        $this->registerInline('i165-prefix', '#csv_cs1_nrOfCompletedInstances>=2');
        $instance = $this->engine->startProcessInstanceById('i165', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $instance = $this->repo->findInstanceById($instanceId);
        $doing = $instance->getDoingTasks();
        $ids = array_map(fn($t) => $t->getTaskId(), $doing);
        $this->engine->executeProcessTask($ids[0], 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $this->engine->executeProcessTask($ids[2], 'userC',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $this->assertSame(ProcessInstanceState::FINISHED,
            $this->repo->findInstanceById($instanceId)->getState(),
            '前缀键形状 2/3 照常放行（165 不改既有兼容面）');
    }
}
