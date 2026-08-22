<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Persist;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Persist\DynamicTableWriter;
use Jeeflow\Persist\Interceptor\PersistPostInterceptor;
use Jeeflow\Persist\PdoDynamicTableWriter;
use PHPUnit\Framework\TestCase;

/**
 * persist 引擎集成场景（issues/82-11：PHP persist 覆盖对齐 Java/Go/Python/Node）
 *
 * 走真实引擎（JeeflowEngine + ModelParser + 拦截器调度），覆盖 PersistPostInterceptorTest
 * 单节点之外的集成面：无表名回落报错 / SYNC 全链路状态列 / SYNC 驳回 45 定稿 /
 * #26 下游权限绕过防护 / 定义级拦截器声明-不声明对照。
 *
 * 参照：Python test_persist.py ④/⑯/⑯'/⑰/⑱，Go interceptor_test.go，Node persist.test.ts。
 */
class PersistEngineScenarioTest extends TestCase
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

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);

        ServiceContext::put(DynamicTableWriter::class, new PdoDynamicTableWriter($this->pdo, 'sqlite'));
        FlowInterceptorRegistry::register(
            PersistPostInterceptor::JAVA_CLASS, new PersistPostInterceptor()
        );
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ModelParser::reset();
    }

    // ─── ④ 无 relTableName → 回落流程 name → 表不存在 → 快速失败（配置错误显式报错）───

    public function testNoTableNameFallsBackAndThrows(): void
    {
        $this->addDefine('1', 'sync_notable', $this->flow(
            'sync_notable', '', 'SYNC', PersistPostInterceptor::JAVA_CLASS,
            $this->simpleNodes('apply')
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->engine->startProcessInstanceById('1', 'user1', FlowData::of(['f_title' => 'x']));
    }

    // ─── ⑯ SYNC 全链路：发起 INSERT → apply_10 → task1 权限过滤 + tf_ + task1_10 → end_20 ───

    public function testSyncFullCycle(): void
    {
        $this->pdo->exec('CREATE TABLE biz_sync (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT, amount TEXT, opinion TEXT,
            "apply_10" INTEGER, "task1_10" INTEGER, "end_20" INTEGER, "end_45" INTEGER,
            process_instance_id TEXT, apply_user_id TEXT, apply_dept_id TEXT,
            create_time TEXT, create_user TEXT, update_time TEXT, update_user TEXT,
            is_deleted INTEGER DEFAULT 0)');
        $nodes = $this->simpleNodes('apply', [
            'id' => 'task1', 'type' => 'snaker:task',
            'properties' => ['assignee' => 'leader',
                'field' => ['PERMISSION_f_title' => 1, 'PERMISSION_f_amount' => 2]],
            'text' => ['value' => '审批'],
        ]);
        $this->addDefine('1', 'sync_full', $this->flow(
            'sync_full', 'biz_sync', 'SYNC', PersistPostInterceptor::JAVA_CLASS, $nodes
        ));

        // ① 发起 → INSERT
        $inst = $this->engine->startProcessInstanceById('1', 'user1', FlowData::of([
            'f_title' => '年假申请', 'f_amount' => '800', 'u_deptId' => 'D01',
        ]));
        $iid = $inst->getInstanceId();
        $this->assertNotNull($this->fetchRow($iid, 'biz_sync'), 'SYNC 发起应 INSERT');

        // ② 完成 apply（APPLY=0）→ apply_10=10
        $ta = $this->doingTaskId($iid, 'apply');
        $this->engine->executeProcessTask($ta, 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        $this->assertSame(10, (int) $this->col($iid, 'biz_sync', 'apply_10'), 'apply 完成后 apply_10=10');

        // ③ 完成 task1（AGREE）：title 只读不改 / amount 可编辑 / opinion(tf_) / task1_10 / end_20
        $tt = $this->doingTaskId($iid, 'task1');
        $this->engine->executeProcessTask($tt, 'leader', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            'f_title' => '修改标题', 'f_amount' => '999', 'tf_opinion' => '同意',
        ]));
        $row = $this->fetchRow($iid, 'biz_sync');
        $this->assertSame('年假申请', $row['title'], '只读字段不应被办理改掉');
        $this->assertSame('999', $row['amount'], '可编辑字段应更新');
        $this->assertSame('同意', $row['opinion'], 'tf_ 任务表单应冗余落列');
        $this->assertSame(10, (int) $row['task1_10']);
        $this->assertSame(20, (int) $row['end_20'], '结束定稿 end_20=20');
        $this->assertSame(1, $this->countRows($iid, 'biz_sync'), '先插后更应仅 1 条');
    }

    // ─── ⑯' SYNC 驳回：task1 REJECT → 结束定稿 end_45=45 ───

    public function testSyncReject(): void
    {
        $this->pdo->exec('CREATE TABLE biz_sync (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT, amount TEXT,
            "apply_10" INTEGER, "task1_10" INTEGER, "end_20" INTEGER, "end_45" INTEGER,
            process_instance_id TEXT, apply_user_id TEXT,
            create_time TEXT, create_user TEXT, update_time TEXT, update_user TEXT,
            is_deleted INTEGER DEFAULT 0)');
        $nodes = $this->simpleNodes('apply', [
            'id' => 'task1', 'type' => 'snaker:task',
            'properties' => ['assignee' => 'leader',
                'field' => ['PERMISSION_f_title' => 1, 'PERMISSION_f_amount' => 2]],
            'text' => ['value' => '审批'],
        ]);
        $this->addDefine('1', 'sync_rej', $this->flow(
            'sync_rej', 'biz_sync', 'SYNC', PersistPostInterceptor::JAVA_CLASS, $nodes
        ));

        $inst = $this->engine->startProcessInstanceById('1', 'user1', FlowData::of([
            'f_title' => '驳回单', 'u_deptId' => 'D01',
        ]));
        $iid = $inst->getInstanceId();
        $ta = $this->doingTaskId($iid, 'apply');
        $this->engine->executeProcessTask($ta, 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        $tt = $this->doingTaskId($iid, 'task1');
        $this->engine->executeProcessTask($tt, 'leader',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::REJECT]));

        $row = $this->fetchRow($iid, 'biz_sync');
        $this->assertSame('驳回单', $row['title']);
        $this->assertSame(45, (int) $row['end_45'], '驳回定稿 end_45=45');
        $this->assertSame('user1', $row['create_user']);
        $this->assertSame(1, $this->countRows($iid, 'biz_sync'));
    }

    // ─── ⑰ #26 下游权限绕过防护：approve1 只读字段被拒值不入变量 → approve2（无权限声明）无法绕过 ───

    public function testSyncPermBypass(): void
    {
        $this->pdo->exec('CREATE TABLE biz_perm3 (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, amount TEXT,
            "apply_10" INTEGER, "approve1_10" INTEGER, "approve2_10" INTEGER, "finish_20" INTEGER,
            process_instance_id TEXT, create_user TEXT, is_deleted INTEGER DEFAULT 0)');
        $nodes = [
            ['id' => 'start', 'type' => 'snaker:start', 'properties' => [], 'text' => ['value' => '开始']],
            ['id' => 'apply', 'type' => 'snaker:task',
                'properties' => ['assignee' => 'applicant'], 'text' => ['value' => '发起']],
            ['id' => 'approve1', 'type' => 'snaker:task',
                'properties' => ['assignee' => 'leader1',
                    'field' => ['PERMISSION_f_title' => 1, 'PERMISSION_f_amount' => 2]],
                'text' => ['value' => '审批一']],
            ['id' => 'approve2', 'type' => 'snaker:task',
                'properties' => ['assignee' => 'leader2'], 'text' => ['value' => '审批二']],
            ['id' => 'finish', 'type' => 'snaker:end', 'properties' => [], 'text' => ['value' => '结束']],
        ];
        $edges = [
            ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply'],
            ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'approve1'],
            ['id' => 'e2', 'sourceNodeId' => 'approve1', 'targetNodeId' => 'approve2'],
            ['id' => 'e3', 'sourceNodeId' => 'approve2', 'targetNodeId' => 'finish'],
        ];
        $this->addDefine('1', 'perm3', $this->flow(
            'perm3', 'biz_perm3', 'SYNC', PersistPostInterceptor::JAVA_CLASS, $nodes, $edges
        ));

        $inst = $this->engine->startProcessInstanceById('1', 'user1', FlowData::of([
            'f_title' => '原始标题', 'f_amount' => '800', 'u_deptId' => 'D01',
        ]));
        $iid = $inst->getInstanceId();

        $ta = $this->doingTaskId($iid, 'apply');
        $this->engine->executeProcessTask($ta, 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        // approve1 只读 title，提交 TRY_HACK → 引擎入口过滤（filterFieldByPerm）→ 不入变量
        $t1 = $this->doingTaskId($iid, 'approve1');
        $this->engine->executeProcessTask($t1, 'leader1', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE, 'f_title' => 'TRY_HACK', 'f_amount' => '800',
        ]));
        // approve2 无权限声明——变量无 TRY_HACK，title 保持原值（不可绕过上游只读）
        $t2 = $this->doingTaskId($iid, 'approve2');
        $this->engine->executeProcessTask($t2, 'leader2', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE, 'f_amount' => '999',
        ]));

        $row = $this->fetchRow($iid, 'biz_perm3');
        $this->assertSame('原始标题', $row['title'], '只读字段被拒值不应落库（下游不可绕过）');
        $this->assertSame('999', $row['amount']);
        $this->assertSame(10, (int) $row['approve1_10']);
        $this->assertSame(10, (int) $row['approve2_10']);
        $this->assertSame(20, (int) $row['finish_20']);
    }

    // ─── ⑱ 定义级拦截器：声明 postInterceptors 触发 / 未声明不触发（NodeModel 回落 processModel 级）───

    public function testDefineLevelInterceptor(): void
    {
        $this->pdo->exec('CREATE TABLE biz_decl (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT,
            process_instance_id TEXT, apply_user_id TEXT,
            create_time TEXT, create_user TEXT, update_time TEXT, update_user TEXT,
            is_deleted INTEGER DEFAULT 0)');
        $nodes = $this->simpleNodes('apply');

        // 声明了拦截器 → 发起即入库
        $this->addDefine('1', 'decl1', $this->flow(
            'decl1', 'biz_decl', 'SYNC', PersistPostInterceptor::JAVA_CLASS, $nodes
        ));
        $this->engine->startProcessInstanceById('1', 'user1', FlowData::of(['f_title' => '声明流程']));
        $this->assertSame(1, $this->countTable('biz_decl'), '声明拦截器的流程发起应入库');

        // 未声明 → 不触发（定义级语义：只有声明了拦截器的流程才持久化）
        $this->addDefine('2', 'decl2', $this->flow(
            'decl2', 'biz_decl', 'SYNC', '', $nodes
        ));
        $this->engine->startProcessInstanceById('2', 'user2', FlowData::of(['f_title' => '未声明流程']));
        $this->assertSame(1, $this->countTable('biz_decl'), '未声明拦截器的流程不应落库');
        $row = $this->pdo->query('SELECT title FROM biz_decl LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('声明流程', $row['title']);
    }

    // ═══ 辅助 ═══

    /**
     * start → 首任务节点 → [追加任务节点] → end 的默认节点集。
     * @param list<array<string, mixed>> $extraTasks 插入在首任务与 end 之间的任务节点
     */
    private function simpleNodes(string $firstTask, array ...$extraTasks): array
    {
        $nodes = [
            ['id' => 'start', 'type' => 'snaker:start', 'properties' => [], 'text' => ['value' => '开始']],
            ['id' => $firstTask, 'type' => 'snaker:task',
                'properties' => ['assignee' => 'applicant'], 'text' => ['value' => '申请']],
        ];
        foreach ($extraTasks as $t) {
            $nodes[] = $t;
        }
        $nodes[] = ['id' => 'end', 'type' => 'snaker:end', 'properties' => [], 'text' => ['value' => '结束']];
        return $nodes;
    }

    /**
     * 组装流程定义 JSON。edges 依据 nodes 的线性顺序自动重建（本测试均为线性链）。
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>>|null $edges 缺省按线性链生成
     */
    private function flow(string $name, string $relTable, string $persistMode, string $post,
                          array $nodes, ?array $edges = null): string
    {
        if ($edges === null) {
            $ids = array_map(fn($n) => $n['id'], $nodes);
            $edges = [];
            for ($i = 0; $i < count($ids) - 1; $i++) {
                $edges[] = ['id' => 'e' . $i, 'sourceNodeId' => $ids[$i], 'targetNodeId' => $ids[$i + 1]];
            }
        }
        return json_encode([
            'name' => $name, 'displayName' => $name, 'type' => 'approval',
            'persistMode' => $persistMode, 'relTableName' => $relTable,
            'postInterceptors' => $post, 'nodes' => $nodes, 'edges' => $edges,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function addDefine(string $id, string $name, string $json): void
    {
        $this->repo->addDefine([
            'id' => $id, 'name' => $name, 'displayName' => $name, 'type' => 'approval',
            'state' => 1, 'content' => $json, 'version' => 1,
        ]);
    }

    private function doingTaskId(string $instanceId, string $taskName): string
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $t) {
            if ($t->getTaskName() === $taskName) {
                return (string) $t->getTaskId();
            }
        }
        return '';
    }

    private function fetchRow(string $instanceId, string $table): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE process_instance_id = ?");
        $stmt->execute([$instanceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function col(string $instanceId, string $table, string $col): mixed
    {
        $row = $this->fetchRow($instanceId, $table);
        return $row[$col] ?? null;
    }

    private function countRows(string $instanceId, string $table): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(1) FROM {$table} WHERE process_instance_id = ?");
        $stmt->execute([$instanceId]);
        return (int) $stmt->fetchColumn();
    }

    private function countTable(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(1) FROM {$table}")->fetchColumn();
    }
}
