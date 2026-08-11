<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Model\DecisionModel;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\ForkModel;
use Jeeflow\Core\Model\JoinModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\StartModel;
use Jeeflow\Core\Model\SubProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\Parser\ModelParser;
use PHPUnit\Framework\TestCase;

/**
 * ModelParser 单元测试 —— 验证各节点类型的解析
 */
class ModelParserTest extends TestCase
{
    protected function tearDown(): void
    {
        ModelParser::reset();
    }

    private function parseFlow(string $jsonFile): ProcessModel
    {
        $json = file_get_contents($jsonFile);
        $this->assertNotFalse($json);
        return ModelParser::parse($json);
    }

    private function flowsDir(): string
    {
        return __DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/';
    }

    // ═══ 节点类型解析 ═══

    public function testParseStartNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $start = $model->getStart();
        $this->assertNotNull($start);
        $this->assertInstanceOf(StartModel::class, $start);
        $this->assertSame('start', $start->getName());
    }

    public function testParseEndNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $ends = $model->getModels(EndModel::class);
        $this->assertCount(1, $ends);
        $this->assertSame('end', $ends[0]->getName());
    }

    public function testParseTaskNodes(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $tasks = $model->getModels(TaskModel::class);
        $this->assertCount(2, $tasks); // apply + task1

        $apply = $model->getNode('apply');
        $this->assertInstanceOf(TaskModel::class, $apply);
        $this->assertSame('apply-form', $apply->getForm());
        $this->assertSame('applicant', $apply->getAssignee());
    }

    public function testParseTaskProperties(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '02-multi-task.json');
        $task1 = $model->getNode('task1');
        $this->assertInstanceOf(TaskModel::class, $task1);
        $this->assertSame('leave-form', $task1->getForm());
        $this->assertSame('leader', $task1->getAssignee());
        $this->assertSame(0, $task1->getTaskType());
        $this->assertSame(0, $task1->getPerformType());
    }

    public function testParseDecisionNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '03-decision-expr.json');
        $decision = $model->getNode('decision1');
        $this->assertInstanceOf(DecisionModel::class, $decision);
        $this->assertSame('amount > 1000', $decision->getExpr());
    }

    public function testParseDecisionEdges(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '03-decision-expr.json');
        $decision = $model->getNode('decision1');
        $outputs = $decision->getOutputs();
        $this->assertCount(2, $outputs);

        // 检查边的表达式
        $exprs = array_map(fn(TransitionModel $t) => $t->getExpr(), $outputs);
        $this->assertContains('amount > 1000', $exprs);
        $this->assertContains('amount <= 1000', $exprs);
    }

    public function testParseForkNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '04-fork-join.json');
        $fork = $model->getNode('fork1');
        $this->assertInstanceOf(ForkModel::class, $fork);
        $this->assertCount(2, $fork->getOutputs(), 'fork 应有 2 条输出边');
    }

    public function testParseJoinNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '04-fork-join.json');
        $join = $model->getNode('join1');
        $this->assertInstanceOf(JoinModel::class, $join);
        $this->assertCount(2, $join->getInputs(), 'join 应有 2 条输入边');
        $this->assertCount(1, $join->getOutputs(), 'join 应有 1 条输出边');
    }

    public function testParseCountersignTask(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '05-countersign-parallel.json');
        $task1 = $model->getNode('task1');
        $this->assertInstanceOf(TaskModel::class, $task1);
        $this->assertSame(1, $task1->getPerformType(), 'performType 应为 COUNTERSIGN');
        $this->assertSame('userA,userB,userC', $task1->getAssignee());
    }

    // ═══ 流程结构验证 ═══

    public function testProcessModelName(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $this->assertSame('simple', $model->getName());
    }

    public function testProcessModelAllNodes(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        // start + apply + task1 + end = 4
        $this->assertCount(4, $model->getNodes());
    }

    public function testProcessModelGetNode(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $this->assertNotNull($model->getNode('apply'));
        $this->assertNull($model->getNode('nonexistent'));
    }

    public function testProcessModelGetModels(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '02-multi-task.json');
        $tasks = $model->getModels(TaskModel::class);
        $this->assertCount(4, $tasks); // apply + task1 + task2 + task3
    }

    // ═══ 边连接验证 ═══

    public function testTransitionSourceTarget(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '01-simple.json');
        $apply = $model->getNode('apply');
        $outputs = $apply->getOutputs();
        $this->assertCount(1, $outputs);
        $this->assertSame('task1', $outputs[0]->getTarget()->getName());
        $this->assertSame('apply', $outputs[0]->getSource()->getName());
    }

    public function testForkJoinConnectivity(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '04-fork-join.json');

        // fork → taskA, taskB
        $fork = $model->getNode('fork1');
        $forkTargets = array_map(fn(TransitionModel $t) => $t->getTarget()->getName(), $fork->getOutputs());
        sort($forkTargets);
        $this->assertSame(['taskA', 'taskB'], $forkTargets);

        // taskA → join, taskB → join
        $join = $model->getNode('join1');
        $joinSources = array_map(fn(TransitionModel $t) => $t->getSource()->getName(), $join->getInputs());
        sort($joinSources);
        $this->assertSame(['taskA', 'taskB'], $joinSources);
    }

    // ═══ 复杂流程解析 ═══

    public function testParseAllFlowsNoException(): void
    {
        $dir = $this->flowsDir();
        $files = glob($dir . '*.json');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $model = $this->parseFlow($file);
            $this->assertNotNull($model->getStart(), basename($file) . ' 应有开始节点');
            $this->assertNotEmpty($model->getNodes(), basename($file) . ' 应有节点');
        }
    }

    public function testParseRejectFlow(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '09-with-reject.json');
        $this->assertNotNull($model);
        $this->assertNotNull($model->getStart());
        $tasks = $model->getModels(TaskModel::class);
        $this->assertNotEmpty($tasks);
    }

    public function testParseMixedModeFlow(): void
    {
        $model = $this->parseFlow($this->flowsDir() . '10-mixed-mode.json');
        $this->assertNotNull($model);
        $allNodes = $model->getNodes();
        $this->assertGreaterThan(3, count($allNodes));
    }
}
