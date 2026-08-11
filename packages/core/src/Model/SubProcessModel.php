<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;

/**
 * 子流程节点模型
 */
class SubProcessModel extends NodeModel
{
    private string $form = '';
    private ?int $version = null;

    protected function exec(Execution $execution): void
    {
        $this->runOutTransition($execution);
    }

    public function getForm(): string { return $this->form; }
    public function setForm(string $v): void { $this->form = $v; }
    public function getVersion(): ?int { return $this->version; }
    public function setVersion(?int $v): void { $this->version = $v; }
}
