<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

use Jeeflow\Core\Domain\ProcessInstance;

/**
 * 待播的「实例终态」登记（spec §11.2 原则 3「只在落库之后 fire」／§11.3 码 2／08-compliance 场景 32）。
 *
 * 逐字移植 Java `event/PendingInstanceEnd`。
 *
 * **为什么要登记而不是就地 fire**：结束节点处理器（`EndProcessHandler`）跑到时，
 * 实例的 `state` 只在**内存聚合根**里落定，真正写库的那次 `repository->updateInstance`
 * 发生在处理器**之后**（引擎 `persistTasks`／发起路径收口）。就地 fire 会让监听器
 * （站内信、待办角标、persist 回写）反查实例读到旧 state——正是 issues/121、issues/126
 * 两轮"回写序"教训的同一族。
 *
 * 形状：处理器把「谁（instanceId）＋ 落库后该是什么（state）＋ 那一行的聚合根（instance）」
 * 挂到本次流转的 {@see \Jeeflow\Core\Execution} 上，由引擎在 `updateInstance` 成功返回后
 * 统一 flush（`JeeflowEngine::flushInstanceEndEvents`）。`instance` 一起带上是必需的——
 * 子流程级联里被连带办结的是**父实例**，它不走子流程这次的 `updateInstance`，
 * flush 时要拿这个对象去补那次写。
 */
final class PendingInstanceEnd
{
    public function __construct(
        private readonly ?string $instanceId,
        private readonly int $state,
        private readonly ?ProcessInstance $instance,
    ) {
    }

    /** 事件 `sourceId` 与载荷 `instanceId` 用哪个实例的终态 */
    public function getInstanceId(): ?string
    {
        return $this->instanceId;
    }

    /** 处理器落定那一刻的实例状态整数（＝随后写库那一行的 state） */
    public function getState(): int
    {
        return $this->state;
    }

    /** 终态所属的聚合根（引擎按它把父实例那一行补写落库） */
    public function getInstance(): ?ProcessInstance
    {
        return $this->instance;
    }
}
