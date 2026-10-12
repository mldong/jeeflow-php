<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use PHPUnit\Framework\TestCase;

/**
 * issues/180 · abandonTask 摘副作用（owner 2026-10-13 拍板 A）——聚合根级。
 *
 * 缺陷：abandonTask 把「任务废弃」连带写成「流程实例也置 99(ABANDON)」，spec/04:329 只写
 * 「废弃单个任务」，go/py/node 三栈实例状态不动（对照形状），boot2/boot3 原版全仓零命中
 * （非产品血统、五栈自创）。修复＝删实例 state 写点；任务级废弃与实例元数据照旧。
 *
 * 判据：① 正向——任务落 99 且实例 state 仍 10；② 负向——非进行中任务照旧拒；
 * ③ 回归——其余任务行不动。
 */
class AbandonTaskInstanceState180Test extends TestCase
{
    private function instance(): ProcessInstance
    {
        $define = [
            'id' => '100', 'name' => 'leave', 'displayName' => '请假流程',
            'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 0,
        ];
        $inst = ProcessInstance::create($define, 'user1');
        $doing = $inst->createTask('task1', '审批', 0, 0, null, ['user2'], 'user1');
        $doing->setTaskId('t-doing');
        return $inst;
    }

    /** ① 正向：废弃后任务=99、实例 state 仍 10（改前被连带置 99——issues/180 病灶）。 */
    public function test180AbandonKeepsInstanceState(): void
    {
        $inst = $this->instance();
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '夹具前提：实例进行中');
        $inst->abandonTask('t-doing', 'user9');
        $this->assertSame(ProcessTaskState::ABANDON, $inst->getTasks()[0]->getTaskState(),
            '任务应落 99(ABANDON)');
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(),
            '实例 state 不得被连带置 99（issues/180：对齐 spec 04 与 go/py/node）');
    }

    /** ② 负向：非进行中任务照旧拒（夹具先办结），实例态不动。 */
    public function test180AbandonRejectsNonDoingTask(): void
    {
        $inst = $this->instance();
        $inst->completeTask('t-doing', 'user2', null);
        try {
            $inst->abandonTask('t-doing', 'user9');
            $this->fail('非进行中任务废弃必须抛（保持原守卫，不得静默放行）');
        } catch (\RuntimeException $e) {
            // 既有文案：任务[t-doing]不是进行中状态
        }
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState(), '被拒后实例 state 不得动');
    }

    /** ③ 回归：只废目标任务，其余行不动。 */
    public function test180AbandonLeavesOtherRowsUntouched(): void
    {
        $inst = $this->instance();
        $other = $inst->createTask('task2', '审批2', 0, 0, null, ['user2'], 'user1');
        $other->setTaskId('t-other');
        $inst->abandonTask('t-doing', 'user9');
        $this->assertSame(ProcessTaskState::ABANDON, $inst->getTasks()[0]->getTaskState());
        $this->assertSame(ProcessTaskState::DOING, $inst->getTasks()[1]->getTaskState(),
            '其余任务行不得被波及');
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState());
    }
}
