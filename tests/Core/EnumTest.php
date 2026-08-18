<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Enum\TaskType;
use PHPUnit\Framework\TestCase;

/**
 * 枚举常量单元测试
 */
class EnumTest extends TestCase
{
    // ═══ ProcessInstanceState ═══

    public function testProcessInstanceStateValues(): void
    {
        $this->assertSame(10, ProcessInstanceState::DOING);
        $this->assertSame(20, ProcessInstanceState::FINISHED);
        $this->assertSame(30, ProcessInstanceState::WITHDRAW);
        $this->assertSame(40, ProcessInstanceState::INTERRUPT);
        $this->assertSame(45, ProcessInstanceState::REJECTED);
        $this->assertSame(50, ProcessInstanceState::PENDING);
        $this->assertSame(99, ProcessInstanceState::ABANDON);
    }

    public function testProcessInstanceStateLabels(): void
    {
        $this->assertSame('进行中', ProcessInstanceState::label(ProcessInstanceState::DOING));
        $this->assertSame('已完成', ProcessInstanceState::label(ProcessInstanceState::FINISHED));
        $this->assertSame('已撤回', ProcessInstanceState::label(ProcessInstanceState::WITHDRAW));
        $this->assertSame('已驳回', ProcessInstanceState::label(ProcessInstanceState::REJECTED));
        $this->assertStringContainsString('未知', ProcessInstanceState::label(999));
    }

    // ═══ ProcessTaskState ═══

    public function testProcessTaskStateValues(): void
    {
        $this->assertSame(10, ProcessTaskState::DOING);
        $this->assertSame(20, ProcessTaskState::FINISHED);
        $this->assertSame(30, ProcessTaskState::WITHDRAW);
        $this->assertSame(40, ProcessTaskState::INTERRUPT);
        $this->assertSame(50, ProcessTaskState::PENDING);
        $this->assertSame(99, ProcessTaskState::ABANDON);
    }

    // ═══ SubmitType ═══

    public function testSubmitTypeValues(): void
    {
        $this->assertSame(0, SubmitType::APPLY);
        $this->assertSame(1, SubmitType::AGREE);
        $this->assertSame(2, SubmitType::REJECT);
        $this->assertSame(3, SubmitType::ROLLBACK);
        $this->assertSame(4, SubmitType::JUMP);
        $this->assertSame(5, SubmitType::RE_APPLY);
        $this->assertSame(6, SubmitType::ROLLBACK_TO_OPERATOR);
        $this->assertSame(20, SubmitType::COUNTERSIGN_DISAGREE);
    }

    public function testSubmitTypeLabels(): void
    {
        $this->assertSame('发起申请', SubmitType::label(SubmitType::APPLY));
        $this->assertSame('同意申请', SubmitType::label(SubmitType::AGREE));
        $this->assertSame('拒绝申请', SubmitType::label(SubmitType::REJECT));
        $this->assertSame('退回上一步', SubmitType::label(SubmitType::ROLLBACK));
        $this->assertSame('跳转', SubmitType::label(SubmitType::JUMP));
        $this->assertSame('重新提交', SubmitType::label(SubmitType::RE_APPLY));
        $this->assertSame('退回发起人', SubmitType::label(SubmitType::ROLLBACK_TO_OPERATOR));
        $this->assertStringContainsString('未知', SubmitType::label(999));
    }

    // ═══ TaskType ═══

    public function testTaskTypeValues(): void
    {
        $this->assertSame(0, TaskType::MAIN);
        $this->assertSame(1, TaskType::ASSIST);
        $this->assertSame(2, TaskType::RECORD);
    }

    public function testTaskTypeLabels(): void
    {
        $this->assertSame('主办', TaskType::label(TaskType::MAIN));
        $this->assertSame('协办', TaskType::label(TaskType::ASSIST));
        $this->assertSame('记录', TaskType::label(TaskType::RECORD));
        $this->assertStringContainsString('未知', TaskType::label(999));
    }

    // ═══ PerformType ═══

    public function testPerformTypeValues(): void
    {
        $this->assertSame(0, PerformType::NORMAL);
        $this->assertSame(1, PerformType::COUNTERSIGN);
    }

    public function testPerformTypeLabels(): void
    {
        $this->assertSame('普通参与', PerformType::label(PerformType::NORMAL));
        $this->assertSame('会签参与', PerformType::label(PerformType::COUNTERSIGN));
        $this->assertStringContainsString('未知', PerformType::label(999));
    }

    public function testPerformTypeFromInt(): void
    {
        $this->assertSame(0, PerformType::from(0));
        $this->assertSame(1, PerformType::from(1));
    }

    public function testPerformTypeFromString(): void
    {
        $this->assertSame(0, PerformType::from('0'));
        $this->assertSame(1, PerformType::from('1'));
        $this->assertSame(1, PerformType::from('ALL'));
        $this->assertSame(1, PerformType::from('countersign'));
        $this->assertSame(1, PerformType::from('COUNTERSIGN'));
    }

    // ═══ CountersignType ═══

    public function testCountersignTypeValues(): void
    {
        $this->assertSame(0, CountersignType::PARALLEL);
        $this->assertSame(1, CountersignType::SERIAL);
    }

    public function testCountersignTypeLabels(): void
    {
        $this->assertSame('并行会签', CountersignType::label(CountersignType::PARALLEL));
        $this->assertSame('串行会签', CountersignType::label(CountersignType::SERIAL));
        $this->assertStringContainsString('未知', CountersignType::label(999));
    }

    // ═══ FlowConst ═══

    public function testFlowConstValues(): void
    {
        $this->assertSame('submitType', FlowConst::SUBMIT_TYPE);
        $this->assertIsString(FlowConst::FORM_DATA_PREFIX);
        $this->assertSame('f_', FlowConst::FORM_DATA_PREFIX);
        $this->assertIsString(FlowConst::CC_ACTORS);
        $this->assertIsString(FlowConst::CC_ACTORS_START);
        $this->assertIsString(FlowConst::NEXT_NODE_OPERATOR);
        $this->assertIsString(FlowConst::BUSINESS_NO);
    }

    public function testFlowConstAutoId(): void
    {
        $this->assertSame('flow.auto', FlowConst::AUTO_ID);
        $this->assertSame('flow.admin', FlowConst::ADMIN_ID);
    }

    public function testFlowConstCountersignVars(): void
    {
        $this->assertIsString(FlowConst::COUNTERSIGN_VARIABLE_PREFIX);
        $this->assertIsString(FlowConst::NR_OF_INSTANCES);
        $this->assertIsString(FlowConst::NR_OF_ACTIVATE_INSTANCES);
        $this->assertIsString(FlowConst::NR_OF_COMPLETED_INSTANCES);
    }
}