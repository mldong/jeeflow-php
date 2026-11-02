<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/121 P1 建单不变量 —— 每次建任务必写 task_parent_id 与行级 isFirstTaskNode。
 *
 * 夹具是 02-multi-task.json：apply → task1 → task2 → task3 四级链。两步流里「上一节点」与
 * 「首任务节点」同格，断言恒真、抓不到缺陷，所以必须用 ≥3 个任务节点的流程。
 */
class LineageColumnsTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->repo->addDefine([
            'id' => '121', 'name' => 'multi-task', 'displayName' => '多级审批流程',
            'type' => 'approval', 'state' => 1, 'version' => 1,
            'content' => (string) file_get_contents(jeeflow_flows_dir() . '/02-multi-task.json'),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    public function testCreateWritesLineageColumns(): void
    {
        $instance = $this->engine->startProcessInstanceById('121', 'user1', FlowData::create());
        $iid = $instance->getInstanceId();

        $apply = $this->doing($iid, 'apply');
        // 发起 execution 没有当前任务 ⇒ parent 落 '0'；apply 是 start 直接后继 ⇒ true
        $this->assertSame('0', (string) $apply->getParentTaskId(), '发起那条 parent 应为 0');
        $this->assertTrue($this->flag($apply), '首任务节点行应落 isFirstTaskNode=true');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', $this->agree());

        $prev = $apply;
        foreach ([['task1', 'leader'], ['task2', 'manager'], ['task3', 'boss']] as [$name, $who]) {
            $task = $this->doing($iid, $name);
            $this->assertSame((string) $prev->getTaskId(), (string) $task->getParentTaskId(),
                "{$name}.parent 应为刚办结的 {$prev->getTaskName()}.id");
            $this->assertFalse($this->flag($task), "非首节点必须 false（{$name}）");
            $this->engine->executeProcessTask($task->getTaskId(), $who, $this->agree());
            $prev = $task;
        }

        // 本案真正要的那格：血缘版回退读的是已办结的历史行，标记必须随行存活
        $his = $this->repo->findTaskById($apply->getTaskId());
        $this->assertNotNull($his);
        $this->assertNotSame(ProcessTaskState::DOING, $his->getTaskState(), 'apply 应已办结');
        $this->assertTrue($this->flag($his), '历史行标记必须还在（现算版在历史行上恒 false）');
        $this->assertSame('0', (string) $his->getParentTaskId(), '历史行血缘指针不应被覆写');
    }

    /**
     * issues/121 P2 血缘版回退：正向复活 parent 行（落点/参与者/随行拷贝/残留剔除），
     * 三格负向（20010007 无血缘／20010008 血缘前驱跨不过 fork／20010008 血缘前驱指向并行旁支）。
     *
     * issues/243 甲案：两档守卫（{@see \Jeeflow\Core\Domain\ProcessInstance::checkRejectable()}）
     * 由引擎排在任何状态变更之前 ⇒ 三格负向同时钉**报错即零写入**（当前行仍 DOING、
     * 待办还在原办理人手里、实例状态不变、任务行总数不变、没有复活行）。
     */
    public function testRollbackRevivesParentRowAndNegatives(): void
    {
        // ── 正向 ──
        $instance = $this->engine->startProcessInstanceById('121', 'user1', FlowData::create());
        $iid = (string) $instance->getInstanceId();
        $apply = $this->doing($iid, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', $this->agree());
        $t1 = $this->doing($iid, 'task1');
        $this->engine->executeProcessTask($t1->getTaskId(), 'leader', $this->agree());
        $t2 = $this->doing($iid, 'task2');

        $this->engine->executeAndJumpTask($t2->getTaskId(), 'manager',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK]), null);

        $revived = $this->doing($iid, 'task1');
        $this->assertNotSame($t1->getTaskId(), $revived->getTaskId(), '复活应是新行，不是把原行改回进行中');
        $this->assertSame(['leader'], $this->repo->findTaskById($revived->getTaskId())->getActorIds(),
            '参与者＝该行原办结人，不是执行回退的 manager');
        $this->assertSame($t1->getParentTaskId(), $revived->getParentTaskId(),
            'parent 随行拷贝＝上一步的上一步');
        $hisVars = $revived->getVariables()->toArray();
        $this->assertArrayNotHasKey(FlowConst::SUBMIT_TYPE, $hisVars, '复活行不该带 submitType 残留');
        $this->assertArrayNotHasKey('taskName', $hisVars, '复活行不该带 taskName 残留');
        foreach (array_keys($hisVars) as $k) {
            $this->assertStringStartsNotWith('tf_', (string) $k, '复活行不该带 tf_ 残留');
            $this->assertStringStartsNotWith('loopCounter', (string) $k, '复活行不该带会签簿记残留');
        }
        $this->assertFalse($this->flag($revived), 'task1 不是首任务节点，标记随行留档 false');

        // ── 负向 1：无血缘（发起那条 parent 为 '0'）⇒ 必须报错，不得静默不建单。
        // 另起一条实例：上面那次正向回退已把 apply/task2 办结，复用会先撞到"任务不在进行中"。
        $inst0 = $this->engine->startProcessInstanceById('121', 'user1', FlowData::create());
        $iid0 = (string) $inst0->getInstanceId();
        $apply0 = $this->doing($iid0, 'apply');
        $this->assertSame('0', (string) $apply0->getParentTaskId(), '前置条件：发起那条 parent 应为 0');
        $rows0 = count($this->repo->findHistoryTasks($iid0));
        $e1 = null;
        try {
            $this->engine->executeAndJumpTask($apply0->getTaskId(), 'user1',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK]), null);
        } catch (\Throwable $e) { $e1 = $e; }
        $this->assertNotNull($e1, '无血缘必须报错，不得静默不建单');
        $this->assertStringContainsString('上一步任务ID为空，无法驳回至上一步处理', $e1->getMessage(), '实得: ' . $e1->getMessage());
        $this->assertStringNotContainsString('2001000', $e1->getMessage(), 'msg 不得带引擎内部码');
        // issues/243 甲案：血缘守卫排在 prepareExecution（内含 completeTask + updateTask）之前
        // ⇒ 报错那一次零写入——当前待办仍在办理人手里、行数不变、没有凭空多出的复活行。
        $this->assertNotNull($this->doing($iid0, 'apply'), '报错后当前待办必须仍是 DOING');
        $this->assertTrue($this->repo->findTaskById($apply0->getTaskId())?->isDoing(),
            '报错的那一条还是它自己，没被静默办结');
        $this->assertSame($rows0, count($this->repo->findHistoryTasks($iid0)), '报错不建单：任务行总数不变');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid0)?->getState(),
            '实例状态不受这次失败影响');

        // 老行形状：parent 为 NULL（issues/121 P1 之前落的数据）同样必须报 20010007，不得静默通过。
        // 复用上面那一条即可——上一段报错**没动它**（零写入），这里只把它的 parent 改成 NULL。
        $apply0->setParentTaskId(null);
        $this->repo->updateTask($apply0);
        $e1b = null;
        try {
            $this->engine->executeAndJumpTask($apply0->getTaskId(), 'user1',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK]), null);
        } catch (\Throwable $e) { $e1b = $e; }
        $this->assertNotNull($e1b, 'parent=NULL 必须报 20010007');
        $this->assertStringContainsString('上一步任务ID为空，无法驳回至上一步处理', $e1b->getMessage(), '实得: ' . $e1b->getMessage());
        $this->assertStringNotContainsString('2001000', $e1b->getMessage(), 'msg 不得带引擎内部码');
        $this->assertTrue($this->repo->findTaskById($apply0->getTaskId())?->isDoing(),
            'NULL 血缘那一档同样零写入');
        $this->assertSame($rows0, count($this->repo->findHistoryTasks($iid0)),
            'NULL 血缘那一档同样不建单：任务行总数不变');

        // ── 负向 2：血缘前驱跨不过 fork（boot2 语义：遇 fork/join/start 跳过该入边不再深入）──
        $this->repo->addDefine([
            'id' => '121f', 'name' => 'fork-join', 'displayName' => 'fork-join',
            'type' => 'approval', 'state' => 1, 'version' => 1,
            'content' => (string) file_get_contents(jeeflow_flows_dir() . '/04-fork-join.json'),
        ]);
        $inst2 = $this->engine->startProcessInstanceById('121f', 'user1', FlowData::create());
        $iid2 = (string) $inst2->getInstanceId();
        $apply2 = $this->doing($iid2, 'apply');
        $this->engine->executeProcessTask($apply2->getTaskId(), 'user1', $this->agree());
        $branch = $this->doing($iid2, 'taskA');
        // 分支节点没配参与者 ⇒ 先落一个，否则会被权限校验先挡下、测不到守卫
        $this->repo->addTaskActor($branch->getTaskId(), ['fk-a']);
        $this->assertSame('apply', $this->repo->findTaskById($branch->getParentTaskId())->getTaskName(),
            '前置条件：分支行的 parent 是 fork 之前的 apply（否则这条红不是因为守卫）');
        $rows2 = count($this->repo->findHistoryTasks($iid2));
        $e2 = null;
        try {
            $this->engine->executeAndJumpTask($branch->getTaskId(), 'fk-a',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK]), null);
        } catch (\Throwable $e) { $e2 = $e; }
        $this->assertNotNull($e2, 'apply→fork→taskA 之间隔着 fork，boot2 语义下不可回退');
        $this->assertStringContainsString('无法驳回至上一步处理，请确认上一步骤并非fork、join、suprocess以及会签任务', $e2->getMessage(), '实得: ' . $e2->getMessage());
        $this->assertStringNotContainsString('2001000', $e2->getMessage(), 'msg 不得带引擎内部码');
        // canRejected 那一档同样零写入（守卫排在 completeTask 之前）
        $this->assertTrue($this->repo->findTaskById($branch->getTaskId())?->isDoing(),
            '报错后当前行必须仍是 DOING（零写入）');
        $this->assertSame($rows2, count($this->repo->findHistoryTasks($iid2)),
            '报错不建单：实例下任务行总数不变');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid2)?->getState(),
            '实例状态不受这次失败影响');

        // ── 负向 3：血缘前驱指向**并行旁支**（b 不是 a 的祖先）⇒ 同样 20010008 且零写入。
        // 与 java `test168RollbackRejectsCrossBranchLineage` 同格：伪造一条 parent 打到旁支行上，
        // 守卫（NodeModel::canRejected 沿入边回溯遇 fork/join/start 不再深入）必须拦住，
        // 拦住之后当前待办一行都不动。
        $inst3 = $this->engine->startProcessInstanceById('121f', 'user1', FlowData::create());
        $iid3 = (string) $inst3->getInstanceId();
        $apply3 = $this->doing($iid3, 'apply');
        $this->engine->executeProcessTask($apply3->getTaskId(), 'user1', $this->agree());
        $doingList = $this->repo->findDoingTasks($iid3);
        $this->assertGreaterThanOrEqual(2, count($doingList), '前置条件：fork 后应至少两条并行 DOING');
        $a3 = $doingList[0];
        $b3 = $doingList[1];
        $a3->setParentTaskId($b3->getTaskId());
        $this->repo->updateTask($a3);
        $this->repo->addTaskActor($a3->getTaskId(), ['forker']);
        $rows3 = count($this->repo->findHistoryTasks($iid3));
        $e3 = null;
        try {
            $this->engine->executeAndJumpTask((string) $a3->getTaskId(), 'forker',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK]), null);
        } catch (\Throwable $e) { $e3 = $e; }
        $this->assertNotNull($e3, '血缘指向旁支（非祖先）必须报 20010008');
        $this->assertStringContainsString('无法驳回至上一步处理，请确认上一步骤并非fork、join、suprocess以及会签任务',
            $e3->getMessage(), '实得: ' . $e3->getMessage());
        $this->assertStringNotContainsString('2001000', $e3->getMessage(), 'msg 不得带引擎内部码');
        $this->assertTrue($this->repo->findTaskById((string) $a3->getTaskId())?->isDoing(),
            '旁支血缘那一档同样零写入：当前行仍是 DOING');
        $this->assertSame($rows3, count($this->repo->findHistoryTasks($iid3)),
            '报错不建单：实例下任务行总数不变');
    }

    private function agree(): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]);
    }

    private function doing(string $iid, string $name): \Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($t->getTaskName() === $name) return $t;
        }
        $this->fail("应有进行中的 {$name} 任务");
    }

    private function flag(\Jeeflow\Core\Domain\ProcessTask $t): bool
    {
        $v = $t->getVariables()->toArray()[FlowConst::IS_FIRST_TASK_NODE] ?? null;
        $this->assertNotNull($v, '建单不变量要求行变量里必须有 isFirstTaskNode 键');
        return (bool) $v;
    }
}
