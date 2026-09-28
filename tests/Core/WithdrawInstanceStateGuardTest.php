<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowException;
use PHPUnit\Framework\TestCase;

/**
 * issues/134 案 A · 撤回的实例状态守卫（引擎内部码 20010009）—— **聚合根层**
 *
 * 契约（八栈逐字统一，owner 2026-09-28 拍板 A）：撤回作用于**实例**时，实例状态不是 10(进行中)
 * 一律拒——被拒时状态不得被改写、一行任务都不动、不落库。改前对已办结(20)/已终止(40) 的实例
 * 调撤回会静默改写成 30，已办列表与按状态聚合的统计口径凭空改历史且零报错（本案病灶）。
 *
 * 落点＝聚合根 {@see ProcessInstance::withdraw()}（守卫排在该方法的任务行循环之前，
 * 门面 JeeflowFacade::withdraw 又把它排在 updateInstance 落库之前）；任务行层面既有保护
 * （已完成 20 / 已终止 40 行不改写）保持原样。
 * 出口按 issues/121 口径：门面吞内部码 ⇒ code=99999999 ＋ msg 逐字固定文案，**码值不进 msg**
 * （出口档见 tests/WebContract/JeeflowFacadeWithdrawInstanceStateTest.php）。
 * 权威＝jeeflow-java 参考实现（WfErrEnum.WITHDRAW_INSTANCE_NOT_DOING + ProcessInstance.withdraw）
 * ＋ jeeflow-doc/docs/spec/06-facade.md §processInstance/withdraw。
 */
class WithdrawInstanceStateGuardTest extends TestCase
{
    /** 内部码 20010009 的固定文案（八栈逐字一致，禁改） */
    private const NOT_DOING_MSG = '流程实例非进行中，无法撤回';

    /**
     * 夹具：一条 DOING 任务 + 一条已完成(20) 任务，实例态由调用方置。
     * 「实例已非进行中但仍有 doing 行」正是病灶要拦的脏数据形状（已终止单残留待办）。
     */
    private function instance(): ProcessInstance
    {
        $define = [
            'id' => '100', 'name' => 'leave', 'displayName' => '请假流程',
            'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 0,
        ];
        $inst = ProcessInstance::create($define, 'user1');
        $doing = $inst->createTask('task1', '审批', 0, 0, null, ['user2'], 'user1');
        $doing->setTaskId('t-doing');
        $apply = $inst->createTask('apply', '申请', 0, 0, null, ['user1'], 'user1', null, true);
        $apply->setTaskId('t-apply');
        $inst->completeTask('t-apply', 'user1', null);
        return $inst;
    }

    /** 任务行快照：态 + 办理人列 + update_user/update_time（撤回会动后两列，故一并钉） */
    private function rowsOf(ProcessInstance $inst): array
    {
        $out = [];
        foreach ($inst->getTasks() as $task) {
            $out[(string) $task->getTaskId()] = [
                $task->getTaskState(), $task->getActorId(), $task->getUpdateUser(), $task->getUpdateTime(),
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * 负向（六档全收）：20/30/40/45/50/99 一律拒——抛 JeeflowException 且**文案逐字相等**
     * （assertSame，不用"包含"这种宽松判据），实例态与全部任务行**零改写**。
     * 40(强行终止) 档门面流转造不出（issues/134 §5.2 已记壳侧 gate 同样造不出），故在聚合根直钉。
     */
    public function testWithdrawRejectsEveryNonDoingStateAndRewritesNothing(): void
    {
        $nonDoing = [
            ProcessInstanceState::FINISHED, ProcessInstanceState::WITHDRAW, ProcessInstanceState::INTERRUPT,
            ProcessInstanceState::REJECTED, ProcessInstanceState::PENDING, ProcessInstanceState::ABANDON,
        ];
        foreach ($nonDoing as $state) {
            $label = sprintf('state=%d(%s)', $state, ProcessInstanceState::label($state));
            $inst = $this->instance();
            $inst->setState($state);
            $inst->setUpdateTime('2026-09-28 10:00:00');
            $inst->setUpdateUser('user2');
            $stateBefore = $state;
            $timeBefore = $inst->getUpdateTime();
            $userBefore = $inst->getUpdateUser();
            $rowsBefore = $this->rowsOf($inst);
            $this->assertCount(2, $rowsBefore, "{$label} 前置：夹具应有 2 条任务行");

            try {
                $inst->withdraw('user9');
                $this->fail("{$label} 撤回必须抛 JeeflowException（内部码 20010009）");
            } catch (JeeflowException $e) {
                $this->assertSame(self::NOT_DOING_MSG, $e->getMessage(),
                    "{$label} 内部码固定文案须逐字相等（八栈同文案）");
            }

            $this->assertSame($stateBefore, $inst->getState(), "{$label} 被拒后实例状态不得被改写（改前会被静默改成 30）");
            $this->assertSame($timeBefore, $inst->getUpdateTime(), "{$label} 被拒后实例 update_time 不得动");
            $this->assertSame($userBefore, $inst->getUpdateUser(), "{$label} 被拒后实例 update_user 不得动");
            $this->assertSame($rowsBefore, $this->rowsOf($inst), "{$label} 被拒后任务行必须一行不动");
        }
    }

    /**
     * 正向对照 state=10：守卫没写反——进行中实例撤回照旧整单落 30，
     * 且既有语义不动（doing 行 → 30 并回写撤回人；已完成 20 行不被改写）。
     */
    public function testWithdrawOnDoingInstanceStillWrites30AndKeepsFinishedRow(): void
    {
        $inst = $this->instance();
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '前置：夹具应为进行中(10)');

        $inst->withdraw('user9');

        $this->assertSame(ProcessInstanceState::WITHDRAW, $inst->getState(), '进行中实例撤回应照旧成功落 30');
        $rows = $this->rowsOf($inst);
        $this->assertSame(ProcessTaskState::WITHDRAW, $rows['t-doing'][0], 'doing 行应落 30');
        $this->assertSame('user9', $rows['t-doing'][2], '被撤任务的 update_user 回写真实撤回人');
        $this->assertSame(ProcessTaskState::FINISHED, $rows['t-apply'][0], '已完成(20) 任务行不得被撤回改写');
    }
}
