<?php

declare(strict_types=1);

namespace Jeeflow\Core\Event;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;

/**
 * 流程事件发布者 —— 对齐 Java ProcessPublisher
 *
 * 引擎/门面各 fire 点统一经本类广播事件（形状权威＝规范 11 §11.3/§11.5）。
 *
 * 关键行为：
 *  - **无监听器注册**时循环零次直接返回（§11.5「无监听器」行：零注册 fire 必须安全返回，
 *    不得空指针），与未加事件机制的 1.3.7 行为逐字节一致。
 *  - **逐监听器** try/catch(\Throwable) 只记 error_log（§11.5「异常隔离」行 / issues/104 P2）：
 *    单个监听器抛异常 ① 不回滚主流程 ② **不中断后续监听器**——本栈是最早落地这一形状的
 *    实现，java 侧注释「对齐 PHP v1.3.8 per-listener catch Throwable」即指此处。
 *  - 引擎不因监听器失败重发（§11.5「幂等」行），下游自己去重。
 */
final class ProcessPublisher
{
    // ═══ 直传载荷键（spec §11.3「直传载荷键（必备）」列，一律 camelCase，与 java KEY_* 同名）═══

    /** 实例 id（码 1/2/3/4/5/6/7/8/9） */
    public const KEY_INSTANCE_ID = 'instanceId';
    /** 任务 id（码 3/5/6/7） */
    public const KEY_TASK_ID = 'taskId';
    /** 待办参与人列表（码 3） */
    public const KEY_ACTORS = 'actors';
    /** 落库后的实例状态整数（码 2） */
    public const KEY_STATE = 'state';
    /** 本次动作的操作人（码 5/6/7/8/9） */
    public const KEY_OPERATOR = 'operator';
    /** 本次动作的提交类型；「码粗载荷细」下用它区分拒绝/退回上一步/退发起人/会签否决（码 5/6） */
    public const KEY_SUBMIT_TYPE = 'submitType';
    /** 转办被摘走的参与人（码 7） */
    public const KEY_FROM_ACTOR = 'fromActor';
    /** 转办接手的参与人（码 7） */
    public const KEY_TO_ACTOR = 'toActor';
    /** 终止原因（码 9） */
    public const KEY_REASON = 'reason';

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
                // 铁律 2 引擎侧兜底（§11.5 异常隔离）：监听器异常只记日志，
                // 既不打断审批主流程，也不打断**其后**的监听器（continue 到下一个）。
                error_log('[jeeflow-php] ProcessEventListener ' . get_class($listener)
                    . ' onEvent(' . $event->getType()->name . ') threw: ' . $e->getMessage());
            }
        }
    }

    /**
     * 抄送知会（{@see ProcessEventTypeEnum::CC_CREATE} / 码 4）fire **收口**：
     * 逐抄送人 fire 一次，`sourceId = instanceId`、`ccActorId` 直传事件体（监听器免反查 cc 表），
     * 载荷带 `instanceId`。
     *
     * 三条路径共用本方法（§11.3 码 4 ＋ §11.7）：发起 `f_ccActors`、办理 `tf_ccActors`
     * （{@code JeeflowEngine::handleCcActors}）、门面手动 `processInstance/createCCInstance`。
     * §11.2 原则 1「码值表达发生了什么事实，不表达谁触发的」⇒ 手动支同样必须 fire
     * （「新增了一条抄送记录」这个事实成立）；PHP 此前手动支静默，与 java 同属「不 fire」那一派，
     * §11.6 点名本轮补齐。
     *
     * 调用前提（§11.2 原则 3）：cc 行已由 `IProcessRepository::createCcInstance` **落库**。
     * 接收人过滤（trim / 非空 / 去重）由集成层监听器负责，引擎只按 cc 行粒度 fire。
     *
     * **入参一律是"实际新建的 actor 子集"**（issues/141 G2 · spec 06-facade.md §4）：调用点先走
     * `ProcessRepositoryInterface::createCcInstanceIfAbsent()` 拿到子集，子集为空整支不 fire——
     * §11.2 原则 1「码=事实」，重复抄送没发生"创建"就不该发码 4，严禁照旧按原始请求全量 fire。
     *
     * @param string[]|array $ccActorIds
     */
    public static function notifyCcCreate(?string $instanceId, array $ccActorIds): void
    {
        if ($instanceId === null || $instanceId === '' || $ccActorIds === []) {
            return;
        }
        foreach ($ccActorIds as $ccActorId) {
            self::notify(ProcessEvent::of(
                ProcessEventTypeEnum::CC_CREATE,
                $instanceId,
                (string) $ccActorId,
                [self::KEY_INSTANCE_ID => $instanceId],
            ));
        }
    }

    /**
     * 实例终态（{@see ProcessEventTypeEnum::PROCESS_INSTANCE_END} / 码 2）fire **收口**：
     * `sourceId`＝instanceId，直传载荷键 `instanceId` ＋ `state`。
     * 办结与拒绝共用这一支，规范名不拆，靠载荷 state 分（§11.3 码 2／§11.6 收口口径）。
     *
     * **调用前提**（§11.2 原则 3／08-compliance 场景 32）：实例那一行的 `state`
     * **已经落库**。本栈唯一调用点＝`JeeflowEngine::flushInstanceEndEvents`，
     * 排在 `repository->updateInstance` 成功返回之后；子流程级联里被连带办结的
     * **父实例**不走那次 update，flush 先补写它自己的行再播。
     *
     * @param int $state 落库后的实例状态整数（处理器落定时刻的快照，见 {@see PendingInstanceEnd}）
     */
    public static function notifyInstanceEnd(?string $instanceId, int $state): void
    {
        if ($instanceId === null || $instanceId === '') {
            return;
        }
        self::notify(ProcessEvent::of(
            ProcessEventTypeEnum::PROCESS_INSTANCE_END,
            $instanceId,
            null,
            [
                self::KEY_INSTANCE_ID => $instanceId,
                self::KEY_STATE => $state,
            ],
        ));
    }
}
