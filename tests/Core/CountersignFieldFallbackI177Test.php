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
 * issues/177 R11 · 会签完成条件「field 子对象」兜底——共享夹具 07-countersign-ratio.json 把
 * countersignCompletionCondition 写进 properties.field（旧设计器落笔处），旧 TaskNodeParser
 * 只认顶层 ⇒ 模型字段恒空、2/4 不放行（java 同夹具 2/4 放行）。修后顶层优先、field 兜底
 * （形状对齐 issues/163 附带读形）。
 */
class CountersignFieldFallbackI177Test extends TestCase
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
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    private function registerInline(string $name, ?string $topLevel, ?string $inField): void
    {
        $csProps = ['width' => 120, 'height' => 60, 'form' => 'countersign-form',
            'assignee' => 'userA,userB,userC', 'taskType' => 0, 'performType' => '1',
            'countersignType' => 'PARALLEL'];
        if ($topLevel !== null) {
            $csProps['countersignCompletionCondition'] = $topLevel;
        }
        if ($inField !== null) {
            $csProps['field'] = ['countersignCompletionCondition' => $inField];
        }
        $flowJson = json_encode([
            'name' => $name,
            'displayName' => '字段兜底会签(177夹具)',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200,
                 'properties' => ['width' => 50, 'height' => 50], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 200, 'y' => 200,
                 'properties' => ['width' => 100, 'height' => 50, 'form' => 'apply-form',
                     'assignee' => 'applicant', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'cs1', 'type' => 'snaker:task', 'x' => 350, 'y' => 200,
                 'properties' => $csProps,
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
            'id' => 'i177',
            'name' => $name,
            'displayName' => '字段兜底会签(177夹具)',
            'type' => 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);
    }

    public function testFieldNestedConditionReleasesAtThreshold(): void
    {
        $this->registerInline('i177-field', null, '#nrOfCompletedInstances>=2');
        $instance = $this->engine->startProcessInstanceById('i177', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $instance = $this->repo->findInstanceById($instanceId);
        $doing = $instance->getDoingTasks();
        $this->assertCount(3, $doing, '会签应创建 3 个并行任务');
        $ids = array_map(fn($t) => $t->getTaskId(), $doing);

        $this->engine->executeProcessTask($ids[0], 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(), '1/3 不得放行');

        $this->engine->executeProcessTask($ids[1], 'userB',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(),
            '2/3 必须放行（条件在 field 内；改前读不到 ⇒ 3/3 全办完也照停）');
    }

    public function testTopLevelWinsOverField(): void
    {
        $this->registerInline('i177-top', '#nrOfCompletedInstances>=3', '#nrOfCompletedInstances>=2');
        $instance = $this->engine->startProcessInstanceById('i177', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $instance = $this->repo->findInstanceById($instanceId);
        $doing = $instance->getDoingTasks();
        $ids = array_map(fn($t) => $t->getTaskId(), $doing);

        $this->engine->executeProcessTask($ids[0], 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $this->engine->executeProcessTask($ids[1], 'userB',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $instance->getState(),
            '2/3 不得放行——顶层 >=3 优先于 field 的 >=2');

        $this->engine->executeProcessTask($ids[2], 'userC',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(),
            '3/3 达到顶层阈值才放行');
    }
}
