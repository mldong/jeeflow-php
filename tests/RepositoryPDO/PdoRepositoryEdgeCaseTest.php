<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * PDO 仓储边界测试 —— 覆盖各种边界条件和数据完整性
 */
class PdoRepositoryEdgeCaseTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private PdoProcessRepository $repo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new \PDO('mysql:host=127.0.0.1;dbname=jeeflow_test', 'root', '');
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../packages/repository-pdo/sql/schema-mysql.sql');
        self::$pdo->exec($schema);
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM wf_process_task_actor');
        self::$pdo->exec('DELETE FROM wf_process_task');
        self::$pdo->exec('DELETE FROM wf_process_instance');
        self::$pdo->exec('DELETE FROM wf_process_cc_instance');
        self::$pdo->exec('DELETE FROM wf_process_define');

        $this->repo = new PdoProcessRepository(self::$pdo);
    }

    // ═══ 定义表边界 ═══

    public function testFindDefineNotFound(): void
    {
        $this->assertNull($this->repo->findDefineById('non-existent'));
    }

    public function testDefineWithNullOptionalFields(): void
    {
        $this->repo->addDefine([
            'id' => 'd-null',
            'name' => 'null-test',
            'displayName' => '空值测试',
        ]);
        $d = $this->repo->findDefineById('d-null');
        $this->assertNotNull($d);
        $this->assertSame('null-test', $d['name']);
        $this->assertNull($d['type']);
    }

    public function testDefineWithLargeContent(): void
    {
        $largeContent = str_repeat('x', 10000);
        $this->repo->addDefine([
            'id' => 'd-large',
            'name' => 'large',
            'displayName' => '大内容',
            'content' => $largeContent,
        ]);
        $d = $this->repo->findDefineById('d-large');
        $this->assertSame($largeContent, $d['content']);
    }

    public function testDefineWithUnicodeContent(): void
    {
        $unicode = '{"name":"测试流程","displayName":"日本語テスト","emoji":"🎉"}';
        $this->repo->addDefine([
            'id' => 'd-uni',
            'name' => 'unicode',
            'displayName' => 'Unicode',
            'content' => $unicode,
        ]);
        $d = $this->repo->findDefineById('d-uni');
        $this->assertSame($unicode, $d['content']);
    }

    // ═══ 实例表边界 ═══

    public function testFindInstanceNotFound(): void
    {
        $this->assertNull($this->repo->findInstanceById('no-such-id'));
    }

    public function testInstanceWithEmptyVariables(): void
    {
        $inst = ProcessInstance::create(
            ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1',
            FlowData::create()
        );
        $inst->setInstanceId('i-empty-vars');
        $this->repo->saveInstance($inst);

        $loaded = $this->repo->findInstanceById('i-empty-vars');
        $this->assertTrue($loaded->getVariables()->isEmpty());
    }

    public function testInstanceWithComplexVariables(): void
    {
        $vars = FlowData::of([
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null_val' => null,
            'array' => [1, 2, 3],
            'nested' => ['a' => ['b' => 'c']],
        ]);
        $inst = ProcessInstance::create(
            ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1',
            $vars
        );
        $inst->setInstanceId('i-complex');
        $this->repo->saveInstance($inst);

        $loaded = $this->repo->findInstanceById('i-complex');
        $this->assertSame('hello', $loaded->getVariables()->get('string'));
        $this->assertSame(42, $loaded->getVariables()->get('int'));
        $this->assertSame(3.14, $loaded->getVariables()->get('float'));
        $this->assertTrue($loaded->getVariables()->get('bool'));
        $this->assertSame([1, 2, 3], $loaded->getVariables()->get('array'));
        $this->assertSame(['a' => ['b' => 'c']], $loaded->getVariables()->get('nested'));
    }

    public function testInstanceStates(): void
    {
        $states = [
            ProcessInstanceState::DOING,
            ProcessInstanceState::FINISHED,
            ProcessInstanceState::WITHDRAW,
            ProcessInstanceState::INTERRUPT,
            ProcessInstanceState::REJECTED,
            ProcessInstanceState::PENDING,
            ProcessInstanceState::ABANDON,
        ];
        foreach ($states as $i => $state) {
            $inst = ProcessInstance::create(
                ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1],
                'user1'
            );
            $inst->setInstanceId("i-state-{$i}");
            $inst->setState($state);
            $this->repo->saveInstance($inst);

            $loaded = $this->repo->findInstanceById("i-state-{$i}");
            $this->assertSame($state, $loaded->getState(), "状态 {$state} 应正确持久化");
        }
    }

    public function testInstanceWithParentInfo(): void
    {
        $inst = ProcessInstance::create(
            ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1',
            null,
            'parent-123',
            'subProcess1'
        );
        $inst->setInstanceId('i-child');
        $this->repo->saveInstance($inst);

        $loaded = $this->repo->findInstanceById('i-child');
        $this->assertSame('parent-123', $loaded->getParentId());
        $this->assertSame('subProcess1', $loaded->getParentNodeName());
    }

    // ═══ 任务表边界 ═══

    public function testFindTaskNotFound(): void
    {
        $this->assertNull($this->repo->findTaskById('no-such-task'));
    }

    public function testTaskWithMultipleActors(): void
    {
        $task = ProcessTask::create('i1', 'task1', '审批', 0, 0, 'form1', ['a', 'b', 'c', 'd', 'e'], 'op');
        $task->setTaskId('t-multi-actor');
        $this->repo->saveTask($task);

        $loaded = $this->repo->findTaskById('t-multi-actor');
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $loaded->getActorIds());
    }

    public function testTaskWithNoActors(): void
    {
        $task = ProcessTask::create('i1', 'task1', '无人任务', 0, 0, null, [], 'op');
        $task->setTaskId('t-no-actor');
        $this->repo->saveTask($task);

        $loaded = $this->repo->findTaskById('t-no-actor');
        $this->assertEmpty($loaded->getActorIds());
    }

    public function testTaskStates(): void
    {
        $states = [
            ProcessTaskState::DOING,
            ProcessTaskState::FINISHED,
            ProcessTaskState::WITHDRAW,
            ProcessTaskState::INTERRUPT,
            ProcessTaskState::PENDING,
            ProcessTaskState::ABANDON,
        ];
        foreach ($states as $i => $state) {
            $task = ProcessTask::create('i1', "task{$i}", "任务{$i}", 0, 0, null, ['user1'], 'op');
            $task->setTaskId("t-state-{$i}");
            $task->setTaskState($state);
            $this->repo->saveTask($task);

            $loaded = $this->repo->findTaskById("t-state-{$i}");
            $this->assertSame($state, $loaded->getTaskState(), "任务状态 {$state} 应正确持久化");
        }
    }

    public function testTaskWithVariables(): void
    {
        $task = ProcessTask::create('i1', 'task1', '有变量任务', 0, 0, null, ['user1'], 'op');
        $task->setTaskId('t-vars');
        $task->setVariables(FlowData::of(['key1' => 'val1', 'key2' => 42]));
        $this->repo->saveTask($task);

        $loaded = $this->repo->findTaskById('t-vars');
        $this->assertSame('val1', $loaded->getVariables()->get('key1'));
        $this->assertSame(42, $loaded->getVariables()->get('key2'));
    }

    public function testTaskUpdateState(): void
    {
        $task = ProcessTask::create('i1', 'task1', '状态变更', 0, 0, null, ['user1'], 'op');
        $task->setTaskId('t-update');
        $this->repo->saveTask($task);

        $loaded = $this->repo->findTaskById('t-update');
        $this->assertSame(ProcessTaskState::DOING, $loaded->getTaskState());

        $loaded->finish('user1', FlowData::of(['result' => 'done']));
        $this->repo->updateTask($loaded);

        $reloaded = $this->repo->findTaskById('t-update');
        $this->assertSame(ProcessTaskState::FINISHED, $reloaded->getTaskState());
        $this->assertSame('user1', $reloaded->getActorId());
        $this->assertNotNull($reloaded->getFinishTime());
        $this->assertSame('done', $reloaded->getVariables()->get('result'));
    }

    // ═══ 抄送表边界 ═══

    public function testCcWithEmptyActors(): void
    {
        $this->repo->createCcInstance('i1', 'user1', []);
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_cc_instance');
        $this->assertSame(0, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    public function testCcWithSingleActor(): void
    {
        $this->repo->createCcInstance('i1', 'user1', ['cc1']);
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_cc_instance');
        $this->assertSame(1, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    public function testCcWithMultipleActors(): void
    {
        $this->repo->createCcInstance('i1', 'user1', ['cc1', 'cc2', 'cc3', 'cc4', 'cc5']);
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_cc_instance');
        $this->assertSame(5, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ═══ 批量操作 ═══

    public function testBulkDefineInsert(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->repo->addDefine([
                'id' => "bulk-d-{$i}",
                'name' => "flow-{$i}",
                'displayName' => "流程{$i}",
                'state' => 1,
                'content' => '{}',
            ]);
        }
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_define');
        $this->assertSame(50, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);

        // 随机抽查
        $d = $this->repo->findDefineById('bulk-d-25');
        $this->assertSame('flow-25', $d['name']);
    }

    public function testBulkInstanceInsert(): void
    {
        $define = ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1];
        for ($i = 0; $i < 20; $i++) {
            $inst = ProcessInstance::create($define, "user{$i}");
            $inst->setInstanceId("bulk-i-{$i}");
            $this->repo->saveInstance($inst);
        }
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_instance');
        $this->assertSame(20, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    public function testBulkTaskInsert(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $task = ProcessTask::create('i1', "task{$i}", "任务{$i}", 0, 0, null, ["user{$i}"], 'op');
            $task->setTaskId("bulk-t-{$i}");
            $this->repo->saveTask($task);
        }
        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_task');
        $this->assertSame(30, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);

        $stmt = self::$pdo->query('SELECT COUNT(*) as cnt FROM wf_process_task_actor');
        $this->assertSame(30, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ═══ 实例加载关联任务 ═══

    public function testInstanceLoadsAssociatedTasks(): void
    {
        $inst = ProcessInstance::create(
            ['id' => 'd1', 'name' => 't', 'displayName' => 'T', 'type' => 'a', 'state' => 1, 'content' => '{}', 'version' => 1],
            'user1'
        );
        $inst->setInstanceId('i-with-tasks');
        $this->repo->saveInstance($inst);

        // 添加 5 个任务
        for ($i = 0; $i < 5; $i++) {
            $task = ProcessTask::create('i-with-tasks', "task{$i}", "任务{$i}", 0, 0, null, ["user{$i}"], 'op');
            $task->setTaskId("t-linked-{$i}");
            $this->repo->saveTask($task);
        }

        // 加载实例应包含所有任务
        $loaded = $this->repo->findInstanceById('i-with-tasks');
        $this->assertCount(5, $loaded->getTasks());
        $this->assertCount(5, $loaded->getDoingTasks());
    }

    // ═══ ID 生成器 ═══

    public function testIdGenerator(): void
    {
        $idGen = new InMemoryIdGenerator();
        $id1 = $idGen->nextId();
        $id2 = $idGen->nextId();
        $this->assertNotSame($id1, $id2);
        $this->assertIsString($id1);
    }
}
