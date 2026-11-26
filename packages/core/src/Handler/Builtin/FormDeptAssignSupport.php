<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

use Jeeflow\Core\Execution;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\OrgUserProviderInterface;

/**
 * 表单指定 1–N 个部门（分管）领导的共用取数档（对齐 Java FormDeptAssignSupport）。
 *
 * 字段查找直接复用 issues/254 的四档并集；部门 ID 与最终 userId 均按首次出现顺序严格去重。
 * 字段缺失／值为空／部门无领导／SPI 未注册／节点未设置 ⇒ null，不抛异常。
 */
final class FormDeptAssignSupport
{
    private function __construct() {}

    public static function resolve(Execution $execution, bool $mainLeader): ?string
    {
        $nodeKey = $execution->getNodeModel()?->getName();
        if ($nodeKey === null || $nodeKey === '') {
            return null;
        }

        $deptIds = self::deptIds(
            FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($execution->getArgs(), $nodeKey)
        );
        if ($deptIds === []) {
            return null;
        }

        $org = ServiceContext::find(OrgUserProviderInterface::class);
        if ($org === null) {
            return null;
        }

        $userIds = [];
        foreach ($deptIds as $deptId) {
            try {
                $leaders = $mainLeader ? $org->findDeptMainLeaders($deptId) : $org->findDeptLeaders($deptId);
            } catch (\Throwable) {
                continue;
            }
            foreach ($leaders as $leader) {
                $userId = self::normalizeId($leader);
                if ($userId !== null && !in_array($userId, $userIds, true)) {
                    $userIds[] = $userId;
                }
            }
        }

        return $userIds === [] ? null : implode(',', $userIds);
    }

    /**
     * 字符串按英文逗号拆；数组／集合逐元素读取，元素不二次拆逗号。
     *
     * @return string[]
     */
    private static function deptIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            $tokens = $value;
        } elseif ($value instanceof \Traversable) {
            $tokens = iterator_to_array($value, false);
        } elseif (is_scalar($value) || $value instanceof \Stringable) {
            $tokens = explode(',', (string) $value);
        } else {
            return [];
        }

        $deptIds = [];
        foreach ($tokens as $token) {
            $deptId = self::normalizeId($token);
            if ($deptId !== null && !in_array($deptId, $deptIds, true)) {
                $deptIds[] = $deptId;
            }
        }
        return $deptIds;
    }

    /** trim 后只丢真空值；'0' 与数字 0 均归一为合法字符串 '0'。 */
    private static function normalizeId(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_resource($value)) {
            return null;
        }
        if (is_object($value) && !$value instanceof \Stringable) {
            return null;
        }
        $id = trim((string) $value);
        return $id === '' ? null : $id;
    }
}
