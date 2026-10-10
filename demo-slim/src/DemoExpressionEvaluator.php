<?php

declare(strict_types=1);

namespace Jeeflow\Demo;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;

/**
 * demo 求值器——形状逐字对齐生产件（mldong-laravel-jeeflow 的 WfExpressionEvaluator）。
 *
 * issues/166 连带：种子 mixed-mode 的决策边是 `finalAmount > 5000 / <= 5000`，而本 demo
 * 此前**根本没注册**表达式求值器 SPI ⇒ 决策节点一律抛「未注册表达式求值器 SPI」，种子
 * FINISHED 行靠"失败逐条打日志不抛异常"苟活（F5/F8/F9 从来没真正办结过）。种子补
 * finalAmount（issues/166 A）后决策第一次被真实走到，这里必须把 SPI 装上。
 */
class DemoExpressionEvaluator implements ExpressionEvaluatorInterface
{
    public function eval(string $expression, FlowData $variables): mixed
    {
        $expr = trim($expression);
        if ($expr === '') {
            return true;
        }

        // Handle logical OR (lowest precedence)
        if (preg_match('/^(.+?)\s*\|\|\s*(.+)$/', $expr, $m)) {
            return $this->eval($m[1], $variables) || $this->eval($m[2], $variables);
        }

        // Handle logical AND
        if (preg_match('/^(.+?)\s*&&\s*(.+)$/', $expr, $m)) {
            return $this->eval($m[1], $variables) && $this->eval($m[2], $variables);
        }

        // Handle comparison operators
        foreach (['>=', '<=', '!=', '==', '>', '<'] as $op) {
            $escaped = preg_quote($op, '/');
            if (preg_match('/^(.+?)\s*' . $escaped . '\s*(.+)$/', $expr, $m)) {
                $left = $this->resolveValue(trim($m[1]), $variables);
                $right = $this->resolveValue(trim($m[2]), $variables);
                return $this->compare($left, $right, $op);
            }
        }

        // Handle negation
        if (str_starts_with($expr, '!')) {
            return !$this->eval(substr($expr, 1), $variables);
        }

        // Single variable / truthy check
        $val = $this->resolveValue($expr, $variables);
        return (bool) $val;
    }

    private function resolveValue(string $token, FlowData $variables): mixed
    {
        // Strip ${...} wrapper
        if (preg_match('/^\$\{(.+)\}$/', $token, $m)) {
            $token = $m[1];
        }

        // String literal
        if (preg_match("/^['\"](.*)['\"]$/", $token, $m)) {
            return $m[1];
        }

        // Numeric literal
        if (is_numeric($token)) {
            return str_contains($token, '.') ? (float) $token : (int) $token;
        }

        // Boolean literal
        if (strtolower($token) === 'true') return true;
        if (strtolower($token) === 'false') return false;
        if (strtolower($token) === 'null') return null;

        // Countersign built-in variables（#裸名 精确查表——issues/165 生产形状）
        if (str_starts_with($token, '#')) {
            $varName = substr($token, 1);
            $val = $variables->get($varName);
            return $val ?? 0;
        }

        // Flow variable
        return $variables->get($token);
    }

    private function compare(mixed $left, mixed $right, string $op): bool
    {
        // Numeric comparison if both are numeric
        if (is_numeric($left) && is_numeric($right)) {
            $left = (float) $left;
            $right = (float) $right;
        }

        return match ($op) {
            '==' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => false,
        };
    }
}
