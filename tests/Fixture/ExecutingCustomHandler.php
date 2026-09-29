<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Fixture;

use Jeeflow\Core\Execution;

/**
 * 只有一个业务方法名的记录类处理器（**不实现任何引擎接口**）。
 *
 * 存在的理由：钉住 java `CustomModel` 那条"非 IHandler ⇒ 按 `properties.methodName`
 * 反射调方法"的腿在 php 侧的同形实现——共享夹具 `flows/08-custom-node.json` 里配的
 * 就是 `"methodName": "execute"`，本栈按名解析到对象后必须真能把那个方法调起来，
 * 并把返回值写进 `properties.val`（夹具里是 `customResult`）。
 */
final class ExecutingCustomHandler
{
    /** @var array<int,mixed>[] 每次调用收到的实参 */
    public array $callsWith = [];

    public function __construct(private readonly mixed $ret = 'EXEC-RET') {}

    /** 方法名与夹具的 properties.methodName 对齐 */
    public function execute(Execution $execution, array $args = []): mixed
    {
        $this->callsWith[] = $args;
        return $this->ret;
    }
}
