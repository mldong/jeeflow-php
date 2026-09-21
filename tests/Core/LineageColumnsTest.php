<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/121 P1 建单不变量 —— 每次建任务必写 task_parent_id 与行级 isFirstTaskNode。
 *
 * 夹具是 02-multi-task.json：apply → task1 → task2 → task3 四级链。两步流里「上一节点」与
 * 「首任务节点」同格，断言恒真、抓不到缺陷，所以必须用 ≥3 个任务节点的流程。
 */
class LineageColumnsTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->repo->addDefine([
            'id' => '121', 'name' => 'multi-task', 'displayName' => '多级审批流程',
            'type' => 'approval', 'state' => 1, 'version' => 1,
            'content' => (string) file_get_contents(jeeflow_flows_dir() . '/02-multi-task.json'),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    public function testCreateWritesLineageColumns(): void
    {
        $instance = $this->engine->startProcessInstanceById('121', 'user1', FlowData::create());
        $iid = $instance->getInstanceId();

        $apply = $this->doing($iid, 'apply');
        // 发起 execution 没有当前任务 ⇒ parent 落 '0'；apply 是 start 直接后继 ⇒ true
        $this->assertSame('0', (string) $apply->getParentTaskId(), '发起那条 parent 应为 0');
        $this->assertTrue($this->flag($apply), '首任务节点行应落 isFirstTaskNode=true');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', $this->agree());

        $prev = $apply;
        foreach ([['task1', 'leader'], ['task2', 'manager'], ['task3', 'boss']] as [$name, $who]) {
            $task = $this->doing($iid, $name);
            $this->assertSame((string) $prev->getTaskId(), (string) $task->getParentTaskId(),
                "{$name}.parent 应为刚办结的 {$prev->getTaskName()}.id");
            $this->assertFalse($this->flag($task), "非首节点必须 false（{$name}）");
            $this->engine->executeProcessTask($task->getTaskId(), $who, $this->agree());
            $prev = $task;
        }

        // 本案真正要的那格：血缘版回退读的是已办结的历史行，标记必须随行存活
        $his = $this->repo->findTaskById($apply->getTaskId());
        $this->assertNotNull($his);
        $this->assertNotSame(ProcessTaskState::DOING, $his->getTaskState(), 'apply 应已办结');
        $this->assertTrue($this->flag($his), '历史行标记必须还在（现算版在历史行上恒 false）');
        $this->assertSame('0', (string) $his->getParentTaskId(), '历史行血缘指针不应被覆写');
    }

    private function agree(): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]);
    }

    private function doing(string $iid, string $name): \Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($t->getTaskName() === $name) return $t;
        }
        $this->fail("应有进行中的 {$name} 任务");
    }

    private function flag(\Jeeflow\Core\Domain\ProcessTask $t): bool
    {
        $v = $t->getVariables()->toArray()[FlowConst::IS_FIRST_TASK_NODE] ?? null;
        $this->assertNotNull($v, '建单不变量要求行变量里必须有 isFirstTaskNode 键');
        return (bool) $v;
    }
}
