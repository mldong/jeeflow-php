<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/79：submitType 3/4/5/6 + 20 + 负向 门面行为测试（对齐 Java 参考实现断言，五语言全覆盖）
 *
 * - submitType=3 ROLLBACK：上一步任务节点新建待办（actor=退回操作人），实例保持 DOING(10)
 * - submitType=4 JUMP：跳转首任务节点 assignee 强制发起人；无效节点名 → 99999999
 * - submitType=5 RE_APPLY：tf_nextNodeOperator 覆盖下一节点处理人 + f_ 表单落实例变量
 * - submitType=6 ROLLBACK_TO_OPERATOR：重执行首个任务节点，actor=发起人
 * - submitType=20 会签一票否决：串行会签提前流转 end → 实例 FINISHED(20)
 * - submitType=2 REJECT：实例 REJECT(45)（PHP 已有分发，钉住行为）
 * - 负向：非处理人执行 → 99999999
 *
 * 流程 JSON 复用 jeeflow-java 共享 fixtures（02-multi-task / 06-countersign-sequential）。
 */
class JeeflowFacadeIssue79Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 辅助 ──

    private function deployFlow(string $file): string
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/' . $file);
        $this->assertNotFalse($json, "缺少流程 fixture: {$file}");
        $r = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'user1']);
        $this->assertSame(0, $r['code'], 'deploy 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processDefineId'];
    }

    /**
     * 部署 02-multi-task 并推进到指定任务节点（startAndExecute 自动完成申请节点 applicant）。
     * 返回 processInstanceId。
     */
    private function startMultiTaskAt(string $name): string
    {
        $defineId = $this->deployFlow('02-multi-task.json');
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'zhangsan',
        ]);
        $this->assertSame(0, $r1['code'], 'startAndExecute 失败: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));
        $instanceId = $r1['data']['processInstanceId'];

        $order = ['task1', 'task2', 'task3'];
        $actor = ['leader', 'manager', 'boss'];
        $target = array_search($name, $order, true);
        $this->assertIsInt($target, "未知目标节点: {$name}");
        for ($i = 0; $i < $target; $i++) {
            $tid = $this->doingTaskId($instanceId, $order[$i]);
            $this->assertNotSame('', $tid, "应推进到 {$order[$i]}");
            $this->repo->addTaskActor($tid, [$actor[$i]]);
            $r = $this->facade->flow('processTask/execute', [
                'processTaskId' => $tid, 'operator' => $actor[$i], 'submitType' => SubmitType::AGREE,
            ]);
            $this->assertSame(0, $r['code'], "推进 {$order[$i]} 失败: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        return $instanceId;
    }

    private function doingTaskId(string $instanceId, string $taskName): string
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) {
                return (string) $t->getTaskId();
            }
        }
        return '';
    }

    private function actorsOf(string $taskId): array
    {
        $t = $this->repo->findTaskById($taskId);
        $this->assertNotNull($t, "任务不存在: {$taskId}");
        return $t->getActorIds();
    }

    /** 会签场景：同节点多个 DOING 任务（每 actor 一个），按 actor 定位 */
    private function doingTaskIdByActor(string $instanceId, string $taskName, string $actor): string
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $t) {
            if ($t->getTaskName() !== $taskName) continue;
            if (in_array($actor, $t->getActorIds(), true)) {
                return (string) $t->getTaskId();
            }
        }
        return '';
    }

    private function stateOf(string $instanceId): int
    {
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($inst, "实例不存在: {$instanceId}");
        return $inst->getState();
    }

    // ── submitType 3/4/5/6 + 负向 ──

    public function testExecuteSubmitTypeBehavior(): void
    {
        // ── submitType=3 ROLLBACK：task2 退回上一步 → task1 新待办（actor=退回操作人），实例保持 DOING(10)
        $rb = $this->startMultiTaskAt('task2');
        $t2 = $this->doingTaskId($rb, 'task2');
        $this->repo->addTaskActor($t2, ['manager']);
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t2, 'operator' => 'manager', 'submitType' => SubmitType::ROLLBACK,
        ]);
        $this->assertSame(0, $r['code'], 'ROLLBACK 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $rbTask1 = $this->doingTaskId($rb, 'task1');
        $this->assertNotSame('', $rbTask1, 'ROLLBACK 应在 task1 产生新待办');
        $this->assertContains('manager', $this->actorsOf($rbTask1), '退回任务 actor 应为退回操作人 manager');
        $this->assertSame(ProcessInstanceState::DOING, $this->stateOf($rb), 'ROLLBACK 后实例应保持 DOING(10)');

        // ── submitType=4 JUMP：task3 跳转 apply（首任务节点 = start 直接后继，assignee 强制发起人）
        $jp = $this->startMultiTaskAt('task3');
        $t3 = $this->doingTaskId($jp, 'task3');
        $this->repo->addTaskActor($t3, ['boss']);
        $jl = $this->facade->flow('processTask/jumpAbleTaskNameList', ['processInstanceId' => $jp]);
        $this->assertSame(0, $jl['code']);
        $jumpValues = array_column($jl['data'], 'value');
        $this->assertContains('task1', $jumpValues, 'jumpAble 应包含已完成的 task1');
        $this->assertContains('apply', $jumpValues, 'jumpAble 应包含已完成的 apply');
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t3, 'operator' => 'boss', 'submitType' => SubmitType::JUMP, 'taskName' => 'apply',
        ]);
        $this->assertSame(0, $r['code'], 'JUMP 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $jpApply = $this->doingTaskId($jp, 'apply');
        $this->assertNotSame('', $jpApply, 'JUMP 应在 apply（首任务节点）产生新待办');
        $this->assertSame(['zhangsan'], $this->actorsOf($jpApply), '跳首任务节点 assignee 强制为发起人 zhangsan');
        $this->assertSame(ProcessInstanceState::DOING, $this->stateOf($jp), 'JUMP 后实例应保持 DOING(10)');

        // ── 负向：JUMP taskName 不存在 → 99999999 + 「无法找到节点模型」
        $jn = $this->startMultiTaskAt('task2');
        $t2n = $this->doingTaskId($jn, 'task2');
        $this->repo->addTaskActor($t2n, ['manager']);
        $jr = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t2n, 'operator' => 'manager', 'submitType' => SubmitType::JUMP, 'taskName' => 'no-such-node',
        ]);
        $this->assertSame(99999999, $jr['code'], 'JUMP 无效节点应报 99999999');
        $this->assertStringContainsString('无法找到节点模型', $jr['msg'], 'JUMP 无效节点应报「无法找到节点模型」');

        // ── submitType=5 RE_APPLY：task1 重新提交（tf_nextNodeOperator + f_ 表单）
        $ra = $this->startMultiTaskAt('task1');
        $t1r = $this->doingTaskId($ra, 'task1');
        $this->repo->addTaskActor($t1r, ['leader']);
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t1r, 'operator' => 'leader', 'submitType' => SubmitType::RE_APPLY,
            'tf_nextNodeOperator' => 'manager', 'f_leaveType' => 'annual',
        ]);
        $this->assertSame(0, $r['code'], 'RE_APPLY 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $task2Id = $this->doingTaskId($ra, 'task2');
        $this->assertNotSame('', $task2Id, 'RE_APPLY 后应推进到 task2');
        $this->assertSame(['manager'], $this->actorsOf($task2Id), 'tf_nextNodeOperator 应覆盖 task2 处理人');
        $raInst = $this->repo->findInstanceById($ra);
        $this->assertSame('annual', $raInst->getVariables()->get('f_leaveType'), 'f_ 表单字段应落实例变量');
        $this->assertSame(ProcessInstanceState::DOING, $this->stateOf($ra), 'RE_APPLY 后实例应保持 DOING(10)');

        // ── submitType=6 ROLLBACK_TO_OPERATOR：task3 退回发起人 → apply 重执行、actor=发起人 zhangsan
        $ro = $this->startMultiTaskAt('task3');
        $t3o = $this->doingTaskId($ro, 'task3');
        $this->repo->addTaskActor($t3o, ['boss']);
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t3o, 'operator' => 'boss', 'submitType' => SubmitType::ROLLBACK_TO_OPERATOR,
        ]);
        $this->assertSame(0, $r['code'], 'ROLLBACK_TO_OPERATOR 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $roApply = $this->doingTaskId($ro, 'apply');
        $this->assertNotSame('', $roApply, 'ROLLBACK_TO_OPERATOR 应重执行首个任务节点 apply');
        $this->assertSame(['zhangsan'], $this->actorsOf($roApply), '退回发起人 assignee 强制为发起人 zhangsan');
        $this->assertSame(ProcessInstanceState::DOING, $this->stateOf($ro), '退回发起人后实例应保持 DOING(10)');

        // ── 负向：非处理人执行被拒（无权执行）
        $na = $this->startMultiTaskAt('task1');
        $t1n = $this->doingTaskId($na, 'task1');
        $nr = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t1n, 'operator' => 'hacker', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(99999999, $nr['code'], '非处理人执行应报 99999999');
        $this->assertStringContainsString('无权执行', $nr['msg'], '非处理人执行应报权限错误');
    }

    // ── submitType=2 REJECT ──

    public function testExecuteReject(): void
    {
        $instId = $this->startMultiTaskAt('task1');
        $t1 = $this->doingTaskId($instId, 'task1');
        $this->repo->addTaskActor($t1, ['leader']);
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $t1, 'operator' => 'leader', 'submitType' => SubmitType::REJECT,
        ]);
        $this->assertSame(0, $r['code'], 'REJECT 失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(ProcessInstanceState::REJECTED, $this->stateOf($instId), 'REJECT 后实例应为 REJECT(45)');
        $this->assertSame(0, count($this->repo->findDoingTasks($instId)), 'REJECT 后应无 DOING 任务');
    }

    // ── submitType=20 会签拒绝：软拒绝（默认）与一票否决（ONE_VOTE_VETO）+ merged 废弃残留（issues/91）──

    public function testExecuteCountersignDisagreeSoft(): void
    {
        // issues/91：06-countersign-sequential 未配置 ONE_VOTE_VETO → submitType=20 为软拒绝
        // （否决者任务正常完成、flag 记录、流程不阻断，继续等其余成员）
        $defineId = $this->deployFlow('06-countersign-sequential.json');
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r1['code'], 'startAndExecute 失败: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));
        $instanceId = $r1['data']['processInstanceId'];
        $taskA = $this->doingTaskIdByActor($instanceId, 'task1', 'userA');
        $this->assertNotSame('', $taskA, '会签节点应有 userA 的 DOING 任务');
        $this->repo->addTaskActor($taskA, ['userA']);
        // userA 会签不同意（未配 ONE_VOTE_VETO → 软拒绝）
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskA, 'operator' => 'userA', 'submitType' => SubmitType::COUNTERSIGN_DISAGREE,
        ]);
        $this->assertSame(0, $r['code'], '会签软拒绝执行失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '软拒绝后实例应保持 DOING(10)，继续等 userB');
        $this->assertEquals(1, (int) $inst->getVariables()->get('countersignDisagreeFlag'), 'countersignDisagreeFlag=1 应落实例变量');
        $doneA = $this->repo->findTaskById($taskA);
        $this->assertSame(ProcessTaskState::FINISHED, $doneA->getTaskState(), '软拒绝任务应正常完成');
        $this->assertEquals(1, (int) $doneA->getVariables()->get('countersignDisagreeFlag'), 'countersignDisagreeFlag=1 应落任务变量');
        $this->assertSame('userA', $doneA->getActorId(), '否决人应记录为实际操作人 userA');
        // 软拒绝不应废弃其余成员：userB 保持 DOING（预创建或串行推进到 userB 均成立）
        $taskB = $this->doingTaskIdByActor($instanceId, 'task1', 'userB');
        $this->assertNotSame('', $taskB, '软拒绝不应废弃 userB，应保持 DOING');
    }

    public function testExecuteCountersignOneVoteVeto(): void
    {
        // issues/91：13-countersign-one-vote-veto（并行 + ONE_VOTE_VETO）→ 任一成员
        // submitType=20 一票否决 → 会签节点立即推进 end，其余 DOING 会签任务废弃(ABANDON 99)
        $defineId = $this->deployFlow('13-countersign-one-vote-veto.json');
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r1['code'], 'startAndExecute 失败: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));
        $instanceId = $r1['data']['processInstanceId'];
        // 并行会签全员预创建：userA/userB/userC 三个 DOING 任务
        $taskA = $this->doingTaskIdByActor($instanceId, 'task1', 'userA');
        $taskB = $this->doingTaskIdByActor($instanceId, 'task1', 'userB');
        $taskC = $this->doingTaskIdByActor($instanceId, 'task1', 'userC');
        $this->assertNotSame('', $taskA, '会签节点应有 userA 的 DOING 任务');
        $this->assertNotSame('', $taskB, '会签节点应有 userB 的 DOING 任务');
        $this->assertNotSame('', $taskC, '会签节点应有 userC 的 DOING 任务');
        $this->repo->addTaskActor($taskA, ['userA']);
        // userA 会签不同意（已配 ONE_VOTE_VETO → 一票否决）
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskA, 'operator' => 'userA', 'submitType' => SubmitType::COUNTERSIGN_DISAGREE,
        ]);
        $this->assertSame(0, $r['code'], '会签否决执行失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $inst->getState(), '一票否决后会签节点应立即推进 end（实例 FINISHED 20）');
        $this->assertEquals(1, (int) $inst->getVariables()->get('countersignDisagreeFlag'), 'countersignDisagreeFlag=1 应落实例变量');
        $doneA = $this->repo->findTaskById($taskA);
        $this->assertSame(ProcessTaskState::FINISHED, $doneA->getTaskState(), '否决任务应已完成');
        $this->assertSame('userA', $doneA->getActorId(), '否决人应记录为实际操作人 userA');
        // 否决应废弃其余成员（ABANDON 99）
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById($taskB)->getTaskState(), '否决应废弃其余成员 userB（ABANDON 99）');
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById($taskC)->getTaskState(), '否决应废弃其余成员 userC（ABANDON 99）');
        $this->assertSame(0, count($this->repo->findDoingTasks($instanceId)), '否决后应无 DOING 任务');
    }

    public function testExecuteCountersignDisagreeParallelSoft(): void
    {
        // issues/91：05-countersign-parallel 未配置 ONE_VOTE_VETO → 并行会签 submitType=20 软拒绝
        // （否决者任务完成、flag 记录、流程不阻断，其余成员仍 DOING 等待）
        $defineId = $this->deployFlow('05-countersign-parallel.json');
        $r1 = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r1['code'], 'startAndExecute 失败: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));
        $instanceId = $r1['data']['processInstanceId'];
        $taskA = $this->doingTaskIdByActor($instanceId, 'task1', 'userA');
        $taskB = $this->doingTaskIdByActor($instanceId, 'task1', 'userB');
        $taskC = $this->doingTaskIdByActor($instanceId, 'task1', 'userC');
        $this->assertNotSame('', $taskA, '会签节点应有 userA 的 DOING 任务');
        $this->assertNotSame('', $taskB, '会签节点应有 userB 的 DOING 任务');
        $this->assertNotSame('', $taskC, '会签节点应有 userC 的 DOING 任务');
        $this->repo->addTaskActor($taskA, ['userA']);
        // userA 会签不同意（未配 ONE_VOTE_VETO → 软拒绝）
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskA, 'operator' => 'userA', 'submitType' => SubmitType::COUNTERSIGN_DISAGREE,
        ]);
        $this->assertSame(0, $r['code'], '会签软拒绝执行失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '并行软拒绝后实例应保持 DOING(10)，等 userB/userC');
        $this->assertEquals(1, (int) $inst->getVariables()->get('countersignDisagreeFlag'), 'countersignDisagreeFlag=1 应落实例变量');
        $doneA = $this->repo->findTaskById($taskA);
        $this->assertSame(ProcessTaskState::FINISHED, $doneA->getTaskState(), '软拒绝任务应正常完成');
        $this->assertSame(ProcessTaskState::DOING, $this->repo->findTaskById($taskB)->getTaskState(), '软拒绝不应废弃 userB');
        $this->assertSame(ProcessTaskState::DOING, $this->repo->findTaskById($taskC)->getTaskState(), '软拒绝不应废弃 userC');
    }
}
