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

    /** 查生效中的委托（issues/82-12，issues/116 批次 D 定契）：四判据必须**全部**满足，
     *  且内存仓与 SQL 仓对同一份数据给出同一结论（06 §4.5 条款 5/6）——
     *  ① 空 processName 全流程兜底（先精确、未命中再查 process_name 为 NULL/'' 的兜底行）；
     *  ② 时间窗 startTime/endTime，**一侧为 null/空即该侧不限**；
     *  ③ 自委托过滤 `surrogate <> operator`（自己委托给自己不生效）；
     *  ④ `enabled` 只认整数 1，脏值不得当启用。
     *  多条同时命中取**主键 id 最大**那条（SQL 侧 `ORDER BY id DESC`，内存侧不得取遍历首条，条款 1.4）。
     *  无命中返回 null。$time 缺省取当前时间。 */
    public function getSurrogate(string $operator, string $processName, ?string $time = null): ?array;

    /** 保存委托 */
    public function saveSurrogate(array $surrogate): string;

    /** 更新委托（issues/77） */
    public function updateSurrogate(array $surrogate): void;

    /** 删除委托 */
    public function removeSurrogate(int|string $id): void;
}
