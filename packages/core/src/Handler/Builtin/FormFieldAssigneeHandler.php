<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Spi\AssignmentHandlerInterface;

/**
 * 按表单字段值分配参与者（对齐 Java FormFieldAssigneeHandler，issues/16）
 *
 * 匹配规则：
 *  1. f_ 前缀优先：args["f_{taskName}"]
 *  2. 裸名回落：args[taskName]
 *  3. 编号后缀去除：taskName 以 _数字 结尾时去后缀匹配
 *
 * 字段值支持逗号分隔字符串 / 数组，取并集。
 */
class FormFieldAssigneeHandler implements AssignmentHandlerInterface
{
    public function assign(Execution $execution): ?string
    {
        $nodeModel = $execution->getNodeModel();
        $taskName = $nodeModel?->getName();
        if ($taskName === null || $taskName === '') {
            return null;
        }
        $args = $execution->getArgs();
        if ($args === null || $args->isEmpty()) {
            return null;
        }

        $fieldValue = $this->findFieldValue($args, $taskName);
        if ($fieldValue === null) {
            return null;
        }

        $ids = $this->collect($fieldValue);
        return $ids === [] ? null : implode(',', $ids);
    }

    private function findFieldValue($args, string $taskName): mixed
    {
        // f_ 前缀优先（issues/48 E20，对齐 Go/Java）
        $prefixed = 'f_' . $taskName;
        if ($args->has($prefixed)) {
            return $args->get($prefixed);
        }
        if ($args->has($taskName)) {
            return $args->get($taskName);
        }
        // 编号后缀去除（如 task_01 → task）
        if (preg_match('/^(.+?)_(\d+)$/', $taskName, $m)) {
            $base = $m[1];
            if ($args->has($base)) {
                return $args->get($base);
            }
        }
        return null;
    }

    /** @return string[] */
    private function collect(mixed $value): array
    {
        $ids = [];
        if (is_array($value) || $value instanceof \Traversable) {
            foreach ((array) $value as $item) {
                $this->add($ids, $item);
            }
        } else {
            $this->add($ids, $value);
        }
        return $ids;
    }

    /** @param string[] $ids */
    private function add(array &$ids, mixed $v): void
    {
        if ($v === null) return;
        foreach (explode(',', (string) $v) as $token) {
            $t = trim($token);
            if ($t !== '' && !in_array($t, $ids, true)) {
                $ids[] = $t;
            }
        }
    }
}
