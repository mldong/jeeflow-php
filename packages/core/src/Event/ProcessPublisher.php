<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

/**
 * 流程事件发布者 —— 对齐 Java ProcessPublisher
 *
 * 引擎各 fire 点统一经本类广播事件。
 *
 * 关键行为（保证纯增量 + 铁律 2 引擎侧兜底）：
 *  - 无监听器注册时循环零次直接返回——与未加事件机制的 1.3.7 行为逐字节一致
 *    （对齐 Go interceptor.go「ext==nil 静默返回」语义）。
 *  - 对**每个** listener 的调用包 try/catch（\Throwable）只记 error_log：
 *    某个监听器抛异常不影响主流程，也不影响其余监听器。与 Java boot2 监听器内
 *    catch Throwable 同效果（双保险：监听器内部仍应各自 try/catch）。
 */
final class ProcessPublisher
{
    private function __construct()
    {
    }

    public static function notify(ProcessEvent $event): void
    {
        $listeners = ProcessEventListenerRegistry::listeners();
        foreach ($listeners as $listener) {
            try {
                $listener->onEvent($event);
            } catch (\Throwable $e) {
                // 铁律 2 引擎侧兜底：监听器异常只记日志，绝不打断审批主流程
                error_log('[jeeflow-php] ProcessEventListener ' . get_class($listener)
                    . ' onEvent(' . $event->getType()->name . ') threw: ' . $e->getMessage());
            }
        }
    }
}
