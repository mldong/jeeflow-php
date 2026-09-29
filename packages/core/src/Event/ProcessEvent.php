<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;

/**
 * 流程事件值对象 —— 对齐 Java ProcessEvent（`{eventType, sourceId, ccActorId, data}`）
 *
 * 形状权威＝规范 11 §11.3：`sourceId` ＋ `ccActorId` ＋ **直传载荷 `data`**（键名一律 camelCase，
 * 键常量见 {@see ProcessPublisher::KEY_*}）。载荷只钉「必须能拿到 §11.3 那几列这些键」，
 * 键之外的字段允许监听器按 `sourceId` **反查仓储**得到（任务名/流程名/发起人）——
 * 本类刻意不带 FlowData / 聚合对象快照（§4.3 原口径不变）。
 *
 *  - {@see $sourceId}：事件主体 id
 *      · PROCESS_TASK_START / TASK_COMPLETE / TASK_REJECT / TASK_TRANSFER → taskId
 *      · PROCESS_INSTANCE_START / PROCESS_INSTANCE_END / CC_CREATE
 *        / TASK_WITHDRAW / INSTANCE_TERMINATED                            → instanceId
 *  - {@see $ccActorId}：仅 CC_CREATE 用，抄送人 id（逐抄送人 fire，直接取用免反查 cc 表）
 *  - {@see $data}：§11.3「直传载荷键（必备）」，按码值装填；缺键即契约违约
 */
final class ProcessEvent
{
    /**
     * @param array<string, mixed> $data 直传载荷（camelCase 键，见 ProcessPublisher::KEY_*）
     */
    public function __construct(
        private readonly ProcessEventTypeEnum $type,
        private readonly ?string $sourceId,
        private readonly ?string $ccActorId = null,
        private readonly array $data = [],
    ) {
    }

    public static function of(ProcessEventTypeEnum $type, ?string $sourceId, ?string $ccActorId = null,
                              array $data = []): self
    {
        return new self($type, $sourceId, $ccActorId, $data);
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

    /** @return array<string, mixed> 直传载荷（不可变副本） */
    public function getData(): array
    {
        return $this->data;
    }

    /** 取单个载荷键（缺键返回 $default；监听器免写 isset 三元） */
    public function datum(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
