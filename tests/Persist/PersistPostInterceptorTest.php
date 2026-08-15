<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Persist;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Metadata\HandlerRegistry;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Persist\DynamicTableWriter;
use Jeeflow\Persist\Interceptor\PersistPostInterceptor;
use Jeeflow\Persist\PdoDynamicTableWriter;
use PHPUnit\Framework\TestCase;

class PersistPostInterceptorTest extends TestCase
{
    private \PDO $pdo;
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE biz_order (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            process_instance_id TEXT,
            apply_user_id TEXT,
            apply_dept_id TEXT,
            title TEXT,
            amount TEXT,
            create_time TEXT,
            create_user TEXT,
            update_time TEXT,
            update_user TEXT,
            is_deleted INTEGER DEFAULT 0,
            apply_10 INTEGER,
            apply INTEGER,
            "end_20" INTEGER,
            "end" INTEGER
        )');

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        $writer = new PdoDynamicTableWriter($this->pdo, 'sqlite');
        ServiceContext::put(DynamicTableWriter::class, $writer);
        FlowInterceptorRegistry::register(
            PersistPostInterceptor::JAVA_CLASS,
            new PersistPostInterceptor()
        );
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ModelParser::reset();
    }

    public function testArchiveAgreeInsertsRow(): void
    {
        $this->addDefine('ARCHIVE');
        $instance = $this->runToEnd(SubmitType::AGREE, ['f_title' => 'hello', 'f_amount' => '9']);
        $row = $this->fetchRow($instance->getInstanceId());
        $this->assertNotNull($row);
        $this->assertSame('hello', $row['title']);
        $this->assertSame('9', $row['amount']);
        $this->assertSame('user1', $row['apply_user_id']);
        $this->assertSame('0', (string) $row['is_deleted']);
    }

    public function testRejectNoPersist(): void
    {
        $this->addDefine('ARCHIVE');
        $instance = $this->runToEnd(SubmitType::REJECT, ['f_title' => 'nope']);
        $this->assertNull($this->fetchRow($instance->getInstanceId()));
    }

    public function testNoWriterSkip(): void
    {
        ServiceContext::remove(DynamicTableWriter::class);
        $this->addDefine('ARCHIVE');
        $instance = $this->runToEnd(SubmitType::AGREE, ['f_title' => 'x']);
        $this->assertNull($this->fetchRow($instance->getInstanceId()));
    }

    public function testDeclaredInterceptorMissingThrows(): void
    {
        FlowInterceptorRegistry::clear();
        $this->addDefine('ARCHIVE');
        $this->expectException(JeeflowException::class);
        $this->expectExceptionMessage('拦截器未注册');
        $this->engine->startProcessInstanceById('1', 'user1', FlowData::of(['f_title' => 'x']));
    }

    public function testSyncStartInsertsAndAgreeUpdates(): void
    {
        $this->addDefine('SYNC', true);
        $args = FlowData::of(['f_title' => 'orig', 'f_amount' => '1']);
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $args);
        $row = $this->fetchRow($instance->getInstanceId());
        $this->assertNotNull($row, 'SYNC 发起应 INSERT');
        $this->assertSame('orig', $row['title']);

        $apply = $instance->getDoingTasks()[0];
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'f_title' => 'HACKED',
            'f_amount' => '99',
        ]));
        $row = $this->fetchRow($instance->getInstanceId());
        $this->assertSame('orig', $row['title'], '只读 title 不被办理改掉');
        $this->assertSame('99', $row['amount']);
    }

    public function testRegisterMeta(): void
    {
        $registry = new HandlerRegistry();
        PersistPostInterceptor::registerMeta($registry);
        $items = $registry->listHandlers('FlowInterceptor', 'post');
        $this->assertCount(1, $items);
        $this->assertSame(PersistPostInterceptor::JAVA_CLASS, $items[0]->className);
    }

    public function testIdempotentArchive(): void
    {
        $this->addDefine('ARCHIVE');
        $instance = $this->runToEnd(SubmitType::AGREE, ['f_title' => 'once']);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(1) FROM biz_order WHERE process_instance_id = '" . $instance->getInstanceId() . "'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    /** @param array<string, mixed> $form */
    private function runToEnd(int $submitType, array $form): \Jeeflow\Core\Domain\ProcessInstance
    {
        $startArgs = FlowData::of($form);
        $instance = $this->engine->startProcessInstanceById('1', 'user1', $startArgs);
        $apply = $instance->getDoingTasks()[0];
        $complete = FlowData::of($form);
        $complete->set(FlowConst::SUBMIT_TYPE, $submitType);
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', $complete);
        return $this->repo->findInstanceById($instance->getInstanceId());
    }

    private function addDefine(string $persistMode, bool $withPerm = false): void
    {
        $field = $withPerm
            ? ',"field":{"PERMISSION_f_title":1,"PERMISSION_f_amount":2}'
            : '';
        $json = <<<JSON
{
  "name": "persist_demo",
  "displayName": "persist demo",
  "persistMode": "{$persistMode}",
  "relTableName": "biz_order",
  "postInterceptors": "com.mldong.jeeflow.persist.interceptor.PersistPostInterceptor",
  "nodes": [
    {"id":"start","type":"snaker:start","x":0,"y":0,"properties":{},"text":{"value":"开始"}},
    {"id":"apply","type":"snaker:task","x":0,"y":0,"properties":{"assignee":"applicant"{$field}},"text":{"value":"申请"}},
    {"id":"end","type":"snaker:end","x":0,"y":0,"properties":{},"text":{"value":"结束"}}
  ],
  "edges": [
    {"id":"e0","sourceNodeId":"start","targetNodeId":"apply","properties":{}},
    {"id":"e1","sourceNodeId":"apply","targetNodeId":"end","properties":{}}
  ]
}
JSON;
        $this->repo->addDefine([
            'id' => '1',
            'name' => 'persist_demo',
            'displayName' => 'persist demo',
            'type' => 'approval',
            'state' => 1,
            'content' => $json,
            'version' => 1,
        ]);
    }

    private function fetchRow(?string $instanceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM biz_order WHERE process_instance_id = ?');
        $stmt->execute([$instanceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
