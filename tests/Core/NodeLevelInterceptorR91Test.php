<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Interceptor\FlowInterceptor;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/234 §4.2 → 队列 R91 的 **php 侧判据**（owner 拍的"参考侧也要补牙"里剩的那一半）。
 *
 * 现读事实（写本件前先跑过 grep）：php **实现齐全**＝`NodeModel::execute()` 走
 * `execPreInterceptors → exec → execPostInterceptors`，两档都是"节点声明非空即只跑节点的，
 * 空才回落 `ProcessModel` 的根级声明"（`NodeModel.php:40-53`），未注册名显式抛
 * `拦截器未注册: <名字>`（`:66-68`，issues/60 那条）；缺的是**零枚照这条腿的用例**
 * ——八栈里 java/csharp/go/py/node/rust/moon 都有节点级档，php 一处没有，
 * 所以"回落"与"不叠加"这两条语义在本栈此前可以随便改而没人报红。
 *
 * 判据档位逐字对齐 csharp `NodeLevelInterceptorR91Tests`（同名同形五档）＋本栈多一枚顺序哨兵，
 * 因为本栈的派发点就在 `NodeModel::execute()` 里，能直接照到 pre/体/post 的先后。
 */
final class NodeLevelInterceptorR91Test extends TestCase
{
    /** @var array<string,string> 声明名 → 记入 sink 的标记 */
    private array $names = [
        'r91.A' => 'A',
        'r91.B' => 'B',
        'r91.C' => 'C',
        'r91.D' => 'D',
    ];

    private R91Sink $sink;

    protected function setUp(): void
    {
        FlowInterceptorRegistry::clear();
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->sink = new R91Sink();
        foreach ($this->names as $name => $tag) {
            FlowInterceptorRegistry::register($name, new R91Recorder($tag, $this->sink));
        }
    }

    protected function tearDown(): void
    {
        FlowInterceptorRegistry::clear();
        ServiceContext::clear();
        ModelParser::reset();
    }

    /** 节点级 post 声明生效，且**不与根级叠加**（spec02 §3「缺省回落流程级」的另一半＝非空即替换） */
    public function testNodeDeclarationWinsNoStacking(): void
    {
        $node = new R91SilentNode();
        $this->runNode($node, post: 'r91.B', rootPost: 'r91.A');

        self::assertContains('B', $this->sink->marks, '节点档没被调到＝判据空转');
        self::assertNotContains('A', $this->sink->marks, '根级与节点级叠加了＝两档同时跑（应替换而非并集）');
    }

    /** 节点没声明 ⇒ 回落流程级（root 的 post 要在**每个**节点上被读到） */
    public function testFallsBackToProcessRoot(): void
    {
        $this->runNode(new R91SilentNode(), post: null, rootPost: 'r91.A');

        self::assertSame(['A'], $this->sink->marks, '回落档实得=' . json_encode($this->sink->marks));
    }

    /** pre 与 post 同优先级：只声明根级时读根级；节点声明 pre 后不再叠加根级 pre */
    public function testRootAndNodePreUseSamePriority(): void
    {
        $this->runNode(new R91SilentNode(), pre: null, rootPre: 'r91.C');
        self::assertSame(['C'], $this->sink->marks, '根级 pre 没被读到实得=' . json_encode($this->sink->marks));

        $this->sink->marks = [];
        $this->runNode(new R91SilentNode(), pre: 'r91.D', rootPre: 'r91.C');
        self::assertSame(['D'], $this->sink->marks, '节点 pre 与根 pre 叠加／读错档实得=' . json_encode($this->sink->marks));
    }

    /** 声明了但没注册 ⇒ 显式抛错并带上那个名字（不得静默跳过＝issues/60 那条在本栈的形状） */
    public function testNodeLevelUnregisteredNameThrowsExplicitly(): void
    {
        $ghost = 'com.mldong.jeeflow.ghost.GhostInterceptor';
        try {
            $this->runNode(new R91SilentNode(), post: $ghost, rootPost: null);
            self::fail('未注册名必须显式抛错（实得：正常返回，marks=' . json_encode($this->sink->marks) . '）');
        } catch (JeeflowException $e) {
            self::assertStringContainsString('拦截器未注册', $e->getMessage());
            self::assertStringContainsString($ghost, $e->getMessage(), '文案要能指到是哪个名字没注册');
        }
        self::assertSame([], $this->sink->marks, '抛错前不得先跑掉别的拦截器');
    }

