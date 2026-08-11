<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * PDO 仓储集成测试 —— MySQL jeeflow_test 库
 *
 * 覆盖核心五表的 CRUD + 引擎完整生命周期。
 */
class PdoProcessRepositoryTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private PdoProcessRepository $repo;
    private JeeflowEngine $engine;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new \PDO('mysql:host=127.0.0.1;dbname=jeeflow_test', 'root', '');
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // 建表
        $schema = file_get_contents(__DIR__ . '/../../packages/repository-pdo/sql/schema-mysql.sql');
        self::$pdo->exec($schema);
    }

    protected function setUp(): void
    {
        // 清空所有表
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM wf_process_task_actor');
        $pdo->exec('DELETE FROM wf_process_task');
        $pdo->exec('DELETE FROM wf_process_instance');
        $pdo->exec('DELETE FROM wf_process_cc_instance');
        $pdo->exec('DELETE FROM wf_process_define');

        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->repo = new PdoProcessRepository($pdo);
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ═══ 定义表 CRUD ═══

    public function testDefineCrud(): void
    {
        $this->repo->addDefine([
            'id' => 'def-1',
            'name' => 'test-flow',
            'displayName' => '测试流程',
            'type' => 'approval',
            'state' => 1,
            'content' => '{"nodes":[],"edges":[]}',
            'version' => 1,
        ]);

        $define = $this->repo->findDefineById('def-1');
        $this->assertNotNull($define);
        $this->assertSame('test-flow', $define['name']);
        $this->assertSame('测试流程', $define['displayName']);
        $this->assertSame(1, $define['state']);
        $this->assertSame('{"nodes":[],"edges":[]}', $define['content']);

        // 不存在的定义
        $this->assertNull($this->repo->findDefineById('non-existent'));
    }

    // ═══ 实例表 CRUD ═══

    public function testInstanceCrud(): void
    {
        $instance = ProcessInstance::create(
            ['id' => 'def-1', 'name' => 'test', 'displayName' => 'Test', 'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1',
            FlowData::of(['key1' => 'val1'])
        );
        $instance->setInstanceId('inst-1');

        $this->repo->saveInstance($instance);

        $loaded = $this->repo->findInstanceById('inst-1');
        $this->assertNotNull($loaded);
        $this->assertSame('inst-1', $loaded->getInstanceId());
        $this->assertSame('def-1', $loaded->getDefineId());
        $this->assertSame('user1', $loaded->getOperator());
        $this->assertSame(ProcessInstanceState::DOING, $loaded->getState());
        $this->assertSame('val1', $loaded->getVariables()->get('key1'));

        // 更新
        $loaded->setState(ProcessInstanceState::FINISHED);
        $loaded->addVariable(FlowData::of(['key2' => 'val2']));
        $this->repo->updateInstance($loaded);

        $reloaded = $this->repo->findInstanceById('inst-1');
        $this->assertSame(ProcessInstanceState::FINISHED, $reloaded->getState());
        $this->assertSame('val2', $reloaded->getVariables()->get('key2'));
    }

    // ═══ 任务表 CRUD ═══

    public function testTaskCrud(): void
    {
        // 先创建实例
        $instance = ProcessInstance::create(
            ['id' => 'def-1', 'name' => 'test', 'displayName' => 'Test', 'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1'
        );
        $instance->setInstanceId('inst-2');
        $this->repo->saveInstance($instance);

        // 创建任务
        $task = ProcessTask::create('inst-2', 'task1', '审批任务', 0, 0, 'form1', ['userA', 'userB'], 'user1');
        $task->setTaskId('task-1');
        $this->repo->saveTask($task);

        // 查找
        $loaded = $this->repo->findTaskById('task-1');
        $this->assertNotNull($loaded);
        $this->assertSame('task1', $loaded->getTaskName());
        $this->assertSame('审批任务', $loaded->getDisplayName());
        $this->assertSame(ProcessTaskState::DOING, $loaded->getTaskState());
        $this->assertSame(['userA', 'userB'], $loaded->getActorIds());

        // 完成任务
        $loaded->finish('userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $this->repo->updateTask($loaded);

        $reloaded = $this->repo->findTaskById('task-1');
        $this->assertSame(ProcessTaskState::FINISHED, $reloaded->getTaskState());
        $this->assertSame('userA', $reloaded->getActorId());
        $this->assertNotNull($reloaded->getFinishTime());
    }

    // ═══ 抄送表 ═══

    public function testCcInstance(): void
    {
        $this->repo->createCcInstance('inst-1', 'user1', ['cc1', 'cc2', 'cc3']);

        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_cc_instance WHERE process_instance_id = \'inst-1\'');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(3, (int) $row['cnt']);
    }

    // ═══ 引擎完整生命周期（PDO 仓储） ═══

    public function testEngineFullLifecycleWithPdo(): void
    {
        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '100',
            'name' => 'simple',
            'displayName' => '简单流程',
            'type' => 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);

        // 1. 启动
        $instance = $this->engine->startProcessInstanceById('100', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();
        $this->assertNotNull($instanceId);

        // 2. 验证数据库中有实例
        $dbInstance = $this->repo->findInstanceById($instanceId);
        $this->assertNotNull($dbInstance);
        $this->assertSame(ProcessInstanceState::DOING, $dbInstance->getState());

        // 3. 验证任务已持久化
        $doingTasks = $dbInstance->getDoingTasks();
        $this->assertCount(1, $doingTasks);
        $this->assertSame('apply', $doingTasks[0]->getTaskName());

        // 4. 完成 apply
        $this->engine->executeProcessTask($doingTasks[0]->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        // 5. 验证 task1 已创建
        $dbInstance = $this->repo->findInstanceById($instanceId);
        $task1 = $this->findDoingTask($dbInstance, 'task1');
        $this->assertNotNull($task1, '应找到 task1');

        // 6. 完成 task1 → 流程结束
        $this->engine->executeProcessTask($task1->getTaskId(), 'leader', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $dbInstance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $dbInstance->getState(), '流程应已完成');

        // 7. 验证所有任务已完成
        foreach ($dbInstance->getTasks() as $t) {
            $this->assertTrue($t->isFinished(), "任务 {$t->getTaskName()} 应已完成");
        }

        // 8. 验证数据库中任务记录
        $stmt = self::$pdo->prepare('SELECT COUNT(*) as cnt FROM wf_process_task WHERE process_instance_id = ?');
        $stmt->execute([$instanceId]);
        $cnt = (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
        $this->assertSame(2, $cnt, '应有 2 条任务记录');

        // 9. 验证 actor 表
        $stmt = self::$pdo->prepare('SELECT COUNT(*) as cnt FROM wf_process_task_actor WHERE process_task_id IN (SELECT id FROM wf_process_task WHERE process_instance_id = ?)');
        $stmt->execute([$instanceId]);
        $actorCnt = (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
        $this->assertGreaterThan(0, $actorCnt, 'actor 表应有记录');
    }

    // ═══ 多级流程 PDO 测试 ═══

    public function testMultiTaskWithPdo(): void
    {
        $flowJson = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/02-multi-task.json');
        $this->assertNotFalse($flowJson);
        $this->repo->addDefine([
            'id' => '200',
            'name' => 'multi-task',
            'displayName' => '多级审批',
            'type' => 'approval',
            'state' => 1,
            'content' => $flowJson,
            'version' => 1,
        ]);

        $instance = $this->engine->startProcessInstanceById('200', 'user1', FlowData::create());
        $instanceId = $instance->getInstanceId();

        // apply → task1 → task2 → task3 → end
        $actors = ['user1', 'leader', 'manager', 'boss'];
        $taskNames = ['apply', 'task1', 'task2', 'task3'];

        foreach ($taskNames as $i => $name) {
            $dbInstance = $this->repo->findInstanceById($instanceId);
            $task = $this->findDoingTask($dbInstance, $name);
            $this->assertNotNull($task, "应找到任务 {$name}");
            $submitType = $i === 0 ? SubmitType::APPLY : SubmitType::AGREE;
            $this->engine->executeProcessTask($task->getTaskId(), $actors[$i], FlowData::of([FlowConst::SUBMIT_TYPE => $submitType]));
        }

        $dbInstance = $this->repo->findInstanceById($instanceId);
        $this->assertSame(ProcessInstanceState::FINISHED, $dbInstance->getState());
        $this->assertCount(4, $dbInstance->getTasks());
    }

    private function findDoingTask(ProcessInstance $instance, string $taskName): ?ProcessTask
    {
        foreach ($instance->getDoingTasks() as $t) {
            if ($t->getTaskName() === $taskName) return $t;
        }
        return null;
    }
}
