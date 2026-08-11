<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 分页结果 —— 对齐 Java PageResult
 */
class PageResult
{
    private int $pageNum;
    private int $pageSize;
    private int $recordCount;
    private array $rows;

    public function __construct(int $pageNum, int $pageSize, int $recordCount, array $rows)
    {
        $this->pageNum = $pageNum;
        $this->pageSize = $pageSize;
        $this->recordCount = $recordCount;
        $this->rows = $rows;
    }

    public function getPageNum(): int
    {
        return $this->pageNum;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    public function getTotalPage(): int
    {
        return $this->pageSize > 0 ? (int) ceil($this->recordCount / $this->pageSize) : 0;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function toArray(): array
    {
        return [
            'pageNum' => $this->pageNum,
            'pageSize' => $this->pageSize,
            'recordCount' => $this->recordCount,
            'totalPage' => $this->getTotalPage(),
            'rows' => $this->rows,
        ];
    }
}
