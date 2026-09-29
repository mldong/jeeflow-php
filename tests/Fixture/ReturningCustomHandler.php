<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Fixture;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Spi\CustomHandlerInterface;

/**
 * 固定返回值的记录类处理器（`CustomHandlerInterface` 形状，方法名就叫 `handle`）。
 *
 * 钉的是"返回值落执行变量"这一腿：`properties.methodName` 未配 ⇒ 引擎回落调 `handle`，
 * 返回非 null ⇒ 写进 `properties.val` 指的键（缺省 `custom_return_val`）。
 */
final class ReturningCustomHandler implements CustomHandlerInterface
{
    /** @var array<int,mixed>[] 每次调用收到的实参（properties.args 解出来的那一串） */
    public array $callsWith = [];

    public function __construct(private readonly mixed $ret = 'RET') {}

    public function handle(Execution $execution, array $args = []): mixed
    {
        $this->callsWith[] = $args;
        return $this->ret;
    }
}
