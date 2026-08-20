<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 模拟 PDO 仓储：findDesignById 返回底层行，id 为原生 int（PDO 默认整型绑定）。
 * 用于验证 facade 出口 hook 必须把 int id 字符串化（issues/75）。
 */
class PdoLikeExtRepo implements ProcessExtRepositoryInterface
{
    public int $designId;
    public int $hisId;

    public function __construct(int $designId = 17769128440810003, int $hisId = 9000000000000000007)
    {
        $this->designId = $designId;
        $this->hisId = $hisId;
    }

    public function findDesignById(int|string $id): ?array
    {
        return [
            'id' => $this->designId,                    // 原生 int（PDO 行为）
            'name' => 'e2e_leave',
            'display_name' => 'L3请假申请',
            'type' => '1',
            'icon' => null,
            'is_deployed' => 1,
            'remark' => 'E2E 场景流程',
            'create_time' => '2026-08-19 14:41:24',
            'update_time' => '2026-08-19 14:41:24',
        ];
    }

    public function findLatestDesignHis(int|string $designId): ?array
    {
        return [
            'id' => $this->hisId,                       // 原生 int
            'process_design_id' => $this->designId,
            'content' => '{"name":"e2e_leave","nodes":[],"edges":[]}',
        ];
    }

    public function findDesignHisList(int|string $designId): array
    {
        return [[                                          // 行带 int id + int process_design_id
            'id' => $this->hisId,
            'process_design_id' => $this->designId,
            'content' => '{"name":"e2e_leave","nodes":[],"edges":[]}',
            'create_time' => '2026-08-19 14:41:24',
        ]];
    }

