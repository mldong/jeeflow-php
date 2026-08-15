<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;

/**
 * 流程工具 —— 对齐 Java FlowUtil（本轮只补字段权限过滤，issues/26）
 */
final class FlowUtil
{
    public const PERMISSION_PREFIX = 'PERMISSION_';
    public const PERM_EDIT = 2;

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
