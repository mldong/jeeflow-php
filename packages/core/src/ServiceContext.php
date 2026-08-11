<?php

declare(strict_types=1);

namespace Jeeflow\Core;

/**
 * 服务定位器 —— 对齐 Java ServiceContext
 *
 * 引擎通过此类查找 SPI 实现，不依赖任何容器/框架。
 */
class ServiceContext
{
    /** @var array<string, object> */
    private static array $services = [];

    public static function put(string $key, object $service): void
    {
        self::$services[$key] = $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $key
     * @return T|null
     */
    public static function find(string $key): ?object
    {
        return self::$services[$key] ?? null;
    }

    public static function remove(string $key): void
    {
        unset(self::$services[$key]);
    }

    public static function clear(): void
    {
        self::$services = [];
    }

    /** @return array<string, object> */
    public static function all(): array
    {
        return self::$services;
    }
}
