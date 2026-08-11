<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

class NoOpTransactionTemplate implements TransactionTemplateInterface
{
    public function required(callable $work): mixed
    {
        return $work();
    }
}
