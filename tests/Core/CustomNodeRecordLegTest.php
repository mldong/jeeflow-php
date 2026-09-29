<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\CustomHandlerRegistry;
use Jeeflow\Core\Handler\HandlerInterface;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Model\CustomModel;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\CustomHandlerInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Tests\Fixture\ExecutingCustomHandler;
use Jeeflow\Tests\Fixture\PeekCustomHandler;
use Jeeflow\Tests\Fixture\RecordingCustomListener;
use Jeeflow\Tests\Fixture\ReturningCustomHandler;
use PHPUnit\Framework\TestCase;

/**
 * 记录类节点（`snaker:custom`）执行腿 —— issues/142 A 批 · spec 02-flow-definition.md §6.1／§6.2
 * （owner 2026-09-30 逐条拍）。
 *
 * 本栈此前的形状不是"偏了"而是**这条能力整个没有**：`ModelParser` 类型表只有七档
 * （start/end/task/decision/fork/join/subprocess），无 `custom` 档也没有 `CustomNodeParser`
 * ⇒ 解析期 `$parser === null` 分支记一条 WARNING 后 `continue`，**节点连同它的出边被整个吞掉**，
 * 自家共享夹具 `flows/08-custom-node.json:38` 的 `snaker:custom` 同样被吞；
 * `FlowConst::CUSTOM_RETURN_VAL` 常量全仓零调用者。
 *
 * 本案钉的是 §6.2 那三条硬要求（任何一栈只做满一半都算违反）：
 *
 * 1. **历史行必须真落库**（`task_state=20`、参与者＝当前操作人、两条建单不变量照走）
 *    ——本件走内存仓，SQL 仓那一路在 `tests/RepositoryPDO/PdoSqliteCustomNodeHistoryTest`
 *    用真表读值钉（**两条 INSERT 通道都要**）；
 * 2. **`clazz` 不可解析 ⇒ 记日志 + 照常落历史行 + 令牌继续流转，严禁抛异常打断建单**，
 *    且「clazz 为空串/缺失」与「非空但未注册」两档日志**分别可诊断**；
 *    处理器**自身执行失败**不在豁免内 ⇒ 照旧外抛（负向对照在本件最后一个用例）；
 * 3. **记录类腿不解析参与者**，反过来**任务类零参与者必须建 DOING 行**——本栈
 *    `CreateTaskHandler` 一直是无条件建单（无 actors 判空），本来就合规，本件只钉住它不变形
 *    （见 {@see testTaskLegStillCreatesRowWithZeroActors()}）。
 *
 * 外加一条 §6.1 硬结论 2 的直接后果：**落库与码 3 解耦** ——
 * 历史行走引擎 `persistHistoryTasks` 那条只 INSERT 的腿，绝不进
 * `saveNewTask → applySurrogate → saveTask → notifyTaskStart` 那条"新待办产生"的腿
 * （码 3 PROCESS_TASK_START 表达"新待办"，记录类节点不该有待办）。
 */
