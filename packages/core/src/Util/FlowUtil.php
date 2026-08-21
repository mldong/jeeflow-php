<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\UserProviderInterface;

/**
 * 流程工具 —— 对齐 Java FlowUtil
 */
final class FlowUtil
{
    public const PERMISSION_PREFIX = 'PERMISSION_';
    public const PERM_EDIT = 2;

    /**
     * 注入发起人用户信息到流程变量（对齐 Java FlowUtil.addUserInfoToArgs）
     *
     * 通过 ServiceContext 获取 UserProviderInterface，查询用户信息后注入 u_* 变量。
     * flow.auto / flow.admin 跳过注入；UserProvider 未注册时静默跳过。
     */
    public static function addUserInfoToArgs(string $operator, FlowData $args): void
    {
        if (FlowConst::AUTO_ID === $operator || FlowConst::ADMIN_ID === $operator) {
            return;
        }
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        if ($userProvider === null) {
            return;
        }
        $u = $userProvider->getUser($operator);
        if ($u === null) {
            return;
        }
        $args->set(FlowConst::USER_USER_ID, $u['userId'] ?? $operator);
        $args->set(FlowConst::USER_REAL_NAME, $u['realName'] ?? $operator);
        $args->set(FlowConst::USER_DEPT_ID, $u['deptId'] ?? null);
        $args->set(FlowConst::USER_DEPT_NAME, $u['deptName'] ?? null);
        $args->set(FlowConst::USER_POST_ID, $u['postId'] ?? null);
        $args->set(FlowConst::USER_POST_NAME, $u['postName'] ?? null);
    }

    /**
     * 生成自动标题（对齐 Java FlowUtil.addAutoGenTitle）
     *
     * 格式："{realName}的{displayName}-{yyyy-MM-dd HH:mm:ss}"
     */
    public static function addAutoGenTitle(string $displayName, FlowData $args): void
    {
        $realName = $args->get(FlowConst::USER_REAL_NAME) ?? '';
        $title = $realName . '的' . $displayName . '-' . date('Y-m-d H:i:s');
        $args->set(FlowConst::AUTO_GEN_TITLE, $title);
    }

    /**
     * 指定任务名是否为第一个任务节点（start 的直接后继）—— 对齐 Java FlowUtil.isFirstTaskName
     */
    public static function isFirstTaskName(ProcessModel $model, string $taskName): bool
    {
        $start = $model->getStart();
        if ($start === null) {
            return false;
        }
        foreach ($start->getOutputs() as $tm) {
            if (strcasecmp($tm->getTo(), $taskName) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function filterFieldByPerm(FlowData $args, ProcessModel $model, string $taskName): void
    {
        $node = $model->getNode($taskName);
        if (!$node instanceof TaskModel) {
            return;
        }
        $ext = $node->getExt();
        $hasPerm = false;
        foreach ($ext->keys() as $k) {
            if (str_starts_with((string) $k, self::PERMISSION_PREFIX)) {
                $hasPerm = true;
                break;
            }
        }
        if (!$hasPerm) {
            return;
        }
        foreach ($args->keys() as $key) {
            if (!str_starts_with($key, FlowConst::FORM_DATA_PREFIX) || strlen($key) <= strlen(FlowConst::FORM_DATA_PREFIX)) {
                continue;
            }
            $fieldName = substr($key, strlen(FlowConst::FORM_DATA_PREFIX));
            if (!self::isEditable($ext, $fieldName)) {
                $args->remove($key);
            }
        }
    }

    public static function isEditable(FlowData $fieldPerm, string $fieldName): bool
    {
        $perm = $fieldPerm->get(self::PERMISSION_PREFIX . FlowConst::FORM_DATA_PREFIX . $fieldName);
        if ($perm === null) {
            $perm = $fieldPerm->get(self::PERMISSION_PREFIX . $fieldName);
        }
        if ($perm === null) {
            return true;
        }
        return (int) $perm === self::PERM_EDIT;
    }
}
