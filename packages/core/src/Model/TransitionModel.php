<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\CreateTaskHandler;
use Jeeflow\Core\Handler\StartSubProcessHandler;

/**
 * 边/转移模型 —— 连接两个节点的有向边
 *
 * 对齐 Java TransitionModel。
 */
class TransitionModel extends BaseModel
{
    private ?NodeModel $source = null;
    private ?NodeModel $target = null;
    private string $to = '';
    private string $expr = '';
    private string $g = '';
    private bool $enabled = false;

    public function execute(Execution $execution): void
    {
        if (!$this->enabled) return;
        if ($this->target instanceof TaskModel) {
            $this->fire(new CreateTaskHandler($this->target), $execution);
        } elseif ($this->target instanceof SubProcessModel) {
            $this->fire(new StartSubProcessHandler($this->target), $execution);
        } else {
            $this->target->execute($execution);
        }
    }

    // ── Getters/Setters ──

    public function getSource(): ?NodeModel { return $this->source; }
    public function setSource(?NodeModel $v): void { $this->source = $v; }
    public function getTarget(): ?NodeModel { return $this->target; }
    public function setTarget(?NodeModel $v): void { $this->target = $v; }
    public function getTo(): string { return $this->to; }
    public function setTo(string $v): void { $this->to = $v; }
    public function getExpr(): string { return $this->expr; }
    public function setExpr(string $v): void { $this->expr = $v; }
    public function getG(): string { return $this->g; }
    public function setG(string $v): void { $this->g = $v; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $v): void { $this->enabled = $v; }
}
