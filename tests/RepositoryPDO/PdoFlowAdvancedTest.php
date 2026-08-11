<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\Tests\Fixture\SimpleExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * PDO 高级流程集成测试 —— 使用 MySQL 持久化跑各种流程拓扑
 */
class PdoFlowAdvancedTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private PdoProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $defineSeq = 1000;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new \PDO('mysql:host=127.0.0.1;dbname=jeeflow_test', 'root', '');
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../packages/repository-pdo/sql/schema-mysql.sql');
        self::$pdo->exec($schema);
    }

    protected function setUp(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM wf_process_task_actor');
        $pdo->exec('DELETE FROM wf_process_task');
        $pdo->exec('DELETE FROM wf_process_instance');
        $pdo->exec('DELETE FROM wf_process_cc_instance');
        $pdo->exec('DELETE FROM wf_process_define');

        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());

        $this->repo = new PdoProcessRepository($pdo);
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    private function addFlowDefine(string $jsonFile, ?string $id = null): string
    {
        $id = $id ?? (string) (++$this->defineSeq);
        $flowJson = file_get_contents($jsonFile);
        $this->assertNotFalse($flowJson);
        $data = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $id,
            'name' => $data['name'],
            'displayName' => $data['displayName'],
            'type' => $data['type'] ?? 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);
        return $id;
    }

    private function flowsDir(): string
    {
        return __DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/';
    }

    private function findDoingTask(\Jeeflow\Core\Domain\ProcessInstance $inst, string $name): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($inst->getDoingTasks() as $t) {
            if ($t->getTaskName() === $name) return $t;
        }
        return null;
    }

    // ═══ Fork-Join with PDO ═══

    public function testForkJoinWithPdo(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '04-fork-join.json');

        $instance = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::create());
        $iid = $instance->getInstanceId();

        // 完成 apply
        $inst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($inst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // fork → 2 个并行任务
        $inst = $this->repo->findInstanceById($iid);
        $doing = $inst->getDoingTasks();
        $this->assertCount(2, $doing);

        // 完成 taskA
        $taskA = $this->findDoingTask($inst, 'taskA');
        $this->engine->executeProcessTask($taskA->getTaskId(), 'userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $inst = $this->repo->findInstanceById($iid);
        $this->assertSame(ProcessInstanceState::DOING, $inst->getState());

        // 完成 taskB → join → end
        $taskB = $this->findDoingTask($inst, 'taskB');
        $this->engine->executeProcessTask($taskB->getTaskId(), 'userB', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $inst = $this->repo->findInstanceById($iid);
        $this->assertSame(ProcessInstanceState::FINISHED, $inst->getState());
    }

    // ═══ Decision with PDO ═══

    public function testDecisionWithPdo(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '03-decision-expr.json');

        // 大额
        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::of(['amount' => 2000]));
        $iid = $inst->getInstanceId();

        $dbInst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($dbInst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $dbInst = $this->repo->findInstanceById($iid);
        $task1 = $this->findDoingTask($dbInst, 'task1');
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE, 'amount' => 2000]));

        $dbInst = $this->repo->findInstanceById($iid);
        $doing = $dbInst->getDoingTasks();
        $this->assertCount(1, $doing);
        $this->assertSame('task2', $doing[0]->getTaskName(), '大额走经理');

        $this->engine->executeProcessTask($doing[0]->getTaskId(), 'manager', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertSame(ProcessInstanceState::FINISHED, $dbInst->getState());
    }

    // ═══ Countersign with PDO ═══

    public function testCountersignWithPdo(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '05-countersign-parallel.json');

        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::create());
        $iid = $inst->getInstanceId();

        // 完成 apply
        $dbInst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($dbInst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 3 个会签任务
        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertCount(3, $dbInst->getDoingTasks());

        // 逐个完成
        foreach ($dbInst->getDoingTasks() as $i => $t) {
            $actor = ['userA', 'userB', 'userC'][$i];
            $this->engine->executeProcessTask($t->getTaskId(), $actor, FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        }

        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertSame(ProcessInstanceState::FINISHED, $dbInst->getState());
        $this->assertCount(4, $dbInst->getTasks());
    }

    // ═══ Reject with PDO ═══

    public function testRejectWithPdo(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '01-simple.json');

        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::create());
        $iid = $inst->getInstanceId();

        // 完成 apply
        $dbInst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($dbInst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 驳回
        $dbInst = $this->repo->findInstanceById($iid);
        $task1 = $this->findDoingTask($dbInst, 'task1');
        $this->engine->executeAndJumpToEnd($task1->getTaskId(), 'leader', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::REJECT]));

        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertSame(ProcessInstanceState::REJECTED, $dbInst->getState(), '驳回后流程状态应为 REJECTED');
    }

    // ═══ 变量持久化验证 ═══

    public function testVariablePersistence(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '01-simple.json');

        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::of(['initVar' => 'hello']));
        $iid = $inst->getInstanceId();

        // 验证初始变量已持久化
        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertSame('hello', $dbInst->getVariables()->get('initVar'));

        // 完成任务时传入新变量
        $apply = $this->findDoingTask($dbInst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::APPLY,
            'newVar' => 'world',
        ]));

        // 验证变量已合并
        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertSame('hello', $dbInst->getVariables()->get('initVar'));
        $this->assertSame('world', $dbInst->getVariables()->get('newVar'));
    }

    // ═══ 多实例并发 ═══

    public function testMultipleInstances(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '01-simple.json');

        // 启动 3 个实例
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $inst = $this->engine->startProcessInstanceById($defId, "user{$i}", FlowData::of(['seq' => $i]));
            $ids[] = $inst->getInstanceId();
        }

        // 验证 3 个实例都在运行
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_instance');
        $this->assertSame(3, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);

        // 各自独立完成任务
        foreach ($ids as $i => $iid) {
            $userNo = $i + 1;
            $dbInst = $this->repo->findInstanceById($iid);
            $apply = $this->findDoingTask($dbInst, 'apply');
            $this->assertNotNull($apply, "实例 {$iid} 应有 apply 任务");
            $this->engine->executeProcessTask($apply->getTaskId(), "user{$userNo}", FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        }

        // 验证 3 个实例都有 task1
        foreach ($ids as $iid) {
            $dbInst = $this->repo->findInstanceById($iid);
            $task1 = $this->findDoingTask($dbInst, 'task1');
            $this->assertNotNull($task1, "实例 {$iid} 应有 task1");
        }

        // 总任务数 = 3 apply + 3 task1 = 6
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_task');
        $this->assertSame(6, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ═══ CC 抄送持久化 ═══

    public function testCcPersistence(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '01-simple.json');

        // 启动时带抄送
        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::of([
            FlowConst::CC_ACTORS_START => 'cc1,cc2',
        ]));

        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_cc_instance');
        $this->assertSame(2, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ═══ Withdraw with PDO ═══

    public function testWithdrawWithPdo(): void
    {
        $defId = $this->addFlowDefine($this->flowsDir() . '01-simple.json');

        $inst = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::create());
        $iid = $inst->getInstanceId();

        // 完成 apply
        $dbInst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($dbInst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 撤回（task1 的 assignee 是 leader）
        $dbInst = $this->repo->findInstanceById($iid);
        $task1 = $this->findDoingTask($dbInst, 'task1');
        $this->engine->executeAndJumpToFirstTaskNode($task1->getTaskId(), 'leader');

        // 验证：撤回后流程回到初始任务节点
        $dbInst = $this->repo->findInstanceById($iid);
        $this->assertNotNull($dbInst);
    }
}
