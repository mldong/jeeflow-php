<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

class NoOpUserProvider implements UserProviderInterface
{
    public function getUser(string $userId): ?array
    {
        return [
            'userId' => $userId,
            'realName' => '',
            'deptId' => null,
            'deptName' => null,
            'postId' => null,
            'postName' => null,
        ];
    }
}