    /** 两个节点各用各的声明（根级留空＝排除"其实是回落救的"） */
    public function testTwoNodesUseTheirOwnDeclarations(): void
    {
        $first = new R91SilentNode();
        $first->setPostInterceptors('r91.A');
        $second = new R91SilentNode();
        $second->setPostInterceptors('r91.B');

        $root = new ProcessModel();
        foreach ([$first, $second] as $n) {
            $e = new Execution();
            $e->setProcessModel($root);
            $n->execute($e);
        }

        self::assertSame(['A', 'B'], $this->sink->marks, '两节点声明串档实得=' . json_encode($this->sink->marks));
    }

    /** 顺序哨兵：pre → 节点体 → post（去掉任一档或调序都会红） */
    public function testPreRunsBeforeBodyAndPostAfterBody(): void
    {
        $node = new R91SilentNode($this->sink);
        $this->runNode($node, pre: 'r91.C', post: 'r91.D');

        self::assertSame(['C', 'body', 'D'], $this->sink->marks,
            '派发顺序实得=' . json_encode($this->sink->marks));
    }

    /** 阴性对照：什么都没声明 ⇒ 一个拦截器都不该跑 */
    public function testNothingDeclaredRunsNothing(): void
    {
        $this->runNode(new R91SilentNode(), pre: null, post: null);
        self::assertSame([], $this->sink->marks, '无声明却跑了拦截器实得=' . json_encode($this->sink->marks));
    }

