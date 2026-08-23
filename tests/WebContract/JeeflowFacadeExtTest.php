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

    /**
     * issues/81：listByType items 必含 processDefineState（前端发起按钮硬依赖），取值随定义 state 联动。
     * 场景 A：deploy → 定义 state=1 → 可发起；场景 B：upAndDown 禁用 → state=0 → 前端置灰。
     */
    public function testDesignListByTypeProcessDefineState(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
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
