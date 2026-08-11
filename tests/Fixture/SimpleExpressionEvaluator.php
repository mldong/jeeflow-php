<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Fixture;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;

/**
 * 简单表达式求值器（测试用）
 *
 * 对齐 Java TestExpressionEvaluator 的 evalSimple 逻辑。
 * 支持：>, <, >=, <=, == 比较运算，变量自动替换。
 */
class SimpleExpressionEvaluator implements ExpressionEvaluatorInterface
{
    public function eval(string $expression, FlowData $variables): mixed
    {
        $expr = trim($expression);

        // 处理会签变量 #nrOfCompletedInstances / #nrOfInstances
        foreach (['#nrOfCompletedInstances', '#nrOfInstances'] as $placeholder) {
            if (str_contains($expr, $placeholder)) {
                foreach ($variables->keys() as $key) {
                    if (str_ends_with($key, ltrim($placeholder, '#'))) {
                        $val = $variables->get($key);
                        $expr = str_replace($placeholder, (string) ($val ?? 0), $expr);
                        break;
                    }
                }
            }
        }

        // 变量替换
        foreach ($variables->keys() as $key) {
            $val = $variables->get($key);
            if ($val !== null && str_contains($expr, $key)) {
                $expr = str_replace($key, (string) $val, $expr);
            }
        }

        return self::evaluateComparison($expr);
    }

    private static function evaluateComparison(string $expr): bool
    {
        try {
            if (str_contains($expr, '>=')) {
                [$a, $b] = explode('>=', $expr, 2);
                return (float) trim($a) >= (float) trim($b);
            }
            if (str_contains($expr, '<=')) {
                [$a, $b] = explode('<=', $expr, 2);
                return (float) trim($a) <= (float) trim($b);
            }
            if (str_contains($expr, '==')) {
                [$a, $b] = explode('==', $expr, 2);
                return trim($a) === trim($b);
            }
            if (str_contains($expr, '>')) {
                [$a, $b] = explode('>', $expr, 2);
                return (float) trim($a) > (float) trim($b);
            }
            if (str_contains($expr, '<')) {
                [$a, $b] = explode('<', $expr, 2);
                return (float) trim($a) < (float) trim($b);
            }
            return filter_var($expr, FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            return false;
        }
    }
}