    // 未在本用例使用的接口方法
    public function pageDesigns(PageQuery $query): PageResult { return new PageResult(1, 10, 0, []); }
    public function saveDesign(array $design): string { return (string) $this->designId; }
    public function updateDesign(array $design): void {}
    public function saveDesignHis(int|string $designId, string $content, ?string $operator = null): void {}
    public function removeDesign(int|string $id): void {}
    public function updateDesignDeployed(int|string $designId, int $isDeployed): void {}
    public function listDesignsByType(): array { return []; }
    public function pageSurrogates(PageQuery $query): PageResult { return new PageResult(1, 10, 0, []); }
    public function findSurrogateById(int|string $id): ?array { return null; }
    public function saveSurrogate(array $surrogate): string { return '1'; }
    public function updateSurrogate(array $surrogate): void {}
    public function removeSurrogate(int|string $id): void {}
}

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

    /**
     * issues/75 回归：底层行 id 为 int（PDO 行为）时，detail 出口必须字符串化。
     * 19 位雪花 id 若以 JSON 数字下发，前端 JS JSON.parse(float64) 会丢精度
     * （奇数尾四舍五入），导致 designer 保存时 processDesignId 指向不存在的记录、
     * 静默 no-op（S8a 偶发根因）。对齐 Java 全局 Long→String / Go okResult(stringifyIDs)。
     */
    public function testDesignDetailStringifiesIntIds(): void
    {
        $bigIntId = 17769128440810003; // 19 位雪花 id（奇数尾，float64 下会变为 ...0004）
        $hisId = 9000000000000000007;
        $pdoLike = new PdoLikeExtRepo($bigIntId, $hisId);
        $facade = new JeeflowFacade($this->engine, $this->repo, $pdoLike);

        $detail = $facade->flow('processDesign/detail', ['id' => (string) $bigIntId]);
        $this->assertEquals(0, $detail['code']);

        // 顶层 id：字符串且逐字符精确（float64 会把它变成 ...0004）
        $this->assertIsString($detail['data']['id']);
        $this->assertSame((string) $bigIntId, $detail['data']['id']);

        // 嵌套 his 列表：行 id 与 process_design_id 也必须字符串化
        $this->assertNotEmpty($detail['data']['his']);
        foreach ($detail['data']['his'] as $his) {
            $this->assertIsString($his['id'], 'his.id must be string');
            $this->assertSame((string) $hisId, $his['id']);
            $this->assertIsString($his['process_design_id'], 'his.process_design_id must be string');
            $this->assertSame((string) $bigIntId, $his['process_design_id']);
        }
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
        // issues/77：page 行走统一行结构（camelCase + 时间格式化），与 detail 同构
        $row = $page['data']['rows'][0];
        $this->assertSame('leave', $row['processName']);
        $this->assertSame('user2', $row['surrogate']);
        $this->assertSame('2026-08-01 00:00:00', $row['startTime']);
        $this->assertSame('2026-08-31 23:59:59', $row['endTime']);
        $this->assertSame(1, $row['enabled']);
    }

    public function testSurrogateDetailAndUpdate(): void
    {
        // 委托编辑链路（issues/77）：save（前端空格格式时间窗）→ detail 回显 →
        // update 改字段（不带 operator，授权人应保留）→ detail 再回显 + 负向
        $save = $this->facade->flow('processSurrogate/save', [
            'operator' => 'zhangsan',
            'surrogate' => 'lisi',
            'processName' => 'leave',
            'startTime' => '2026-08-01 00:00:00',
            'endTime' => '2026-08-31 23:59:59',
            'enabled' => 1,
        ]);
        $this->assertEquals(0, $save['code'], json_encode($save, JSON_UNESCAPED_UNICODE));
        $surrogateId = $save['data']['id'];

        // detail 回显：行结构齐全 + 时间格式化
        $d = $this->facade->flow('processSurrogate/detail', ['id' => $surrogateId]);
        $this->assertEquals(0, $d['code'], json_encode($d, JSON_UNESCAPED_UNICODE));
        $this->assertSame('leave', $d['data']['processName']);
        $this->assertSame('zhangsan', $d['data']['operator']);
        $this->assertSame('lisi', $d['data']['surrogate']);
        $this->assertSame('2026-08-01 00:00:00', $d['data']['startTime']);
        $this->assertSame('2026-08-31 23:59:59', $d['data']['endTime']);

        // update：改代理人/时间窗/启用状态（不带 operator，授权人应保留）
        $up = $this->facade->flow('processSurrogate/update', [
            'id' => $surrogateId,
            'surrogate' => 'wangwu',
            'processName' => 'leave',
            'startTime' => '2026-09-01 00:00:00',
            'endTime' => '2026-09-30 23:59:59',
            'enabled' => 0,
        ]);
        $this->assertEquals(0, $up['code'], json_encode($up, JSON_UNESCAPED_UNICODE));
        $this->assertSame($surrogateId, $up['data']['id']);

        // detail 再回显：变更生效 + 授权人未被清空
        $d = $this->facade->flow('processSurrogate/detail', ['id' => $surrogateId]);
        $this->assertEquals(0, $d['code'], json_encode($d, JSON_UNESCAPED_UNICODE));
        $this->assertSame('wangwu', $d['data']['surrogate']);
        $this->assertSame('zhangsan', $d['data']['operator']);
        $this->assertSame(0, $d['data']['enabled']);
        $this->assertSame('2026-09-01 00:00:00', $d['data']['startTime']);
        $this->assertSame('2026-09-30 23:59:59', $d['data']['endTime']);

        // 仓储侧同步（update 真的写了）
        $stored = $this->extRepo->findSurrogateById($surrogateId);
        $this->assertNotNull($stored);
        $this->assertSame('wangwu', $stored['surrogate']);
        $this->assertSame(0, (int) $stored['enabled']);

        // 负向：detail/update id 不存在
        $this->assertEquals(99999999, $this->facade->flow('processSurrogate/detail', ['id' => 99999])['code']);
        $this->assertEquals(99999999, $this->facade->flow('processSurrogate/update', ['id' => 99999, 'surrogate' => 'x'])['code']);
        // 负向：update 缺 id
        $this->assertEquals(99999999, $this->facade->flow('processSurrogate/update', ['surrogate' => 'x'])['code']);
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
