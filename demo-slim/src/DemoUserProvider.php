<?php

declare(strict_types=1);

namespace Jeeflow\Demo;

use Jeeflow\Core\Spi\UserProviderInterface;

/**
 * 演示用户 SPI —— 对齐四端 demo 的 8 个具名用户（user1 永远是张三，leader 永远是李四）。
 * issues/124：u_* 变量族与 autoGenTitle 真名的来源。
 */
final class DemoUserProvider implements UserProviderInterface
{
    private const USERS = [
        'user1' => ['张三', '工程师'],
        'userA' => ['孙倩', '工程师'],
        'userB' => ['周明', '工程师'],
        'userC' => ['吴婷', '工程师'],
        'leader' => ['李四', '组长'],
        'manager' => ['王五', '经理'],
        'director' => ['赵六', '总监'],
        'boss' => ['钱七', '总经理'],
    ];

    public function getUser(string $userId): ?array
    {
        [$realName, $postName] = self::USERS[$userId] ?? ['用户' . $userId, '工程师'];
        return [
            'userId' => $userId,
            'realName' => $realName,
            'deptId' => null,
            'deptName' => null,
            'postId' => null,
            'postName' => $postName,
        ];
    }
}
