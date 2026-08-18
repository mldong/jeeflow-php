<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

use Jeeflow\Core\Execution;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\AssignmentHandlerInterface;
use Jeeflow\Core\Spi\OrgUserProviderInterface;
use Jeeflow\Core\Spi\UserProviderInterface;

/**
 * 发起人部门领导取人（对齐 Java OrgUserAssignmentHandlers.ApplicantDeptLeaderAssignmentHandler）
 *
 * 逻辑：取发起人 → 查其 deptId → 查该部门领导列表。
 * 依赖 OrgUserProviderInterface + UserProviderInterface SPI。
 */
class ApplicantDeptLeaderAssignmentHandler implements AssignmentHandlerInterface
{
    public function assign(Execution $execution): ?string
    {
        $applicant = $execution->getProcessInstance()?->getOperator();
        $deptId = $this->deptIdOf($applicant);
        if ($deptId === null || $deptId === '') {
            return null;
        }
        $org = ServiceContext::find(OrgUserProviderInterface::class);
        if ($org === null) {
            return null;
        }
        $ids = $org->findDeptLeaders($deptId);
        return ($ids === null || $ids === []) ? null : implode(',', $ids);
    }

    private function deptIdOf(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        if ($userProvider === null) {
            return null;
        }
        $u = $userProvider->getUser($userId);
        return $u['deptId'] ?? null;
    }
}