    /**
     * issues/244-①：**当前节点** postInterceptors 写了没注册的名字时，拦截器解析必须排在
     * 任何状态变更之前——报错即零写入（原任务仍 DOING、id 未变、实例状态不变、任务行总数不变）。
     *
     * 改前形状（红样）：`prepareExecution` 先 `completeTask` + `updateTask`（首写），再
     * `$node->execute()` → `execPostInterceptors` → `runInterceptors` 才 `resolve` 抛
     * 「拦截器未注册」。真库有外层事务整笔回滚看不出来；内存仓储无回滚 ⇒ 报错那一次
     * "当前任务已 DONE、新任务没建" ⇒ 实例 DOING 却零待办、流程失联。
     *
     * 夹具在测试内构造（不动共享 flows/）：start → apply(postInterceptors＝未注册名) → end，
     * apply 的 assignee＝applicant（发起人），办理走 submitType=1（同意）。
     */
    public function testUnregisteredPostInterceptorFailsBeforeAnyWrite(): void
    {
        $ghost = 'com.mldong.jeeflow.ghost.PostGhostInterceptor';
        $repo = new InMemoryProcessRepository();
        $repo->addDefine([
            'id' => 'i244a',
            'name' => 'i244a-ghost-post',
            'displayName' => '未注册后置拦截器(244①夹具)',
            'type' => 'approval',
            'state' => 1,
            'content' => $this->ghostPostFlowJson($ghost),
            'version' => 1,
        ]);
        $engine = new JeeflowEngine($repo);

        $instance = $engine->startProcessInstanceById('i244a', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();
        $applyTask = $instance->getDoingTasks()[0] ?? null;
        self::assertNotNull($applyTask, '发起后应有 apply 待办');
        $taskIdBefore = $applyTask->getTaskId();
        $stateBefore = $instance->getState();
        $countBefore = count($instance->getTasks());

        try {
            $engine->executeProcessTask($taskIdBefore, 'user1',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
            self::fail('未注册的拦截器名必须显式抛错（实得：办理正常返回）');
        } catch (JeeflowException $e) {
            self::assertStringContainsString('拦截器未注册', $e->getMessage());
            self::assertStringContainsString($ghost, $e->getMessage(), '文案要能指到是哪个名字没注册');
        }

        // —— 报错零写入（内存仓储无回滚，这三条就是本病的判据）——
        $taskAfter = $repo->findTaskById($taskIdBefore);
        self::assertNotNull($taskAfter);
        self::assertSame($taskIdBefore, $taskAfter->getTaskId(), '原任务 id 不得变');
        self::assertTrue($taskAfter->isDoing(),
            '原任务必须仍进行中——改前被静默办结（state=' . $taskAfter->getTaskState() . '）');
        $instanceAfter = $repo->findInstanceById($instanceId);
        self::assertSame($stateBefore, $instanceAfter->getState(), '实例状态不得变');
        self::assertCount($countBefore, $instanceAfter->getTasks(), '任务行总数不得变（不得凭空建新行）');
    }

    /**
     * 244① 正向哨兵：**已注册**的 postInterceptors 不受前置守卫影响——照常在节点体之后跑到，
     * 办理照常推进（守卫只查表、不执行拦截器；执行期派发点仍在 {@see NodeModel::execute()}）。
     */
    public function testRegisteredPostInterceptorStillRunsAndFlowProceeds(): void
    {
        $repo = new InMemoryProcessRepository();
        $repo->addDefine([
            'id' => 'i244a-ok',
            'name' => 'i244a-ok-post',
            'displayName' => '已注册后置拦截器(244①正向)',
            'type' => 'approval',
            'state' => 1,
            'content' => $this->ghostPostFlowJson('r91.A'),
            'version' => 1,
        ]);
        $engine = new JeeflowEngine($repo);

        $instance = $engine->startProcessInstanceById('i244a-ok', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();
        $applyTask = $instance->getDoingTasks()[0];
        $taskId = $applyTask->getTaskId();

        $engine->executeProcessTask($taskId, 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        self::assertContains('A', $this->sink->marks, '已注册的 post 必须照常跑到（实得=' . json_encode($this->sink->marks) . '）');
        self::assertTrue($repo->findTaskById($taskId)->isFinished(), '办理应正常完成');
        self::assertSame(ProcessInstanceState::FINISHED, $repo->findInstanceById($instanceId)->getState(),
            'apply → end，实例应办结');
    }

    /** 244① 夹具：start → apply(postInterceptors＝$ghost) → end（测试内构造，不动共享 flows/） */
    private function ghostPostFlowJson(string $ghost): string
    {
        $json = json_encode([
            'name' => 'i244a-ghost-post',
            'displayName' => '未注册后置拦截器(244①夹具)',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200,
                 'properties' => ['width' => 50, 'height' => 50], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 200, 'y' => 200,
                 'properties' => ['width' => 100, 'height' => 50, 'form' => 'apply-form',
                     'assignee' => 'applicant', 'taskType' => 0, 'performType' => 0,
                     'postInterceptors' => $ghost],
                 'text' => ['value' => '发起申请']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 400, 'y' => 200,
                 'properties' => ['width' => 50, 'height' => 50], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ]);
        self::assertNotFalse($json);
        return $json;
    }

    private function runNode(R91SilentNode $node, ?string $pre = null, ?string $post = null,
                         ?string $rootPre = null, ?string $rootPost = null): void
    {
        if ($pre !== null) {
            $node->setPreInterceptors($pre);
        }
        if ($post !== null) {
            $node->setPostInterceptors($post);
        }
        $root = new ProcessModel();
        if ($rootPre !== null) {
            $root->setPreInterceptors($rootPre);
        }
        if ($rootPost !== null) {
            $root->setPostInterceptors($rootPost);
        }
        $e = new Execution();
        $e->setProcessModel($root);
        $node->execute($e);
    }
}

/** 共享记录盒（按引用累加，避开 PHP 构造期拷贝） */
final class R91Sink
{
    /** @var string[] */
    public array $marks = [];
}

final class R91Recorder implements FlowInterceptor
{
    public function __construct(private readonly string $tag, private readonly R91Sink $sink)
    {
    }

    public function intercept(Execution $execution): void
    {
        $this->sink->marks[] = $this->tag;
    }
}

/** 静默节点：可选地把"体"也记进 sink，用于顺序哨兵 */
final class R91SilentNode extends NodeModel
{
    public function __construct(private readonly ?R91Sink $sink = null)
    {
    }

    protected function exec(Execution $execution): void
    {
        if ($this->sink !== null) {
            $this->sink->marks[] = 'body';
        }
    }
}