final class CustomNodeRecordLegTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private CustomHandlerRegistry $handlers;
    private RecordingCustomListener $listener;
    private ?string $logFile = null;
    private string|false $savedErrorLog = false;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
        ProcessEventListenerRegistry::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->handlers = new CustomHandlerRegistry();
        ServiceContext::put(CustomHandlerRegistry::class, $this->handlers);

        $this->listener = new RecordingCustomListener();
        ProcessEventListenerRegistry::register($this->listener);

        // error_log 收流（CLI 默认落 stderr 不可断言），姿势与 UnknownNodeTypeDiagnosisTest 同款
        $this->logFile = sys_get_temp_dir() . '/jeeflow-custom-leg-' . getmypid()
            . '-' . spl_object_id($this) . '.log';
        @unlink($this->logFile);
        $this->savedErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->savedErrorLog !== false && $this->savedErrorLog !== '') {
            ini_set('error_log', (string) $this->savedErrorLog);
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    // ═══ 1. 解析期：节点不再被吞掉 ═══

    /**
     * custom 档补进类型表后，节点**进模型**、clazz/methodName/args/val 四键解析到位、
     * **它的出边还在**（target 指到 end）。改前这一格必红：`getNode('custom1')` 是 null，
     * 节点连同出边在解析期被 `continue` 吞掉。
     */
    public function testCustomNodeIsParsedWithItsOutgoingEdge(): void
    {
        $model = ModelParser::parse(self::flow('custom-parsed', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', [
                'clazz' => 'emit.handler', 'methodName' => 'handle',
                'args' => 'param1', 'val' => 'customResult',
            ]),
        ]));

        $node = $model->getNode('custom1');
        $this->assertInstanceOf(CustomModel::class, $node,
            'custom 档必须进模型（改前这里被解析期无声吞掉，getNode 返回 null）');
        $this->assertSame('emit.handler', $node->getClazz());
        $this->assertSame('handle', $node->getMethodName());
        $this->assertSame('param1', $node->getArgs());
        $this->assertSame('customResult', $node->getVar(), 'properties.val 命中时用之');

        $this->assertCount(1, $node->getOutputs(), 'custom 节点的出边不能被吞');
        $this->assertInstanceOf(EndModel::class, $node->getOutputs()[0]->getTarget(),
            '出边的 target 必须已接上（节点不在模型时这一步永远接不上）');

        // 已知档不该再冒"未知类型"诊断（G4 义务 2 的诊断只服务真未建档的类型）
        $this->assertStringNotContainsString('没有对应解析器', $this->logTail(),
            'custom 已建档，不得再产生未知类型诊断，实际文案=' . $this->logTail());
    }

    /**
     * `val` 未配置 ⇒ 回落 `FlowConst::CUSTOM_RETURN_VAL`（`custom_return_val`）。
     * 那个常量此前**全仓零调用者**，本单就是把它接上的一单。
     */
    public function testReturnVarKeyDefaultsToCustomReturnVal(): void
    {
        /** @var CustomModel $node */
        $node = ModelParser::parse(self::flow('custom-default-var', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler']),
        ]))->getNode('custom1');

        $this->assertInstanceOf(CustomModel::class, $node);
        $this->assertSame(FlowConst::CUSTOM_RETURN_VAL, $node->getVar());
        $this->assertSame('custom_return_val', $node->getVar(), '缺省键逐字＝契约常量，不许漂移');
    }

    // ═══ 2. 执行期：落历史行 + 真落库 + 令牌续流（内存仓这一路）═══

    /**
     * 核心正向：发起后办理 apply ⇒ 走到 custom 节点 ⇒
     * ① 有一条 `task_state=20` 的历史行**读得到**；② 令牌沿出边继续流到 end、实例办结；
     * ③ 待办数不因此增加（对照组＝同一条流把 custom 节点换成直连 end）。
     */
    public function testHistoryRowIsPersistedAndTokenContinues(): void
    {
        $this->handlers->register('emit.handler', new ReturningCustomHandler('PAY-777'));

        [$instanceId, $applyTaskId] = $this->startFlow('custom-to-end', self::flow('custom-to-end', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler', 'methodName' => 'handle', 'val' => 'customResult']),
        ]));

        $produced = $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());
        $this->assertSame([], $produced,
            '办理后没有新待办 ⇒ 引擎返回的新任务列表必须为空；历史行严禁混进这条"新待办"腿（§6.1 硬结论 2）');

        $history = $this->historyRowOf($instanceId, 'custom1');
        $this->assertSame(ProcessTaskState::FINISHED, $history->getTaskState(),
            '记录类节点落的是 DONE(20) 历史行，不是 DOING(10) 待办行（§6.1 禁的第 ① 种形状）');
        $this->assertSame('通知外部系统', $history->getDisplayName());
        $this->assertSame(['user1'], $history->getActorIds(), '参与者＝当前操作人（留痕主体）');
        $this->assertSame('user1', $history->getActorId(),
            'operator 列必须落值，否则本栈「我已办」按 state<>10 AND operator=? 查不到这条留痕');
        $this->assertNotNull($history->getFinishTime(), 'DONE 行要有办结时间，否则留痕是半成品');
        // 两条建单不变量（issues/121 P1）：parent ＋ 行级首节点标记，记录类同样适用
        $this->assertSame($applyTaskId, $history->getParentTaskId(),
            'task_parent_id 照建单不变量走（血缘版回退要读它）');
        $this->assertFalse((bool) $history->getVariables()->get(FlowConst::IS_FIRST_TASK_NODE),
            'custom1 不是 start 的直接后继 ⇒ isFirstTaskNode=false');

        $this->assertSame(0, count($this->repo->findDoingTasks($instanceId)),
            '待办必须清零：custom 节点既不许多出一条 DOING 行，也不许把流程卡在这一格');
        $this->assertSame(ProcessInstanceState::FINISHED,
            $this->repo->findInstanceById($instanceId)?->getState(),
            '令牌沿出边继续流转 ⇒ 实例走到终点（改前节点被吞、流程停在 apply 之后）');

        // 对照组：同一条流去掉 custom 节点，待办数与历史行数都不该因 custom 而多出一条
        [$ctlInstance, $ctlApply] = $this->startFlow('control-to-end', self::flow('control-to-end', [
            self::taskNode('apply', 'applicant'),
        ]));
        $this->engine->executeProcessTask($ctlApply, 'user1', FlowData::create());
        $this->assertSame(
            count($this->repo->findDoingTasks($ctlInstance)),
            count($this->repo->findDoingTasks($instanceId)),
            '带 custom 的流与不带 custom 的对照流，待办数必须相同（§6.1 硬结论 2：按待办数做的跨栈对账要把它摘出去）'
        );
        $this->assertCount(2, $this->repo->findHistoryTasks($instanceId),
            '历史行＝apply + custom1 两条（custom 那一条是新增的留痕，不是待办）');
    }

    /**
     * §6.2 第 1 条点名 java 犯的洞：「只在聚合内存对象里 append 一条不算做到」。
     * 本栈必须**两条腿都有**：聚合根 tasks 里有一条 **且** 仓储读得到同一 taskId 的那一行。
     */
    public function testHistoryRowReachesRepositoryNotJustAggregate(): void
    {
        $this->handlers->register('emit.handler', fn(Execution $e, array $args = []) => 'PAY-1');
        [$instanceId, $applyTaskId] = $this->startFlow('custom-aggregate', self::flow('custom-aggregate', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler']),
        ]));
        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $instance = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($instance);
        $inAggregate = array_values(array_filter(
            $instance->getTasks(),
            fn($t) => $t->getTaskName() === 'custom1'
        ));
        $this->assertCount(1, $inAggregate, '聚合根里应有那条 DONE 行');
        $row = $this->repo->findTaskById((string) $inAggregate[0]->getTaskId());
        $this->assertNotNull($row,
            '聚合里那条必须能从仓储按 taskId 读回（只 append 进 instance->tasks 不算落库＝java 的洞）');
        $this->assertSame(ProcessTaskState::FINISHED, $row->getTaskState());
    }

    /**
     * 不为记录类节点出现码 3（PROCESS_TASK_START）：码 3 表达"新待办产生"。
     * 探针＝监听器收到的全部码 3 的 sourceId 集合，必须只含发起时那条 apply，
     * 不含 custom1 历史行的 taskId。
     */
    public function testNoTaskStartEventForHistoryRow(): void
    {
        $this->handlers->register('emit.handler', fn(Execution $e, array $args = []) => 'PAY-2');
        [$instanceId, $applyTaskId] = $this->startFlow('custom-events', self::flow('custom-events', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler']),
        ]));
        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $taskStartSourceIds = array_map(
            fn(ProcessEvent $e) => (string) $e->getSourceId(),
            $this->listener->ofType('PROCESS_TASK_START')
        );
        $historyTaskId = (string) $this->historyRowOf($instanceId, 'custom1')->getTaskId();

        $this->assertNotSame([], $taskStartSourceIds, '前置：发起时那条 apply 的码 3 要发出来（否则本格恒真空）');
        $this->assertNotContains($historyTaskId, $taskStartSourceIds,
            '历史行严禁收编进码 3 那条腿（§6.1 码 3 表达"新待办产生"）');
        $this->assertSame([$applyTaskId], $taskStartSourceIds,
            '整条流转只应有一条"新待办产生"＝发起时的 apply，办理后没有新待办');
    }

    // ═══ 3. clazz 不可解析：两档日志分别可诊断，且不炸建单 ═══

    /**
     * 档「clazz 为空串／属性缺失」⇒ 记日志 + 照常落历史行 + 续流，**不抛异常**（§6.2 第 2 条）。
     * 文案要能与另一档区分开。
     */
    public function testBlankClazzLogsItsOwnDiagnosisAndFlowContinues(): void
    {
        [$instanceId, $applyTaskId] = $this->startFlow('custom-blank-clazz', self::flow('custom-blank-clazz', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', []),           // 完全没有 clazz
        ]));

        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create()); // 不抛即合格

        $log = $this->logTail();
        $this->assertStringContainsString('未配置 clazz', $log,
            '空串档要有自己的可诊断文案，实际文案=' . $log);
        $this->assertStringContainsString('nodeId=custom1', $log, '诊断须带节点 id，实际文案=' . $log);
        $this->assertStringNotContainsString('未注册处理器', $log,
            '两档文案不得混用（§6.2 点名 c# 把两者合成同一个异常的病），实际文案=' . $log);
        $this->assertSame(ProcessTaskState::FINISHED, $this->historyRowOf($instanceId, 'custom1')->getTaskState(),
            'clazz 配错也照样要落历史行——"记日志但停在原地"同样违反 §6.2');
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($instanceId)?->getState());
    }

    /**
     * 档「clazz 非空但未注册」⇒ 同样记日志 + 落历史行 + 续流，日志带**具体 clazz 名**（§6.2 第 2 条）。
     */
    public function testUnregisteredClazzLogsItsOwnDiagnosisAndFlowContinues(): void
    {
        // 注册表在场，但里面没有这个名字（≠ 注册表整体缺席，那是另一档形状）
        $this->handlers->register('other.handler', fn() => null);

        [$instanceId, $applyTaskId] = $this->startFlow('custom-unregistered', self::flow('custom-unregistered', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'com.mldong.jeeflow.test.TestCustomHandler']),
        ]));

        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $log = $this->logTail();
        $this->assertStringContainsString('未注册处理器', $log,
            '未注册档要有自己的可诊断文案，实际文案=' . $log);
        $this->assertStringContainsString('clazz=com.mldong.jeeflow.test.TestCustomHandler', $log,
            '未注册档必须点名具体 clazz，集成方一眼看出是忘注册还是名字写错，实际文案=' . $log);
        $this->assertStringContainsString('nodeId=custom1', $log);
        $this->assertStringNotContainsString('未配置 clazz', $log,
            '两档文案不得混用，实际文案=' . $log);
        $this->assertSame(ProcessTaskState::FINISHED, $this->historyRowOf($instanceId, 'custom1')->getTaskState());
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($instanceId)?->getState());
    }

    /**
     * 处理器缺席注册表整体（集成方压根没 put CustomHandlerRegistry）⇒ 走未注册档，
     * 不得因为"注册中心都没配"把流程炸掉（配错不该炸流程，与 spec/04 同一条哲学）。
     */
    public function testMissingRegistryDoesNotBreakFlow(): void
    {
        ServiceContext::remove(CustomHandlerRegistry::class);

        [$instanceId, $applyTaskId] = $this->startFlow('custom-no-registry', self::flow('custom-no-registry', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler']),
        ]));
        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $this->assertStringContainsString('未注册处理器', $this->logTail(), '实际文案=' . $this->logTail());
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($instanceId)?->getState());
    }

    // ═══ 4. 处理器自身失败 ⇒ 外抛（负向对照，业务错误不在豁免内）═══

    public function testHandlerFailurePropagates(): void
    {
        $this->handlers->register('boom.handler', function (Execution $e, array $args = []): void {
            throw new \RuntimeException('外部系统拒单');
        });
        [, $applyTaskId] = $this->startFlow('custom-boom', self::flow('custom-boom', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'boom.handler']),
        ]));

        // 已解析到处理器、跑炸了 ⇒ 不在 §6.2 第 2 条豁免内，照旧外抛（业务错误不能变静默）
        $thrown = null;
        try {
            $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());
        } catch (\Throwable $t) {
            $thrown = $t;
        }
        $this->assertNotNull($thrown, '处理器自身执行失败必须外抛，不许被"记日志继续"吞掉');
        $this->assertStringContainsString('外部系统拒单', $thrown->getMessage());
    }

    /**
     * 已解析到处理器、但 `methodName` 指的方子不存在 ⇒ **配错档**（记日志＋照常落历史行＋续流）。
     *
     * ⚠️ 本格于 2026-09-30 按 spec 02 §6.2 第 2 条的**豁免面边界**改判（原名
     * testMissingMethodOnResolvedHandlerPropagates，断言 expectException 无法找到方法名称）。
     * 八栈派工回来对表发现两栈各自解读了同一句：java 把"方法找不到"归配错档记日志继续，
     * php 当时按 java 的**旧**文案外抛。细则收成一句——**「这一行处理器代码有没有被执行过」**：
     * 没执行过（clazz 空/未注册、反射失败、methodName 未配或找不到、参数形状对不上）＝配错档不炸；
     * 执行过（方法体内部抛的）＝业务错档照旧外抛（由 testHandlerFailurePropagates 钉着）。
     * 配置项在调用之前就错，外抛只会把整条建单炸掉，并不比一条带 class+method 名的日志更可诊断。
     */
    public function testMissingMethodOnResolvedHandlerIsConfigArmAndFlowContinues(): void
    {
        $this->handlers->register('emit.handler', fn() => 'x');
        [$instanceId, $applyTaskId] = $this->startFlow('custom-no-method', self::flow('custom-no-method', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'emit.handler', 'methodName' => 'noSuchMethod']),
        ]));

        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $row = $this->historyRowOf($instanceId, 'custom1');
        $this->assertSame(20, $row->getTaskState(), '方法配错也不能妨碍留痕行照常落库');
        $log = $this->logTail();
        $this->assertStringContainsString('methodName', $log, '日志要能指到配错的那一项：' . $log);
        $this->assertStringContainsString('noSuchMethod', $log, '日志要带方法名原值：' . $log);
        $this->assertStringContainsString('emit.handler', $log, '日志要带 clazz，实际文案=' . $log);
        $this->assertStringNotContainsString('无法找到方法名称', $log,
            '旧的异常文案不该再出现在这条路上（那档已归配错、只记日志）');
    }

    // ═══ 5. 返回值落执行变量 ═══

    /**
     * 上游 custom 节点的返回值落进执行变量，下游节点读得到：
     * ① 配了 `val` ⇒ 落自定义键；② 没配 `val` ⇒ 落 `custom_return_val`。
     * 探针＝第二个 custom 节点（peek 处理器）把读到的值记下来，跨节点观察比断内部对象更硬。
     */
    public function testReturnValueLandsInExecutionVariable(): void
    {
        // 两条处理器形状都要能跑通：
        //   ① 具名方法腿（java CustomModel「非 IHandler ⇒ 按 methodName 反射调方法」的同形）
        //   ② SPI 腿（CustomHandlerInterface::handle，properties.methodName 未配时回落 handle）
        $exec = new ExecutingCustomHandler('PAY-100');
        $spi = new ReturningCustomHandler('DEFAULT-RET');
        $this->handlers->register('exec.handler', $exec);
        $this->handlers->register('spi.handler', $spi);
        $peekCustomResult = new PeekCustomHandler('customResult');
        $peekDefaultKey = new PeekCustomHandler(FlowConst::CUSTOM_RETURN_VAL);
        $this->handlers->register('peek.customResult', $peekCustomResult);
        $this->handlers->register('peek.custom_return_val', $peekDefaultKey);

        // ① val=customResult ⇒ 落自定义键；顺带钉 properties.args 的传参腿
        [$i1, $a1] = $this->startFlow('custom-var-named', self::flow('custom-var-named', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'exec.handler', 'methodName' => 'execute',
                                          'args' => 'param1', 'val' => 'customResult']),
            self::customNode('custom2', ['clazz' => 'peek.customResult']),
        ]), FlowData::create()->set('param1', 'P-100'));
        $this->engine->executeProcessTask($a1, 'user1', FlowData::create());
        $this->assertSame([['P-100']], $exec->callsWith,
            'properties.args 逗号串要按变量 key 从执行变量里取实参（顺序保留，缺失档为 null，同 java getArgs）');
        $this->assertSame('PAY-100', $peekCustomResult->seen,
            '返回值要落到 properties.val 指的键，且下游节点读得到（令牌带着执行变量往前走）');

        // ② 未配 val ⇒ 落 custom_return_val；未配 args ⇒ 实参为空数组
        [$i2, $a2] = $this->startFlow('custom-var-default', self::flow('custom-var-default', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'spi.handler']),
            self::customNode('custom2', ['clazz' => 'peek.custom_return_val']),
        ]));
        $this->engine->executeProcessTask($a2, 'user1', FlowData::create());
        $this->assertSame([[]], $spi->callsWith, '未配 args ⇒ 实参数组为空（不是 [null]）');
        $this->assertSame('DEFAULT-RET', $peekDefaultKey->seen,
            'FlowConst::CUSTOM_RETURN_VAL 这一缺省键必须真的被接上（此前全仓零调用者）');
        $this->assertNotEmpty($this->historyRowOf($i2, 'custom1'), '两档都照常落历史行');
    }

    /**
     * 处理器返回 null ⇒ 不写键（java `IHandler` 那一支自己不写返回值，靠夹具处理器自填）。
     * 形状探针：实现既有 `HandlerInterface`（`handle(Execution): void`）的处理器也要能被调用，
     * 且不得因为 void 往执行变量里塞 null。
     */
    public function testVoidHandlerIsInvokedWithoutWritingVariable(): void
    {
        $void = new class implements HandlerInterface {
            public int $calls = 0;
            public function handle(Execution $execution): void
            {
                $this->calls++;
            }
        };
        $this->handlers->register('void.handler', $void);
        $peek = new PeekCustomHandler(FlowConst::CUSTOM_RETURN_VAL);
        $this->handlers->register('peek.default', $peek);

        [, $applyTaskId] = $this->startFlow('custom-void-handler', self::flow('custom-void-handler', [
            self::taskNode('apply', 'applicant'),
            self::customNode('custom1', ['clazz' => 'void.handler']),
            self::customNode('custom2', ['clazz' => 'peek.default']),
        ]));
        $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());

        $this->assertSame(1, $void->calls, 'HandlerInterface（void 支）形状也要能被按名解析并执行，不写返回值');
        $this->assertFalse($peek->present,
            'void 处理器（返回 null）不得往执行变量里写键——java IHandler 那一支同样不写返回值');
    }

    // ═══ 6. §6.1 第 3 条：任务类腿合规性钉子（本栈本来就合规，钉住它不变形）═══

    /**
     * 记录类不解析参与者，反过来**任务类零参与者必须建一条 DOING 行**（§6.1 表第一行）。
     * 本栈 `CreateTaskHandler` 一直是无条件建单（无 actors 判空），实读确认合规 ⇒
     * 本单不改它，只钉一格防"顺手加判空"把 §6.1 点名的死锁黑洞（实例 state=10 却零可办行）造出来。
     */
    public function testTaskLegStillCreatesRowWithZeroActors(): void
    {
        [$instanceId] = $this->startFlow('zero-actor-task', json_encode([
            'name' => 'zero-actor-task', 'displayName' => '零参与者任务', 'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200, 'properties' => [],
                 'text' => ['value' => '开始']],
                // 没有 assignee / candidateUsers / assignmentHandler ⇒ 参与者解析为空
                ['id' => 'nobody', 'type' => 'snaker:task', 'x' => 300, 'y' => 200,
                 'properties' => ['taskType' => 0, 'performType' => 0], 'text' => ['value' => '没人办的节点']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 500, 'y' => 200, 'properties' => [],
                 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e1', 'sourceNodeId' => 'start', 'targetNodeId' => 'nobody', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'nobody', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $doing, '零参与者也要建一行 DOING（§6.1 禁的形状①：因为"没人可办"就跳过建行）');
        $this->assertSame('nobody', $doing[0]->getTaskName());
        $this->assertSame([], $doing[0]->getActorIds(), '行建出来，参与者为空——不兜底挂当前操作人（硬结论 1）');
    }

    // ═══ 7. 自带夹具现在能跑通 ═══

    /**
     * `flows/08-custom-node.json`（八栈共享夹具，`clazz` 是一个 Java 全限定类名）
     * 在 php 栈从"被无声吞掉"变成跑得通：apply → custom1（历史行）→ end。
     */
    public function testSharedFixtureNowRuns(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/08-custom-node.json');
        $this->assertNotFalse($json, '08-custom-node.json 必须存在');
        $this->handlers->register('com.mldong.jeeflow.test.TestCustomHandler', new ExecutingCustomHandler('FIXTURE-OK'));

        $this->repo->addDefine(['id' => '908', 'name' => 'custom-node', 'displayName' => '自定义节点流程',
            'type' => 'approval', 'state' => 1, 'content' => $json, 'version' => 1]);

        $model = ModelParser::parse($json);
        $this->assertInstanceOf(CustomModel::class, $model->getNode('custom1'),
            '夹具里的 custom1 必须进模型（spec 02 点名本栈的那条现读反面样本）');

        $instance = $this->engine->startProcessInstanceById('908', 'user1', FlowData::create()->set('param1', 'P-1'));
        $instanceId = (string) $instance->getInstanceId();
        $apply = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $apply, '前置：夹具应起出发起申请待办');

        $this->engine->executeProcessTask((string) $apply[0]->getTaskId(), 'user1', FlowData::create());

        $history = $this->historyRowOf($instanceId, 'custom1');
        $this->assertSame(ProcessTaskState::FINISHED, $history->getTaskState());
        $this->assertSame((string) $apply[0]->getTaskId(), (string) $history->getParentTaskId());
        $this->assertSame(0, count($this->repo->findDoingTasks($instanceId)));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($instanceId)?->getState());
    }

    // ═══ 夹具与辅助 ═══

    /**
     * @return array{0:string,1:string} [instanceId, apply 任务 id]
     */
    private function startFlow(string $name, string $json, ?FlowData $args = null): array
    {
        $id = 'd-' . $name;
        $this->repo->addDefine(['id' => $id, 'name' => $name, 'displayName' => '记录类节点用例',
            'type' => 'approval', 'state' => 1, 'content' => $json, 'version' => 1]);
        $instance = $this->engine->startProcessInstanceById($id, 'user1', $args ?? FlowData::create());
        $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertNotEmpty($doing, "夹具前提：{$name} 发起后应有一条待办");
        return [(string) $instance->getInstanceId(), (string) $doing[0]->getTaskId()];
    }

    private function historyRowOf(string $instanceId, string $taskName): \Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($this->repo->findHistoryTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) {
                return $t;
            }
        }
        $this->fail("夹具前提：仓储里读不到 {$taskName} 那一行（spec 02 §6.2 第 1 条：只在内存对象里 append 不算做到）");
    }

    private function logTail(): string
    {
        return is_file((string) $this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private static function startNode(): array
    {
        return ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200,
                'properties' => [], 'text' => ['value' => '开始']];
    }

    private static function endNode(): array
    {
        return ['id' => 'end', 'type' => 'snaker:end', 'x' => 900, 'y' => 200,
                'properties' => [], 'text' => ['value' => '结束']];
    }

    private static function taskNode(string $id, string $assignee): array
    {
        return ['id' => $id, 'type' => 'snaker:task', 'x' => 260, 'y' => 200,
                'properties' => ['assignee' => $assignee, 'taskType' => 0, 'performType' => 0],
                'text' => ['value' => '发起申请']];
    }

    private static function customNode(string $id, array $properties): array
    {
        return ['id' => $id, 'type' => 'snaker:custom', 'x' => 420, 'y' => 200,
                'properties' => $properties + ['width' => 100, 'height' => 50],
                'text' => ['value' => '通知外部系统']];
    }

    /** start → nodes[0] → nodes[1] → … → end 的线性链。 */
    private static function flow(string $name, array $nodes, array $extraProps = []): string
    {
        $chain = array_merge([self::startNode()], $nodes, [self::endNode()]);
        $edges = [];
        for ($i = 0, $n = count($chain) - 1; $i < $n; $i++) {
            $edges[] = ['id' => 'e' . $i, 'sourceNodeId' => $chain[$i]['id'],
                        'targetNodeId' => $chain[$i + 1]['id'], 'properties' => []];
        }
        return json_encode([
            'name' => $name, 'displayName' => '记录类节点用例', 'type' => 'approval',
            'nodes' => $chain, 'edges' => $edges,
        ], JSON_UNESCAPED_UNICODE);
    }
}
