<?php

declare(strict_types=1);

namespace Jeeflow\Core\Interceptor;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Execution;

/**
 * 空实现委托拦截器（issues/116 批次 D）——**显式关闭**委托自动生效的第二条路。
 *
 * 引擎内置的委托自动生效默认开启，集成方关闭有三条形（任选其一）：
 *
 * 1. 构造参数（主路径，一行）：`new JeeflowEngine($repo, surrogateAutoApply: false)`
 *    或 `$engine->setSurrogateAutoApply(false)`；
 * 2. **注册空实现**（本类）：`ServiceContext::put(SurrogateInterceptor::CONTEXT_KEY, new NullSurrogateInterceptor())`
 *    ——等价于 Java/Python 的"注册空 applier"，不改引擎开关也能按部署形态关掉；
 * 3. 自建扩展仓储让 `getSurrogate` 恒返回 null（等价于没有生效委托）。
 *
 * 关闭后回到"仅台账"行为：`processSurrogate/*` 五个 action 照存照查，建任务不再应用委托。
 */
final class NullSurrogateInterceptor extends SurrogateInterceptor
{
    public function intercept(Execution $execution): void
    {
        // 刻意空实现：不应用委托（显式关闭）
    }

    public function apply(ProcessTask $task, string $processName, ?string $at = null): void
    {
        // 刻意空实现：不应用委托（显式关闭）
    }
}
