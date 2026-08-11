<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;

/**
 * 节点模型抽象基类
 *
 * 对齐 Java NodeModel。
 */
abstract class NodeModel extends BaseModel
{
    protected string $layout = '';
    /** @var TransitionModel[] */
    protected array $inputs = [];
    /** @var TransitionModel[] */
    protected array $outputs = [];
    protected string $preInterceptors = '';
    protected string $postInterceptors = '';

    /** 子类实现具体执行逻辑 */
    abstract protected function exec(Execution $execution): void;

    public function execute(Execution $execution): void
    {
        $execution->setNodeModel($this);
        $this->exec($execution);
        $execution->setNodeModel($this);
    }

    /** 执行所有输出边 */
    protected function runOutTransition(Execution $execution): void
    {
        foreach ($this->outputs as $tr) {
            $tr->setEnabled(true);
            $tr->execute($execution);
        }
    }

    /**
     * 判断 current 是否可以退回到 parent
     */
    public static function canRejected(NodeModel $current, NodeModel $parent): bool
    {
        foreach ($current->getInputs() as $tm) {
            $source = $tm->getSource();
            if ($source === $parent) return true;
            if ($source instanceof ForkModel || $source instanceof JoinModel || $source instanceof StartModel) {
                continue;
            }
            if (self::canRejected($source, $parent)) return true;
        }
        return false;
    }

    // ── Getters/Setters ──

    public function getLayout(): string { return $this->layout; }
    public function setLayout(string $v): void { $this->layout = $v; }
    /** @return TransitionModel[] */
    public function getInputs(): array { return $this->inputs; }
    /** @param TransitionModel[] $v */
    public function setInputs(array $v): void { $this->inputs = $v; }
    public function addInput(TransitionModel $v): void { $this->inputs[] = $v; }
    /** @return TransitionModel[] */
    public function getOutputs(): array { return $this->outputs; }
    /** @param TransitionModel[] $v */
    public function setOutputs(array $v): void { $this->outputs = $v; }
    public function addOutput(TransitionModel $v): void { $this->outputs[] = $v; }
    public function getPreInterceptors(): string { return $this->preInterceptors; }
    public function setPreInterceptors(string $v): void { $this->preInterceptors = $v; }
    public function getPostInterceptors(): string { return $this->postInterceptors; }
    public function setPostInterceptors(string $v): void { $this->postInterceptors = $v; }
}
