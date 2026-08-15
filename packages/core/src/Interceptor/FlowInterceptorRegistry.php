<?php

declare(strict_types=1);

namespace Jeeflow\Core\Interceptor;

/**
 * 运行时拦截器实例表。流程 JSON 里的名字是 Java FQCN（跨语言共用），
 * PHP 无法 Class.forName，必须在此注册实例。声明了但未注册 → 抛错（issues/60）。
 */
final class FlowInterceptorRegistry
{
    /** @var array<string, FlowInterceptor> */
    private static array $instances = [];

    public static function register(string $className, FlowInterceptor $instance): void
    {
        self::$instances[$className] = $instance;
    }

    public static function resolve(string $className): ?FlowInterceptor
    {
        return self::$instances[$className] ?? null;
    }

    public static function clear(): void
    {
        self::$instances = [];
    }
}
