<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Spi\AssignmentHandlerInterface;

/**
 * 参与者处理器注册表（对齐 Java Class.forName + AssignmentHandler 模式）
 *
 * Java 端通过 Class.forName(handlerClass) 反射实例化 handler；
 * PHP 端改为注册表模式：集成方在 ServiceProvider 中 register(handlerName, handler)。
 * handlerName 与 Java 全限定类名一致，保证流程定义 JSON 跨语言可移植。
 */
class AssignmentHandlerRegistry
{
    /** @var array<string, AssignmentHandlerInterface> */
    private array $handlers = [];

    public function register(string $name, AssignmentHandlerInterface $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function resolve(string $name): ?AssignmentHandlerInterface
    {
        return $this->handlers[$name] ?? null;
    }

    /** @return string[] 已注册的 handler 名称 */
    public function listHandlers(): array
    {
        return array_keys($this->handlers);
    }
}
