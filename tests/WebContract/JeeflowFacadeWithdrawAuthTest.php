<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 撤回鉴权（issues/114，规范 06 §processInstance/withdraw / 合规用例 24）
 *
 * PHP 此前是 `$args['operator'] ?? 'user1'` + 零鉴权：任何人缺省 operator 都能把撤回人
 * 静默记成 user1，且无关第三人也能撤回整单。本文件钉住：
 * - operator 硬必填（缺失/空串一律 `operator 必填`，严禁回落固定账号）；
 * - 三条归属判据（发起人 ∪ 任一进行中任务参与者 ∪ flow.auto/flow.admin），全不命中拒绝；
 * - 实例与被撤任务 update_user 回写为真实撤回人；
 * - 已完成(20)/已终止(40) 任务行不被改写；
 * - 撤回作用于整单（同实例全部进行中任务），不是只撤操作人自己那一条。
 */
class JeeflowFacadeWithdrawAuthTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    private function deploy(string $file): string
    {
        $r = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/' . $file),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processDefineId'];
    }

    /** 起一单 simple：apply 由 user1 办结(20)，task1 由 leader 进行中(10) */
    private function startSimple(): string
    {
        $defineId = $this->deploy('01-simple.json');
        $r = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processInstanceId'];
    }

    // ── 负向：operator 硬必填 ──

    public function testWithdrawWithoutOperatorIsRejectedNotDefaulted(): void
    {
        $instanceId = $this->startSimple();

        foreach ([
            '缺 operator 键' => ['id' => $instanceId],
            'operator 为空串' => ['id' => $instanceId, 'operator' => ''],
            'operator 为空白' => ['id' => $instanceId, 'operator' => '   '],
            'operator 为 null' => ['id' => $instanceId, 'operator' => null],
        ] as $case => $args) {
            $r = $this->facade->flow('processInstance/withdraw', $args);
            $this->assertSame(99999999, $r['code'], $case . ' 应失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertSame('operator 必填', $r['msg'], $case . ' 的 msg 须逐字对齐跨栈统一文案');
            $this->assertNull($r['data'], $case . ' 失败不得返回 data');
        }

        // 关键：不得被"缺省回落 user1"静默撤走——实例与任务仍是进行中
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '缺 operator 不得撤回成功（严禁回落 user1）');
        $this->assertNotEmpty($this->repo->findDoingTasks($instanceId), '缺 operator 后进行中任务必须原样保留');
    }

    // ── 负向：无关第三人被拒 ──

    public function testWithdrawByUnrelatedThirdPartyDenied(): void
    {
        $instanceId = $this->startSimple();

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'stranger']);
        $this->assertSame(99999999, $r['code']);
        $this->assertSame('无权限撤回该流程实例', $r['msg']);

        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($instanceId)->getState());
        $this->assertCount(1, $this->repo->findDoingTasks($instanceId), '被拒后进行中任务不得被改写');
    }

    // ── 正向：判据 1 发起人 ──

    public function testWithdrawByStarterWritesRealUpdateUser(): void
    {
        $instanceId = $this->startSimple();
        $applyTask = $this->doingOrFinished($instanceId, 'apply');
        $task1 = $this->doingOrFinished($instanceId, 'task1');
        $this->assertNotNull($applyTask);
        $this->assertNotNull($task1);
        $this->assertSame(ProcessTaskState::FINISHED, $applyTask->getTaskState(), '前置：apply 应已办结(20)');

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($r['data'], '契约 data → null');

        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::WITHDRAW, $inst->getState());
        // 实例 update_user 回写为真实撤回人（此前恒被写成缺省 user1，看不出鉴权是否生效）
        $this->assertSame('user1', $inst->getUpdateUser());

        // 进行中任务 → 30 且 update_user 回写
        $this->assertSame(ProcessTaskState::WITHDRAW, $this->repo->findTaskById($task1->getTaskId())->getTaskState());
        $this->assertSame('user1', $this->repo->findTaskById($task1->getTaskId())->getUpdateUser(),
            '进行中任务的 update_user 同样回写为撤回人');

        // 已完成(20) 行不得被改写
        $applyAfter = $this->repo->findTaskById($applyTask->getTaskId());
        $this->assertSame(ProcessTaskState::FINISHED, $applyAfter->getTaskState(), '已完成(20) 任务行不得被撤回改写');
        $this->assertSame('user1', $applyAfter->getActorId(), '已完成任务办理人列不得被撤回改写');
        $this->assertCount(0, $this->repo->findDoingTasks($instanceId));
    }

    /**
     * 正向：判据 1 用「非 user1 的真实发起人」验证不回落到固定账号 ——
     * 以 user9 发起，则 user9 可撤、user1 反被拒（若仍缺省回落 user1，这条必红）。
     */
    public function testWithdrawByNonDefaultStarterAndOthersRejected(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user9',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];

        $wrong = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);
        $this->assertSame(99999999, $wrong['code'], 'user1 既非发起人又非参与者，不得撤回');
        $this->assertSame('无权限撤回该流程实例', $wrong['msg']);

        $ok = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user9']);
        $this->assertSame(0, $ok['code'], json_encode($ok, JSON_UNESCAPED_UNICODE));
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::WITHDRAW, $inst->getState());
        $this->assertSame('user9', $inst->getUpdateUser(), '撤回人须记成真实操作人 user9');
    }

    // ── 正向：判据 2 进行中任务参与者（非发起人） ──

    public function testWithdrawByDoingTaskParticipant(): void
    {
        $instanceId = $this->startSimple();
        $task1 = $this->doingOrFinished($instanceId, 'task1');
        $this->assertNotNull($task1);
        $this->assertSame(['leader'], $task1->getActorIds(), '前置：task1 参与者为 leader');

        // leader 不是发起人，但他是进行中任务的参与者 → 判据 2 放行
        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'leader']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState());
        $withdrawn = $this->repo->findTaskById($task1->getTaskId());
        $this->assertSame(ProcessTaskState::WITHDRAW, $withdrawn->getTaskState());
        $this->assertSame('leader', $withdrawn->getUpdateUser(), '任务 update_user 须记真实撤回人 leader');
    }

    /** 判据 2 的参与者以「参与者表」为准：加签进来的人同样可撤回整单 */
    public function testWithdrawBySurrogateAddedActor(): void
    {
        $instanceId = $this->startSimple();
        $task1 = $this->doingOrFinished($instanceId, 'task1');
        $add = $this->facade->flow('processTask/surrogate', [
            'processTaskId' => $task1->getTaskId(), 'actorIds' => ['zhaoliu'],
        ]);
        $this->assertSame(0, $add['code'], json_encode($add, JSON_UNESCAPED_UNICODE));

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'zhaoliu']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState());
    }

    // ── 正向：判据 3 flow.auto / flow.admin ──

    public function testWithdrawByPrivilegedOperatorsAllowed(): void
    {
        foreach (['flow.admin', 'flow.auto'] as $privileged) {
            $instanceId = $this->startSimple();
            $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => $privileged]);
            $this->assertSame(0, $r['code'], "{$privileged} 应放行: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState());
        }
    }

    // ── 正向：撤回作用于整单（多进行中任务一次全撤） ──

    public function testWithdrawCoversWholeInstanceNotOnlyOwnTask(): void
    {
        $defineId = $this->deploy('05-countersign-parallel.json');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];

        // 并行会签：userA/userB/userC 各一条进行中任务
        $doingBefore = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(3, $doingBefore, '前置：并行会签应有 3 条进行中任务');

        // userA 撤回 —— 必须把整单 3 条都撤掉，而不是只撤他自己那条
        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'userA']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, $this->repo->findDoingTasks($instanceId), '撤回作用于整单');
        foreach ($doingBefore as $t) {
            $stored = $this->repo->findTaskById($t->getTaskId());
            $this->assertSame(ProcessTaskState::WITHDRAW, $stored->getTaskState(),
                "进行中任务 {$t->getTaskName()} 应落 30");
            $this->assertSame('userA', $stored->getUpdateUser(),
                "被撤任务 {$t->getTaskName()} 的 update_user 都应回写撤回人");
        }
    }

    // ── 回归：废弃路径仍写 99，不与撤回 30 混用 ──

    public function testAbandonPathStillWrites99(): void
    {
        $defineId = $this->deploy('01-simple.json');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];
        $inst = $this->repo->findInstanceById($instanceId);
        $doing = $inst->getDoingTasks()[0];
        $inst->abandonTask($doing->getTaskId(), 'user1');
        $this->repo->updateInstance($inst);
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById($doing->getTaskId())->getTaskState(),
            '废弃路径仍写 99（99 与 30 两码不得混用）');
    }

    private function doingOrFinished(string $instanceId, string $taskName): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($this->repo->findHistoryTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
