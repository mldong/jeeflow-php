<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 「未知档节点在链路中间」时，指向它的那条边必须**落穿成不推进**，严禁把办理打崩
 * —— issues/143（G4 义务 2 的后半，php 会话在 issues/142 A 批普查时撞出来）。
 *
 * <p><b>病灶形状</b>（本栈 `ModelParser.php:124-135` ＋ `TransitionModel.php:25-35`）：
 * 解析期类型表查不到解析器 ⇒ 记一条 WARNING 后 `continue`，节点不进模型；
 * 但**指向它的边**照样进了上游节点的 `outputs`，而那条边的 `target` 只在
 * 「目标节点存在于模型里」时才被 `setTarget()` 赋值 ⇒ 边留着、target 恒 null。
 * 令牌走到上游节点、办结后 `runOutTransition` → `TransitionModel::execute()` →
 * `else { $this->target->execute($execution); }` ⇒
 * <b>`Error: Call to a member function execute() on null`</b>（PHP 8 的 TypeError 级致命错）。</p>
 *
 * <p>为什么这不是"php 独有"而是对象图三栈的共同形状：java `ModelParser.java:99-109`
 * 与 `TransitionModel.java:23-32`、c# `ModelParser.cs:104-110` 是**逐字同形**的
 * （同样只在匹配到节点时 `setTarget`，else 分支同样裸调 `target.execute()`）⇒ 同一把 NPE。
 * 按 id 现查目标的栈（go `findNode(...)!=nil` / python / node / moon `get_target_node` 返回
 * option / rust 把未知节点留在模型里标 Unknown 再由执行腿跳过）都不会炸，只是"停住"。
 * 所以八栈在这一点上分成了两派：一派停住、一派崩。</p>
 *
 * <p><b>判据来自已有裁定，不是新法</b>：spec 02 §6.2 第 2 条（owner 2026-09-30）
 * 「记日志 + 照常落历史行 + 令牌继续流转，<b>严禁抛错打断建单</b>」与 spec 04
 * 「节点属性配错不该把流程炸掉」——一条边指向不存在的节点，正是"配置在调用之前就错"的
 * 同一族；而 G4 义务 2 说的"再决定<b>跳过</b>"，跳过的可观测结果必须是
 * **实例停在未知节点处、库里不产生任何越过它的行**，不是把办理打崩。
 * 修复姿势取与 rust/moon 一致的"停住"（不让令牌越过未知节点）：越过它等于
 * 用一条臆造的通路把误配的定义跑成功，用户面更难发现。</p>
 */
