<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * 抄送分页归属条件必填（issues/141 G1 · PHP 栈，PDO/SQLite 仓一路 ＋ 两仓交叉核对）。
 *
 * 立法逐字依据＝spec 06-facade.md §2.5「抄送分页同一条尺子」：`cc.actor_id` 缺失或为空值 ⇒
 * **空页**，严禁退化成"这条条件不加"而返回全部实例。
 *
 * 本栈 SQL 仓的现读反面教材就被 spec 点名了：
 * `FROM wf_process_instance t LEFT JOIN wf_process_cc_instance cc ON t.id = cc.process_instance_id`
 * 不带条件时放出**全部实例**（`buildConditions` 只会"照值比对"，压根没有这一条 WHERE）。
 * 空值档原先由 issues/129 的"照值比对"自然得到 0 行，这一件补的是"条件整条没给"。
 *
 * 用 SQLite 内存库跑（与 `PdoSqliteOwnershipBlankConditionTest` 同一姿势）：判据打在 SQL 构造层，
 * 不依赖 160 上的真 MySQL，T0 就能钉住。
 *
 * 最后一格是本案的**核心**：同一份数据在 PDO 仓与内存仓上必须逐格同读数
 * （issues/117 场景 27 那把尺子扩到 ccList，只修一边不算修完）。
 */
final class PdoSqliteCcOwnershipTest extends TestCase
{
    /** 与内存仓那件（CcPageOwnershipTest）逐字相同的两条数据 */
    private const SEEDS = [
        ['9001', 'CC141-user1', 'user1'],
        ['9002', 'CC141-user2', 'user2'],
    ];

    private \PDO $pdo;
    private PdoProcessRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, display_name TEXT NOT NULL, type TEXT NULL,
  state INTEGER NULL, content TEXT NULL, version INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY, parent_id TEXT NULL, process_define_id TEXT NULL, state INTEGER NULL,
  parent_node_name TEXT NULL, business_no TEXT NULL, operator TEXT NULL, expire_time TEXT NULL,
  variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL, update_time TEXT NULL,
  update_user TEXT NULL);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, task_name TEXT NOT NULL,
  display_name TEXT NOT NULL, task_type INTEGER NULL, perform_type INTEGER NULL, task_state INTEGER NULL,
  operator TEXT NULL, finish_time TEXT NULL, expire_time TEXT NULL, form_key TEXT NULL,
  task_parent_id TEXT NULL, variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY, process_task_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  create_time TEXT NULL, create_user TEXT NULL);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
