<?php

declare(strict_types=1);

namespace Jeeflow\Core;

/**
 * 时限动作凭证仓储侧实现（集成层按框架执行队列实现，引擎在业务事务内回调）。
 * 未注册实现时引擎侧全部时限动作静默跳过。取消只清 REMIND/AUTO_COMPLETE，
 * CALLBACK 表达既成事实、不随失效取消。
 */
interface ITimeActionSink
{
    public function insertTimeAction(TimeAction $action): void;

    public function cancelTaskTimeActions(string $taskId): void;

    public function cancelInstanceTimeActions(string $instanceId): void;
}
