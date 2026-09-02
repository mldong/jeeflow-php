<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

/**
 * 流程事件监听器 SPI —— 对齐 Java ProcessEventListener
 *
 * 集成层（如 mldong-laravel-jeeflow）实现本接口并注册进
 * {@see ProcessEventListenerRegistry}，即可接收引擎在流程生命周期各
 * fire 点发布的 {@see ProcessEvent}（issues/101 方案 A：PHP 栈补事件机制）。
 *
 * 契约：payload 只带 id（sourceId / ccActorId），监听器**自己反查仓储**
 * 取业务数据（spec 04-extensions §4.3「刻意不带完整快照」）。
 */
interface ProcessEventListener
{
    public function onEvent(ProcessEvent $event): void;
}
