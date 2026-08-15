<?php

declare(strict_types=1);

namespace Jeeflow\Persist;

/**
 * 动态表写入组件 —— 对齐 Java DynamicTableWriter
 */
interface DynamicTableWriter
{
    /**
     * @param string[] $columns
     * @return string[] 表内实际存在的列（原名）
     */
    public function filterColumns(string $tableName, array $columns): array;

    public function insert(string $tableName, array $data): mixed;

    public function update(string $tableName, array $data, string $whereColumn, mixed $whereValue): int;

    public function exists(string $tableName, string $bizKey, mixed $bizKeyValue): bool;

    public function fillSystemFields(array &$data, bool $insert): void;
}
