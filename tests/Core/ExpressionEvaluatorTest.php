<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Tests\Fixture\SimpleExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * SimpleExpressionEvaluator 单元测试
 */
class ExpressionEvaluatorTest extends TestCase
{
    private SimpleExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new SimpleExpressionEvaluator();
    }

    // ═══ 比较运算 ═══

    public function testGreaterThan(): void
    {
        $vars = FlowData::of(['amount' => 2000]);
        $this->assertTrue($this->eval->eval('amount > 1000', $vars));
        $this->assertFalse($this->eval->eval('amount > 3000', $vars));
    }

    public function testLessThan(): void
    {
        $vars = FlowData::of(['amount' => 500]);
        $this->assertTrue($this->eval->eval('amount < 1000', $vars));
        $this->assertFalse($this->eval->eval('amount < 100', $vars));
    }

    public function testGreaterOrEqual(): void
    {
        $vars = FlowData::of(['amount' => 1000]);
        $this->assertTrue($this->eval->eval('amount >= 1000', $vars));
        $this->assertTrue($this->eval->eval('amount >= 999', $vars));
        $this->assertFalse($this->eval->eval('amount >= 1001', $vars));
    }

    public function testLessOrEqual(): void
    {
        $vars = FlowData::of(['amount' => 1000]);
        $this->assertTrue($this->eval->eval('amount <= 1000', $vars));
        $this->assertTrue($this->eval->eval('amount <= 1001', $vars));
        $this->assertFalse($this->eval->eval('amount <= 999', $vars));
    }

    public function testEqual(): void
    {
        $vars = FlowData::of(['status' => 1]);
        $this->assertTrue($this->eval->eval('status == 1', $vars));
        $this->assertFalse($this->eval->eval('status == 2', $vars));
    }

    // ═══ 变量替换 ═══

    public function testVariableSubstitution(): void
    {
        $vars = FlowData::of(['x' => 10, 'y' => 20]);
        $this->assertTrue($this->eval->eval('x < y', $vars));
        $this->assertFalse($this->eval->eval('x > y', $vars));
    }

    public function testMultipleVariables(): void
    {
        $vars = FlowData::of(['a' => 5, 'b' => 3, 'c' => 8]);
        $this->assertTrue($this->eval->eval('a > b', $vars));
        $this->assertTrue($this->eval->eval('c > a', $vars));
    }

    // ═══ 边界情况 ═══

    public function testZeroComparison(): void
    {
        $vars = FlowData::of(['count' => 0]);
        $this->assertTrue($this->eval->eval('count == 0', $vars));
        $this->assertFalse($this->eval->eval('count > 0', $vars));
    }

    public function testNegativeNumbers(): void
    {
        $vars = FlowData::of(['val' => -5]);
        $this->assertTrue($this->eval->eval('val < 0', $vars));
        $this->assertFalse($this->eval->eval('val > 0', $vars));
    }

    public function testDecimalComparison(): void
    {
        $vars = FlowData::of(['price' => 9.99]);
        $this->assertTrue($this->eval->eval('price < 10', $vars));
        $this->assertTrue($this->eval->eval('price > 9', $vars));
    }

    public function testBooleanResult(): void
    {
        $vars = FlowData::of(['flag' => 'true']);
        $this->assertTrue($this->eval->eval('flag', $vars));
    }

    public function testInvalidExpression(): void
    {
        $vars = FlowData::of(['a' => 1]);
        // 无效表达式应返回 false
        $this->assertFalse($this->eval->eval('invalid expression here', $vars));
    }

    // ═══ 会签变量 ═══

    public function testCountersignVariable(): void
    {
        // issues/165：`#裸名` 按生产 WfExpressionEvaluator 形状**精确查表**——
        // 旧断言喂的是 `task1_nrOfCompletedInstances` 靠夹具后缀桥桥接到裸名，
        // "测试绿生产红"由此而来；桥拆除后此处喂的键必须是求值上下文里真实存在的
        // （handler 修复后 buildCountersignVars 裸名+前缀键同挂，两形都能查到）。
        $vars = FlowData::of(['nrOfCompletedInstances' => 3]);
        $this->assertTrue($this->eval->eval('#nrOfCompletedInstances >= 3', $vars));
        $this->assertFalse($this->eval->eval('#nrOfCompletedInstances >= 4', $vars));
    }

    public function testCountersignVariableEqual(): void
    {
        $vars = FlowData::of(['nrOfInstances' => 5]);
        $this->assertTrue($this->eval->eval('#nrOfInstances == 5', $vars));
    }

    public function testCountersignPrefixKeyStillResolves(): void
    {
        // 前缀键形状（引擎内部命名）保留兼容：`#csv_cs1_...` 精确查表
        $vars = FlowData::of(['csv_cs1_nrOfCompletedInstances' => 2]);
        $this->assertTrue($this->eval->eval('#csv_cs1_nrOfCompletedInstances >= 2', $vars));
    }

    public function testCountersignBareNameMissingNeverTrue(): void
    {
        // 165 病根本形：条件引用裸名、上下文只有前缀键 ⇒ 恒 false
        $vars = FlowData::of(['csv_cs1_nrOfCompletedInstances' => 2]);
        $this->assertFalse($this->eval->eval('#nrOfCompletedInstances >= 2', $vars));
    }
}
