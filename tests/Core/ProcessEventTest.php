<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 引擎事件机制测试（issues/101 方案 A / 1.3.8）
 *
 * 覆盖：
 *  - start 发 INSTANCE_START + 每个落库 task 一条 TASK_START（taskId 非 null）
 *  - 流程走到 end（办结 + 驳回）恰好一条 INSTANCE_END
 *  - 带 cc 发起 → 每个 cc actor 一条 CC_CREATE（ccActorId 正确）
 *  - 【无监听器注册】跑全流程零异常、返回对象与现状一致（纯增量）
 *  - 监听器 throw → 主流程不受影响（铁律 2 引擎侧兜底）
 *
 * 去重（铁律 3）是**监听器**职责（filterReceivers），引擎按 ccArr 逐抄送人 fire
 * （对齐 createCcInstance 逐行 INSERT 粒度），故本测试用互不相同的抄送人断言 1:1。
 */
class ProcessEventTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $flowJson = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($flowJson, '01-simple.json 必须存在');
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
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    private function eventsOfType(RecordingListener $l, ProcessEventTypeEnum $type): array
    {
        return array_values(array_filter(
            $l->events,
            fn(ProcessEvent $e) => $e->getType() === $type,
        ));
    }

    private function instanceTaskIds(string $instanceId): array
    {
        $ids = [];
        foreach ($this->repo->getAllTasks() as $t) {
            if ($t->getProcessInstanceId() === $instanceId) {
                $ids[] = $t->getTaskId();
            }
        }
        return $ids;
    }

    /** 01-simple：start → apply → task1 → end；跑完整个流程到办结 */
    private function runToFinish(): void
    {
        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 完成 apply（user1 发起申请）
        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // 完成 task1（leader 审批通过 → 办结）
        $task1 = $this->findDoingTask($instanceId, 'task1');
        $agreeArgs = FlowData::create();
        $agreeArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', $agreeArgs);
    }

    private function findDoingTask(string $instanceId, string $taskName)
    {
        $instance = $this->repo->findInstanceById($instanceId);
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) {
                return $t;
            }
        }
        $this->fail("未找到进行中任务 {$taskName}");
    }

    // ── 1. start 发 INSTANCE_START + 逐 task TASK_START ──

    public function testStartFiresInstanceStartAndTaskStart(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $instance = $this->engine->startProcessInstanceById('1', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();
        $this->assertNotNull($instanceId);

        // INSTANCE_START：恰好一条，sourceId = instanceId
        $starts = $this->eventsOfType($l, ProcessEventTypeEnum::INSTANCE_START);
        $this->assertCount(1, $starts, 'start 应恰好发一条 INSTANCE_START');
        $this->assertSame($instanceId, $starts[0]->getSourceId());

        // TASK_START：每个落库 task 一条，taskId 非 null 且可反查
        $taskStarts = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_START);
        $persistedIds = $this->instanceTaskIds($instanceId);
        $this->assertCount(count($persistedIds), $taskStarts, 'TASK_START 数应等于落库 task 数');
        foreach ($taskStarts as $e) {
            $this->assertNotNull($e->getSourceId(), 'TASK_START sourceId(taskId) 非 null');
            $this->assertNotNull(
                $this->repo->findTaskById($e->getSourceId()),
                'TASK_START taskId 可被 findTaskById 反查',
            );
        }
    }

    // ── 2. 走到 end（办结 / 驳回）恰好一条 INSTANCE_END ──

    public function testFinishFiresExactlyOneInstanceEnd(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $this->runToFinish();

        $ends = $this->eventsOfType($l, ProcessEventTypeEnum::INSTANCE_END);
        $this->assertCount(1, $ends, '办结路径应恰好发一条 INSTANCE_END');
        $instance = array_values($this->repo->getAllInstances())[0];
        $this->assertSame($instance->getInstanceId(), $ends[0]->getSourceId(), 'INSTANCE_END sourceId=instanceId');
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
    }

    public function testRejectFiresExactlyOneInstanceEnd(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        // 完成 apply
        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // 驳回 task1（jumpToEnd + REJECT → 走 EndProcessHandler.reject）
        $task1 = $this->findDoingTask($instanceId, 'task1');
        $rejectArgs = FlowData::create();
        $rejectArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::REJECT);
        $this->engine->executeAndJumpToEnd($task1->getTaskId(), 'leader', $rejectArgs);

        $ends = $this->eventsOfType($l, ProcessEventTypeEnum::INSTANCE_END);
        $this->assertCount(1, $ends, '驳回路径应恰好发一条 INSTANCE_END');
        $this->assertSame($instanceId, $ends[0]->getSourceId(), 'INSTANCE_END sourceId=instanceId');
        $this->assertSame(
            ProcessInstanceState::REJECTED,
            $this->repo->findInstanceById($instanceId)->getState(),
        );
    }

    // ── 3. 带 cc 发起 → 逐抄送人 CC_CREATE ──

    public function testCcStartFiresPerActorCcCreate(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $args = FlowData::create();
        $args->set(FlowConst::CC_ACTORS_START, 'u1001,u1002'); // 互不相同的抄送人
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();

        $ccs = $this->eventsOfType($l, ProcessEventTypeEnum::CC_CREATE);
        $this->assertCount(2, $ccs, '每个 cc actor 一条 CC_CREATE');
        $actorIds = array_map(fn(ProcessEvent $e) => $e->getCcActorId(), $ccs);
        sort($actorIds);
        $this->assertSame(['u1001', 'u1002'], $actorIds, 'ccActorId 正确');
        foreach ($ccs as $e) {
            $this->assertSame($instanceId, $e->getSourceId(), 'CC_CREATE sourceId=instanceId');
        }
        // 与仓储逐行 INSERT 的 cc 实例一一对应
        $this->assertCount(2, $this->repo->getCcInstances());
    }

    // ── 4. 无监听器注册 → 纯增量（零异常、行为与现状一致）──

    public function testNoListenerIsPureIncremental(): void
    {
        // 不注册任何监听器（setUp 已 clear）
        $this->assertSame([], ProcessEventListenerRegistry::listeners());

        $this->runToFinish(); // 不应抛任何异常

        $instance = array_values($this->repo->getAllInstances())[0];
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
        // 任务状态与现状一致：apply/task1 均完成
        $tasks = $this->instanceTaskIds($instance->getInstanceId());
        $this->assertCount(2, $tasks, '01-simple 全流程应产生 apply + task1 两条任务');
    }

    // ── 5. 监听器 throw → 主流程不受影响（铁律 2 引擎侧兜底）──

    public function testListenerThrowDoesNotBreakFlow(): void
    {
        $good = new RecordingListener();
        $bad = new class implements ProcessEventListener {
            public function onEvent(ProcessEvent $event): void
            {
                throw new \RuntimeException('boom（监听器故意抛异常）');
            }
        };
        ProcessEventListenerRegistry::register($good);
        ProcessEventListenerRegistry::register($bad);

        $this->runToFinish(); // 必须不因 $bad 抛异常而中断

        $instance = array_values($this->repo->getAllInstances())[0];
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState(), '监听器异常不得打断主流程');
        // 正常监听器仍收到事件
        $this->assertNotEmpty($this->eventsOfType($good, ProcessEventTypeEnum::TASK_START));
    }
}

/** 记录所有收到的事件的假监听器（单测用） */
class RecordingListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
    }
}
