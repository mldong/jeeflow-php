<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\Domain\FlowData;

/**
 * 表达式求值 SPI —— 对齐 Java IExpressionEvaluator
 */
interface ExpressionEvaluatorInterface
{
    /**
     * 对给定的表达式求值
     *
     * @param string   $expression 表达式（如 "amount > 1000"）
     * @param FlowData $variables 流程变量
     * @return mixed 表达式求值结果
     */
    public function eval(string $expression, FlowData $variables): mixed;
}
