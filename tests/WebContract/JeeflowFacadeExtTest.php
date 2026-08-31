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
    public function listDesignsByType(): array
    {
        // issues/98：返回一条与 findDesignById 同记录的 snake_case 原生行（type 分组键 '1'）
        return [
            '1' => [[
                'id' => $this->designId,
                'name' => 'e2e_leave',
                'display_name' => 'L3请假申请',
                'type' => '1',
                'icon' => null,
                'is_deployed' => 1,
                'remark' => 'E2E 场景流程',
                'create_time' => '2026-08-19 14:41:24',
                'update_time' => '2026-08-19 14:41:24',
            ]],
        ];
    }
    public function pageSurrogates(PageQuery $query): PageResult { return new PageResult(1, 10, 0, []); }
    public function findSurrogateById(int|string $id): ?array { return null; }
    public function getSurrogate(string $operator, string $processName, ?string $time = null): ?array { return null; }
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
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
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

        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
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
        // 82-9：save 带 remark/icon，page 行应回显（设计页回显字段，对齐 Java/Go/Python/Node）
        $this->facade->flow('processDesign/save', ['name' => 'a', 'displayName' => 'A',
            'icon' => 'icon-echo', 'remark' => '回显验证备注']);
        $this->facade->flow('processDesign/save', ['name' => 'b', 'displayName' => 'B']);

        $page = $this->facade->flow('processDesign/page', ['pageNum' => 1, 'pageSize' => 10]);
        $this->assertEquals(0, $page['code']);
        $this->assertEquals(2, $page['data']['recordCount']);

        $rowA = null;
        foreach ($page['data']['rows'] as $row) {
            if ($row['name'] === 'a') { $rowA = $row; break; }
        }
        $this->assertNotNull($rowA, 'designPage 应含 name=a 行');
        $this->assertSame('回显验证备注', $rowA['remark'] ?? null, 'designPage remark 应回显保存值');
        $this->assertSame('icon-echo', $rowA['icon'] ?? null, 'designPage icon 应回显保存值');

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
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
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
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
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

    /**
     * issues/98：design 读路径必须兼容 PDO snake_case 行键（display_name/is_deployed）。
     * PdoLikeExtRepo 是 snake_case 行键夹具（issues/75 而造）——修复前 listByType 出口
     * displayName 恒空、detail 顶层 isDeployed 恒 0（S13 laravel 栈流程名称列空白根因）。
     */
    public function testDesignReadPathPdoSnakeCaseKeys(): void
    {
        $bigIntId = 17769128440810003;
        $pdoLike = new PdoLikeExtRepo($bigIntId, 9000000000000000007);
        $facade = new JeeflowFacade($this->engine, $this->repo, $pdoLike);

        // listByType 出口：displayName 必须从 snake_case display_name 读出
        $list = $facade->flow('processDesign/listByType');
        $this->assertEquals(0, $list['code']);
        $item = null;
        foreach (($list['data']['1'] ?? []) as $d) {
            if (($d['name'] ?? '') === 'e2e_leave') $item = $d;
        }
        $this->assertNotNull($item, 'listByType type=1 分组缺 e2e_leave item: ' . json_encode($list['data'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('L3请假申请', $item['displayName'], 'listByType 出口 displayName 必须非空（PDO snake_case display_name 行）');

        // detail 顶层：isDeployed 必须从 snake_case is_deployed 读出为 1，displayName 非空
        $detail = $facade->flow('processDesign/detail', ['id' => (string) $bigIntId]);
        $this->assertEquals(0, $detail['code']);
        $this->assertSame(1, $detail['data']['isDeployed'], 'detail 顶层 isDeployed 应为 1（PDO snake_case is_deployed 行）');
        $this->assertSame('L3请假申请', $detail['data']['displayName'], 'detail 顶层 displayName 必须非空');
        // jsonObject 补值路径（his content 无 displayName 时从 design 行补齐）
        $this->assertSame('L3请假申请', $detail['data']['jsonObject']['displayName'] ?? '', 'jsonObject.displayName 补值必须来自 display_name');
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

    /**
     * issues/81：listByType items 必含 processDefineState（前端发起按钮硬依赖），取值随定义 state 联动。
     * 场景 A：deploy → 定义 state=1 → 可发起；场景 B：upAndDown 禁用 → state=0 → 前端置灰。
     */
    public function testDesignListByTypeProcessDefineState(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $save = $this->facade->flow('processDesign/save', ['name' => 'simple', 'content' => $json]);
        $designId = $save['data']['id'];
        $deploy = $this->facade->flow('processDesign/deploy', ['id' => $designId]);
        $this->assertEquals(0, $deploy['code']);
        $defineId = $deploy['data']['processDefineId'];

        // 场景 A：启用定义 processDefineState=1（前端可发起）
        $rA = $this->facade->flow('processDesign/listByType');
        $this->assertEquals(0, $rA['code']);
        $itemA = $this->listByTypeItem($rA['data'], 'simple');
        $this->assertSame((string) $defineId, (string) $itemA['processDefineId']);
        $this->assertSame(1, (int) $itemA['processDefineState'], '启用定义 processDefineState 应为 1: ' . json_encode($itemA, JSON_UNESCAPED_UNICODE));

        // 场景 B：upAndDown 禁用 → processDefineState=0（前端置灰）
        $ud = $this->facade->flow('processDefine/upAndDown', ['id' => $defineId, 'opType' => 0]);
        $this->assertEquals(0, $ud['code']);
        $rB = $this->facade->flow('processDesign/listByType');
        $this->assertEquals(0, $rB['code']);
        $itemB = $this->listByTypeItem($rB['data'], 'simple');
        $this->assertSame(0, (int) $itemB['processDefineState'], '禁用定义 processDefineState 应为 0: ' . json_encode($itemB, JSON_UNESCAPED_UNICODE));
    }

    /** listByType 出口里按 design name 取 item（approval 分组） */
    private function listByTypeItem(array $data, string $name): array
    {
        $approval = $data['approval'] ?? [];
        foreach ($approval as $item) {
            if (($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        $this->fail("listByType 缺 {$name} item: " . json_encode($data, JSON_UNESCAPED_UNICODE));
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

    /**
     * 委托分页 m_ 条件（issues/82-7 五语言基准）：m_IN_processName / m_EQ_enabled
     *
     * PHP facade surrogatePage 无 operator 通道（对齐 Java，仅 m_ 条件），
     * 故基准用同一 operator 让 operator 过滤成为 no-op，m_ 条件为被测点。
     * 显式 enabled=0 是合法值（停用），save 后须保留（applySurrogateFields 已处理）。
     */
    public function testSurrogatePageInAndEqConditions(): void
    {
        // 3 条委托：leave(启用) / overtime(启用) / sick(停用)
        foreach ([['leave', 'lisi', 1], ['overtime', 'wangwu', 1], ['sick', 'zhaoliu', 0]] as [$name, $agent, $enabled]) {
            $save = $this->facade->flow('processSurrogate/save', [
                'operator' => 'zhangsan',
                'surrogate' => $agent,
                'processName' => $name,
                'enabled' => $enabled,
            ]);
            $this->assertEquals(0, $save['code'], json_encode($save, JSON_UNESCAPED_UNICODE));
        }

        // 无过滤：3 条
        $p0 = $this->facade->flow('processSurrogate/page', ['operator' => 'zhangsan']);
        $this->assertEquals(0, $p0['code']);
        $this->assertEquals(3, $p0['data']['recordCount']);

        // m_IN_processName：IN 列表命中 2 条
        $pIn = $this->facade->flow('processSurrogate/page', [
            'operator' => 'zhangsan',
            'm_IN_processName' => ['leave', 'overtime'],
        ]);
        $this->assertEquals(0, $pIn['code'], json_encode($pIn, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(2, $pIn['data']['recordCount']);
        $names = array_column($pIn['data']['rows'], 'processName');
        $this->assertContains('leave', $names);
        $this->assertContains('overtime', $names);

        // m_EQ_enabled：启用过滤命中 2 条（依赖 enabled=0 未被吞）
        $pEq = $this->facade->flow('processSurrogate/page', [
            'operator' => 'zhangsan',
            'm_EQ_enabled' => 1,
        ]);
        $this->assertEquals(0, $pEq['code'], json_encode($pEq, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(2, $pEq['data']['recordCount']);

        // m_IN + m_EQ 组合：sick/overtime 中仅启用 → 1 条（overtime）
        $pCombo = $this->facade->flow('processSurrogate/page', [
            'operator' => 'zhangsan',
            'm_IN_processName' => ['sick', 'overtime'],
            'm_EQ_enabled' => 1,
        ]);
        $this->assertEquals(0, $pCombo['code'], json_encode($pCombo, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(1, $pCombo['data']['recordCount']);
        $this->assertSame('overtime', $pCombo['data']['rows'][0]['processName']);

        // 负向：IN 全不命中 / EQ 无匹配 → 0 条
        $pNone = $this->facade->flow('processSurrogate/page', [
            'operator' => 'zhangsan',
            'm_IN_processName' => ['none1', 'none2'],
        ]);
        $this->assertEquals(0, $pNone['code']);
        $this->assertEquals(0, $pNone['data']['recordCount']);
        $pEq2 = $this->facade->flow('processSurrogate/page', [
            'operator' => 'zhangsan',
            'm_EQ_enabled' => 2,
        ]);
        $this->assertEquals(0, $pEq2['code']);
        $this->assertEquals(0, $pEq2['data']['recordCount']);
    }

    /**
     * issues/82-12：委托生效判断——时间窗 startTime/endTime + enabled 过滤
     * （内存仓储 getSurrogate 首次落地，对齐 Java/Go/Python/Node 基准）。
     * 5 条委托各对应一个时间态：在窗/未到/已过/无窗(enabled=0)/无窗(enabled=1)，
     * 每条查询只命中其中一条（processName 精确区分）→ 不依赖仓储返回顺序。
     */
    public function testSurrogateEffectiveWindowAndEnabled(): void
    {
        $op = 'winop';

        $save = function (string $agent, string $pn, array $extra = []) use ($op): void {
            $r = $this->facade->flow('processSurrogate/save', array_merge([
                'operator' => $op,
                'surrogate' => $agent,
                'processName' => $pn,
                'enabled' => 1,
            ], $extra));
            $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        };

        // A 在窗（2026-08-01 ~ 08-31）
        $save('sA', 'winA', ['startTime' => '2026-08-01 00:00:00', 'endTime' => '2026-08-31 23:59:59']);
        // B 未到（2026-09-01 起）
        $save('sB', 'winB', ['startTime' => '2026-09-01 00:00:00']);
        // C 已过（07-31 止）
        $save('sC', 'winC', ['endTime' => '2026-07-31 23:59:59']);
        // D 无窗但停用（enabled=0）
        $save('sD', 'winD', ['enabled' => 0]);
        // E 无窗且启用（enabled=1）
        $save('sE', 'winE');

        $at = '2026-08-15 12:00:00';
        $hit = $this->extRepo->getSurrogate($op, 'winA', $at);
        $this->assertNotNull($hit, '在窗委托应生效');
        $this->assertSame('sA', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winB', $at), '未到窗委托不应生效');
        $this->assertNull($this->extRepo->getSurrogate($op, 'winC', $at), '已过窗委托不应生效');
        $this->assertNull($this->extRepo->getSurrogate($op, 'winD', $at), 'enabled=0 不应生效');
        $hit = $this->extRepo->getSurrogate($op, 'winE', $at);
        $this->assertNotNull($hit, '无窗启用委托应生效（NULL=不限）');
        $this->assertSame('sE', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winZ', $at), '无匹配流程应返回 null');

        // 换时间验证窗口边界随时间变化：B 在 9 月生效、A 在 9 月失效
        $atSep = '2026-09-15 12:00:00';
        $hit = $this->extRepo->getSurrogate($op, 'winB', $atSep);
        $this->assertNotNull($hit, '9 月：B 进入窗口应生效');
        $this->assertSame('sB', $hit['surrogate']);
        $this->assertNull($this->extRepo->getSurrogate($op, 'winA', $atSep), '9 月：A 已出窗口不应生效');
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

    /** 委托删除 {ids} 批量（issues/95）：前端「我的委托」行内与批量删除统一发 ids 数组
     *  （行内 = 长度 1），此前门面只读单数 id → 该页删除整体不可用；单 id 保留兼容。 */
    public function testSurrogateRemoveBatchIds(): void
    {
        $save = function (string $op, string $agent, string $name): string {
            $r = $this->facade->flow('processSurrogate/save', [
                'operator' => $op, 'surrogate' => $agent, 'processName' => $name,
            ]);
            $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
            return (string) $r['data']['id'];
        };

        $a = $save('zhangsan', 'lisiA', 'leaveA');
        $b = $save('zhangsan', 'lisiB', 'leaveB');
        $r = $this->facade->flow('processSurrogate/remove', ['ids' => [$a, $b]]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->extRepo->findSurrogateById($a), '批量 a 应已删除');
        $this->assertNull($this->extRepo->findSurrogateById($b), '批量 b 应已删除');

        // 行内删除：前端同样走 {ids}，长度 1
        $c = $save('lisiC', 'lisiD', 'leaveC');
        $r = $this->facade->flow('processSurrogate/remove', ['ids' => [$c]]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->extRepo->findSurrogateById($c), '行内 c 应已删除');

        // 单 {id} 兼容形态回归（移动端 workflow.uts 发这个）
        $d = $save('zhangsan', 'lisiE', 'leaveD');
        $r = $this->facade->flow('processSurrogate/remove', ['id' => $d]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->extRepo->findSurrogateById($d), '单 id d 应已删除');
    }

    /** {ids}/{id} 缺失或空数组一律报错，禁止静默成功（issues/95 §5②）：
     *  PHP 曾把 $args['id'] ?? '' 的空串绑进 DELETE，删 0 行仍回 code=0。 */
    public function testRemoveEmptyIdsRejected(): void
    {
        $cases = [
            ['processSurrogate/remove', ['ids' => []]],
            ['processSurrogate/remove', ['surrogate' => 'lisi']],
            ['processSurrogate/remove', ['ids' => [123, null]]],
            ['processDefine/remove', ['ids' => []]],
            ['processDesign/remove', ['ids' => []]],
            ['processDefine/upAndDown', ['ids' => [], 'opType' => 0]],
        ];
        foreach ($cases as [$action, $args]) {
            $r = $this->facade->flow($action, $args);
            $this->assertEquals(
                99999999,
                $r['code'],
                $action . ' ' . json_encode($args, JSON_UNESCAPED_UNICODE) . ' 应报错而非静默成功'
            );
            $this->assertStringContainsString('id 缺失或非法', (string) $r['msg'], $action . ' msg=' . $r['msg']);
        }
    }

    // ── issues/96 B 档：入口批量参数形态矩阵（4 个 IdsParam action × 4 态）──
    //
    // A 档只在 processSurrogate/remove 上验了 {ids}；B 档把同一套形态钉到四个 action。
    // 每态：① {ids:[a,b]} 批量正向（事后回查两条都取不到）② {id:c} 旧形态回归
    // ③ {ids:[]} 必须非零 ④ {ids:[""]} / 含 null 必须非零。
    // PHP 的特殊意义（issues/95 §2）：旧实现 $args['id'] ?? '' 会把空串绑进
    // DELETE ... WHERE id=''，删 0 行仍回 code=0 静默成功——所以 ③④ 钉的是
    // 「不得静默成功」，①② 的回查钉的是「真删掉了 / 真没被牵连」。

    /** 矩阵 · processSurrogate/remove：前端「我的委托」行内与批量删除统一发 {ids} */
    public function testSurrogateRemoveIdsParamMatrix(): void
    {
        $save = function (string $processName, string $surrogate): string {
            $r = $this->facade->flow('processSurrogate/save', [
                'processName' => $processName,
                'surrogate' => $surrogate,
                'startTime' => '2026-08-01 00:00:00',
                'endTime' => '2026-08-31 23:59:59',
                'enabled' => 1,
            ]);
            $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
            return (string) $r['data']['id'];
        };
        $a = $save('matrixA', 'sA');
        $b = $save('matrixB', 'sB');
        $c = $save('matrixC', 'sC');
        $d = $save('matrixD', 'sD');

        // 态 1：{ids:[a,b]} → 成功且事后回查两条都取不到
        $r = $this->facade->flow('processSurrogate/remove', ['ids' => [$a, $b]]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ([$a, $b] as $id) {
            $this->assertNull($this->extRepo->findSurrogateById($id), "批量删除后 {$id} 仓储应取不到");
            $this->assertSame(99999999, $this->facade->flow('processSurrogate/detail', ['id' => $id])['code'],
                "批量删除后 {$id} 门面 detail 应报不存在");
        }
        $this->assertNotNull($this->extRepo->findSurrogateById($c), '态 1 不应波及未传入的 c');

        // 态 2：{id:c} 旧形态（移动端 workflow.uts 载荷）回归保护
        $r = $this->facade->flow('processSurrogate/remove', ['id' => $c]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->extRepo->findSurrogateById($c), '单 id 形态 c 应已删除');

        // 态 3：{ids:[]} → 必须非零（PHP 专项：空载荷不得假成功）
        $this->assertIdsParamRejected('processSurrogate/remove', ['ids' => []]);
        $this->assertNotNull($this->extRepo->findSurrogateById($d), '被拒的空数组载荷不得删掉 d');

        // 态 4：{ids:[""]} / {ids:[d,null]} → 必须非零，且整批不落地
        $this->assertIdsParamRejected('processSurrogate/remove', ['ids' => ['']]);
        $this->assertIdsParamRejected('processSurrogate/remove', ['ids' => [$d, null]]);
        $this->assertNotNull($this->extRepo->findSurrogateById($d), '含非法元素应整批拒绝，d 不得被部分删除');
        // 两者皆缺（既无 ids 也无 id）同样非零
        $this->assertIdsParamRejected('processSurrogate/remove', ['surrogate' => 'sD']);
    }

    /** 矩阵 · processDesign/remove：前端「流程设计」行内与批量删除统一发 {ids} */
    public function testDesignRemoveIdsParamMatrix(): void
    {
        $save = function (string $name): string {
            $r = $this->facade->flow('processDesign/save', ['name' => $name, 'displayName' => 'B档' . $name]);
            $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
            return (string) $r['data']['id'];
        };
        $a = $save('matrixDesignA');
        $b = $save('matrixDesignB');
        $c = $save('matrixDesignC');
        $d = $save('matrixDesignD');

        // 态 1
        $r = $this->facade->flow('processDesign/remove', ['ids' => [$a, $b]]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ([$a, $b] as $id) {
            $this->assertNull($this->extRepo->findDesignById($id), "批量删除后设计 {$id} 应取不到");
            $this->assertSame(99999999, $this->facade->flow('processDesign/detail', ['id' => $id])['code'],
                "批量删除后 {$id} 门面 detail 应报设计不存在");
        }
        $this->assertNotNull($this->extRepo->findDesignById($c), '态 1 不应波及未传入的 c');

        // 态 2
        $r = $this->facade->flow('processDesign/remove', ['id' => $c]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->extRepo->findDesignById($c), '单 id 形态 c 应已删除');

        // 态 3
        $this->assertIdsParamRejected('processDesign/remove', ['ids' => []]);
        $this->assertNotNull($this->extRepo->findDesignById($d), '被拒的空数组载荷不得删掉 d');

        // 态 4
        $this->assertIdsParamRejected('processDesign/remove', ['ids' => ['']]);
        $this->assertIdsParamRejected('processDesign/remove', ['ids' => [$d, null]]);
        $this->assertNotNull($this->extRepo->findDesignById($d), '含非法元素应整批拒绝，d 不得被部分删除');
        $this->assertIdsParamRejected('processDesign/remove', ['displayName' => 'B档']);
    }

    /** 矩阵 · processDefine/remove：前端「流程定义」行内与批量删除统一发 {ids} */
    public function testDefineRemoveIdsParamMatrix(): void
    {
        $a = $this->deployDefineOf('01-simple.json');
        $b = $this->deployDefineOf('02-multi-task.json');
        $c = $this->deployDefineOf('09-with-reject.json');
        $d = $this->deployDefineOf('10-mixed-mode.json');

        // 态 1
        $r = $this->facade->flow('processDefine/remove', ['ids' => [$a, $b]]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ([$a, $b] as $id) {
            $this->assertNull($this->repo->findDefineById($id), "批量删除后定义 {$id} 应取不到");
            $this->assertSame(99999999, $this->facade->flow('processDefine/detail', ['id' => $id])['code'],
                "批量删除后 {$id} 门面 detail 应报流程定义不存在");
        }
        $this->assertNotNull($this->repo->findDefineById($c), '态 1 不应波及未传入的 c');

        // 态 2
        $r = $this->facade->flow('processDefine/remove', ['id' => $c]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->repo->findDefineById($c), '单 id 形态 c 应已删除');

        // 态 3
        $this->assertIdsParamRejected('processDefine/remove', ['ids' => []]);
        $this->assertNotNull($this->repo->findDefineById($d), '被拒的空数组载荷不得删掉 d');

        // 态 4
        $this->assertIdsParamRejected('processDefine/remove', ['ids' => ['']]);
        $this->assertIdsParamRejected('processDefine/remove', ['ids' => [$d, null]]);
        $this->assertNotNull($this->repo->findDefineById($d), '含非法元素应整批拒绝，d 不得被部分删除');
        $this->assertIdsParamRejected('processDefine/remove', ['name' => 'simple']);
    }

    /**
     * 矩阵 · processDefine/upAndDown：前端「流程定义」启用/停用统一发 {ids,opType}。
     * ⚠ 除 ids 外还有 opType/state：每个载荷都显式带 opType，并回查 state 真按 opType 变了。
     * 不带 opType 时 PHP 侧 $args['opType'] ?? $args['state'] ?? 1 会静默取默认值（不报错），
     * 但断言仍必须锚在「非零 code 来自 ids 解析」上，否则 ③④ 会变恒真。
     */
    public function testDefineUpAndDownIdsParamMatrix(): void
    {
        $a = $this->deployDefineOf('01-simple.json');
        $b = $this->deployDefineOf('02-multi-task.json');
        $c = $this->deployDefineOf('09-with-reject.json');
        $d = $this->deployDefineOf('10-mixed-mode.json');
        // 前置：deploy 出的定义 state=1，否则下面「变 0」断言无鉴别力
        foreach ([$a, $b, $c, $d] as $id) {
            $this->assertSame(1, $this->defineState($id), "初始 state 应为 1: {$id}");
        }

        // 态 1：{ids:[a,b],opType:0} → 成功且两条 state 真的变 0
        $r = $this->facade->flow('processDefine/upAndDown', ['ids' => [$a, $b], 'opType' => 0]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->defineState($a), '批量停用后 a state 应为 0');
        $this->assertSame(0, $this->defineState($b), '批量停用后 b state 应为 0');
        $this->assertSame(1, $this->defineState($c), '态 1 不应波及未传入的 c');

        // 态 2：{id:c,opType:0} 旧形态
        $r = $this->facade->flow('processDefine/upAndDown', ['id' => $c, 'opType' => 0]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->defineState($c), '单 id 形态 c 应已停用');

        // 态 3
        $this->assertIdsParamRejected('processDefine/upAndDown', ['ids' => [], 'opType' => 0]);
        $this->assertSame(1, $this->defineState($d), '被拒的空数组载荷不得改 d 的 state');

        // 态 4
        $this->assertIdsParamRejected('processDefine/upAndDown', ['ids' => [''], 'opType' => 0]);
        $this->assertIdsParamRejected('processDefine/upAndDown', ['ids' => [$d, null], 'opType' => 0]);
        $this->assertSame(1, $this->defineState($d), '含非法元素应整批拒绝，d 的 state 不得被部分改动');
        $this->assertIdsParamRejected('processDefine/upAndDown', ['opType' => 0]);

        // state 别名（opType 缺省时走 state）同样支持 {ids}，并让 d 在这一态里被停用
        $r = $this->facade->flow('processDefine/upAndDown', ['ids' => [$d], 'state' => 0]);
        $this->assertEquals(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->defineState($d), 'state 别名的 {ids} 批量停用应生效');
    }

    /** 批量主键负向态共用断言：非零 code（契约 99999999）+ msg 含「id 缺失或非法」 */
    private function assertIdsParamRejected(string $action, array $args): void
    {
        $payload = json_encode($args, JSON_UNESCAPED_UNICODE);
        $r = $this->facade->flow($action, $args);
        $this->assertNotSame(0, $r['code'],
            "{$action} {$payload} 不得静默成功（PHP 旧实现把空串绑进 DELETE，删 0 行仍回 code=0）；实际: "
            . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(99999999, $r['code'],
            "{$action} {$payload} 实际响应: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('id 缺失或非法', (string) $r['msg'], "{$action} {$payload} msg=" . $r['msg']);
    }

    /** 造一条已发布定义：processDesign/save → updateDefine(content) → deploy → getLastByName 回查 id */
    private function deployDefineOf(string $flowFile): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/' . $flowFile);
        $this->assertNotFalse($json, "流程文件读取失败: {$flowFile}");

        $save = $this->facade->flow('processDesign/save', ['name' => 'matrix_' . $flowFile, 'displayName' => 'B档矩阵']);
        $this->assertEquals(0, $save['code'], json_encode($save, JSON_UNESCAPED_UNICODE));
        $designId = (string) $save['data']['id'];

        $upd = $this->facade->flow('processDesign/updateDefine', [
            'processDesignId' => $designId, 'content' => $json, 'operator' => 'user1',
        ]);
        $this->assertEquals(0, $upd['code'], json_encode($upd, JSON_UNESCAPED_UNICODE));

        $dep = $this->facade->flow('processDesign/deploy', ['id' => $designId]);
        $this->assertEquals(0, $dep['code'], json_encode($dep, JSON_UNESCAPED_UNICODE));

        $name = (string) json_decode($json, true)['name'];
        $last = $this->facade->flow('processDefine/getLastByName', ['processDefineName' => $name]);
        $this->assertEquals(0, $last['code'], json_encode($last, JSON_UNESCAPED_UNICODE));
        return (string) $last['data']['id'];
    }

    /** 回查定义 state（走 processDefine/detail 出口） */
    private function defineState(string $defineId): int
    {
        $r = $this->facade->flow('processDefine/detail', ['id' => $defineId]);
        $this->assertEquals(0, $r['code'], "定义 {$defineId} 回查失败: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        return (int) ($r['data']['state'] ?? -1);
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