final class DanglingTransitionTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private ?string $logFile = null;
    private string|false $savedErrorLog = false;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $this->logFile = sys_get_temp_dir() . '/jeeflow-dangling-' . getmypid()
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
        ModelParser::reset();
    }

    // ═══ 1. 解析期形状自证：节点不在模型里，但那条边的 target 也没接上 ═══

    /**
     * 形状读数（不是判据，是把"为什么会崩"钉在案发现场）：
     * ① 未知档节点不进模型（G4 义务 2 要求的"记日志再跳过"）；
     * ② 上游节点 apply 的出边**还在**，而它的 `getTarget()` 是 null ⇒ 执行腿裸调就崩。
     */
    public function testDanglingEdgeShapeIsReproducedAtParseTime(): void
    {
        $model = ModelParser::parse($this->flow('dangling-shape', 'snaker:notRegisteredAtAll'));

        $this->assertNull($model->getNode('odd'),
            '夹具前提：未知档节点在解析期被跳过（不进模型）');
        $apply = $model->getNode('apply');
        $this->assertNotNull($apply, '夹具前提：apply 节点正常入模型');
        $outputs = $apply->getOutputs();
        $this->assertCount(1, $outputs, '指向未知节点的边仍然挂在 apply 的 outputs 上');
        $this->assertNull($outputs[0]->getTarget(),
            '该边的 target 没被接上（解析期只在目标节点存在时 setTarget）⇒ 裸调必崩的那一枚 null');
        $this->assertStringContainsString('nodeId=odd', $this->logTail(),
            '解析期那条可诊断记录要带 nodeId（G4 义务 2），实际=' . $this->logTail());
    }

    // ═══ 2. 执行期：办理不得被打崩，实例停在未知节点处 ═══

    /**
     * 核心判据：发起 → 办理 apply（令牌随即撞上指向未知节点的那条边）。
     * 修复后必须：① 不抛任何 Throwable（改前这里是 `Call to a member function execute() on null`）；
     * ② 实例仍是进行中（停住，不办结 ⇒ 未知节点没被"越过"）；
     * ③ 库里没有 `odd` 的任何一行（既不建待办也不建留痕）；
     * ④ 落穿那一刻也要留一条可诊断记录（带 nodeId 与边 id），与解析期那条一起能定位。
     */
    public function testExecutingUpstreamTaskDoesNotCrashOnDanglingEdge(): void
    {
        $json = $this->flow('dangling-execute', 'snaker:notRegisteredAtAll');
        $this->repo->addDefine(['id' => 'd-dangling-execute', 'name' => 'dangling-execute',
            'displayName' => '未知档落穿用例', 'type' => 'approval', 'state' => 1,
            'content' => $json, 'version' => 1]);

        $instance = $this->engine->startProcessInstanceById('d-dangling-execute', 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertNotEmpty($doing, '夹具前提：发起后 apply 有一条待办');
        $applyTaskId = (string) $doing[0]->getTaskId();

        try {
            $this->engine->executeProcessTask($applyTaskId, 'user1', FlowData::create());
        } catch (\Throwable $ex) {
            $this->fail('办理撞上"指向未知节点的边"不得把流程打崩（spec 02 §6.2 第 2 条严禁抛错打断建单／'
                . 'spec 04 节点属性配错不该把流程炸掉），实得 '
                . get_class($ex) . ': ' . $ex->getMessage());
        }

        $fresh = $this->repo->findInstanceById((string) $instance->getInstanceId());
        $this->assertNotNull($fresh);
        $this->assertSame(ProcessInstanceState::DOING, $fresh->getState(),
            '令牌停在未知节点处（不办结＝不假装那条边跑得通）');

        $names = [];
        foreach ($this->repo->findHistoryTasks((string) $instance->getInstanceId()) as $t) {
            $names[] = $t->getTaskName();
        }
        $this->assertNotContains('odd', $names, '未知档不许产生任何行（既不建待办也不建留痕）');
        $this->assertStringContainsString('nodeId=odd', $this->logTail(),
            '落穿那一刻也要有可诊断记录，实际=' . $this->logTail());
    }

    /**
     * 反向对照：**已知档**的链路不该出现"落穿"记录，也不该因为本修复而改变形状
     * （apply → custom1 → end 照常把令牌送到 end，实例办结）。
     * 这一格防的是"把守卫做成什么都吞"——修完之后正常流程必须照旧跑得通。
     */
    public function testKnownChainStillFlowsToEndAfterGuard(): void
    {
        $json = json_encode([
            'name' => 'dangling-known-ok', 'displayName' => '已知档对照', 'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task',
                 'properties' => ['assignee' => 'applicant', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'end', 'type' => 'snaker:end', 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ], JSON_UNESCAPED_UNICODE);
        $this->repo->addDefine(['id' => 'd-known-ok', 'name' => 'dangling-known-ok',
            'displayName' => '已知档对照', 'type' => 'approval', 'state' => 1,
            'content' => $json, 'version' => 1]);

        $instance = $this->engine->startProcessInstanceById('d-known-ok', 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertNotEmpty($doing, '夹具前提：发起后有 apply 待办');
        $this->engine->executeProcessTask((string) $doing[0]->getTaskId(), 'user1', FlowData::create());

        $fresh = $this->repo->findInstanceById((string) $instance->getInstanceId());
        $this->assertNotNull($fresh);
        $this->assertSame(ProcessInstanceState::FINISHED, $fresh->getState(),
            '已知档链路必须照旧走到终点：守卫不得把正常出边也吞掉');
        $this->assertStringNotContainsString('没有对应解析器', $this->logTail(),
            '已知档不该产生未知类型诊断');
        $this->assertStringNotContainsString('出边的目标节点不在模型里', $this->logTail(),
            '已知档不该产生落穿记录');
    }

    // ═══ 夹具 ═══

    /** start → apply(snaker:task) → odd($oddType) → end 的线性链。 */
    private function flow(string $name, string $oddType): string
    {
        return json_encode([
            'name' => $name, 'displayName' => '未知档落穿用例', 'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task',
                 'properties' => ['assignee' => 'applicant', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'odd', 'type' => $oddType, 'properties' => [], 'text' => ['value' => '没登记的类型']],
                ['id' => 'end', 'type' => 'snaker:end', 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'odd', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'odd', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function logTail(): string
    {
        return is_file((string) $this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
