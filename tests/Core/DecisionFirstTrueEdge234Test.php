<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Model\DecisionModel;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/234 丙-9（R90 拍 A）：决策节点「首条真边获胜」——命中即 break（php 半边）。
 * 改前 foreach 无 break，多真边全部执行＝多路并行流转。变异＝摘 break，判据复红。
 */
final class DecisionFirstTrueEdge234Test extends TestCase
{
    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(ExpressionEvaluatorInterface::class, new class implements ExpressionEvaluatorInterface {
            public function eval(string $expression, $variables): mixed
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    public function testFirstTrueEdgeWins(): void
    {
        $hits = [];
        $decision = new class extends DecisionModel {
            public function execPublic(Execution $e): void
            {
                $this->exec($e);
            }
        };

        $e1 = $this->edge($decision, 't1', 'always-true', new RecordingNode234('t1', $hits));
        $e2 = $this->edge($decision, 't2', 'always-true', new RecordingNode234('t2', $hits));

        $decision->execPublic(new Execution());

        $this->assertSame(['t1'], $hits, '首条真边获胜：只有第一条边被执行（改前 hits=[t1, t2]）');
        $this->assertTrue($e1->isEnabled());
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

final class RecordingNode234 extends NodeModel
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
