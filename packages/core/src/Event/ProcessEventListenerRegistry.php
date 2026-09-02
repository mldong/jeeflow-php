<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

/**
 * 流程事件监听器静态注册表 —— 仿本仓既有 FlowInterceptorRegistry 静态表模式
 *
 * 与 Java 用 ServiceContext 存监听器列表不同，PHP 这里用独立静态表（**不**改
 * ServiceContext 结构，保持 ServiceContext 纯 SPI 定位语义）。集成层在
 * ServiceProvider boot() 里 register() 即可（对齐 Java @PostConstruct put /
 * Go SetExtensions 的注册时机）。
 */
final class ProcessEventListenerRegistry
{
    /** @var ProcessEventListener[] */
    private static array $listeners = [];

    public static function register(ProcessEventListener $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** @return ProcessEventListener[] */
    public static function listeners(): array
    {
        return self::$listeners;
    }

    public static function clear(): void
    {
        self::$listeners = [];
    }
}
