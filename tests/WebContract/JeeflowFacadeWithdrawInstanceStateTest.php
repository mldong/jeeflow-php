<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/134 案 A · 撤回的实例状态守卫（引擎内部码 20010009）—— **门面出口层**
 *
 * 契约（八栈逐字统一，owner 2026-09-28 拍板 A）：`processInstance/withdraw` 只允许作用于
 * **进行中(10)** 的实例，非 10 一律拒 ⇒ 出口 `code=99999999` ＋ `msg` **逐字**
 * 「流程实例非进行中，无法撤回」（issues/121 口径：内部码不进 msg，不拼码值、不加前缀），
 * 且被拒时实例与任务行**一行都不改写、不落库**。
 *
 * 三档各一格（摘守卫做变异对照时按档报红，不许糊成一格）：
 * - 负向 state=20：真跑到办结的夹具（非手搓状态）；
 * - 负向 state=40：门面流转造不出 40（issues/134 §5.2 同款豁免，壳侧 gate 同样造不出），
 *   故栈内直钉「已终止实例 + 残留 doing 行」的脏数据形状，另加一格**聚合根直钉**的六档全收，
 *   见 tests/Core/WithdrawInstanceStateGuardTest.php；
 * - 正向 state=10：撤回仍 code=0 且整单落 30（防"守卫写反把正常路径也拦了"的假绿）。
 *
 * 鉴权（issues/114 三判据）与 operator 硬必填排在前面，不受本守卫影响：本文件三档都用**发起人
 * user1**（命中判据 1），确保挡住的是实例态而不是归属。
 * 权威＝jeeflow-java 参考实现（WfErrEnum.WITHDRAW_INSTANCE_NOT_DOING）
 * ＋ jeeflow-doc/docs/spec/06-facade.md §processInstance/withdraw。
 */
class JeeflowFacadeWithdrawInstanceStateTest extends TestCase
{
    /** 内部码 20010009 的固定文案（八栈逐字一致，禁改；断言一律 assertSame，不用"包含"判据） */
    private const NOT_DOING_MSG = '流程实例非进行中，无法撤回';

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

