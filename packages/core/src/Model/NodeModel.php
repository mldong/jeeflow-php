<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowException;

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
        $this->execPreInterceptors($execution);
        $this->exec($execution);
        // 流转时 CreateTaskHandler 会改写 nodeModel；post 必须看回本节点
        $execution->setNodeModel($this);
        $this->execPostInterceptors($execution);
    }

    private function execPreInterceptors(Execution $execution): void
    {
        $this->runInterceptors($this->preCsv($execution->getProcessModel()), $execution);
    }

    private function execPostInterceptors(Execution $execution): void
    {
        $this->runInterceptors($this->postCsv($execution->getProcessModel()), $execution);
    }

    /**
     * 拦截器声明的「名字可解析」**纯读**校验（issues/244-①）。
     *
     * <p><b>为什么需要它、又为什么不是"再跑一次拦截器"</b>：本栈的症状是"引擎先把状态改完
     * （当前任务行 10→20、写实例、fire 事件），之后才在 {@see self::runInterceptors()} 里
     * `resolve` 注册表、缺名字才抛"。真库有外层事务整笔回滚看不出差别；内存仓储无回滚 ⇒
     * 报错那一次留下「当前任务已 DONE、新任务没建」＝实例 DOING 却零待办的失联状态。
     * 因此引擎要在任何写之前问一次"这个名字能不能解析"——回答这个问题只需查表，**不需要
     * 也不允许**把拦截器真跑一遍：`intercept()` 是执行期副作用（持久化、发通知、写业务数据），
     * 预跑一次等于把副作用提前到校验位（还会在校验失败时留下一半副作用），比原来的病更重。
     * 所以本方法只做 `FlowInterceptorRegistry::resolve() !== null` 这一件事。</p>
     *
     * <p>判据与执行期逐条同源：节点级串非空即用节点的、为空才回落流程级（{@see self::preCsv()} /
     * {@see self::postCsv()} 与 `execPreInterceptors`/`execPostInterceptors` 共用同一份取串逻辑，
     * 不在校验位另写一份"回落规则"）；逗号串里逐名字 trim、空名跳过；缺名字抛**同一句**
     * 「拦截器未注册: 名字」——文案与错误类型都不另造，外部可见面零变化。</p>
     *
     * <p>调用点唯一：{@see \Jeeflow\Core\JeeflowEngine::prepareExecution()}，排在 `completeTask`
     * 之前。{@see self::runInterceptors()} 的执行期行为一字不动（真正的拦截器仍在节点体后照常跑）。</p>
     *
     * @param ProcessModel|null $processModel 用于"节点级为空 ⇒ 回落流程级"的那一档
     * @throws JeeflowException 声明了但注册表里没有这个名字
     */
    public function assertInterceptorsResolvable(?ProcessModel $processModel = null): void
    {
        $this->assertCsvResolvable($this->preCsv($processModel));
        $this->assertCsvResolvable($this->postCsv($processModel));
    }

    /** 节点级 preInterceptors（非空优先），空则回落流程级——校验位与执行位共用 */
    private function preCsv(?ProcessModel $processModel): string
    {
        return $this->preInterceptors !== ''
            ? $this->preInterceptors
            : ($processModel?->getPreInterceptors() ?? '');
    }

    /** 节点级 postInterceptors（非空优先），空则回落流程级——校验位与执行位共用 */
    private function postCsv(?ProcessModel $processModel): string
    {
        return $this->postInterceptors !== ''
            ? $this->postInterceptors
            : ($processModel?->getPostInterceptors() ?? '');
    }

    /** 逐个名字查注册表，只读；判据与 {@see self::runInterceptors()} 的解析段逐条同形 */
    private function assertCsvResolvable(string $csv): void
    {
        if (trim($csv) === '') {
            return;
        }
        foreach (explode(',', $csv) as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            if (FlowInterceptorRegistry::resolve($name) === null) {
                throw new JeeflowException('拦截器未注册: ' . $name);
            }
        }
    }

    private function runInterceptors(string $csv, Execution $execution): void
    {
        if (trim($csv) === '') {
            return;
        }
        foreach (explode(',', $csv) as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $interceptor = FlowInterceptorRegistry::resolve($name);
            if ($interceptor === null) {
                throw new JeeflowException('拦截器未注册: ' . $name);
            }
            $interceptor->intercept($execution);
        }
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
