<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Execution;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\DecisionModel;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/234 丙-10 → 队列 R93（owner 10-15 拍「php 一并钉齐」）：决策节点零命中的失败形状。
 *
 * spec06 §4.6 义务 2「零命中档」（issues/164 立法）＝显式报错，文案逐字取 java
 * `WfErrEnum.NOT_FOUND_NEXT_NODE`（内部码 20010007/20010008 同族，不进 msg），对外信封 99999999＋该 msg。
 * php 旧形状＝`throw new JeeflowException('无法确定下一节点')`：信封 code 早已是 99999999
 * （门面 error() 硬编码），差的是**文案不同串＋异常内部码缺 20010001**——本用例把两档一起钉。
 */
final class DecisionZeroHitMsg236Test extends TestCase
{
    private const EXPECTED_MSG = 'decision节点无法确定下一步执行路线';
    private const EXPECTED_CODE = 20010001;

    protected function setUp(): void
    {
        ServiceContext::clear();
        // 全部表达式判假 ⇒ 遍历完出边仍无命中（零命中档夹具，线性/恒真夹具照不到这一档）
        ServiceContext::put(ExpressionEvaluatorInterface::class, new class implements ExpressionEvaluatorInterface {
            public function eval(string $expression, $variables): mixed
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    public function testZeroHitThrowsVerbatimMsgAndInternalCode(): void
    {
        $hits = [];
        $decision = new class extends DecisionModel {
            public function execPublic(Execution $e): void
            {
                $this->exec($e);
            }
        };
        $this->edge($decision, 't1', '#amount > 1000', new SilentNode236('t1', $hits));
        $this->edge($decision, 't2', '#amount <= 1000', new SilentNode236('t2', $hits));

        try {
            $decision->execPublic(new Execution(['amount' => 500]));
            $this->fail('零命中必须显式报错，不得静默落出（实得：正常返回，hits=' . json_encode($hits) . '）');
        } catch (JeeflowException $e) {
            $this->assertSame(
                self::EXPECTED_MSG,
                $e->getMessage(),
                '文案必须逐字对齐 java WfErrEnum.NOT_FOUND_NEXT_NODE（八栈同串）'
            );
            $this->assertSame(
                self::EXPECTED_CODE,
                $e->getCode(),
                '异常内部码须为 20010001（旧形状用默认 0＝与「无码可归因」同形；对外信封仍由门面转 99999999）'
            );
        }

        $this->assertSame([], $hits, '零命中不得执行任何出边目标');
    }

    private function edge(DecisionModel $from, string $to, string $expr, NodeModel $target): TransitionModel
    {
        $tm = new TransitionModel();
        $tm->setSource($from);
        $tm->setTo($to);
        $tm->setExpr($expr);
        $tm->setTarget($target);
        $from->addOutput($tm);
        return $tm;
    }
}

final class SilentNode236 extends NodeModel
{
    private string $tag;
    /** @var list<string> */
    private array $hits;

    /** @param list<string> $hits */
    public function __construct(string $tag, array &$hits)
    {
        $this->tag = $tag;
        $this->hits = &$hits;
    }

    public function execute(Execution $execution): void
    {
        $this->hits[] = $this->tag;
    }

    protected function exec(Execution $execution): void
    {
    }
}
