<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\CustomHandlerRegistry;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 自定义节点模型（记录类节点 `snaker:custom`）—— 对齐 Java CustomModel
 *
 * issues/142 A 批 · spec 02-flow-definition.md §6.1／§6.2（owner 2026-09-30 逐条拍）。
 * 本栈此前**根本没有这一档**（`ModelParser` 七档无 `custom`，也无 `CustomNodeParser`）⇒
 * custom 节点在解析期连同它的出边被整个吞掉，自带夹具 `flows/08-custom-node.json` 也被吞。
 * 这一档补齐的是"记录类节点的三条硬要求"：
 *
 * 1. **执行 `clazz`**（按名解析，见 {@see CustomHandlerRegistry}）；
 * 2. **落一条历史行**（`task_state=20`、参与者＝当前操作人）并**真落库**
 *    ——本方法把行挂到 {@see Execution::addHistoryTask()}，由引擎的落库腿写进
 *    `wf_process_task`（内存仓/SQL 仓同一支），**不进** `processTaskList` 那条
 *    "saveNewTask → notifyTaskStart" 的待办腿：码 3 表达的是"新待办产生"，
 *    记录类节点不该有待办（§6.1 三条硬结论之 2）；
 * 3. **令牌沿出边继续流转**（不卡在这一格）。
 *
 * `clazz` 不可解析的两档（空串／未注册）都**记日志后照常落历史行＋续流，严禁抛异常打断建单**
 * （§6.2 第 2 条，与 spec/04"节点属性配错不该把流程炸掉"同一条哲学）；
 * 处理器**自身执行失败**不在豁免内，照旧外抛——那是业务错误，不是配错形状。
 */
class CustomModel extends NodeModel
{
    private string $clazz = '';
    private string $methodName = '';
    private string $args = '';
    /** 返回值写入的变量键：properties.val 命中用之，否则 FlowConst::CUSTOM_RETURN_VAL */
    private string $var = FlowConst::CUSTOM_RETURN_VAL;

    protected function exec(Execution $execution): void
    {
        // 1. 执行处理器（两档不可解析只记日志，不外抛；处理器自身炸了才外抛）
        $this->runHandler($execution);

        // 2. 落一条 DONE 历史行（形状基准＝java ProcessInstance#createHistoryTask：
        //    ProcessTask.create(...) ＋ taskState=FINISHED；建单不变量 parent／行级首节点标记照走）
        $instance = $execution->getProcessInstance();
        if ($instance !== null) {
            $execution->addHistoryTask($instance->createHistoryTask(
                $this,
                $execution->getOperator(),
                $execution->getProcessTaskId(),
                FlowUtil::isFirstTaskName($execution->getProcessModel(), $this->getName())
            ));
        }

        // 3. 令牌继续流转（java CustomModel 收尾那句 runOutTransition）
        $this->runOutTransition($execution);
    }

    /**
     * `clazz` 按名解析并执行。日志两档必须**分别可诊断**（§6.2 第 2 条点名要求：
     * c# 曾把两者合成同一个异常，覆盖面比 java 宽）：
     *
     * - 档「clazz 为空串／属性缺失」＝定义配错（设计器里没填或填了空）；
     * - 档「clazz 非空但未注册」＝集成方忘了注册或名字写错 ⇒ 日志带**具体 clazz 名**与注册姿势。
     *
     * 日志通道沿用本仓 core 既有姿势（`ModelParser` 未知档诊断／`ProcessPublisher` 监听器隔离
     * ／`SurrogateInterceptor` 委托降级都走 error_log，零新依赖），级别语义靠 "WARNING" 前缀表达。
     */
    private function runHandler(Execution $execution): void
    {
        $clazz = trim($this->clazz);
        if ($clazz === '') {
            error_log('[jeeflow-php] WARNING custom 节点未配置 clazz（属性缺失或空串），跳过执行、'
                . '照常落历史行并续流: nodeId=' . $this->getName());
            return;
        }

        $registry = ServiceContext::find(CustomHandlerRegistry::class);
        $handler = $registry?->resolve($clazz);
        if ($handler === null) {
            error_log('[jeeflow-php] WARNING custom 节点的 clazz 未注册处理器，跳过执行、'
                . '照常落历史行并续流: nodeId=' . $this->getName() . ', clazz=' . $clazz
                . '（本栈按名注册：ServiceContext::put(CustomHandlerRegistry::class, new CustomHandlerRegistry())'
                . ' 后 ->register(clazz, handler)）');
            return;
        }

        // 方法名：properties.methodName 命中用之，未配回落 handle
        // （handle 同时覆盖 CustomHandlerInterface／HandlerInterface(void)／__invoke 三种形状）
        $method = $this->methodName !== '' ? $this->methodName : 'handle';
        $argValues = $this->resolveArgValues($execution);
        if (method_exists($handler, $method)) {
            // 处理器自身执行失败 ⇒ 不兜底、照旧外抛（业务错误不在 §6.2 第 2 条豁免内）
            $ret = $handler->{$method}($execution, $argValues);
        } elseif ($this->methodName === '' && is_callable($handler)) {
            $ret = $handler($execution, $argValues);
        } else {
            // spec 02 §6.2 第 2 条的豁免面边界（八栈对表时补的细则）：判据只有一句
            // **「这一行处理器代码有没有被执行过」**——
            //   没执行过 ⇒ 配错档 ⇒ 记日志＋照常落历史行＋续流：clazz 空/未注册、反射实例化失败、
            //           methodName 未配或在处理器上找不到、参数形状对不上；
            //   执行过 ⇒ 业务错档 ⇒ 照旧外抛：handle/方法体内部抛出的异常。
            // `methodName` 配错属前者：配置在调用**之前**就错、处理器一行都没跑，外抛并不比
            // 一条带 class+method 名的日志更可诊断，反而把整条建单炸掉（java 已同档改判，
            // 见 jeeflow-java CustomModel.invokeHandler 的 HandlerConfigException 分支）。
            error_log('[jeeflow-php] WARNING custom 节点的 methodName 在处理器上找不到（属性配错），'
                . '跳过执行、照常落历史行并续流: nodeId=' . $this->getName()
                . ', clazz=' . $clazz . ', methodName=' . $method);
            return;
        }

        // 返回值落执行变量（非 null 才写，java IHandler 支不写返回值 ⇒ 同款静默）
        if ($ret !== null && $this->var !== '') {
            $execution->getArgs()->set($this->var, $ret);
        }
    }

    /**
     * `properties.args`（逗号分隔的变量 key）→ 实参数组。
     * 逐字对齐 java `CustomModel.getArgs`：未配 args ⇒ 空数组；配了但执行变量里没有该 key ⇒
     * 该档为 null（位置保留，不静默丢，否则参数错位比报错更难查）。
     *
     * @return array<int, mixed>
     */
    private function resolveArgValues(Execution $execution): array
    {
        if (trim($this->args) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $this->args) as $raw) {
            $key = trim($raw);
            if ($key === '') {
                continue;
            }
            $out[] = $execution->getArgs()->get($key);
        }
        return $out;
    }

    // ── Getters/Setters ──

    public function getClazz(): string { return $this->clazz; }
    public function setClazz(string $v): void { $this->clazz = $v; }
    public function getMethodName(): string { return $this->methodName; }
    public function setMethodName(string $v): void { $this->methodName = $v; }
    public function getArgs(): string { return $this->args; }
    public function setArgs(string $v): void { $this->args = $v; }
    public function getVar(): string { return $this->var; }
    public function setVar(string $v): void { $this->var = $v; }
}
