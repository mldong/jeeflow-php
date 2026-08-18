<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\Execution;

/**
 * 参与者处理器接口（对齐 Java AssignmentHandler）
 *
 * 实现类通过 AssignmentHandlerRegistry 注册，由 CreateTaskHandler 在
 * resolveActors 阶段调用。返回逗号分隔的 userId 字符串，或 null 表示未解析。
 */
interface AssignmentHandlerInterface
{
    /**
     * 解析当前节点的参与者
     *
     * @return string|null 逗号分隔的 userId，或 null 表示未解析
     */
    public function assign(Execution $execution): ?string;
}
