<?php

declare(strict_types=1);

namespace Jeeflow\WebPsr;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 响应工厂 —— 基于 nyholm/psr7
 *
 * 单例模式，供 JeeflowRequestHandler 生成 JSON 响应。
 * 也可替换为任何 PSR-17 ResponseFactoryInterface 实现。
 */
class ResponseFactory
{
    private static ?ResponseFactory $instance = null;

    private ResponseFactoryInterface $factory;

    private function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 允许注入自定义 PSR-17 工厂（用于测试或其他 PSR-7 实现）
     */
    public static function setFactory(ResponseFactoryInterface $factory): void
    {
        $instance = self::getInstance();
        $instance->factory = $factory;
    }

    public function createResponse(int $status = 200): ResponseInterface
    {
        return $this->factory->createResponse($status);
    }
}