SQL);
        $this->pdo->exec("INSERT INTO wf_process_define (id, name, display_name, type, state, content, version)
            VALUES ('def-1','cc-owner-141','抄送归属流程','approval',1,'{}',1)");
        foreach (self::SEEDS as $i => [$id, $businessNo, $actor]) {
            // create_time 逐条递增：pageCcInstances 默认 `ORDER BY t.create_time DESC`，
            // 相同时间戳会让行序不稳定，交叉核对那一格比的是排好序的集合，这里仍给个确定序。
            $this->pdo->exec("INSERT INTO wf_process_instance (id, process_define_id, state, business_no, operator, variable, create_time)
                VALUES ('{$id}','def-1',10,'{$businessNo}','zhangsan','{}','2026-09-29 10:00:0{$i}')");
        }
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new PdoProcessRepository($this->pdo);
        foreach (self::SEEDS as [$id, , $actor]) {
            $this->repo->createCcInstance($id, 'zhangsan', [$actor]);
        }
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    /** 正向对照：带归属条件时只出"我的"那一页。 */
    public function testCcPageWithOwnershipConditionReturnsOnlyMine(): void
    {
        $query = new PageQuery(1, 50);
        $query->add('cc.actor_id', 'EQ', 'user1');
        $page = $this->repo->pageCcInstances($query);

        $this->assertSame(1, $page->getRecordCount(), '带条件应命中我的那 1 条');
        $this->assertCount(1, $page->getRows(), 'rows 数与 recordCount 同口径');
        $this->assertSame('9001', (string) $page->getRows()[0]['id']);
        $this->assertSame('CC141-user1', (string) $page->getRows()[0]['businessNo']);
    }

    /**
     * 缺陷档：整条归属条件都不给 ⇒ 空页。
     * 改前这一格是红的——LEFT JOIN 不带条件放出全部实例（2 条），正是 spec 点名的本栈反面教材。
     */
    public function testCcPageWithoutOwnershipConditionIsEmptyPage(): void
    {
        $noCondition = $this->repo->pageCcInstances(new PageQuery(1, 50));
        $this->assertSame(0, $noCondition->getRecordCount(),
            '缺归属条件必须返回空页，而不是 LEFT JOIN 不过滤放出的全部实例');
        $this->assertSame([], $noCondition->getRows(), '空页的 rows 也必须是空集合');

        $this->assertSame(0, $this->repo->pageCcInstances(new PageQuery())->getRecordCount(),
            '默认分页参数同样缺归属条件 ⇒ 空页');

        // 只给非归属条件同样不算"给了归属条件"
        $onlyOther = new PageQuery(1, 50);
        $onlyOther->add('t.business_no', 'EQ', 'CC141-user1');
        $this->assertSame(0, $this->repo->pageCcInstances($onlyOther)->getRecordCount(),
            '给了别的条件但没给 cc.actor_id ⇒ 仍须空页');

        // 对照：实例表本身不缺行，空页是判据造成的，不是"表就是空的"
        $this->assertCount(2, $this->repo->pageInstances(new PageQuery(1, 50))->getRows(),
            '前置哨兵：实例表确有 2 行，否则上面的 0 是恒真空、断言失去鉴别力');
    }

    /** 空值三形（空串 / 全空白 / null）与"条件整条缺失"同档，外加空集合（IN 给空集＝没有人）。 */
    public function testBlankOwnershipConditionIsAlsoEmptyPage(): void
    {
        foreach ([['空串', ''], ['全空白', '   '], ['null', null], ['空集合', []]] as [$label, $value]) {
            $query = new PageQuery(1, 50);
            $query->add('cc.actor_id', $label === '空集合' ? 'IN' : 'EQ', $value);
            $page = $this->repo->pageCcInstances($query);
            $this->assertSame(0, $page->getRecordCount(), "{$label}归属条件 ⇒ 空页（issues/141 G1）");
            $this->assertSame([], $page->getRows(), "{$label}归属条件的 rows 必须是空集合");
        }
    }

    /** 改动面哨兵：非归属列的空值放行不变（issues/129 那条边界仍成立）。 */
    public function testBlankNonOwnershipConditionIsStillIgnored(): void
    {
        $query = new PageQuery(1, 50);
        $query->add('cc.actor_id', 'EQ', 'user1');
        $query->add('t.business_no', 'LIKE', '');
        $page = $this->repo->pageCcInstances($query);

        $this->assertSame(1, $page->getRecordCount(),
            '空值非归属条件应被忽略、归属条件照常生效（只收归属谓词这一列）');
        $this->assertSame('9001', (string) $page->getRows()[0]['id']);
    }

    /**
     * 本案核心：同一份数据、同一批条件，**PDO 仓与内存仓逐格同读数**
     * （issues/117 场景 27 那把尺子扩到 ccList；spec 06 §2.5「SQL 仓与内存仓必须给同一个答案」）。
     *
     * 行身份用 `businessNo` 对齐（两仓各自的实例主键来源不同，比 id 会把"装配差异"误报成"判据分叉"）。
     */
    public function testBothRepositoriesGiveTheSameAnswerOnEveryCell(): void
    {
        $memory = new InMemoryProcessRepository();
        foreach (self::SEEDS as $i => [$id, $businessNo, $actor]) {
            $memory->addDefine([
                'id' => "def-{$id}", 'name' => "cc-owner-141-{$i}", 'displayName' => '抄送归属流程',
                'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 1,
            ]);
            $inst = ProcessInstance::create(['id' => "def-{$id}"], 'zhangsan',
                FlowData::create()->set(FlowConst::BUSINESS_NO, $businessNo));
            $memory->saveInstance($inst);
            $memory->createCcInstance((string) $inst->getInstanceId(), 'zhangsan', [$actor]);
        }

        $cells = [
            '零条件' => null,
            '空串' => ['', 'EQ'],
            '全空白' => ['   ', 'EQ'],
            'null' => [null, 'EQ'],
            '空集合' => [[], 'IN'],
            'user1' => ['user1', 'EQ'],
            'user2' => ['user2', 'EQ'],
            '不存在的人' => ['__nobody__', 'EQ'],
        ];
        foreach ($cells as $label => $cell) {
            $rowsOf = function (object $repo) use ($label, $cell): array {
                $query = new PageQuery(1, 50);
                if ($cell !== null) {
                    [$value, $op] = $cell;
                    $query->add('cc.actor_id', $op, $value);
                }
                $page = $repo->pageCcInstances($query);
                $businessNos = array_map(fn(array $r) => (string) ($r['businessNo'] ?? ''), $page->getRows());
                sort($businessNos);
                return [$page->getRecordCount(), $businessNos];
            };
            $pdoAnswer = $rowsOf($this->repo);
            $memoryAnswer = $rowsOf($memory);
            $this->assertSame($pdoAnswer, $memoryAnswer,
                "「{$label}」这一格 PDO 仓与内存仓必须同答案（recordCount + 行集合都对比）");
        }

        // 哨兵：交叉核对里确有非空格，否则上面八格全是"0 == 0"的自等假绿
        $probe = new PageQuery(1, 50);
        $probe->add('cc.actor_id', 'EQ', 'user1');
        $this->assertSame(1, $this->repo->pageCcInstances($probe)->getRecordCount(),
            '前置哨兵：PDO 仓带条件须出 1 行');
        $this->assertSame(1, $memory->pageCcInstances($probe)->getRecordCount(),
            '前置哨兵：内存仓带条件须出 1 行');
    }
}
