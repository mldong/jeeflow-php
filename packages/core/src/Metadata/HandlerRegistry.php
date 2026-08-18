<?php

declare(strict_types=1);

namespace Jeeflow\Core\Metadata;

/**
 * 处理器元数据注册中心（设计器字典）——对齐 Java HandlerRegistry
 */
final class HandlerRegistry
{
    /** @var HandlerMeta[] */
    private array $items = [];

    public function __construct()
    {
        $this->registerBuiltIn();
    }

    /**
     * 内置 7 个 AssignmentHandler 元数据（对齐 Python BUILTIN_ASSIGNMENT_METAS / Java HandlerRegistry）
     * 注册名使用 Java 类全限定名（四语言通用约定）
     */
    private function registerBuiltIn(): void
    {
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OperatorAssignmentHandler', '流程发起人', -9999));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OrgUserAssignmentHandlers$ApplicantDeptLeaderAssignmentHandler', '发起人所属部门经理', 10));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OrgUserAssignmentHandlers$ApplicantDeptMainLeaderAssignmentHandler', '发起人所属部门分管领导', 20));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OrgUserAssignmentHandlers$DeptLeaderAssignmentHandler', '当前用户所属部门经理', 30));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OrgUserAssignmentHandlers$DeptMainLeaderAssignmentHandler', '当前用户所属部门分管领导', 40));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.FormFieldAssigneeHandler', '根据表单字段值分配参与者', 50));
        $this->register(new HandlerMeta('AssignmentHandler', 'com.mldong.jeeflow.interceptor.impl.OrgUserAssignmentHandlers$TaskRoleAssigneeHandler', '根据任务节点唯一编码关联角色分配参与者', 60));
    }

    public function register(HandlerMeta $meta): void
    {
        $this->items[] = $meta;
    }

    /**
     * @return HandlerMeta[]
     */
    public function listHandlers(?string $type = null, ?string $group = null): array
    {
        $out = [];
        foreach ($this->items as $item) {
            if ($type !== null && $item->type !== $type) {
                continue;
            }
            if ($group !== null && $item->group !== $group) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }
}
