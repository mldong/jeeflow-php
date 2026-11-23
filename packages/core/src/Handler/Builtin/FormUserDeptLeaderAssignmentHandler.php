<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

/**
 * 表单指定用户所属部门的经理（注册名与 Java 顶级类全限定名一致，跨语言流程 JSON 通用）
 */
class FormUserDeptLeaderAssignmentHandler extends FormUserDeptAssignmentHandlerBase
{
    protected function mainLeader(): bool
    {
        return false;
    }
}
