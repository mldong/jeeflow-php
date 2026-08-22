<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\OrgUserProviderInterface;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Spi\UserSearchProviderInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/61：processInstance/bizData 与 processTask/candidatePage 补齐测试
 *
 * 对齐 Java JeeflowFacadeTest 的 testCandidatePageDualSource / testBizData* 场景：
 * - candidatePage：模型候选（candidateUsers/candidateGroups）命中 → 用户映射；
 *   未命中 → UserSearchProviderInterface 分页搜索（未配置明确报错）
 * - bizData：relTableName（回落 name）→ metaTableReader 回显（未注册明确报错）
 */
class JeeflowFacadeIssue61Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
        // 注册简单事务模板（直接执行）
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    private function deployFlow(string $file): string
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/' . $file);
        $result = $this->facade->flow('processDefine/deploy', [
            'content' => $json,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code'], '部署失败: ' . $result['msg']);
        return $result['data']['processDefineId'];
    }

    /** 部署带 relTableName 的流程（01-simple 顶层注入 relTableName） */
    private function deployFlowWithRelTable(string $tableName): string
    {
        $json = json_decode(
            file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json'),
            true
        );
        $json['relTableName'] = $tableName;
        $result = $this->facade->flow('processDefine/deploy', [
            'content' => json_encode($json, JSON_UNESCAPED_UNICODE),
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code'], '部署失败: ' . $result['msg']);
        return $result['data']['processDefineId'];
    }

    // ── candidatePage：模型候选命中（candidateUsers / candidateGroups）──

    public function testCandidatePageModelCandidates(): void
    {
        $defineId = $this->deployFlow('12-candidate-page.json');
        // 引擎直接启动（不自动完成），apply 为进行中任务
        $inst = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());
        $this->assertCount(1, $doing);
        $this->assertEquals('apply', $doing[0]->getTaskName());

        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => $doing[0]->getTaskId()]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $userIds = array_column($result['data']['rows'], 'userId');
        // candidateUsers 指定人
        $this->assertContains('userA', $userIds);
        $this->assertContains('userB', $userIds);
        // 未注册 OrgUserProviderInterface 时 candidateGroups 跳过（与 Java 无 orgProvider 一致）
        $this->assertNotContains('finA', $userIds);
        $this->assertNotContains('finB', $userIds);
        // 兜底映射：无 provider 时 userId = realName = actorId
        $rowA = $result['data']['rows'][array_search('userA', $userIds, true)];
        $this->assertEquals('userA', $rowA['realName']);
    }

    public function testCandidatePageGroupsExpansion(): void
    {
        // 注册组织用户钩子：finance 角色 → finA/finB
        ServiceContext::put(OrgUserProviderInterface::class, new class implements OrgUserProviderInterface {
            public function findByRole(string $roleCode): array
            {
                return $roleCode === 'finance' ? ['finA', 'finB'] : [];
            }
            public function findDeptLeaders(string $deptId): array
            {
                return [];
            }
            public function findDeptMainLeaders(string $deptId): array
            {
                return [];
            }
        });

        $defineId = $this->deployFlow('12-candidate-page.json');
        $inst = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());

        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => $doing[0]->getTaskId()]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $userIds = array_column($result['data']['rows'], 'userId');
        // 双源候选：candidateUsers + candidateGroups 角色成员
        $this->assertContains('userA', $userIds);
        $this->assertContains('userB', $userIds);
        $this->assertContains('finA', $userIds);
        $this->assertContains('finB', $userIds);
        $this->assertCount(4, $result['data']['rows']);
        // 单页固定 1/10 结构
        $this->assertEquals(1, $result['data']['pageNum']);
        $this->assertEquals(10, $result['data']['pageSize']);
        // issues/80：行键契约 {id, realName}（对齐前端 UserSelect valueField='id'）
        $ids = [];
        foreach ($result['data']['rows'] as $row) {
            $this->assertArrayHasKey('id', $row, '候选行缺 id 键: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            $this->assertArrayHasKey('realName', $row, '候选行缺 realName 键: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            if (isset($row['userId'])) {
                $this->assertSame($row['id'], $row['userId'], 'id 与 userId 应一一对齐（行键归一）');
            }
            $ids[] = $row['id'];
        }
        $this->assertContains('userA', $ids);
    }

    public function testCandidatePageUserProviderDeptNamePassThrough(): void
    {
        // issues/80：UserProvider 子路径 realName/deptName 可得时透传，id 行键归一
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                return $userId === 'userA'
                    ? ['userId' => 'userA', 'realName' => '张三', 'deptName' => '财务部']
                    : null;
            }
        });

        $defineId = $this->deployFlow('12-candidate-page.json');
        $inst = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());

        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => $doing[0]->getTaskId()]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $rowA = null;
        foreach ($result['data']['rows'] as $row) {
            if ($row['id'] === 'userA') {
                $rowA = $row;
            }
        }
        $this->assertNotNull($rowA);
        $this->assertEquals('张三', $rowA['realName']);
        $this->assertEquals('财务部', $rowA['deptName']);
        // 保留 userId 兼容旧消费方
        $this->assertEquals('userA', $rowA['userId']);
    }

    // ── candidatePage：无模型候选 → 用户分页搜索 ──

    public function testCandidatePageSearchFallback(): void
    {
        // 用户搜索钩子：分页搜索 mock + findById 映射
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                return $userId === 'u1' ? ['userId' => 'u1', 'realName' => '用户一'] : null;
            }
        });
        $this->facade->setUserSearchProvider(new class implements UserSearchProviderInterface {
            public function page(PageQuery $query): PageResult
            {
                return new PageResult(1, 10, 2, [
                    ['userId' => 'u1', 'realName' => '用户一'],
                    ['userId' => 'u2', 'realName' => '用户二'],
                ]);
            }

            public function findById(string $userId): ?array
            {
                return $userId === 'u1' ? ['userId' => 'u1', 'realName' => '用户一'] : null;
            }
        });

        // 01-simple：apply 下一节点 task1 无候选配置 → 走分页搜索
        $defineId = $this->deployFlow('01-simple.json');
        $inst = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());

        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => $doing[0]->getTaskId()]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $this->assertEquals(2, $result['data']['recordCount']);
        $this->assertCount(2, $result['data']['rows']);
        $this->assertEquals('用户二', $result['data']['rows'][1]['realName']);
    }

    public function testCandidatePageNoProviderError(): void
    {
        // 01-simple 无候选配置 + 未注册搜索钩子 → 明确报错
        $defineId = $this->deployFlow('01-simple.json');
        $inst = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());

        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => $doing[0]->getTaskId()]);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('UserSearchProviderInterface', $result['msg']);
    }

    public function testCandidatePageTaskNotFound(): void
    {
        $result = $this->facade->flow('processTask/candidatePage', ['processTaskId' => '999']);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('任务不存在', $result['msg']);
    }

    // ── bizData：业务数据回显 ──

    public function testBizDataRegistered(): void
    {
        // 注册业务数据读取器（同 MetaTableReader 契约）
        ServiceContext::put('metaTableReader', new class {
            public function readByProcessInstance(string $tableName, mixed $processInstanceId): array
            {
                return [
                    'tableName' => $tableName,
                    'processInstanceId' => $processInstanceId,
                    'title' => '业务数据',
                ];
            }
        });

        $defineId = $this->deployFlowWithRelTable('biz_leave');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
            'title' => 'x',
        ]);
        $this->assertEquals(0, $start['code'], $start['msg']);
        $instanceId = $start['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/bizData', ['processInstanceId' => $instanceId]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $this->assertEquals('biz_leave', $result['data']['tableName']);
        $this->assertEquals('业务数据', $result['data']['title']);
        $this->assertEquals($instanceId, $result['data']['processInstanceId']);
    }

    public function testBizDataNameFallback(): void
    {
        // 无 relTableName → 回落流程 name
        ServiceContext::put('metaTableReader', new class {
            public string $lastTableName = '';

            public function readByProcessInstance(string $tableName, mixed $processInstanceId): array
            {
                $this->lastTableName = $tableName;
                return ['tableName' => $tableName];
            }
        });

        $defineId = $this->deployFlow('01-simple.json');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/bizData', ['id' => $instanceId]);
        $this->assertEquals(0, $result['code'], $result['msg']);
        $this->assertEquals('simple', $result['data']['tableName']);
    }

    public function testBizDataUnregistered(): void
    {
        $defineId = $this->deployFlowWithRelTable('biz_leave');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];

        $result = $this->facade->flow('processInstance/bizData', ['processInstanceId' => $instanceId]);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('metaTableReader', $result['msg']);
    }

    public function testBizDataInstanceNotFound(): void
    {
        $result = $this->facade->flow('processInstance/bizData', ['processInstanceId' => '999']);
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('流程实例不存在', $result['msg']);
    }
}
