<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * JeeflowFacade 扩展 action 测试 —— processDesign 9 + processSurrogate 3
 */
class JeeflowFacadeExtTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private InMemoryProcessExtRepository $extRepo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->extRepo = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->extRepo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 无扩展仓储时报错 ──

    public function testDesignWithoutExtRepo(): void
    {
        $facade = new JeeflowFacade($this->engine, $this->repo);
        $result = $facade->flow('processDesign/page');
        $this->assertEquals(99999999, $result['code']);
        $this->assertStringContainsString('IProcessExtRepository', $result['msg']);
    }

    // ── processDesign ──

    public function testDesignSaveAndDetail(): void
    {
        $result = $this->facade->flow('processDesign/save', [
            'name' => 'leave',
            'displayName' => '请假流程',
            'type' => 'approval',
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);
        $designId = $result['data']['id'];

        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertEquals(0, $detail['code']);
        $this->assertEquals('leave', $detail['data']['name']);
        $this->assertEquals('请假流程', $detail['data']['displayName']);
    }

    public function testDesignSaveWithContent(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $result = $this->facade->flow('processDesign/save', [
            'name' => 'simple',
            'displayName' => '简单审批',
            'content' => $json,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);
        $designId = $result['data']['id'];

        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertNotNull($detail['data']['jsonObject']);
        $this->assertNotEmpty($detail['data']['his']);
    }

    public function testDesignUpdate(): void
    {
        $save = $this->facade->flow('processDesign/save', [
            'name' => 'test',
            'displayName' => '测试',
        ]);
        $designId = $save['data']['id'];

        $this->facade->flow('processDesign/update', [
            'id' => $designId,
            'displayName' => '更新后',
        ]);

        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertEquals('更新后', $detail['data']['displayName']);
    }

    public function testDesignUpdateDefine(): void
    {
        $save = $this->facade->flow('processDesign/save', ['name' => 'test']);
        $designId = $save['data']['id'];

        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $result = $this->facade->flow('processDesign/updateDefine', [
            'processDesignId' => $designId,
            'content' => $json,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code']);

        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertNotNull($detail['data']['jsonObject']);
        // 应该置为未部署
        $this->assertEquals(0, $detail['data']['isDeployed']);
    }

    public function testDesignPage(): void
    {
        $this->facade->flow('processDesign/save', ['name' => 'a', 'displayName' => 'A']);
        $this->facade->flow('processDesign/save', ['name' => 'b', 'displayName' => 'B']);

        $page = $this->facade->flow('processDesign/page', ['pageNum' => 1, 'pageSize' => 10]);
        $this->assertEquals(0, $page['code']);
        $this->assertEquals(2, $page['data']['recordCount']);

        // issues/63：时间格式应为 yyyy-MM-dd HH:mm:ss
        $timeRe = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        foreach ($page['data']['rows'] as $row) {
            $this->assertMatchesRegularExpression($timeRe, $row['createTime'], "createTime should be yyyy-MM-dd HH:mm:ss, got {$row['createTime']}");
            $this->assertMatchesRegularExpression($timeRe, $row['updateTime'], "updateTime should be yyyy-MM-dd HH:mm:ss, got {$row['updateTime']}");
            // 确认列名为 camelCase
            $this->assertArrayHasKey('displayName', $row, 'row should have camelCase displayName');
        }
    }

    public function testDesignRemove(): void
    {
        $save = $this->facade->flow('processDesign/save', ['name' => 'test']);
        $designId = $save['data']['id'];

        $this->facade->flow('processDesign/remove', ['id' => $designId]);
        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertEquals(99999999, $detail['code']);
    }

    public function testDesignDeploy(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $save = $this->facade->flow('processDesign/save', [
            'name' => 'simple',
            'content' => $json,
        ]);
        $designId = $save['data']['id'];

        $deploy = $this->facade->flow('processDesign/deploy', ['id' => $designId]);
        $this->assertEquals(0, $deploy['code']);
        $this->assertArrayHasKey('processDefineId', $deploy['data']);

        // 设计已部署
        $detail = $this->facade->flow('processDesign/detail', ['id' => $designId]);
        $this->assertEquals(1, $detail['data']['isDeployed']);

        // 流程定义存在
        $defineId = $deploy['data']['processDefineId'];
        $defDetail = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(0, $defDetail['code']);
        $this->assertEquals('simple', $defDetail['data']['name']);
    }

    public function testDesignRedeploy(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $save = $this->facade->flow('processDesign/save', ['name' => 'simple', 'content' => $json]);
        $designId = $save['data']['id'];

        // 首次发布
        $deploy1 = $this->facade->flow('processDesign/deploy', ['id' => $designId]);
        $defineId1 = $deploy1['data']['processDefineId'];

        // 重新发布
        $deploy2 = $this->facade->flow('processDesign/redeploy', ['id' => $designId]);
        $this->assertEquals(0, $deploy2['code']);
        // 应该原地替换（同 ID）
        $this->assertEquals($defineId1, $deploy2['data']['processDefineId']);
    }

    public function testDesignListByType(): void
    {
        $this->facade->flow('processDesign/save', ['name' => 'leave', 'type' => 'approval', 'displayName' => '请假']);
        $this->facade->flow('processDesign/save', ['name' => 'purchase', 'type' => 'approval', 'displayName' => '采购']);
        $this->facade->flow('processDesign/save', ['name' => 'notify', 'type' => 'notification', 'displayName' => '通知']);

        $result = $this->facade->flow('processDesign/listByType');
        $this->assertEquals(0, $result['code']);
        $this->assertArrayHasKey('approval', $result['data']);
        $this->assertArrayHasKey('notification', $result['data']);
        $this->assertCount(2, $result['data']['approval']);
        $this->assertCount(1, $result['data']['notification']);
    }

    // ── processSurrogate ──

    public function testSurrogateSaveAndPage(): void
    {
        $save = $this->facade->flow('processSurrogate/save', [
            'processName' => 'leave',
            'surrogate' => 'user2',
            'startTime' => '2026-08-01 00:00:00',
            'endTime' => '2026-08-31 23:59:59',
            'enabled' => 1,
        ]);
        $this->assertEquals(0, $save['code']);
        $surrogateId = $save['data']['id'];

        $page = $this->facade->flow('processSurrogate/page');
        $this->assertEquals(0, $page['code']);
        $this->assertEquals(1, $page['data']['recordCount']);
    }

    public function testSurrogateRemove(): void
    {
        $save = $this->facade->flow('processSurrogate/save', [
            'processName' => 'leave',
            'surrogate' => 'user2',
            'startTime' => '2026-08-01 00:00:00',
            'endTime' => '2026-08-31 23:59:59',
        ]);
        $surrogateId = $save['data']['id'];

        $this->facade->flow('processSurrogate/remove', ['id' => $surrogateId]);
        $page = $this->facade->flow('processSurrogate/page');
        $this->assertEquals(0, $page['code']);
        $this->assertEquals(0, $page['data']['recordCount']);
    }

    public function testSurrogateWithoutExtRepo(): void
    {
        $facade = new JeeflowFacade($this->engine, $this->repo);
        $result = $facade->flow('processSurrogate/page');
        $this->assertEquals(99999999, $result['code']);
    }
}
