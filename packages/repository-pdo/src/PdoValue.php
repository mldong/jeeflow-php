<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

/**
 * PDO 行值转换 —— issues/68
 *
 * MySQL PDO 对能放进 PHP int 的数值主键返回 int，领域对象 setter 是 ?string。
 */
final class PdoValue
{
    public static function strId(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }
}
