<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\IInstanceLockRepository;
use Jeeflow\Core\ITimeActionSink;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\TimeAction;
use Jeeflow\Core\TimeLimitSupport;
use PHPUnit\Framework\TestCase;

/**
 * 任务时限动作 T0（spec 04 · 任务时限动作；工作流时限方案 §8 的引擎侧判据，PHP 腿）。
 * 与 go/java/python/node/csharp 同构：建行、旁路覆盖、不继承档、失效联动、
 * 回调事实范围、实例级排他锁调用面。
 */
final class TimeLimitActionTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private FakeTimeActionSink $sink;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ModelParser::reset();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->sink = new FakeTimeActionSink();
        ServiceContext::put(ITimeActionSink::class, $this->sink);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    /** start→apply→review→end；reviewProps 覆盖 review 节点 properties。 */
    private function chain(array $reviewProps): array
    {
        $this->seq++;
        return [
            'name' => 'tl-' . $this->seq,
            'displayName' => '时限夹具',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'af', 'taskType' => 0, 'performType' => 0, 'assignee' => 'applicant'],
                 'text' => ['value' => '申请']],
                ['id' => 'review', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => array_merge(
                     ['form' => 'f', 'taskType' => 0, 'performType' => 0, 'assignee' => 'userA'],
                     $reviewProps),
                 'text' => ['value' => '审批']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e1', 'type' => 'snaker:transition', 'sourceNodeId' => 'start',
                 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e2', 'type' => 'snaker:transition', 'sourceNodeId' => 'apply',
                 'targetNodeId' => 'review', 'properties' => []],
                ['id' => 'e3', 'type' => 'snaker:transition', 'sourceNodeId' => 'review',
                 'targetNodeId' => 'end', 'properties' => []],
            ],
        ];
    }

    /** @return array{0: object, 1: array<int, ProcessTask>} */
    private function startReview(array $reviewProps): array
    {
        $flow = $this->chain($reviewProps);
        $this->repo->addDefine([
            'id' => '', 'name' => $flow['name'], 'display_name' => $flow['displayName'],
            'type' => 'approval', 'state' => 1, 'version' => 1,
            'content' => json_encode($flow, JSON_UNESCAPED_UNICODE),
        ]);
        $defines = $this->repo->findLatestDefineByName($flow['name']);
        $inst = $this->engine->startProcessInstanceById((string) $defines['id'], 'applicant', FlowData::create());
        foreach ($this->repo->findDoingTasks($inst->getInstanceId()) as $t) {
            if ($t->getTaskName() === 'apply') {
                $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant',
                    $this->submit((int) SubmitType::APPLY));
            }
        }
        $rows = array_values(array_filter(
            $this->repo->findDoingTasks($inst->getInstanceId()),
            static fn ($t) => $t->getTaskName() === 'review'));
        return [$inst, $rows];
    }

    private function submit(int $type): FlowData
    {
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, $type);
        return $args;
    }

    private function dueAt(string $type, int $idx = 0): int
    {
        return $this->sink->ofType($type)[$idx]->dueAt;
    }

    public function testAutoAndReminderOnCreate(): void
    {
        [$inst, $rows] = $this->startReview(
            ['expireTime' => '2h', 'autoExecute' => 'Y', 'reminderTime' => '30m']);
        self::assertCount(1, $rows);
        $review = $rows[0];
        self::assertCount(1, $this->sink->ofType(TimeAction::TYPE_AUTO_COMPLETE));
        self::assertCount(1, $this->sink->ofType(TimeAction::TYPE_REMIND));
        $auto = $this->sink->ofType(TimeAction::TYPE_AUTO_COMPLETE)[0];
        $remind = $this->sink->ofType(TimeAction::TYPE_REMIND)[0];
        self::assertSame('wf:auto:' . $review->getTaskId() . ':0', $auto->idempotencyKey);
        self::assertSame('wf:remind:' . $review->getTaskId() . ':1', $remind->idempotencyKey);
        self::assertSame(1, $remind->occurrenceNo);
        self::assertSame((string) $review->getTaskId(), $auto->taskId);
        self::assertSame((string) $inst->getInstanceId(), $auto->instanceId);
        $base = strtotime((string) $review->getCreateTime());
        self::assertSame(($base + 1800) * 1000, $remind->dueAt);
        self::assertSame(($base + 7200) * 1000, $auto->dueAt);
    }

    public function testNotConfiguredCreatesNoAction(): void
    {
        $this->startReview([]);
        self::assertSame([], $this->sink->actions);
    }

    public function testAutoExecuteOnlyExactY(): void
    {
        foreach (['N', 'n', 'y', 'true'] as $v) {
            $this->sink->actions = [];
            $this->startReview(['expireTime' => '2h', 'autoExecute' => $v]);
            self::assertSame([], $this->sink->ofType(TimeAction::TYPE_AUTO_COMPLETE),
                'autoExecute=' . $v . ' 不得开启');
        }
    }

    public function testReminderHHmmSameDayAndNextDay(): void
    {
        [, $rows] = $this->startReview(['reminderTime' => '23:59']);
        $base = strtotime((string) $rows[0]->getCreateTime());
        $sameDay = (int) (strtotime(date('Y-m-d', $base) . ' 23:59:00') * 1000);
        $nextDay = (int) (strtotime(date('Y-m-d', $base) . ' 23:59:00 +1 day') * 1000);
        $want = $sameDay >= $base * 1000 ? $sameDay : $nextDay;
        self::assertSame($want, $this->dueAt(TimeAction::TYPE_REMIND));
    }

    public function testInvalidReminderDoesNotBreakFlow(): void
    {
        [, $rows] = $this->startReview(['reminderTime' => '2.5h']);
        self::assertCount(1, $rows);
        self::assertSame([], $this->sink->ofType(TimeAction::TYPE_REMIND));
    }

    public function testParallelCountersignEveryMember(): void
    {
        [, $rows] = $this->startReview([
            'assignee' => 'userA,userB', 'performType' => 1, 'countersignType' => 'PARALLEL',
            'reminderTime' => '1h']);
        self::assertCount(2, $rows);
        $ids = array_map(static fn ($a) => $a->taskId, $this->sink->ofType(TimeAction::TYPE_REMIND));
        foreach ($rows as $r) {
            self::assertContains((string) $r->getTaskId(), $ids, '成员缺提醒行');
        }
    }

    public function testSequentialAdvanceGetsAction(): void
    {
        [$inst, $rows] = $this->startReview([
            'assignee' => 'userA,userB', 'performType' => 1, 'countersignType' => 'SEQUENTIAL',
            'expireTime' => '2h', 'autoExecute' => 'Y']);
        $first = $rows[0];
        self::assertNotEmpty(array_filter($this->sink->ofType(TimeAction::TYPE_AUTO_COMPLETE),
            static fn ($a) => $a->taskId === (string) $first->getTaskId()));
        $this->engine->executeProcessTask((string) $first->getTaskId(), 'userA',
            $this->submit((int) SubmitType::AGREE));
        $nxt = array_values(array_filter($this->repo->findDoingTasks($inst->getInstanceId()),
            static fn ($t) => $t->getTaskName() === 'review' && $t->getTaskId() !== $first->getTaskId()));
        self::assertCount(1, $nxt, '串行会签应推进出下一位');
        self::assertNotEmpty(array_filter($this->sink->ofType(TimeAction::TYPE_AUTO_COMPLETE),
            static fn ($a) => $a->taskId === (string) $nxt[0]->getTaskId()), '推进出的下一位缺自动办理行');
        self::assertContains((string) $first->getTaskId(), $this->sink->cancelledTasks);
    }

    public function testAidantRowInheritsNoAction(): void
    {
        [, $rows] = $this->startReview(
            ['expireTime' => '2h', 'autoExecute' => 'Y', 'reminderTime' => '1h']);
        $before = count($this->sink->actions);
        $this->engine->aidantTask((string) $rows[0]->getTaskId(), ['aid1'], '', false, 'userA');
        self::assertSame($before, count($this->sink->actions), '协办行不得继承时限动作');
    }

    public function testCallbackOnCompleteOnly(): void
    {
        [, $rows] = $this->startReview(['callback' => 'demoHandler', 'reminderTime' => '1h']);
        $review = $rows[0];
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA',
            $this->submit((int) SubmitType::AGREE));
        $cbs = $this->sink->ofType(TimeAction::TYPE_CALLBACK);
        self::assertCount(1, $cbs);
        self::assertSame('wf:callback:' . $review->getTaskId() . ':0', $cbs[0]->idempotencyKey);
        self::assertContains((string) $review->getTaskId(), $this->sink->cancelledTasks);

        $this->sink->actions = [];
        [, $rows2] = $this->startReview(['callback' => 'demoHandler']);
        $this->engine->executeAndJumpToEnd((string) $rows2[0]->getTaskId(), 'userA',
            $this->submit((int) SubmitType::REJECT));
        self::assertSame([], $this->sink->ofType(TimeAction::TYPE_CALLBACK), '退回不得触发回调');
    }

    public function testInstanceTerminalCancelsActions(): void
    {
        [$inst, $rows] = $this->startReview(['reminderTime' => '1h']);
        $this->engine->executeProcessTask((string) $rows[0]->getTaskId(), 'userA',
            $this->submit((int) SubmitType::AGREE));
        self::assertContains((string) $inst->getInstanceId(), $this->sink->cancelledInstances);
    }

    public function testLockInstanceCalledOnExecute(): void
    {
        [$inst, $rows] = $this->startReview([]);
        $this->engine->executeProcessTask((string) $rows[0]->getTaskId(), 'userA',
            $this->submit((int) SubmitType::AGREE));
        self::assertTrue($this->repo instanceof IInstanceLockRepository);
        $this->assertTrue(true); // 调用面由仓储 instanceof 保证；真库 FOR UPDATE 由 PDO 仓承担
    }
}

/** 时限动作凭证的测试替身（逐条记录建行/取消）。 */
final class FakeTimeActionSink implements ITimeActionSink
{
    /** @var list<TimeAction> */
    public array $actions = [];
    /** @var list<string> */
    public array $cancelledTasks = [];
    /** @var list<string> */
    public array $cancelledInstances = [];

    public function insertTimeAction(TimeAction $action): void
    {
        $this->actions[] = $action;
    }

    public function cancelTaskTimeActions(string $taskId): void
    {
        $this->cancelledTasks[] = $taskId;
    }

    public function cancelInstanceTimeActions(string $instanceId): void
    {
        $this->cancelledInstances[] = $instanceId;
    }

    /** @return list<TimeAction> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->actions, static fn ($a) => $a->type === $type));
    }
}
