<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 用户搜索钩子（可选）——candidatePage 的"用户分页搜索"依赖集成方用户系统
 *
 * 对齐 Java IUserSearchProvider。
 * 未注入时：模型候选命中仍可用 UserProviderInterface::getUser 逐个映射；
 * 用户分页搜索返回明确错误。集成方实现后通过门面 setUserSearchProvider 注入。
 */
interface UserSearchProviderInterface
{
    /**
     * 用户分页搜索（query 透传 pageNum/pageSize/搜索条件 m_*）
     */
    public function page(PageQuery $query): PageResult;

    /**
     * 单用户信息（候选映射用）
     *
     * @return array<string, mixed>|null 含 userId/realName 等键；未找到返回 null
     */
    public function findById(string $userId): ?array;
}
