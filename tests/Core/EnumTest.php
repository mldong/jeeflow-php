<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
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

    // ═══ PerformType ═══

    public function testPerformTypeValues(): void
    {
        $this->assertSame(0, PerformType::NORMAL);
        $this->assertSame(1, PerformType::COUNTERSIGN);
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
