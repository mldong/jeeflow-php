<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\Execution;

/**
 * 记录类（自定义）节点处理器 SPI —— 流程定义 `properties.clazz` 按名解析的目标
 * （issues/142 A 批 · spec 02-flow-definition.md §6.1／§6.2，owner 2026-09-30 逐条拍）。
 *
 * 形状基准＝java `IHandler.handle(Execution)`／python `ICustomHandler.handle(...)`／
 * c# `Context.CustomHandlers`。java 用 `Class.forName(clazz)` 反射实例化，**PHP 没有 JVM
 * 类路径可反射**（共享夹具 `flows/08-custom-node.json` 里写的就是
 * `com.mldong.jeeflow.test.TestCustomHandler` 这种 Java 类名），故与 python/c# 同策：
 * 集成方按 `clazz` 原样字符串把实例注册进
 * {@see \Jeeflow\Core\Handler\CustomHandlerRegistry}，引擎按名解析后调用。
 *
 * 两条腿的返回值语义（对齐 java `CustomModel` 的 `var` 那支）：
 * - 返回**非 null** ⇒ 写进执行变量，键＝`properties.val`（未配则 `FlowConst::CUSTOM_RETURN_VAL`
 *   = `custom_return_val`，那个此前全仓零调用者的常量就是这一条接上的）；
 * - 返回 `null` ⇒ 不写键（夹具处理器自己往 execution 里塞值的 java `IHandler` 支同此形状）。
 *
 * 只想"办事不留返回值"的处理器可以直接实现既有
 * {@see \Jeeflow\Core\Handler\HandlerInterface}（`handle(Execution): void`）—— 引擎按同一
 * 调用式 `$handler->handle($execution, $args)` 打过去，PHP 用户态函数**忽略多余实参**，
 * void 返回即 null ⇒ 不写变量键，与 java「`IHandler` 那一支自己不写返回值」逐字同形。
 */
interface CustomHandlerInterface
{
    /**
     * @param Execution          $execution 执行上下文（节点模型/实例/参与变量都在上面）
     * @param array<int,  mixed> $args      `properties.args` 逗号串按变量 key 从执行变量里取出的实参
     *                                      （未配 args ⇒ 空数组；配了但变量缺失 ⇒ 该档为 null，同 java `getArgs`）
     * @return mixed 非 null 时写入执行变量
     */
    public function handle(Execution $execution, array $args = []): mixed;
}
