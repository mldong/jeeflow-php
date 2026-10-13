<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 事务模板 SPI —— 对齐 Java ITransactionTemplate
 *
 * 引擎不自行开启事务，只表达"需要原子执行"的语义。
 * 由 PDO 或宿主框架实现。
 *
 * 可选扩展（issues/191，引擎用 method_exists 探测；不实现＝保持既有行为，不破坏第三方实现）：
 *  - `inTransaction(): bool`（audit8 R9）——嵌套并入探测口：有活动事务则并入最外层，不二次 begin；
 *  - `afterCommit(callable $callback): void`（audit8 R25）——提交后派发腿：有活动事务时登记回调
 *    （**root 提交后执行、回滚丢弃**），无事务时立即执行。引擎在事务内 fire 流程事件时经此把广播
 *    挪到 COMMIT 之后（spec11 §11.3.1「派发时序对外承诺为'提交之后'」／spec12 §12.3.1）。
 */
interface TransactionTemplateInterface
{
    /**
     * 在事务中执行回调
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function required(callable $work): mixed;
}
