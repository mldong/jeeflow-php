<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\HandlerInterface;

/**
 * 模型基类
 *
 * 对齐 Java BaseModel。
 */
class BaseModel
{
    protected string $name = '';
    protected string $displayName = '';

    /** 将执行对象交给具体的处理器处理 */
    protected function fire(HandlerInterface $handler, Execution $execution): void
    {
        $handler->handle($execution);
    }

    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): void { $this->displayName = $v; }
}
