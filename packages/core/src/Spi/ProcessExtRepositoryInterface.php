<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 扩展仓储 SPI —— 对齐 Java IProcessExtRepository
 *
 * 管理流程设计稿（草稿）和委托代理。
 * 未接入时，processDesign/* 和 processSurrogate/* action 报错。
 */
interface ProcessExtRepositoryInterface
{
    // ── 流程设计 ──

    /** 设计分页 */
    public function pageDesigns(PageQuery $query): PageResult;

    /** 设计详情 */
    public function findDesignById(int|string $id): ?array;

    /** 保存设计（新建或更新基本信息） */
    public function saveDesign(array $design): string;

    /** 更新设计基本信息 */
    public function updateDesign(array $design): void;

    /** 保存设计稿快照（历史） */
    public function saveDesignHis(int|string $designId, string $content, ?string $operator = null): void;

    /** 获取设计最新快照 */
    public function findLatestDesignHis(int|string $designId): ?array;

    /** 获取设计历史快照列表 */
    public function findDesignHisList(int|string $designId): array;

    /** 删除设计 */
    public function removeDesign(int|string $id): void;

    /** 更新设计部署状态 */
    public function updateDesignDeployed(int|string $designId, int $isDeployed): void;

    /** 按类型列出所有设计（listByType） */
    public function listDesignsByType(): array;

    // ── 委托代理 ──

    /** 委托分页 */
    public function pageSurrogates(PageQuery $query): PageResult;

    /** 委托详情（issues/77） */
    public function findSurrogateById(int|string $id): ?array;

    /** 保存委托 */
    public function saveSurrogate(array $surrogate): string;

    /** 更新委托（issues/77） */
    public function updateSurrogate(array $surrogate): void;

    /** 删除委托 */
    public function removeSurrogate(int|string $id): void;
}
