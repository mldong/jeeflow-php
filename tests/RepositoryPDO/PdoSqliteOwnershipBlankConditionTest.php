<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessExtRepository;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * issues/129 第二层取证 · 本栈 SQL 构造里「归属列遇空值」的形状（SQLite 内存库，不依赖 160 MySQL）
 *
 * issues/129 当时的结论：**php 的四个实例/任务归属出口没有"空值 ⇒ 这条条件不加"的通用放行**，
 * 所以第二层对那四条不适用、不改。读到的代码（写本件时逐字核对）：
 *   - PdoProcessRepository::buildConditions()（四张归属出口共用：pageInstances /
 *     pageTodoTasks·pageDoneTasks 经 pagedTaskQuery / pageCcInstances）对每条条件无条件
 *     拼 `AND <col> = ?` 并绑定原值，match 的 default 分支只吞**未知操作符**、不吞空值；
 *   - PageQuery::add() 也不判空（core/src/Spi/PageQuery.php）；
 *   - 于是空串进本栈仓储只会得到 0 行（拿 "" 当值比对），绝不是全库 —— 门面那一层
 *     （JeeflowFacade::operatorOf）才是洞；这两件把"仓储不丢条件"钉成回归位，
 *     一旦哪天 buildConditions 被加上"空值跳过"（= 另外五栈的读全库症状），这里立刻红。
 *
 * ⚠️ 但"唯一的通用放行"那一半在 issues/152 ②（案 A）之后**收窄了**：
 *   PdoProcessExtRepository::buildSurrogateConditions() 里那句 `if ($val === null || $val === '')
 *   continue;` 对**可选过滤列**（process_name / surrogate / enabled）照旧放行，
 *   对**归属列 t.operator** 不再放行 —— 委托分页的"我的委托"语义与那四条出口同一把尺子
 *   （spec 06 §2.5 口径表 ＋ §4.5「归属不变式」），空值 ⇒ `AND 1=0` 空页。
 *   旧文案把 t.operator 说成"门面从不往这条路上注入归属谓词"，随案 A 作废；
 *   见 {@see self::testOptionalFilterBlankIsStillIgnored()} 里逐字交代的期望值改动。
 */
