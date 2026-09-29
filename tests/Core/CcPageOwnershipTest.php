<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\PageQuery;
use PHPUnit\Framework\TestCase;

/**
 * 抄送分页归属条件必填（issues/141 G1 · PHP 栈，内存仓一路）。
 *
 * 立法逐字依据＝spec 06-facade.md §2.5「抄送分页同一条尺子（owner 2026-09-29 拍）」：
 * `pageCcInstances` 这类"抄送我"取数入口，归属条件（`cc.actor_id`）**必填**——条件缺失或为
 * 空值时**返回空页**（recordCount=0、rows=[]），不得退化成"这条条件不加"而返回全部实例。
 *
 * 本案的原始症状正是"同一栈两个仓储两个答案"：本内存仓旧形状是"无 cc 条件时保持 PDO
 * LEFT JOIN 的同读数"（返全部实例），PDO 仓不带条件时也返全部实例，两仓各自漂移到不同集合上。
 * 判据要同时钉在两仓上（issues/117 场景 27 那把尺子扩到 ccList）——PDO 仓一路见
 * `Jeeflow\Tests\RepositoryPDO\PdoSqliteCcOwnershipTest`，两仓每一格读数必须相等（那件里有
 * 一格专司交叉核对）。
 *
 * 门面路径本身不受影响（`ccList` 恒挂 `cc.actor_id EQ operator`，空串按 issues/129 第一层
 * 回落缺省 user1），这里打的是**直连仓储**那一档：绕过门面的调用方、或下一版门面漏挂条件时，
 * 仓储这一层必须自己顶住。
 */
final class CcPageOwnershipTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    /**
     * 建一个实例并抄送给给定的人，返回实例 id。
     *
     * business_no 给非空值：空值列在任何条件上都是 SQL 三值逻辑的"恒不命中"档，
     * 会让下面那格"非归属条件空值仍被忽略"的对照在两仓各说各话（既有形状，非本案范围）。
     */
    private function instanceCcTo(string $actorId): string
    {
        $no = ++$this->seq;
        $this->repo->addDefine([
            'id' => "def-{$no}",
            'name' => "cc-owner-141-{$no}",
            'displayName' => '抄送归属流程',
            'type' => 'approval',
            'state' => 1,
            'content' => '{}',
            'version' => 1,
        ]);
        // 直接走仓储写侧（不引引擎），把这一格钉在"分页判据"上而不是抄送流程上。
        $inst = ProcessInstance::create(
            ['id' => "def-{$no}"],
            'zhangsan',
            FlowData::create()->set(FlowConst::BUSINESS_NO, "CC141-{$actorId}"),
        );
        $this->repo->saveInstance($inst);
        $this->repo->createCcInstance((string) $inst->getInstanceId(), 'zhangsan', [$actorId]);
        $instanceId = (string) $inst->getInstanceId();
        $this->assertNotSame('', $instanceId, '前置：实例必须已落库并拿到 id');
        return $instanceId;
    }

    /** 正向对照：带归属条件时照旧只出"我的"那一页。 */
    public function testCcPageWithOwnershipConditionReturnsOnlyMine(): void
    {
        $mine = $this->instanceCcTo('user1');
        $theirs = $this->instanceCcTo('user2');

        $query = new PageQuery(1, 50);
        $query->add('cc.actor_id', 'EQ', 'user1');
        $page = $this->repo->pageCcInstances($query);

        $this->assertSame(1, $page->getRecordCount(), '带条件应命中我的那 1 条');
        $this->assertCount(1, $page->getRows(), 'rows 数与 recordCount 同口径');
        $this->assertSame($mine, (string) $page->getRows()[0]['id'], '命中的应是我的实例');
        $this->assertNotSame($theirs, (string) $page->getRows()[0]['id'], '别人的实例不该串进来');
    }

    /**
     * 缺陷档：整条归属条件都不给 ⇒ **空页**。
     * 改前这一格是红的——本仓会把"全部实例"放出去（旧注释写着"保持 PDO LEFT JOIN 的同读数"）。
     */
    public function testCcPageWithoutOwnershipConditionIsEmptyPage(): void
    {
        $this->instanceCcTo('user1');
        $this->instanceCcTo('user2');

        $withOtherCondition = new PageQuery(1, 50);
        $noCondition = $this->repo->pageCcInstances($withOtherCondition);
        $this->assertSame(0, $noCondition->getRecordCount(),
            '缺归属条件必须返回空页，而不是所有实例（issues/141 G1 · spec 06 §2.5）');
        $this->assertSame([], $noCondition->getRows(), '空页的 rows 也必须是空集合');

        $bareQuery = $this->repo->pageCcInstances(new PageQuery());
        $this->assertSame(0, $bareQuery->getRecordCount(), '默认分页参数同样缺归属条件 ⇒ 空页');

        // 只给非归属条件同样不算"给了归属条件"
        $onlyOther = new PageQuery(1, 50);
        $onlyOther->add('t.business_no', 'EQ', 'CC141-user1');
        $this->assertSame(0, $this->repo->pageCcInstances($onlyOther)->getRecordCount(),
            '给了别的条件但没给 cc.actor_id ⇒ 仍须空页（条件必填不是"有条款就行"）');
    }

    /** 空值三形（空串 / 全空白 / null）与"条件整条缺失"同档，外加空集合（IN 给空集＝没有人）。 */
    public function testBlankOwnershipConditionIsAlsoEmptyPage(): void
    {
        $this->instanceCcTo('user1');
        $this->instanceCcTo('user2');

        foreach ([['空串', ''], ['全空白', '   '], ['null', null], ['空集合', []]] as [$label, $value]) {
            $query = new PageQuery(1, 50);
            $query->add('cc.actor_id', $label === '空集合' ? 'IN' : 'EQ', $value);
            $page = $this->repo->pageCcInstances($query);
            $this->assertSame(0, $page->getRecordCount(), "{$label}归属条件 ⇒ 空页（issues/141 G1）");
            $this->assertSame([], $page->getRows(), "{$label}归属条件的 rows 必须是空集合");
        }
    }

    /**
     * 改动面哨兵：只收归属谓词，不把 `PageQuery` 对**可选过滤**空值的通用放行一起改掉
     * （`m_LIKE_*` 传空串按"没填"处理是对的，issues/129 同一条边界）。
     */
    public function testBlankNonOwnershipConditionIsStillIgnored(): void
    {
        $mine = $this->instanceCcTo('user1');
        $theirs = $this->instanceCcTo('user2');
        $this->assertNotSame($mine, $theirs);

        $query = new PageQuery(1, 50);
        $query->add('cc.actor_id', 'EQ', 'user1');
        $query->add('t.business_no', 'LIKE', '');
        $page = $this->repo->pageCcInstances($query);

        $this->assertSame(1, $page->getRecordCount(),
            '空值非归属条件应被忽略、归属条件照常生效（只收归属谓词这一列）');
        $this->assertSame($mine, (string) $page->getRows()[0]['id']);
    }

    /** 分页元信息不破：空页仍回显请求的 pageNum/pageSize（门面 pageResult 依赖）。 */
    public function testEmptyPageStillEchoesPagingMeta(): void
    {
        $this->instanceCcTo('user1');
        $page = $this->repo->pageCcInstances(new PageQuery(2, 7));
        $this->assertSame(2, $page->getPageNum(), '空页也得回显 pageNum');
        $this->assertSame(7, $page->getPageSize(), '空页也得回显 pageSize');
        $this->assertSame(0, $page->getTotalPage(), '空页 totalPage=0');
    }
}
