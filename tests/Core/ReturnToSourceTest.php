<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
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
 * 「退回修改、重提直返」验收（PHP 栈）：基本直返、条件不重算、未汇合并行等待、
 * 多分支分别退回、重复退回、回归（无标记走原路）与负向拦截。
 */
final class ReturnToSourceTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $defineSeq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    private function start(string $file, array $args = []): array
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/' . $file);
        $this->assertNotFalse($json, $file . ' 必须存在');
        $this->defineSeq++;
        $defineId = 'rj-' . $this->defineSeq;
        $this->repo->addDefine([
            'id' => $defineId, 'name' => 'rj-' . $file, 'displayName' => $file,
            'type' => 'approval', 'state' => 1, 'content' => $json, 'version' => 1,
        ]);
        $inst = $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::of($args));
        $iid = (string) $inst->getInstanceId();
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($t->getTaskName() === 'apply') {
                $this->repo->addTaskActor((string) $t->getTaskId(), ['applicant']);
                $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant');
            }
        }
        return [$iid, $defineId];
    }

    private function doing(string $iid, string $name): ?ProcessTask
    {
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($t->getTaskName() === $name) return $t;
        }
        return null;
    }

    private function execute(ProcessTask $task, string $operator, int $submit, array $extra = []): void
    {
        $this->repo->addTaskActor((string) $task->getTaskId(), [$operator]);
        $this->engine->executeProcessTask((string) $task->getTaskId(), $operator,
            FlowData::of([FlowConst::SUBMIT_TYPE => $submit] + $extra));
    }

    private function ret(ProcessTask $task, string $operator): void
    {
        $this->repo->addTaskActor((string) $task->getTaskId(), [$operator]);
        $this->engine->executeAndJumpToFirstTaskNode((string) $task->getTaskId(), $operator,
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::ROLLBACK_TO_OPERATOR]));
    }

    public function testSimpleDirectReturn(): void
    {
        [$iid] = $this->start('14-return-to-source.json');
        $a = $this->doing($iid, 'taskA');
        $this->assertNotNull($a);
        $this->ret($a, 'userA');

        $app = $this->doing($iid, 'apply');
        $this->assertNotNull($app, '退回后应有申请待办');
        $this->assertNotNull($app->getVariables()->get('_reapplyContext'), '申请待办应带直返上下文');
        $this->assertNull($this->doing($iid, 'taskB'), '退回不生成后续节点任务');

        $this->execute($app, 'applicant', SubmitType::RE_APPLY);
        $newA = $this->doing($iid, 'taskA');
        $this->assertNotNull($newA, '重提应回到来源节点');
        $this->assertSame((string) $app->getTaskId(), (string) $newA->getParentTaskId(), '新 C 的血缘指向本轮申请任务');
        $this->assertNull($this->doing($iid, 'taskB'));

        $this->execute($newA, 'userA', SubmitType::AGREE);
        $b = $this->doing($iid, 'taskB');
        $this->assertNotNull($b);
        $this->execute($b, 'userB', SubmitType::AGREE);
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($iid)->getState());
    }

    public function testReapplyRejectsOtherSubmitTypes(): void
    {
        [$iid] = $this->start('14-return-to-source.json');
        $this->ret($this->doing($iid, 'taskA'), 'userA');
        $app = $this->doing($iid, 'apply');
        $this->repo->addTaskActor((string) $app->getTaskId(), ['applicant']);
        try {
            $this->engine->executeProcessTask((string) $app->getTaskId(), 'applicant',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
            $this->fail('直返申请待办不得用普通同意绕过');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('重新提交', $e->getMessage());
        }
        $this->assertNull($this->doing($iid, 'taskA'));
    }

    public function testJoinWaitsWhileApplicantEditing(): void
    {
        [$iid] = $this->start('15-return-to-source-fork.json');
        $a = $this->doing($iid, 'taskA');
        $b = $this->doing($iid, 'taskB');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->ret($a, 'userA');
        $this->assertNotNull($this->doing($iid, 'taskB'), '另一分支可继续');

        $this->execute($b, 'userB', SubmitType::AGREE);
        $this->assertNull($this->doing($iid, 'taskC'), '来源分支仍在申请人手里，汇合不得放行');

        $app = $this->doing($iid, 'apply');
        $this->execute($app, 'applicant', SubmitType::RE_APPLY);
        $newA = $this->doing($iid, 'taskA');
        $this->assertNotNull($newA);
        $this->assertNull($this->doing($iid, 'taskB'), '不重建 D');
        $this->assertNull($this->doing($iid, 'taskC'), '重提本身不放行汇合');

        $this->execute($newA, 'userA', SubmitType::AGREE);
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '汇合后只建一条负责人待办');
        $this->assertSame('taskC', $doing[0]->getTaskName());
    }

    public function testTwoBranchesReturnIndependently(): void
    {
        [$iid] = $this->start('15-return-to-source-fork.json');
        $this->ret($this->doing($iid, 'taskA'), 'userA');
        $this->ret($this->doing($iid, 'taskB'), 'userB');
        $apps = array_values(array_filter($this->repo->findDoingTasks($iid),
            fn(ProcessTask $t) => $t->getTaskName() === 'apply'));
        $this->assertCount(2, $apps, '两条分支分别退回产生两条申请待办');
        $appA = null; $appB = null;
        foreach ($apps as $x) {
            $rc = $x->getVariables()->get('_reapplyContext');
            if (($rc['sourceNode'] ?? '') === 'taskA') $appA = $x;
            if (($rc['sourceNode'] ?? '') === 'taskB') $appB = $x;
        }
        $this->assertNotNull($appA);
        $this->assertNotNull($appB);

        $this->execute($appA, 'applicant', SubmitType::RE_APPLY);
        $rest = array_filter($this->repo->findDoingTasks($iid), fn(ProcessTask $t) => $t->getTaskName() === 'apply');
        $this->assertCount(1, $rest, 'B 的申请待办不受影响');
        $this->execute($this->doing($iid, 'taskA'), 'userA', SubmitType::AGREE);
        $this->assertNull($this->doing($iid, 'taskC'), 'B 仍在修改，汇合不得放行');

        $this->execute($appB, 'applicant', SubmitType::RE_APPLY);
        $this->execute($this->doing($iid, 'taskB'), 'userB', SubmitType::AGREE);
        $this->assertNotNull($this->doing($iid, 'taskC'));
    }

    public function testRepeatedReturnBindsItsOwnRound(): void
    {
        [$iid] = $this->start('14-return-to-source.json');
        $this->ret($this->doing($iid, 'taskA'), 'userA');
        $this->execute($this->doing($iid, 'apply'), 'applicant', SubmitType::RE_APPLY);
        $round2 = $this->doing($iid, 'taskA');
        $this->ret($round2, 'userA');
        $rc = $this->doing($iid, 'apply')->getVariables()->get('_reapplyContext');
        $this->assertSame((string) $round2->getTaskId(), (string) ($rc['sourceTaskId'] ?? ''));
    }

    public function testMarkedApplicantRejectsJumpEntries(): void
    {
        [$iid] = $this->start('14-return-to-source.json');
        $this->ret($this->doing($iid, 'taskA'), 'userA');
        $app = $this->doing($iid, 'apply');
        $this->assertNotNull($app);
        $this->repo->addTaskActor((string) $app->getTaskId(), ['applicant']);
        $doingBefore = count($this->repo->findDoingTasks($iid));
        $histBefore = count($this->repo->findHistoryTasks($iid));

        $cases = [
            'named jump' => fn() => $this->engine->executeAndJumpTask(
                (string) $app->getTaskId(), 'applicant', FlowData::of([]), 'taskB'),
            'rollback' => fn() => $this->engine->executeAndJumpTask(
                (string) $app->getTaskId(), 'applicant', FlowData::of([]), null),
            'reject' => fn() => $this->engine->executeAndJumpToEnd(
                (string) $app->getTaskId(), 'applicant', FlowData::of([])),
            'return again' => fn() => $this->engine->executeAndJumpToFirstTaskNode(
                (string) $app->getTaskId(), 'applicant', FlowData::of([])),
        ];
        foreach ($cases as $label => $case) {
            try {
                $case();
                $this->fail($label . ' must be rejected for marked applicant task');
            } catch (\Jeeflow\Core\JeeflowException $e) {
                $this->assertStringContainsString('只接受重新提交', $e->getMessage(), $label);
            }
        }

        $this->assertCount($doingBefore, $this->repo->findDoingTasks($iid), '拒绝后不新增任务');
        $this->assertCount($histBefore, $this->repo->findHistoryTasks($iid), '拒绝后不新增历史行');
        $again = $this->repo->findTaskById((string) $app->getTaskId());
        $this->assertNotNull($again);
        $this->assertTrue($again->isDoing());
        $this->assertNotNull($again->getVariables()->get('_reapplyContext'));

        $this->execute($again, 'applicant', SubmitType::RE_APPLY);
        $this->assertNotNull($this->doing($iid, 'taskA'), '正常重提仍可用');
    }

    public function testLegacyReapplyUnchanged(): void
    {
        [$iid] = $this->start('04-fork-join.json');
        $this->ret($this->doing($iid, 'taskA'), 'userA');
        $app = $this->doing($iid, 'apply');
        $this->assertNull($app->getVariables()->get('_reapplyContext'), '未配置直返不得生成上下文');
        $this->execute($app, 'applicant', SubmitType::RE_APPLY);
        $this->assertCount(3, $this->repo->findDoingTasks($iid), '普通重提仍按流程重新分叉');
    }

    public function testNestedForkKeepsOwnership(): void
    {
        [$iid] = $this->start('18-return-to-source-nested.json');
        $a = $this->doing($iid, 'taskA');
        $b = $this->doing($iid, 'taskB');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        // 外层分支 A 进入内层并行组
        $this->execute($a, 'userA', SubmitType::AGREE);
        $x = $this->doing($iid, 'taskX');
        $y = $this->doing($iid, 'taskY');
        $this->assertNotNull($x);
        $this->assertNotNull($y);

        // 内层分支 X 退回发起人
        $this->ret($x, 'userX');
        $this->assertNotNull($this->doing($iid, 'apply'), '退回后应有申请待办');

        // 内层另一支 Y 办完：内层汇合必须等 X 分支
        $this->execute($y, 'userY', SubmitType::AGREE);
        $this->assertNull($this->doing($iid, 'taskA2'), '内层汇合不得在来源分支修改中放行');

        // 重提只重建 X，不重建 Y，也不重放内层分叉
        $this->execute($this->doing($iid, 'apply'), 'applicant', SubmitType::RE_APPLY);
        $newX = $this->doing($iid, 'taskX');
        $this->assertNotNull($newX, '重提应重建内层来源任务');
        $this->assertNull($this->doing($iid, 'taskY'), '不重建 Y');
        $this->assertNull($this->doing($iid, 'taskA2'), '重提本身不放行内层汇合');

        $this->execute($newX, 'userX', SubmitType::AGREE);
        $this->assertSame(1, $this->histCount($iid, 'taskA2'), '内层汇合只放行一次');

        // 外层：A 分支已到外层汇合，但 B 分支仍在办
        $this->execute($this->doing($iid, 'taskA2'), 'userA', SubmitType::AGREE);
        $this->assertNull($this->doing($iid, 'taskC'), '外层汇合等待 B 分支');
        $this->execute($b, 'userB', SubmitType::AGREE);
        $this->assertSame(1, $this->histCount($iid, 'taskC'), '外层汇合只放行一次');
    }

    private function histCount(string $iid, string $name): int
    {
        return count(array_filter($this->repo->findHistoryTasks($iid),
            fn(ProcessTask $t) => $t->getTaskName() === $name));
    }
}
