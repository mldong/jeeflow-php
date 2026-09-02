<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;

/**
 * 流程事件值对象 —— 对齐 Java ProcessEvent
 *
 * 刻意只携带 id 级信息（§4.3），不带 FlowData / 聚合对象：
 *  - {@see $sourceId}：事件主体 id
 *      · TASK_START       → taskId（监听器 findTaskById 反查）
 *      · INSTANCE_START   → instanceId
 *      · INSTANCE_END     → instanceId（监听器 findInstanceById 反查）
 *      · CC_CREATE        → instanceId（监听器 findInstanceById 反查发起人/流程名）
 *  - {@see $ccActorId}：仅 CC_CREATE 用，抄送人 id（逐抄送人 fire，直接取用免反查 cc 表）
 */
final class ProcessEvent
{
    public function __construct(
        private readonly ProcessEventTypeEnum $type,
        private readonly ?string $sourceId,
        private readonly ?string $ccActorId = null,
    ) {
    }

    public static function of(ProcessEventTypeEnum $type, ?string $sourceId, ?string $ccActorId = null): self
    {
        return new self($type, $sourceId, $ccActorId);
    }

    public function getType(): ProcessEventTypeEnum
    {
        return $this->type;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function getCcActorId(): ?string
    {
        return $this->ccActorId;
    }
}
