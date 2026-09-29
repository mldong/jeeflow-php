<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 门面三条事件腿（issues/127＋132，权威＝规范 11 §11.2 原则 1 / §11.3 码 4/7/8 / §11.7）
 *
 * 覆盖三条**只有门面才有路径**的事实：
 *  - 手动 `processInstance/createCCInstance` → CC_CREATE(4) 逐抄送人
 *    （PHP 此前与 java 同属"手动不 fire"那一派，§11.6 点名本轮补齐）
 *  - `processTask/transfer` → TASK_TRANSFER(7)，落库之后、且不伴随 PROCESS_TASK_START(3)
 *  - `processInstance/withdraw` → TASK_WITHDRAW(8)，**每轮撤回只 fire 一次**（不逐任务）
 *
 * 另钉抄送行的列语义（§11.7／规范 06 §ccList）：`actor_id`＝被抄送人、
 * `create_user`＝发起抄送的人，与 Java `createCcInstance(instanceId, creator, actorIds)` 同形。
 *
 * 负向：抄送人为空 / 任务非进行中 / 撤回被 134 守卫拒绝 ⇒ 事实不成立 ⇒ **零 fire**。
 */
class ProcessEventFacadeTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
    }

    /** 起一单 01-simple（startAndExecute 会自动办掉 apply），返回 [instanceId, task1Id] */
    private function startSimple(): array
    {
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：应有进行中任务 task1');
        return [$instanceId, $doing[0]->getTaskId()];
    }

    /** @return ProcessEvent[] */
    private function eventsOfType(EventRecordingListener $l, ProcessEventTypeEnum $type): array
    {
        return array_values(array_filter(
            $l->events,
            fn(ProcessEvent $e) => $e->getType() === $type,
        ));
    }

    // ── 1. 手动 createCCInstance：cc 行落库后逐抄送人 fire CC_CREATE ──

    public function testManualCreateCcInstanceFiresCcCreatePerActor(): void
    {
        [$instanceId] = $this->startSimple();
        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);
        $before = count($this->repo->getCcInstances());

        $r = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId,
            'actorIds' => ['u5001', 'u5002'],
            'operator' => 'boss',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));

        $ccs = $this->eventsOfType($l, ProcessEventTypeEnum::CC_CREATE);
        $this->assertCount(2, $ccs, '手动腿同样必须逐抄送人 fire（§11.2 原则 1「不表达谁触发的」）');
        $actorIds = array_map(fn(ProcessEvent $e) => $e->getCcActorId(), $ccs);
        sort($actorIds);
        $this->assertSame(['u5001', 'u5002'], $actorIds);
        foreach ($ccs as $e) {
            $this->assertSame((string) $instanceId, $e->getSourceId(), 'CC_CREATE sourceId=instanceId');
            $this->assertSame((string) $instanceId, $e->datum(ProcessPublisher::KEY_INSTANCE_ID),
                '码 4 直传载荷键 instanceId');
        }
        // fire 粒度＝cc 行落库粒度（§11.2 原则 3：先落库后 fire）
        $this->assertSame($before + 2, count($this->repo->getCcInstances()));
    }

    // ── 2. 抄送行列语义：actor_id＝被抄送人、create_user＝发起抄送的人（与 Java 同形）──

    public function testCcRowColumnsMatchJavaSemantics(): void
    {
        [$instanceId] = $this->startSimple();
        $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId,
            'actorIds' => ['u5003'],
            'operator' => 'boss',
        ]);

        $rows = array_values(array_filter(
            $this->repo->getCcInstances(),
            fn(array $row) => (string) $row['processInstanceId'] === (string) $instanceId
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('u5003', $rows[0]['actorId'], 'actor_id 必须是**被抄送人**（§11.7）');
        $this->assertSame('boss', $rows[0]['createUser'],
            'create_user 必须是**发起抄送的人**（Java createCcInstance(instanceId, creator, actorIds) 同形）');
    }

    // ── 3. 负向：抄送人为空 ⇒ 事实不成立 ⇒ 零 fire ──

    public function testEmptyCcActorsFireNothing(): void
    {
        [$instanceId] = $this->startSimple();
        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);

        $r = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId, 'actorIds' => [], 'operator' => 'boss',
        ]);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->eventsOfType($l, ProcessEventTypeEnum::CC_CREATE),
            '没落 cc 行就不许 fire CC_CREATE');
    }

    // ── 4. 转办：落库后 fire TASK_TRANSFER，且不伴随 PROCESS_TASK_START ──

    public function testTransferFiresTaskTransferWithSpecPayload(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);

        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'u6002',
            'operator' => 'leader', 'reason' => '出差',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));

        $fires = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_TRANSFER);
        $this->assertCount(1, $fires, '一次转办 fire 一次（§11.3 码 7）');
        $e = $fires[0];
        $this->assertSame($taskId, $e->getSourceId(), '码 7 sourceId＝taskId');
        foreach ([ProcessPublisher::KEY_INSTANCE_ID, ProcessPublisher::KEY_TASK_ID,
                  ProcessPublisher::KEY_FROM_ACTOR, ProcessPublisher::KEY_TO_ACTOR,
                  ProcessPublisher::KEY_OPERATOR] as $key) {
            $this->assertArrayHasKey($key, $e->getData(), "码 7 载荷缺必备键 {$key}");
        }
        $this->assertSame((string) $instanceId, $e->datum(ProcessPublisher::KEY_INSTANCE_ID));
        $this->assertSame('leader', $e->datum(ProcessPublisher::KEY_FROM_ACTOR));
        $this->assertSame('u6002', $e->datum(ProcessPublisher::KEY_TO_ACTOR));
        $this->assertSame('leader', $e->datum(ProcessPublisher::KEY_OPERATOR));
        // 转办不新建任务行 ⇒ 不得顺带发码 3（§11.3 码 7 注）
        $this->assertSame([], $this->eventsOfType($l, ProcessEventTypeEnum::PROCESS_TASK_START),
            '转办不新建任务行，不伴随 PROCESS_TASK_START');
    }

    // ── 5. 负向：转办被打回（任务非进行中）⇒ 零 fire ──

    public function testFailedTransferFiresNothing(): void
    {
        [, $taskId] = $this->startSimple();
        $this->repo->findTaskById($taskId)->withdraw('boss');   // 任务离开进行中
        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);

        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'u6003',
            'operator' => 'leader',
        ]);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->eventsOfType($l, ProcessEventTypeEnum::TASK_TRANSFER),
            '转办没落库就不许 fire（§11.2 原则 3）');
    }

    // ── 6. 撤回：实例写 30 落库后每轮只 fire 一次 TASK_WITHDRAW ──

    public function testWithdrawFiresTaskWithdrawOncePerRound(): void
    {
        [$instanceId] = $this->startSimple();
        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));

        $fires = $this->eventsOfType($l, ProcessEventTypeEnum::TASK_WITHDRAW);
        $this->assertCount(1, $fires, '每轮撤回只 fire 一次，不逐任务（§11.3 码 8）');
        $this->assertSame((string) $instanceId, $fires[0]->getSourceId(), '码 8 sourceId＝instanceId');
        $this->assertSame((string) $instanceId, $fires[0]->datum(ProcessPublisher::KEY_INSTANCE_ID));
        $this->assertSame('user1', $fires[0]->datum(ProcessPublisher::KEY_OPERATOR));
        // 落库之后才 fire：撤回人能在事件里读到已置 30 的实例
        $this->assertSame(ProcessInstanceState::WITHDRAW,
            $this->repo->findInstanceById($instanceId)->getState());
    }

    // ── 7. 负向：134 守卫拒掉的撤回 ⇒ 零 fire（被拒不发本支）──

    public function testRejectedWithdrawFiresNothing(): void
    {
        [$instanceId] = $this->startSimple();
        $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);

        $l = new EventRecordingListener();
        ProcessEventListenerRegistry::register($l);
        $again = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);
        $this->assertSame(99999999, $again['code'], json_encode($again, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->eventsOfType($l, ProcessEventTypeEnum::TASK_WITHDRAW),
            '被守卫拒绝的撤回一行都没落库，不得 fire（issues/134 ×  §11.3 码 8）');
    }

    // ── 8. §11.5 无监听器：门面三条腿零注册时安全返回 ──

    public function testFacadeFirePointsAreSafeWithoutListeners(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertSame([], ProcessEventListenerRegistry::listeners());

        foreach ([
            ['processInstance/createCCInstance',
             ['processInstanceId' => $instanceId, 'actorIds' => ['u7001'], 'operator' => 'boss']],
            ['processTask/transfer',
             ['processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'u7002', 'operator' => 'leader']],
            ['processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']],
        ] as [$action, $args]) {
            $r = $this->facade->flow($action, $args);
            $this->assertSame(0, $r['code'], "{$action} 应零影响地跑通：" . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState());
    }

    // ── 9. §11.5 异常隔离在门面腿同样成立：监听器抛异常不影响 action 出口 ──

    public function testListenerThrowKeepsFacadeActionSuccessful(): void
    {
        [$instanceId] = $this->startSimple();
        ProcessEventListenerRegistry::register(new class implements ProcessEventListener {
            public function onEvent(ProcessEvent $event): void
            {
                throw new \RuntimeException('boom（门面腿监听器故意抛）');
            }
        });
        $good = new EventRecordingListener();
        ProcessEventListenerRegistry::register($good);

        $r = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId, 'actorIds' => ['u8001'], 'operator' => 'boss',
        ]);
        $this->assertSame(0, $r['code'], '监听器抛异常不得回滚/打断主流程（§11.5）');
        $this->assertCount(1, $this->eventsOfType($good, ProcessEventTypeEnum::CC_CREATE),
            '抛异常之后的监听器仍要收到（逐监听器 catch）');
        $this->assertCount(1, array_filter($this->repo->getCcInstances(),
            fn(array $row) => ($row['actorId'] ?? null) === 'u8001'), 'cc 行不回滚');
    }
}

/** 记录所有收到的事件的假监听器（本套件自带，不依赖 core 套件的类加载顺序） */
class EventRecordingListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
    }
}
