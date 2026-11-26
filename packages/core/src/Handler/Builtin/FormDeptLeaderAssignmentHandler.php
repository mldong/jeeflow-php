<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Spi\AssignmentHandlerInterface;

/**
 * 表单指定 1–N 个部门，取这些部门的负责人。
 */
class FormDeptLeaderAssignmentHandler implements AssignmentHandlerInterface
{
    public function assign(Execution $execution): ?string
    {
        return FormDeptAssignSupport::resolve($execution, false);
    }
}