    private function deploy(string $file = '01-simple.json'): string
    {
        $r = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/' . $file),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processDefineId'];
    }

    /** 起一单 simple（发起人 user1）：apply 已办结(20)，task1 由 leader 进行中(10) ⇒ 实例 10 */
    private function startSimple(): string
    {
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $this->deploy(), 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($instanceId)->getState(),
            '前置：夹具应为进行中(10)');
        return $instanceId;
    }

    /** 把 01-simple 办到终态 ⇒ 实例 20、doing 任务清空（真实办结，非手搓状态） */
    private function finishSimple(): string
    {
        $instanceId = $this->startSimple();
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing, '前置：应停在 task1');
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $doing[0]->getTaskId(), 'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($instanceId)->getState(),
            '前置：夹具应是已办结(20)');
        return $instanceId;
    }

    /** 落库行快照（读回仓储，不看内存对象顺手改了什么）：态 + 办理人列 + update_user/update_time */
    private function rowSnapshot(string $instanceId): array
    {
        $out = [];
        foreach ($this->repo->findHistoryTasks($instanceId) as $task) {
            $out[(string) $task->getTaskId()] = [
                $task->getTaskState(), $task->getActorId(), $task->getUpdateUser(), $task->getUpdateTime(),
            ];
        }
        ksort($out);
        return $out;
    }

    private function instanceSnapshot(string $instanceId): array
    {
        $inst = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($inst);
        return [$inst->getState(), $inst->getUpdateUser(), $inst->getUpdateTime()];
    }

    // ── 负向：state=20（已办结）真实夹具 ──

    public function testWithdrawOnFinishedInstance20IsRefusedAndPersistsNothing(): void
    {
        $instanceId = $this->finishSimple();
        $instBefore = $this->instanceSnapshot($instanceId);
        $rowsBefore = $this->rowSnapshot($instanceId);
        $this->assertNotEmpty($rowsBefore, '前置：应有历史任务行');

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);

        $this->assertSame(99999999, $r['code'], '已办结(20)实例撤回必须显式报错，实得 ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(self::NOT_DOING_MSG, $r['msg'],
            'msg 须逐字等值（不拼码值、不加前缀），实得 ' . var_export($r['msg'], true));
        $this->assertStringNotContainsString('20010009', $r['msg'], '内部码不进 msg（issues/121 口径）');
        $this->assertNull($r['data'], '失败不得返回 data');

        // 病灶：改前这里会被静默改写成 30
        $this->assertSame($instBefore, $this->instanceSnapshot($instanceId),
            '被拒后实例 state/update_user/update_time 必须原样（不得被改成 30）');
        $this->assertSame(ProcessInstanceState::FINISHED, $this->instanceSnapshot($instanceId)[0]);
        $this->assertSame($rowsBefore, $this->rowSnapshot($instanceId), '被拒后任务行必须一行不动');
    }

    // ── 负向：state=40（强行终止）栈内直钉，doing 行残留 ──

    public function testWithdrawOnInterruptedInstance40IsRefusedAndKeepsDoingRow(): void
    {
        $instanceId = $this->startSimple();
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：应有 doing 行');
        // 40 档门面流转造不出（issues/134 §5.2 豁免）⇒ 仓储层直置实例态，其余字段不动，
        // 保留"已终止实例 + 残留 doing 任务行"的脏数据形状（同 JeeflowFacadeTransferTest 的 40 夹具口径）
        $inst = $this->repo->findInstanceById($instanceId);
        $inst->setState(ProcessInstanceState::INTERRUPT);
        $this->repo->updateInstance($inst);

        $instBefore = $this->instanceSnapshot($instanceId);
        $rowsBefore = $this->rowSnapshot($instanceId);
        $this->assertSame(ProcessInstanceState::INTERRUPT, $instBefore[0], '前置：夹具应为 40');

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);

        $this->assertSame(99999999, $r['code'], '已终止(40)实例撤回必须显式报错，实得 ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(self::NOT_DOING_MSG, $r['msg'], 'msg 须逐字等值（40 档与 20 档同文案）');
        $this->assertStringNotContainsString('20010009', $r['msg'], '内部码不进 msg（issues/121 口径）');

        $this->assertSame($instBefore, $this->instanceSnapshot($instanceId), '被拒后实例仍应是 40、update_* 不得动');
        $this->assertSame($rowsBefore, $this->rowSnapshot($instanceId),
            '被拒后 doing 行不得被改写成 30（改前整单静默落 30）');
        $left = $this->repo->findDoingTasks($instanceId);
        $this->assertSame([$doing[0]->getTaskId()], array_map(
            static fn($t) => $t->getTaskId(), $left), '被拒后 doing 行不得消失');
        $this->assertSame(ProcessTaskState::DOING, $left[0]->getTaskState(), 'doing 行仍应是 10');
    }

    // ── 正向对照：state=10 仍成功落 30 ──

    public function testWithdrawOnDoingInstance10StillSucceedsAndWrites30(): void
    {
        $instanceId = $this->startSimple();
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：应有 doing 行');

        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'user1']);

        $this->assertSame(0, $r['code'],
            '进行中实例撤回必须照旧成功（守卫写成"只允许非 10"时这一格报红）: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($r['data'], '契约 data → null');
        $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState(),
            '成功后整单落 30');
        $this->assertSame('user1', $this->repo->findInstanceById($instanceId)->getUpdateUser(), '撤回人回写真实操作人');
        $this->assertSame(ProcessTaskState::WITHDRAW, $this->repo->findTaskById($doing[0]->getTaskId())->getTaskState(),
            'doing 行落 30');
        $this->assertCount(0, $this->repo->findDoingTasks($instanceId), '撤回后不应再有进行中任务');
    }
}
