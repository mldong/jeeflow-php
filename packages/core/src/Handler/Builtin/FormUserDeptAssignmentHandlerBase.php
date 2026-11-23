<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler\Builtin;

use Jeeflow\Core\Execution;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\AssignmentHandlerInterface;
use Jeeflow\Core\Spi\OrgUserProviderInterface;
use Jeeflow\Core\Spi\UserProviderInterface;

/**
 * 「表单指定用户 → 该用户所属部门领导」两枚内置处理类的公共腿。
 *
 * 场景（参照 mldong-fastapi-wf 提交 fe9233d）：HR 代员工提交、代理申请——表单里给出被代理人的
 * 用户 id，取**该员工**所属部门的经理／分管领导，与当前操作人无关。
 *
 * 字段查找走 {@see findFormUserFieldValue} 的四档并集；取人走 user → dept → leader。
 * 字段缺失／值为空／用户不存在／无部门／部门无领导／SPI 未注册 ⇒ 返回 null，不抛异常
 * （与同目录现存 handler 同形）。
 */
abstract class FormUserDeptAssignmentHandlerBase implements AssignmentHandlerInterface
{
    /** true ⇒ 取分管领导（findDeptMainLeaders），false ⇒ 取部门经理（findDeptLeaders） */
    abstract protected function mainLeader(): bool;

    public function assign(Execution $execution): ?string
    {
        $taskName = $execution->getNodeModel()?->getName();
        if ($taskName === null || $taskName === '') {
            return null;
        }
        $userId = self::firstFormUserId(self::findFormUserFieldValue($execution->getArgs(), $taskName));
        if ($userId === null) {
            return null;
        }
        $deptId = $this->deptIdOf($userId);
        if ($deptId === null || $deptId === '') {
            return null;
        }
        $org = ServiceContext::find(OrgUserProviderInterface::class);
        if ($org === null) {
            return null;
        }
        $ids = $this->mainLeader() ? $org->findDeptMainLeaders($deptId) : $org->findDeptLeaders($deptId);
        return ($ids === null || $ids === []) ? null : implode(',', $ids);
    }

    private function deptIdOf(string $userId): ?string
    {
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        if ($userProvider === null) {
            return null;
        }
        $u = $userProvider->getUser($userId);
        return $u['deptId'] ?? null;
    }

    /**
     * 四档并集查找（顺序即优先级）：
     *  1. f_<nodeKey>  2. <nodeKey>  3. 含 # 时取右半：先裸名、再 f_  4. 匹配 _NN 时取 base：先 f_、再裸名
     *
     * 与 FormFieldAssigneeHandler::findFieldValue 的差别只在 3、4 两档各补了 f_ 变体
     * （参照实现没有 f_ 分支，jeeflow 家族变量存 f_ 必须有）。现存 handler 仍走它们自己的查找。
     *
     * @param mixed $args FlowData 或 null
     */
    public static function findFormUserFieldValue($args, string $nodeKey): mixed
    {
        if ($args === null || $nodeKey === '' || !method_exists($args, 'has')) {
            return null;
        }
        if ($args->has('f_' . $nodeKey)) {
            return $args->get('f_' . $nodeKey);
        }
        if ($args->has($nodeKey)) {
            return $args->get($nodeKey);
        }
        $hash = strrpos($nodeKey, '#');
        if ($hash !== false) {
            $field = substr($nodeKey, $hash + 1);
            if ($field !== '' && $args->has($field)) {
                return $args->get($field);
            }
            if ($field !== '' && $args->has('f_' . $field)) {
                return $args->get('f_' . $field);
            }
        }
        if (preg_match('/^(.+?)_(\d+)$/', $nodeKey, $m) === 1) {
            $base = $m[1];
            if ($args->has('f_' . $base)) {
                return $args->get('f_' . $base);
            }
            if ($args->has($base)) {
                return $args->get($base);
            }
        }
        return null;
    }

    /**
     * 取**第一个**非空用户 id（逗号串／数组同样只取第一个，多值语义不在本轮承诺）。
     * 判空只比空串 ⇒ '0' 是合法 id（不被 PHP 弱判据吃掉）。
     */
    public static function firstFormUserId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $items = is_array($value) ? $value : explode(',', (string) $value);
        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            $id = trim((string) $item);
            if ($id !== '') {
                return $id;
            }
        }
        return null;
    }
}
