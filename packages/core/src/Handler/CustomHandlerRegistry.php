<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

/**
 * 记录类（自定义）节点处理器注册表（对齐 java `Class.forName(clazz)` 的 PHP 替身）
 *
 * 为什么是注册表而不是反射：java 端 `CustomModel.exec` 走
 * `Class.forName(clazz.trim()).getDeclaredConstructor().newInstance()`，PHP 没有 JVM 类路径
 * 可解析，而流程定义 JSON 是八栈共享夹具（`flows/08-custom-node.json` 的 `clazz` 就是一个
 * Java 全限定类名）⇒ 本栈照 {@see AssignmentHandlerRegistry} 已在的形状：集成方按
 * `clazz` 原样字符串注册实例，保证流程定义跨语言可移植。
 *
 * 挂点是**既有**的 `ServiceContext` 定位器（与 `AssignmentHandlerRegistry`／
 * `SurrogateInterceptor::CONTEXT_KEY` 同一条腿），不另起一套容器：
 *
 *   ServiceContext::put(CustomHandlerRegistry::class, $registry = new CustomHandlerRegistry());
 *   $registry->register('com.mldong.jeeflow.test.TestCustomHandler', new MyHandler());
 *
 * 未注册本注册表 ⇒ 视为"没有任何已注册的处理器"，custom 节点按 spec 02 §6.2 第 2 条
 * 记日志＋照常落历史行＋续流，**不打断建单**。
 *
 * 接受的处理器形状（{@see \Jeeflow\Core\Spi\CustomHandlerInterface} 的注释里有逐条语义）：
 * 1. 实现 `CustomHandlerInterface` —— `handle(Execution, array $args): mixed`；
 * 2. 实现既有 `HandlerInterface` —— `handle(Execution): void`（不写返回值，java `IHandler` 支同形）；
 * 3. 任意对象 ＋ `properties.methodName` —— 引擎反射调用那个方法（java 非 `IHandler` 支同形）；
 *    `methodName` 未配时对象也可为 `Closure`／实现了 `__invoke` 的对象。
 */
class CustomHandlerRegistry
{
    /** @var array<string, object> */
    private array $handlers = [];

    public function register(string $name, object $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    /**
     * 按 `clazz` 原样字符串解析处理器；未注册／名字为空 ⇒ null
     * （null 是"未解析到"而不是"解析失败"，日志分档由 {@see \Jeeflow\Core\Model\CustomModel} 负责）。
     */
    public function resolve(string $name): ?object
    {
        if ($name === '') {
            return null;
        }
        return $this->handlers[$name] ?? null;
    }

    /** @return string[] 已注册的处理器名称（排障/自检用） */
    public function listHandlers(): array
    {
        return array_keys($this->handlers);
    }
}
