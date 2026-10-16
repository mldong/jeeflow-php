<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Interceptor\FlowInterceptor;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
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
        $this->sink = new R91Sink();
        foreach ($this->names as $name => $tag) {
            FlowInterceptorRegistry::register($name, new R91Recorder($tag, $this->sink));
        }
    }

    protected function tearDown(): void
    {
        FlowInterceptorRegistry::clear();
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
