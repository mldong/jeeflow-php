<?php

declare(strict_types=1);

namespace Jeeflow\Core;

/**
 * 任务时限动作凭证（spec 04 · 任务时限动作）：dueAt 用 UTC 毫秒，避免时区歧义。
 */
final class TimeAction
{
    public const TYPE_REMIND = 'REMIND';
    public const TYPE_AUTO_COMPLETE = 'AUTO_COMPLETE';
    public const TYPE_CALLBACK = 'CALLBACK';

    public function __construct(
        public string $type,
        public int $dueAt,
        public string $instanceId,
        public string $taskId,
        public string $nodeKey,
        public int $occurrenceNo,
        public string $idempotencyKey,
    ) {
    }
}
