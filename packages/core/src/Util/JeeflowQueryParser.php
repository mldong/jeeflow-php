<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

use Jeeflow\Core\Spi\PageQuery;

/**
 * 查询解析器 —— 解析 m_ 前缀过滤参数
 *
 * 对齐 Java JeeflowQueryParser。
 *
 * 格式：m_{op}_{column} 或 m_{alias}_{op}_{column}
 * 例：m_EQ_taskName=leaveApply → t.task_name EQ 'leaveApply'
 *     m_t_LIKE_displayName=请假 → t.display_name LIKE '%请假%'
 */
class JeeflowQueryParser
{
    /**
     * 从 args 解析出 PageQuery
     * @param array<string, mixed> $args
     */
    public function parse(array $args): PageQuery
    {
        $pageNum = (int) ($args['pageNum'] ?? 1);
        $pageSize = (int) ($args['pageSize'] ?? 10);
        $orderBy = (string) ($args['orderBy'] ?? '');

        $query = new PageQuery(max(1, $pageNum), max(1, $pageSize));
        if ($orderBy !== '') {
            $query->setOrderBy($orderBy);
        }

        foreach ($args as $key => $value) {
            if (!str_starts_with($key, 'm_')) continue;
            $parsed = $this->parseCondition($key, $value);
            if ($parsed !== null) {
                $query->add($parsed['column'], $parsed['op'], $parsed['value']);
            }
        }

        return $query;
    }

    /**
     * 解析单个 m_ 条件
     * @return array{column:string, op:string, value:mixed}|null
     */
    private function parseCondition(string $key, mixed $value): ?array
    {
        // 去掉 m_ 前缀
        $rest = substr($key, 2);
        $parts = explode('_', $rest);

        if (count($parts) < 2) return null;

        // 判断格式：m_{op}_{column} 或 m_{alias}_{op}_{column}
        $knownOps = ['EQ', 'LIKE', 'GT', 'LT', 'GE', 'LE', 'IN'];

        if (count($parts) === 2) {
            // m_{op}_{column}
            $op = strtoupper($parts[0]);
            $column = $parts[1];
            if (!in_array($op, $knownOps, true)) return null;
            return ['column' => 't.' . $this->toSnake($column), 'op' => $op, 'value' => $value];
        }

        if (count($parts) >= 3) {
            // m_{alias}_{op}_{column}
            $alias = $parts[0];
            $op = strtoupper($parts[1]);
            $column = implode('_', array_slice($parts, 2));
            if (!in_array($op, $knownOps, true)) {
                // 可能是 m_{op}_{column_with_underscores}
                $op = strtoupper($parts[0]);
                if (in_array($op, $knownOps, true)) {
                    $column = implode('_', array_slice($parts, 1));
                    return ['column' => 't.' . $this->toSnake($column), 'op' => $op, 'value' => $value];
                }
                return null;
            }
            $prefix = ($alias === 't') ? 't.' : $alias . '.';
            return ['column' => $prefix . $this->toSnake($column), 'op' => $op, 'value' => $value];
        }

        return null;
    }

    /**
     * camelCase → snake_case
     */
    private function toSnake(string $input): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $input));
    }
}
