<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

class JeeflowFacadeStatsTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($engine, $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── helpers ──

    private function addDefine(string $id = '100', string $name = 'leave', string $displayName = '请假', string $type = 'oa'): void
    {
        $this->repo->addDefine([
            'id' => $id, 'name' => $name, 'displayName' => $displayName,
            'display_name' => $displayName, 'type' => $type, 'state' => 1, 'version' => 1,
        ]);
    }

    private function makeInstance(string $id, string $defineId, int $state, string $operator, string $createTime): ProcessInstance
    {
        $inst = new ProcessInstance();
        $inst->setInstanceId($id);
        $inst->setDefineId($defineId);
        $inst->setState($state);
        $inst->setOperator($operator);
        $inst->setCreateTime($createTime);
        return $inst;
    }

    private function makeTask(string $id, string $instanceId, int $taskState, string $displayName,
                              ?string $actorId, array $actorIds, ?string $finishTime,
                              ?string $expireTime, ?int $performType, ?string $createTime = null): ProcessTask
    {
        $task = new ProcessTask();
        $task->setTaskId($id);
        $task->setProcessInstanceId($instanceId);
        $task->setTaskState($taskState);
        $task->setDisplayName($displayName);
        $task->setActorId($actorId);
        $task->setActorIds($actorIds);
        $task->setFinishTime($finishTime);
        $task->setExpireTime($expireTime);
        $task->setPerformType($performType);
        $task->setCreateTime($createTime ?? '2026-09-01 10:00:00');
        return $task;
    }

    private function saveInstanceWithTasks(ProcessInstance $inst, array $tasks): void
    {
        $inst->setTasks($tasks);
        $this->repo->saveInstance($inst);
        foreach ($tasks as $task) {
            $this->repo->saveTask($task);
        }
    }

    // ═══ 边界：空库 ═══

    public function testOverviewEmptyRepo(): void
    {
        $r = $this->facade->flow('processInstance/stats/overview');
        $this->assertEquals(0, $r['code']);
        $d = $r['data'];
        $this->assertSame(0, $d['total']);
        $this->assertSame(0, $d['inProgress']);
        $this->assertSame(0, $d['completed']);
        $this->assertSame(0, $d['rejected']);
        $this->assertSame(0, $d['withdrawn']);
        $this->assertSame(0, $d['suspended']);
        $this->assertSame(0, $d['todayNew']);
        $this->assertSame(0, $d['avgDurationSeconds']);
        $this->assertSame(0.0, $d['rejectRate']);
        $this->assertSame(0, $d['pendingTaskCount']);
        $this->assertSame(0, $d['overdueTaskCount']);
        $this->assertSame(0.0, $d['countersignRate']);
        $this->assertSame(0.0, $d['onTimeRate']);
    }

    public function testTrendEmptyRepo(): void
    {
        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'day', 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-03 00:00:00',
        ]);
        $this->assertEquals(0, $r['code']);
        // A：data 本体为裸数组（无 {granularity, series} 包装）；含 end 桶 → 09-01..09-03 共 3 桶
        $this->assertIsArray($r['data']);
        $this->assertArrayNotHasKey('granularity', $r['data']);
        $this->assertCount(3, $r['data']);
        foreach ($r['data'] as $bucket) {
            $this->assertSame(0, $bucket['started']);
            $this->assertSame(0, $bucket['finished']);
        }
    }

    public function testGroupEmptyRepo(): void
    {
        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'state']);
        $this->assertEquals(0, $r['code']);
        // A：data 本体为裸数组（无 {dimension, rows} 包装）
        $this->assertEmpty($r['data']);
    }

    // ═══ 正向：overview 13 字段 ═══

    public function testOverviewMixedData(): void
    {
        $this->addDefine();
        $today = date('Y-m-d') . ' 10:00:00';

        // inst1: FINISHED, 2 tasks (dur = 3600s)
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 11:00:00', '2026-09-02 00:00:00', PerformType::NORMAL, '2026-09-01 10:00:00');
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1]);

        // inst2: DOING, 1 pending task (overdue)
        $t2 = $this->makeTask('t2', 'i2', ProcessTaskState::DOING, '审批', 'u2', ['u2'],
            null, '2020-01-01 00:00:00', PerformType::NORMAL);
        $i2 = $this->makeInstance('i2', '100', ProcessInstanceState::DOING, 'bob', '2026-09-01 12:00:00');
        $this->saveInstanceWithTasks($i2, [$t2]);

        // inst3: REJECTED
        $i3 = $this->makeInstance('i3', '100', ProcessInstanceState::REJECTED, 'carol', '2026-09-01 14:00:00');
        $this->saveInstanceWithTasks($i3, []);

        // inst4: today new, WITHDRAW
        $i4 = $this->makeInstance('i4', '100', ProcessInstanceState::WITHDRAW, 'dave', $today);
        $this->saveInstanceWithTasks($i4, []);

        $r = $this->facade->flow('processInstance/stats/overview');
        $this->assertEquals(0, $r['code']);
        $d = $r['data'];

        $this->assertSame(4, $d['total']);
        $this->assertSame(1, $d['inProgress']);
        $this->assertSame(1, $d['completed']);
        $this->assertSame(1, $d['rejected']);
        $this->assertSame(1, $d['withdrawn']);
        $this->assertSame(0, $d['suspended']);
        $this->assertSame(1, $d['todayNew']);
        $this->assertSame(3600, $d['avgDurationSeconds']);
        // rejectRate = 1 / max(1, 1+1) = 0.5
        $this->assertSame(0.5, $d['rejectRate']);
        $this->assertSame(1, $d['pendingTaskCount']);
        $this->assertSame(1, $d['overdueTaskCount']);
        // countersignRate: 0 countersign / 1 total finished = 0.0
        $this->assertSame(0.0, $d['countersignRate']);
        // onTimeRate: t1 finish 2026-09-01 11:00 <= expire 2026-09-02 => 1/1 = 1.0
        $this->assertSame(1.0, $d['onTimeRate']);
    }

    public function testOverviewExpireAllNull(): void
    {
        $this->addDefine();
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::DOING, '审批', 'u1', ['u1'],
            null, null, PerformType::NORMAL);
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1]);

        $r = $this->facade->flow('processInstance/stats/overview');
        $this->assertEquals(0, $r['code']);
        $this->assertSame(0, $r['data']['overdueTaskCount']);
        $this->assertSame(0.0, $r['data']['onTimeRate']);
    }

    // ═══ 正向：countersignRate 分母不膨胀 ═══

    public function testCountersignRateDenominator(): void
    {
        $this->addDefine();
        // 1 countersign finished task (perform_type=1) + 1 normal finished task
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '会签', 'u1', ['u1', 'u2'],
            '2026-09-01 11:00:00', null, PerformType::COUNTERSIGN, '2026-09-01 10:00:00');
        $t2 = $this->makeTask('t2', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 12:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1, $t2]);

        $r = $this->facade->flow('processInstance/stats/overview');
        $this->assertEquals(0, $r['code']);
        // 1 countersign / 2 total finished = 0.5
        $this->assertSame(0.5, $r['data']['countersignRate']);
    }

    // ═══ 负向 ═══

    public function testTrendInvalidGranularity(): void
    {
        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'abc', 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-03 00:00:00',
        ]);
        $this->assertNotEquals(0, $r['code']);
    }

    public function testGroupInvalidDimension(): void
    {
        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'bogus']);
        $this->assertNotEquals(0, $r['code']);
    }

    // ═══ 正向：trend 4 粒度 + 连续桶 ═══

    public function testTrendDayGranularity(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-02 15:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1]);

        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'day', 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-04 00:00:00',
        ]);
        $this->assertEquals(0, $r['code']);
        $series = $r['data'];
        // 含 end 桶 → 09-01..09-04 共 4 桶
        $this->assertCount(4, $series);
        $this->assertSame('2026-09-01', $series[0]['bucket']);
        $this->assertSame('2026-09-02', $series[1]['bucket']);
        $this->assertSame('2026-09-03', $series[2]['bucket']);
        $this->assertSame(1, $series[0]['started']);
        $this->assertSame(0, $series[0]['finished']);
        $this->assertSame(0, $series[1]['started']);
        $this->assertSame(1, $series[1]['finished']);
    }

    public function testTrendHourGranularity(): void
    {
        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'hour', 'start' => '2026-09-01 10:00:00', 'end' => '2026-09-01 13:00:00',
        ]);
        $this->assertEquals(0, $r['code']);
        // 含 end 桶 → 10:00..13:00 共 4 桶
        $this->assertCount(4, $r['data']);
        $this->assertSame('2026-09-01 10:00', $r['data'][0]['bucket']);
    }

    public function testTrendMonthGranularity(): void
    {
        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'month', 'start' => '2026-07-01 00:00:00', 'end' => '2026-10-01 00:00:00',
        ]);
        $this->assertEquals(0, $r['code']);
        $series = $r['data'];
        // 含 end 桶 → 2026-07..2026-10 共 4 桶
        $this->assertCount(4, $series);
        $this->assertSame('2026-07', $series[0]['bucket']);
        $this->assertSame('2026-09', $series[2]['bucket']);
    }

    public function testTrendWeekGranularity(): void
    {
        $r = $this->facade->flow('processInstance/stats/trend', [
            'granularity' => 'week', 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-22 00:00:00',
        ]);
        $this->assertEquals(0, $r['code']);
        $series = $r['data'];
        $this->assertGreaterThanOrEqual(3, count($series));
        // ISO week format: yyyy-Www
        $this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $series[0]['bucket']);
    }

    public function testTrendNoStartEnd(): void
    {
        // C 自证：缺 start/end → code!=0，不再静默返回空 series
        $r = $this->facade->flow('processInstance/stats/trend', ['granularity' => 'day']);
        $this->assertNotEquals(0, $r['code']);
        $r2 = $this->facade->flow('processInstance/stats/trend',
            ['granularity' => 'day', 'start' => '2026-09-01 00:00:00']);
        $this->assertNotEquals(0, $r2['code']);
    }

    // ═══ 正向：group 9 维度 ═══

    public function testGroupState(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $i2 = $this->makeInstance('i2', '100', ProcessInstanceState::DOING, 'bob', '2026-09-01 11:00:00');
        $i3 = $this->makeInstance('i3', '100', ProcessInstanceState::FINISHED, 'carol', '2026-09-01 12:00:00');
        $this->saveInstanceWithTasks($i1, []);
        $this->saveInstanceWithTasks($i2, []);
        $this->saveInstanceWithTasks($i3, []);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'state']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertNotEmpty($rows);
        // FINISHED(20) count=2 should be first
        $this->assertSame('20', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function testGroupDefine(): void
    {
        $this->addDefine('100', 'leave', '请假', 'oa');
        $this->addDefine('200', 'expense', '报销', 'oa');

        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 11:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1]);

        $i2 = $this->makeInstance('i2', '200', ProcessInstanceState::FINISHED, 'bob', '2026-09-01 10:00:00');
        $t2 = $this->makeTask('t2', 'i2', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 14:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i2, [$t2]);

        // add a 3rd instance for define 100 to test count desc
        $i3 = $this->makeInstance('i3', '100', ProcessInstanceState::DOING, 'carol', '2026-09-01 12:00:00');
        $this->saveInstanceWithTasks($i3, []);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'define']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertSame('leave', $rows[0]['key']);
        $this->assertSame('请假', $rows[0]['label']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertNotNull($rows[0]['avgDurationSeconds']);
        $this->assertSame('expense', $rows[1]['key']);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function testGroupCategory(): void
    {
        $this->addDefine('100', 'leave', '请假', 'oa');
        $this->addDefine('200', 'purchase', '采购', 'biz');

        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 10:00:00');
        $i2 = $this->makeInstance('i2', '200', ProcessInstanceState::DOING, 'bob', '2026-09-01 11:00:00');
        $i3 = $this->makeInstance('i3', '100', ProcessInstanceState::DOING, 'carol', '2026-09-01 12:00:00');
        $this->saveInstanceWithTasks($i1, []);
        $this->saveInstanceWithTasks($i2, []);
        $this->saveInstanceWithTasks($i3, []);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'category']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertSame('oa', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame('biz', $rows[1]['key']);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function testGroupApprover(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 11:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $t2 = $this->makeTask('t2', 'i1', ProcessTaskState::FINISHED, '审批', 'u2', ['u2'],
            '2026-09-01 12:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1, $t2]);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'approver']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function testGroupApplicant(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 10:00:00');
        $i2 = $this->makeInstance('i2', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 11:00:00');
        $i3 = $this->makeInstance('i3', '100', ProcessInstanceState::DOING, 'bob', '2026-09-01 12:00:00');
        $this->saveInstanceWithTasks($i1, []);
        $this->saveInstanceWithTasks($i2, []);
        $this->saveInstanceWithTasks($i3, []);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'applicant']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertSame('alice', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame('bob', $rows[1]['key']);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function testGroupNode(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'alice', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '经理审批', 'u1', ['u1'],
            '2026-09-01 11:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $t2 = $this->makeTask('t2', 'i1', ProcessTaskState::FINISHED, '经理审批', 'u2', ['u2'],
            '2026-09-01 12:00:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1, $t2]);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'node']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertSame('经理审批', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['count']);
        // avg = (3600 + 7200) / 2 = 5400
        $this->assertSame(5400, $rows[0]['avgDurationSeconds']);
    }

    public function testGroupStuckNode(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::DOING, '总监审批', 'u1', ['u1'],
            null, null, PerformType::NORMAL);
        $t2 = $this->makeTask('t2', 'i1', ProcessTaskState::DOING, '总监审批', 'u2', ['u2'],
            null, null, PerformType::NORMAL);
        $this->saveInstanceWithTasks($i1, [$t1, $t2]);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'stuckNode']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertSame('总监审批', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function testGroupStuckApprover(): void
    {
        $this->addDefine();
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::DOING, 'alice', '2026-09-01 10:00:00');
        // 1 DOING task with 2 actors → each actor gets count=1
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::DOING, '审批', null, ['u1', 'u2'],
            null, null, PerformType::NORMAL);
        $this->saveInstanceWithTasks($i1, [$t1]);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'stuckApprover']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame(1, $rows[1]['count']);
    }

    public function testGroupDurationBucket(): void
    {
        $this->addDefine();
        // sameDay: 1800s < 86400
        $i1 = $this->makeInstance('i1', '100', ProcessInstanceState::FINISHED, 'a', '2026-09-01 10:00:00');
        $t1 = $this->makeTask('t1', 'i1', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-01 10:30:00', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i1, [$t1]);

        // 1to3d: 100000s (between 86400 and 259200)
        $i2 = $this->makeInstance('i2', '100', ProcessInstanceState::FINISHED, 'b', '2026-09-01 10:00:00');
        $t2 = $this->makeTask('t2', 'i2', ProcessTaskState::FINISHED, '审批', 'u1', ['u1'],
            '2026-09-02 13:46:40', null, PerformType::NORMAL, '2026-09-01 10:00:00');
        $this->saveInstanceWithTasks($i2, [$t2]);

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'durationBucket']);
        $this->assertEquals(0, $r['code']);
        $rows = $r['data'];
        // fixed 4-bucket order
        $this->assertCount(4, $rows);
        $this->assertSame('sameDay', $rows[0]['key']);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame('1to3d', $rows[1]['key']);
        $this->assertSame(1, $rows[1]['count']);
        $this->assertSame('3to7d', $rows[2]['key']);
        $this->assertSame(0, $rows[2]['count']);
        $this->assertSame('over7d', $rows[3]['key']);
        $this->assertSame(0, $rows[3]['count']);
    }

    // ═══ group limit ═══

    public function testGroupLimit(): void
    {
        $this->addDefine();
        for ($i = 1; $i <= 5; $i++) {
            $inst = $this->makeInstance("i{$i}", '100', ProcessInstanceState::DOING, "user{$i}", '2026-09-01 10:00:00');
            $this->saveInstanceWithTasks($inst, []);
        }

        $r = $this->facade->flow('processInstance/stats/group', ['dimension' => 'applicant', 'limit' => 3]);
        $this->assertEquals(0, $r['code']);
        $this->assertCount(3, $r['data']);
    }

    // ═══ 回归：未知 action ═══

    public function testUnknownActionStillWorks(): void
    {
        $r = $this->facade->flow('unknown/action');
        $this->assertNotEquals(0, $r['code']);
    }
}
