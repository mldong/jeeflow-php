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

    /** 查生效中的委托（issues/82-12，issues/116 批次 D 定契，issues/123 定顺序）：
     *  **取行与裁决的顺序不可颠倒**（06 §4.5 条款 1.4）——先按主键 id 在流程作用域内取
     *  **最新一条**（不带生效判据过滤），再由四判据裁决**这一条**；内存仓与 SQL 仓对同一份
     *  数据给出同一结论（条款 5/6）：
     *  ① 作用域：先按当前流程名精确取，该作用域**一条记录都没有**才查 process_name 为 NULL/''
     *     的全流程兜底；精确作用域最新一条判否（含池空）后**仍要看**全流程作用域的最新一条（不得判否即止）；
     *  ② 时间窗 startTime/endTime，**一侧为 null/空即该侧不限**；
     *  ③ 自委托过滤 `surrogate <> operator`（自己委托给自己不生效）；
     *  ④ `enabled` **只认整数 1**，脏值不得当启用（0/2/null 一律不生效）。
     *  ⚠️ 不得"先按②③④过滤、剩下的才取最新"——那等于"历史上留过一条窗内委托就永久生效"，
     *  用户随后新建的窗外/停用/脏值/自委托记录都判不动它（issues/123）。
     *  最新一条不生效 ⇒ 返回 null，**不回落**到更旧那条。$time 缺省取当前时间。 */
    public function getSurrogate(string $operator, string $processName, ?string $time = null): ?array;

    /** 保存委托 */
    public function saveSurrogate(array $surrogate): string;

    /** 更新委托（issues/77） */
    public function updateSurrogate(array $surrogate): void;

    /** 删除委托 */
    public function removeSurrogate(int|string $id): void;
}
