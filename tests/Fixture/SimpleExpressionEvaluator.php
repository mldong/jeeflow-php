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

        // issues/165：`#变量` 引用按**生产 WfExpressionEvaluator 形状**精确查表
        // （`#key` ⇔ 变量表里的 `key`）。旧形状是 str_ends_with 后缀桥
        // （`#nrOfCompletedInstances` 桥接到 `csv_<node>_nrOf*`），会签门控裸名没进
        // 原料的单测也能绿——"测试绿生产红"由此而来，桥拆除后裸名格只有在
        // handler 真挂了裸名才可能绿。
        foreach ($variables->keys() as $key) {
            $val = $variables->get($key);
            $ref = '#' . $key;
            if ($val !== null && str_contains($expr, $ref)) {
                $expr = str_replace($ref, (string) $val, $expr);
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
