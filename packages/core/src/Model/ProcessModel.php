<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

/**
 * 流程模型 —— 整个流程定义的顶层对象
 *
 * 对齐 Java ProcessModel。
 */
class ProcessModel extends BaseModel
{
    private string $type = '';
    private string $instanceUrl = '';
    private string $expireTime = '';
    private string $instanceNoClass = '';
    private string $preInterceptors = '';
    private string $postInterceptors = '';
    private string $relTableName = '';
    private string $persistMode = '';
    /** @var NodeModel[] */
    private array $nodes = [];
    /** @var TaskModel[] */
    private array $tasks = [];

    /** 获取开始节点 */
    public function getStart(): ?StartModel
    {
        foreach ($this->nodes as $node) {
            if ($node instanceof StartModel) return $node;
        }
        return null;
    }

    /** 根据名称获取节点 */
    public function getNode(string $nodeName): ?NodeModel
    {
        foreach ($this->nodes as $node) {
            if ($node->getName() === $nodeName) return $node;
        }
        return null;
    }

    /** 获取指定类型的所有节点 */
    /** @template T of NodeModel */
    /** @param class-string<T> $clazz */
    /** @return T[] */
    public function getModels(string $clazz): array
    {
        $models = [];
        $start = $this->getStart();
        if ($start !== null) {
            foreach ($this->collectNextModels($start, $clazz) as $m) {
                $models[] = $m;
            }
        }
        return $models;
    }

    /** @template T of NodeModel */
    /** @param class-string<T> $clazz */
    /** @return T[] */
    private function collectNextModels(NodeModel $node, string $clazz): array
    {
        $result = [];
        $visited = [];
        foreach ($node->getOutputs() as $tm) {
            $this->addNextModels($result, $tm, $clazz, $visited);
        }
        return $result;
    }

    /** @template T of NodeModel */
    /** @param T[] $models */
    /** @param class-string<T> $clazz */
    private function addNextModels(array &$models, TransitionModel $tm, string $clazz, array &$visited): void
    {
        $to = $tm->getTo();
        if (isset($visited[$to])) return;
        $target = $tm->getTarget();
        if ($target === null) return;
        if ($target instanceof $clazz) {
            if (!in_array($target, $models, true)) {
                $models[] = $target;
            }
        }
        $visited[$to] = true;
        foreach ($target->getOutputs() as $tm2) {
            $this->addNextModels($models, $tm2, $clazz, $visited);
        }
    }

    // ── Getters/Setters ──

    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = $v; }
    public function getInstanceUrl(): string { return $this->instanceUrl; }
    public function setInstanceUrl(string $v): void { $this->instanceUrl = $v; }
    public function getExpireTime(): string { return $this->expireTime; }
    public function setExpireTime(string $v): void { $this->expireTime = $v; }
    public function getInstanceNoClass(): string { return $this->instanceNoClass; }
    public function setInstanceNoClass(string $v): void { $this->instanceNoClass = $v; }
    public function getPreInterceptors(): string { return $this->preInterceptors; }
    public function setPreInterceptors(string $v): void { $this->preInterceptors = $v; }
    public function getPostInterceptors(): string { return $this->postInterceptors; }
    public function setPostInterceptors(string $v): void { $this->postInterceptors = $v; }
    public function getRelTableName(): string { return $this->relTableName; }
    public function setRelTableName(string $v): void { $this->relTableName = $v; }
    public function getPersistMode(): string { return $this->persistMode; }
    public function setPersistMode(string $v): void { $this->persistMode = $v; }
    /** @return NodeModel[] */
    public function getNodes(): array { return $this->nodes; }
    /** @param NodeModel[] $v */
    public function setNodes(array $v): void { $this->nodes = $v; }
    public function addNode(NodeModel $v): void { $this->nodes[] = $v; }
    /** @return TaskModel[] */
    public function getTasks(): array { return $this->tasks; }
    /** @param TaskModel[] $v */
    public function setTasks(array $v): void { $this->tasks = $v; }
    public function addTask(TaskModel $v): void { $this->tasks[] = $v; }
}
