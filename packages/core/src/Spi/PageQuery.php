<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 分页查询参数 —— 对齐 Java PageQuery
 *
 * 包含分页参数 + 过滤条件列表 + 排序。
 */
class PageQuery
{
    private int $pageNum;
    private int $pageSize;
    private string $orderBy = '';

    /** @var array<int, array{column:string, op:string, value:mixed}> */
    private array $conditions = [];

    public function __construct(int $pageNum = 1, int $pageSize = 10)
    {
        $this->pageNum = max(1, $pageNum);
        $this->pageSize = max(1, $pageSize);
    }

    public function getPageNum(): int
    {
        return $this->pageNum;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getOffset(): int
    {
        return ($this->pageNum - 1) * $this->pageSize;
    }

    public function getOrderBy(): string
    {
        return $this->orderBy;
    }

    public function setOrderBy(string $orderBy): void
    {
        $this->orderBy = $orderBy;
    }

    /**
     * 添加过滤条件
     * @param string $column 列名（可含别名，如 "t.operator"）
     * @param string $op 操作符（EQ/LIKE/GT/LT/GE/LE/IN）
     * @param mixed $value 值
     */
    public function add(string $column, string $op, mixed $value): void
    {
        $this->conditions[] = ['column' => $column, 'op' => strtoupper($op), 'value' => $value];
    }

    /** @return array<int, array{column:string, op:string, value:mixed}> */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}