final class PdoSqliteOwnershipBlankConditionTest extends TestCase
{
    /**
     * [PageQuery 列, 仓储方法, 无归属条件时的全库行数]
     *
     * issues/138 后 ccList 的列写作 `cc.actor_id`、行源是实例表（spec 06-facade.md §2.5 口径表
     * 「`processInstance/ccList` | `cc.actor_id EQ operator`」+ §ccList 行形状三要件「`ccList` 的每一行
     * 必须来自 `wf_process_instance`（cc 表只当过滤/关联用）」）。于是"无归属条件的全库行数"
     * 从 cc 行数（1）变成实例行数（2）—— 与 Java JdbcProcessRepository
     * .pageCcInstances = pageInstances(query, cc=true) 同读数（LEFT JOIN 不过滤）。
     */
    private const OWNERSHIP = [
        ['t.operator', 'pageInstances', 2],
        ['t.operator', 'pageDoneTasks', 2],
        ['pta.actor_id', 'pageTodoTasks', 1],
        ['cc.actor_id', 'pageCcInstances', 2],
    ];

    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private PdoProcessExtRepository $extRepo;

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
CREATE TABLE wf_process_surrogate (
  id INTEGER PRIMARY KEY, process_name TEXT NULL, operator TEXT NULL, surrogate TEXT NULL,
  start_time TEXT NULL, end_time TEXT NULL, enabled INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL);
SQL);
        // 造数据：每类归属列都有真实行，否则"空值 ⇒ 0 行"会和"空表 ⇒ 0 行"混成恒真空
        $this->pdo->exec("INSERT INTO wf_process_instance (id, process_define_id, state, operator, variable, create_time)
            VALUES ('9001','def-1',10,'user1','{}','2026-09-28 10:00:00'),
                    ('9002','def-1',10,'user1','{}','2026-09-28 10:00:01')");
        $this->pdo->exec("INSERT INTO wf_process_task
            (id, process_instance_id, task_name, display_name, task_type, perform_type, task_state,
             operator, variable, create_time)
            VALUES ('9101','9001','task1','上级审批',0,0,20,'user1','{}','2026-09-28 10:00:02'),
                    ('9102','9002','task1','上级审批',0,0,30,'user1','{}','2026-09-28 10:00:03'),
                    ('9103','9001','apply','发起申请',0,0,10,NULL,'{}','2026-09-28 10:00:04')");
        $this->pdo->exec("INSERT INTO wf_process_task_actor (id, process_task_id, actor_id)
            VALUES ('a1','9103','user1')");
        $this->pdo->exec("INSERT INTO wf_process_cc_instance (id, process_instance_id, actor_id, state, create_time)
            VALUES ('c1','9001','user1',0,'2026-09-28 10:00:05')");
        $this->pdo->exec("INSERT INTO wf_process_surrogate
            (id, process_name, operator, surrogate, enabled, create_time)
            VALUES (9201,'leave','user1','lisi',1,'2026-09-28 10:00:06'),
                    (9202,'expense','user1','wangwu',1,'2026-09-28 10:00:07')");

        ServiceContext::clear();
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        $this->repo = new PdoProcessRepository($this->pdo);
        $this->extRepo = new PdoProcessExtRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    /**
     * 绕过门面直接喂空值：归属谓词列 ⇒ 空页，**绝不是**全库。
     * 三种空形各跑一次（空串 / 全空白 / null），null 那支同时钉住"未传值不等于没这条条件"。
     */
    public function testOwnershipPredicateBlankValuesYieldEmptyPageNotWholeLibrary(): void
    {
        foreach (self::OWNERSHIP as [$column, $method, $totalInDb]) {
            foreach ([['空串', ''], ['全空白', "  \t "], ['null', null]] as [$label, $value]) {
                $query = new PageQuery(1, 50);
                $query->add($column, 'EQ', $value);
                $rows = $this->repo->{$method}($query)->getRows();
                $this->assertSame([], $rows,
                    "归属列 {$column} 遇 {$label} 须得到空页（issues/129 第二层：绝不允许把这条条件丢掉）");
                // 同一张表不加条件确实有行 ⇒ 上一条"空"不是恒真空，而是过滤出来的。
                // ⚠️ pageCcInstances 例外（issues/141 G1，owner 2026-09-29 拍）：cc 分页**缺归属条件按契约必须空页**，
                //    不能再拿它当"全库基线"。基线改走 pageInstances——issues/138 后两者行源同为 `wf_process_instance`
                //    （cc 表只当过滤/关联），所以读数等价、非空对照不丢。
                $all = 'pageCcInstances' === $method
                    ? $this->repo->pageInstances(new PageQuery(1, 50))->getRows()
                    : $this->repo->{$method}(new PageQuery(1, 50))->getRows();
                $this->assertCount($totalInDb, $all,
                    "{$method} 全库基线（空表会让上面的断言失去鉴别力）");
                if ('pageCcInstances' === $method) {
                    $this->assertSame([], $this->repo->pageCcInstances(new PageQuery(1, 50))->getRows(),
                        'cc 分页缺归属条件必须空页（G1），且不得与上面的非空基线互换');
                }
            }
        }
    }

    /** 反向哨兵：归属列给**非空**值照常过滤，证明上面的空值是"被当值比对"而不是整条 WHERE 坏了。 */
    public function testOwnershipPredicateStillFiltersRealValues(): void
    {
        $query = new PageQuery(1, 50);
        $query->add('t.operator', 'EQ', 'user1');
        $this->assertCount(2, $this->repo->pageInstances($query)->getRows());

        $query2 = new PageQuery(1, 50);
        $query2->add('pta.actor_id', 'EQ', 'user1');
        $this->assertCount(1, $this->repo->pageTodoTasks($query2)->getRows());

        $query3 = new PageQuery(1, 50);
        $query3->add('cc.actor_id', 'EQ', 'nobody');
        $this->assertCount(0, $this->repo->pageCcInstances($query3)->getRows());
    }

    /**
     * 红线哨兵：动态 where 的**通用空值放行**只属于可选过滤，不许被"归属列空值即空页"带偏。
     *
     * 委托台账（processSurrogate/page）的 m_ 条件里，`process_name` / `surrogate` / `enabled`
     * 仍是纯可选过滤：`m_t_EQ_processName=''` 语义是"没填 ⇒ 不过滤"，
     * PdoProcessExtRepository::buildSurrogateConditions 里那句 `if ($val === null || $val === '') continue;`
     * 对这些列保持原样。若有人把"归属列空值即空页"从归属谓词推广到整条 WHERE，这一格必红。
     *
     * ⚠️ **本用例的 t.operator 那一档在 issues/152 ②（案 A）逐字改过期望值**，交代如下：
     *   改前（本案立案时的读数）：`$null->add('t.operator','EQ',null)` ⇒ 断言 **2 行（全库）**，
     *     理由写在本文件旧注释里——"t.operator 在委托台账里是『授权人』业务列，门面从不往这条
     *     路上注入归属谓词，故它不是归属谓词列，按红线保持原样"。
     *   改后：同一档断言 **空页（0 行）**。依据 spec 06 §2.5 口径表新增行
     *     「processSurrogate/page | t.operator EQ operator」＋ §4.5「归属不变式」仓储层条款
     *     ——「绕过门面直调 pageSurrogates 时，落在归属列上的 EQ 条件值为空 ⇒ 返回空页」。
     *   即 t.operator 在委托分页上**就是归属列**（与实例/待办/已办/抄送同一把尺子），
     *     旧期望把"归属靠集成壳注入"钉成了契约，owner 选 A（引擎侧立法）后该期望作废。
     *     同一条数据上的真值档（下面的 process_name='leave'）与非归属列空值档仍照常放行，
     *     证明收紧只落在归属列上，不是把整条 WHERE 判废。
     */
    public function testOptionalFilterBlankIsStillIgnored(): void
    {
        $blank = new PageQuery(1, 50);
        $blank->add('t.process_name', 'EQ', '');
        $this->assertCount(2, $this->extRepo->pageSurrogates($blank)->getRows(),
            '可选过滤空串须仍按"没填"处理（本栈唯一存在的通用放行，保持原样）');

        // issues/152 ②：t.operator 是归属列 ⇒ 空值三形一律空页（不是"这条条件不加"读全库）
        foreach ([['null', null], ['空串', ''], ['全空白', "  \t "]] as [$label, $value]) {
            $ownership = new PageQuery(1, 50);
            $ownership->add('t.operator', 'EQ', $value);
            $this->assertSame([], $this->extRepo->pageSurrogates($ownership)->getRows(),
                "归属列 t.operator 遇 {$label} 须空页（spec 06 §4.5 归属不变式第二层），不得退化成全库台账");
        }

        $filled = new PageQuery(1, 50);
        $filled->add('t.process_name', 'EQ', 'leave');
        $this->assertCount(1, $this->extRepo->pageSurrogates($filled)->getRows(),
            '对照：非空可选过滤确实生效（否则上面的 2 行是恒真空）');

        // 对照：归属列给真实值照常过滤 ⇒ 证明上面的空页不是把整条 WHERE 判废
        $own = new PageQuery(1, 50);
        $own->add('t.operator', 'EQ', 'user1');
        $this->assertCount(2, $this->extRepo->pageSurrogates($own)->getRows(),
            '对照：t.operator = user1 照常出该授权人的两条');
        $nobody = new PageQuery(1, 50);
        $nobody->add('t.operator', 'EQ', 'nobody-at-all');
        $this->assertCount(0, $this->extRepo->pageSurrogates($nobody)->getRows(),
            '对照：谁都没有的授权人 ⇒ 0 行（过滤真生效，不是恒放行）');
    }
}
