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
use Jeeflow\Core\Event\ProcessPublisher;
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
        $starts = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_START);
        $this->assertCount(1, $starts, 'start 应恰好发一条 INSTANCE_START');
        $this->assertSame($instanceId, $starts[0]->getSourceId());

        // TASK_START：每个落库 task 一条，taskId 非 null 且可反查
        $taskStarts = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_TASK_START);
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

        $ends = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_END);
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

        $ends = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_END);
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
        $this->assertNotEmpty($this->eventsOfType($good, ProcessEventTypeEnum::PROCESS_TASK_START));
    }

    // ═══ issues/127＋132 事件代码腿（权威＝规范 11 §11.3/§11.5/§11.7/§11.8）═══

    /** 记录到的事件按到达顺序出规范名（§11.8 L2-30 判据形状用的就是"顺序"） */
    private function namesInOrder(RecordingListener $l): array
    {
        return array_map(fn(ProcessEvent $e) => $e->getType()->name, $l->events);
    }

    // ── 6. §11.3 码表逐字对齐：规范名＋码值 1..9，10+ 只占号不发 ──

    public function testEnumMatchesSpecCodeTable(): void
    {
        $actual = [];
        foreach (ProcessEventTypeEnum::cases() as $case) {
            $actual[$case->value] = $case->name;
        }
        // 键序＝声明序，期望表逐字抄自 spec §11.3「码｜规范名」两列
        $this->assertSame([
            1 => 'PROCESS_INSTANCE_START',
            2 => 'PROCESS_INSTANCE_END',
            3 => 'PROCESS_TASK_START',
            4 => 'CC_CREATE',
            5 => 'TASK_COMPLETE',
            6 => 'TASK_REJECT',
            7 => 'TASK_TRANSFER',
            8 => 'TASK_WITHDRAW',
            9 => 'INSTANCE_TERMINATED',
        ], $actual, '规范名与码值必须与 spec §11.3 逐字一致（规范名是权威，集成层判据用名不用码）');
        $this->assertCount(9, $actual, '10+ 本轮只占号，不得提前发码（§11.4 第 1 条）');
    }

    // ── 7. §11.8 L2-30：一条流从发起到办结【按顺序】收到 [1,3,5,2] ──

    public function testStartToFinishEventSequenceInSpecOrder(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $this->runToFinish();

        // 01-simple 全链原始顺序：发起 → apply 待办 → apply 办掉 → task1 待办 → task1 办掉 → 实例办结
        $this->assertSame([
            'PROCESS_INSTANCE_START',
            'PROCESS_TASK_START',
            'TASK_COMPLETE',
            'PROCESS_TASK_START',
            'TASK_COMPLETE',
            'PROCESS_INSTANCE_END',
        ], $this->namesInOrder($l), '码值序列必须按 §11.8 的 [1,3,5,2] 顺序，只断"出现过"不算过');
        // 去重后的规范名序列＝§11.8 那一串
        $this->assertSame([
            'PROCESS_INSTANCE_START', 'PROCESS_TASK_START', 'TASK_COMPLETE', 'PROCESS_INSTANCE_END',
        ], array_values(array_unique($this->namesInOrder($l))));
    }

    // ── 8. §11.3 直传载荷键（必备）：码 1/2/3/5 各自的键必须拿得到 ──

    public function testPayloadCarriesRequiredKeysPerCode(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $this->runToFinish();
        $instance = array_values($this->repo->getAllInstances())[0];

        $start = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_START)[0];
        $this->assertSame($instance->getInstanceId(), $start->datum(ProcessPublisher::KEY_INSTANCE_ID),
            '码 1 载荷必备 instanceId');

        $taskStarts = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_TASK_START);
        foreach ($taskStarts as $e) {
            foreach ([ProcessPublisher::KEY_INSTANCE_ID, ProcessPublisher::KEY_TASK_ID,
                      ProcessPublisher::KEY_ACTORS] as $key) {
                $this->assertArrayHasKey($key, $e->getData(), "码 3 载荷缺必备键 {$key}");
            }
            $this->assertSame($instance->getInstanceId(), $e->datum(ProcessPublisher::KEY_INSTANCE_ID));
            $this->assertSame($e->getSourceId(), $e->datum(ProcessPublisher::KEY_TASK_ID),
                '码 3 sourceId＝taskId 且载荷 taskId 同值');
            $this->assertNotEmpty($e->datum(ProcessPublisher::KEY_ACTORS), '码 3 actors＝该待办的参与者列表');
        }

        $completes = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_COMPLETE);
        $this->assertNotEmpty($completes, '任务被办掉必须发码 5（§11.3）');
        foreach ($completes as $e) {
            foreach ([ProcessPublisher::KEY_INSTANCE_ID, ProcessPublisher::KEY_TASK_ID,
                      ProcessPublisher::KEY_OPERATOR, ProcessPublisher::KEY_SUBMIT_TYPE] as $key) {
                $this->assertArrayHasKey($key, $e->getData(), "码 5 载荷缺必备键 {$key}");
            }
            $this->assertNotNull($e->datum(ProcessPublisher::KEY_OPERATOR));
        }

        $end = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_END)[0];
        $this->assertSame($instance->getInstanceId(), $end->datum(ProcessPublisher::KEY_INSTANCE_ID));
        $this->assertSame(ProcessInstanceState::FINISHED, $end->datum(ProcessPublisher::KEY_STATE),
            '码 2 载荷 state＝落库后的实例状态整数（办结 20 / 拒绝 45 靠它分）');
    }

    // ── 9. §11.7 办理腿：execute 带 tf_ccActors ⇒ 建 cc 行落库后逐抄送人 fire CC_CREATE ──

    public function testHandlingCcActorsFiresCcCreatePerActor(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $args = FlowData::create();
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $instanceId = $instance->getInstanceId();
        $before = count($this->repo->getCcInstances());

        // 办理时抄送（tf_ccActors）——与发起 f_ccActors 同判据、同一个 notifyCcCreate 收口
        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $submitArgs->set(FlowConst::CC_ACTORS, 'u2001,u2002');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        $ccs = $this->eventsOfType($l, ProcessEventTypeEnum::CC_CREATE);
        $this->assertCount(2, $ccs, '办理腿必须逐抄送人 fire CC_CREATE（八栈此前零这条腿）');
        $actorIds = array_map(fn(ProcessEvent $e) => $e->getCcActorId(), $ccs);
        sort($actorIds);
        $this->assertSame(['u2001', 'u2002'], $actorIds);
        foreach ($ccs as $e) {
            $this->assertSame($instanceId, $e->getSourceId(), 'CC_CREATE sourceId=instanceId');
            $this->assertSame($instanceId, $e->datum(ProcessPublisher::KEY_INSTANCE_ID), '码 4 载荷 instanceId');
        }
        // fire 的粒度与 cc 行落库的粒度一一对应（§11.2 原则 3：先落库后 fire）
        $this->assertSame($before + 2, count($this->repo->getCcInstances()));
    }

    // ── 10. §11.3 码 5/6 互斥：退回动作发 6 不发 5，靠载荷 submitType 分 ──

    public function testRejectFiresTaskRejectAndNotTaskComplete(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $instance = $this->engine->startProcessInstanceById('1', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        $task1 = $this->findDoingTask($instanceId, 'task1');
        $rejectArgs = FlowData::create();
        $rejectArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::REJECT);
        $this->engine->executeAndJumpToEnd($task1->getTaskId(), 'leader', $rejectArgs);

        $rejects = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_REJECT);
        $this->assertCount(1, $rejects, '退回动作恰好发一条 TASK_REJECT');
        $this->assertSame($task1->getTaskId(), $rejects[0]->getSourceId(), '码 6 sourceId＝taskId');
        $this->assertSame(SubmitType::REJECT, $rejects[0]->datum(ProcessPublisher::KEY_SUBMIT_TYPE),
            '码粗载荷细：submitType 进载荷，不各开一号（§11.2 原则 2）');
        $this->assertSame($instanceId, $rejects[0]->datum(ProcessPublisher::KEY_INSTANCE_ID));
        $this->assertSame('leader', $rejects[0]->datum(ProcessPublisher::KEY_OPERATOR));

        // 互斥：被退回那条任务严禁再发 TASK_COMPLETE（§11.3 码 6「与 5 互斥」）
        $completeIds = array_map(
            fn(ProcessEvent $e) => $e->datum(ProcessPublisher::KEY_TASK_ID),
            $this->eventsOfType($l, ProcessEventTypeEnum::TASK_COMPLETE)
        );
        $this->assertNotContains($task1->getTaskId(), $completeIds, '走 reject 就不再 fire complete');
        $this->assertContains($applyTask->getTaskId(), $completeIds, 'apply 那步照常发码 5');

        // 实例进终态另发码 2，与码 6 互不替代；state＝45
        $ends = $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_INSTANCE_END);
        $this->assertCount(1, $ends);
        $this->assertSame(ProcessInstanceState::REJECTED, $ends[0]->datum(ProcessPublisher::KEY_STATE));
    }

    /** 退回上一步（submitType=3）同样归码 6，不新增号 */
    public function testRollbackFiresTaskRejectWithSubmitTypeInPayload(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $instance = $this->engine->startProcessInstanceById('1', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        $task1 = $this->findDoingTask($instanceId, 'task1');
        $rollbackArgs = FlowData::create();
        $rollbackArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::ROLLBACK);
        $this->engine->executeAndJumpTask($task1->getTaskId(), 'leader', $rollbackArgs, null);

        $rejects = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_REJECT);
        $this->assertCount(1, $rejects, '退回上一步共用 TASK_REJECT 一号');
        $this->assertSame(SubmitType::ROLLBACK, $rejects[0]->datum(ProcessPublisher::KEY_SUBMIT_TYPE));
    }

    // ── 11. §11.5 异常隔离：单监听器抛异常不回滚、不中断**其后**的监听器 ──

    public function testThrowingListenerDoesNotBreakLaterListeners(): void
    {
        $first = new RecordingListener();
        $bomb = new class implements ProcessEventListener {
            public function onEvent(ProcessEvent $event): void
            {
                throw new \RuntimeException('boom（中间监听器故意抛）');
            }
        };
        $last = new RecordingListener();
        // 注册顺序＝回调顺序（§11.5 注册行）：坏的那个夹在中间
        ProcessEventListenerRegistry::register($first);
        ProcessEventListenerRegistry::register($bomb);
        ProcessEventListenerRegistry::register($last);

        $this->runToFinish();

        $this->assertSame($this->namesInOrder($first), $this->namesInOrder($last),
            '单监听器抛异常必须不中断后续监听器（§11.5 异常隔离）');
        $this->assertNotEmpty($this->namesInOrder($last));
        // 不回滚主流程：实例仍办结、任务行仍落库
        $instance = array_values($this->repo->getAllInstances())[0];
        $this->assertSame(ProcessInstanceState::FINISHED, $instance->getState());
        $this->assertCount(2, $this->instanceTaskIds($instance->getInstanceId()));
    }

    // ── 12. §11.5 无监听器：新增各支 fire 点零注册时安全返回（纯增量） ──

    public function testNewFirePointsAreSafeWithoutListeners(): void
    {
        $this->assertSame([], ProcessEventListenerRegistry::listeners());

        $args = FlowData::create();
        $args->set(FlowConst::CC_ACTORS_START, 'u3001');
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $applyTask = $this->findDoingTask($instance->getInstanceId(), 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $submitArgs->set(FlowConst::CC_ACTORS, 'u3002');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);
        $task1 = $this->findDoingTask($instance->getInstanceId(), 'task1');
        $agreeArgs = FlowData::create();
        $agreeArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', $agreeArgs);

        $this->assertSame(ProcessInstanceState::FINISHED,
            $this->repo->findInstanceById($instance->getInstanceId())->getState(),
            '零注册时新增 fire 点必须零影响（不得空指针/异常）');
        $this->assertCount(2, $this->repo->getCcInstances());
    }

    // ── 13. 同一事实只发一次：tf_ccActors 不被后续办理重放（§11.2 原则 1 / §11.1 防重复抄送）──

    public function testHandlingCcIsNotReplayedByLaterTasks(): void
    {
        $l = new RecordingListener();
        ProcessEventListenerRegistry::register($l);

        $instance = $this->engine->startProcessInstanceById('1', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        // 第一步办理带抄送
        $applyTask = $this->findDoingTask($instanceId, 'apply');
        $submitArgs = FlowData::create();
        $submitArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
        $submitArgs->set(FlowConst::CC_ACTORS, 'u4001');
        $this->engine->executeProcessTask($applyTask->getTaskId(), 'user1', $submitArgs);

        // 第二步办理**不带**抄送——tf_ccActors 已随第一步进了实例变量，重放即本案病灶
        $task1 = $this->findDoingTask($instanceId, 'task1');
        $agreeArgs = FlowData::create();
        $agreeArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', $agreeArgs);

        $this->assertCount(1, $this->repo->getCcInstances(), 'cc 行只在带 tf_ccActors 的那次办理落一条');
        $this->assertCount(1, $this->eventsOfType($l, ProcessEventTypeEnum::CC_CREATE),
            'CC_CREATE 不得被后续办理重放（§11.2 原则 1「同一事实只发一次」）');
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
