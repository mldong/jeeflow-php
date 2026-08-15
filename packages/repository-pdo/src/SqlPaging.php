<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

/**
 * 分页 LIMIT/OFFSET 内联拼接 —— issues/67
 *
 * MySQL 预处理（含 PDO emulate prepares）绑定 `LIMIT ?` 会变成 `LIMIT '5'` 或
 * mysqld_stmt_execute 直接失败。pageSize/offset 已是整数，内联进 SQL 即可。
 */
final class SqlPaging
{
    public static function clause(int $pageSize, int $offset): string
    {
        $limit = max(1, $pageSize);
        $off = max(0, $offset);
        return " LIMIT {$limit} OFFSET {$off}";
    }
}
