<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 用户信息 SPI —— 对齐 Java IUserProvider
 */
interface UserProviderInterface
{
    /**
     * 获取用户信息
     * @return array{userId:string,realName:string,deptId:?string,deptName:?string,postId:?string,postName:?string}|null
     */
    public function getUser(string $userId): ?array;
}
