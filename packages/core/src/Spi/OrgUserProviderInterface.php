<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 组织用户钩子（可选）——任务候选 candidateGroups 角色成员展开 + AssignmentHandler 部门领导取人
 *
 * 对齐 Java IOrgUserProvider（getCandidates 的 candidateGroups 分支 + AssignmentHandler SPI）。
 * 未注入时 candidateGroups 静默跳过（与 Java 无 IOrgUserProvider 时一致）。
 * 集成方实现后通过 ServiceContext::put(OrgUserProviderInterface::class, ...) 注册。
 */
interface OrgUserProviderInterface
{
    /**
     * 按角色标识取用户 id 列表
     *
     * @return string[]
     */
    public function findByRole(string $roleCode): array;

    /**
     * 查部门领导 userId 列表（对齐 Java IOrgUserProvider.findDeptLeaders）
     *
     * @return string[]
     */
    public function findDeptLeaders(string $deptId): array;

    /**
     * 查部门分管领导 userId 列表（对齐 Java IOrgUserProvider.findDeptMainLeaders）
     *
     * @return string[]
     */
    public function findDeptMainLeaders(string $deptId): array;
}
