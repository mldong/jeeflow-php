<?php

declare(strict_types=1);

namespace Jeeflow\Core\Util;

/**
 * **表单数据派生（`f_`/`tf_` 前缀 → 带前缀 + 去前缀副本）的唯一一条判据** ——
 * 立法源头是 issues/15（`formData`/`taskFormData` 契约，对齐 boot3 `getFormData`/
 * `getTaskFormData`）：取 vars 中 prefix 前缀字段，输出带前缀 + 去前缀副本两键；空 ⇒ `{}`
 * （对象不是空数组）。
 *
 * 单点的直接动因是 issues/189：门面 detail 腿（{@see \Jeeflow\WebContract\JeeflowFacade::taskVo}）
 * 一直真算，而 todoList/doneList 的行投影在**仓储**（门面 pass-through），
 * {@see \Jeeflow\Core\Repository\InMemoryProcessRepository::taskToRow()} 与
 * `Jeeflow\RepositoryPDO\PdoProcessRepository::pagedTaskQuery()` 曾各把 `taskFormData`
 * 硬编码成 `(object)[]` ⇒ 同一栈同一键两种真相。与 {@see CcActorUtil} 同一立账：
 * 判据只此一处，门面与两仓储都从这里取，不再各抄复本。
 */
final class FormDataUtil
{
    /** @return array<string, mixed>|\stdClass */
    public static function of(array $variables, string $prefix): array|\stdClass
    {
        $result = [];
        foreach ($variables as $k => $v) {
            if (str_starts_with($k, $prefix)) {
                $result[$k] = $v;
                // 去前缀副本
                $result[substr($k, strlen($prefix))] = $v;
            }
        }
        return $result ?: (object)[];
    }
}
