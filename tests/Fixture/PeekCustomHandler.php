<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Fixture;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Spi\CustomHandlerInterface;

/**
 * 探针处理器：从**执行变量**里读一个键，把结果留在对象上给测试断言。
 *
 * 用在 issues/142 A 批的记录类节点用例里 —— 上游 custom 节点的返回值要落进执行变量
 * （`properties.val` 命中用之，否则 `FlowConst::CUSTOM_RETURN_VAL`），
 * 跨节点观察（下游第二个 custom 节点去读）比断引擎内部对象更硬：
 * 令牌没带着执行变量往前走、键名落错、值没写进去，三种病都能照出来。
 */
final class PeekCustomHandler implements CustomHandlerInterface
{
    public mixed $seen = null;
    public bool $present = false;
    public int $calls = 0;

    public function __construct(private readonly string $key) {}

    public function handle(Execution $execution, array $args = []): mixed
    {
        $this->calls++;
        $this->present = $execution->getArgs()->has($this->key);
        $this->seen = $execution->getArgs()->get($this->key);
        return null;   // 探针不写返回值，免得污染下游键空间
    }
}
