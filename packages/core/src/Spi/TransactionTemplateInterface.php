<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * 事务模板 SPI —— 对齐 Java ITransactionTemplate
 *
 * 引擎不自行开启事务，只表达"需要原子执行"的语义。
 * 由 PDO 或宿主框架实现。
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
