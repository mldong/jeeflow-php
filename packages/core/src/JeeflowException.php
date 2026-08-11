<?php

declare(strict_types=1);

namespace Jeeflow\Core;

/**
 * jeeflow 引擎异常 —— 对齐 Java JeeflowException
 */
class JeeflowException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
