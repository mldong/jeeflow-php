<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use PHPUnit\Framework\TestCase;

/**
 * 参与者**删除腿**归属值判据（issues/137 §3-6 · owner 2026-10-02 拍「两形并集」· PHP 腿，
 * **PDO/SQLite 仓一路**）。
 *
 * 与内存仓那件（{@see \Jeeflow\Tests\Core\TaskActorDeleteFormsTest}）**同一条判据、同一批格**：
 * 同一输入必须给同一个答案（issues/117 场景 27；只修一边不算修完）。
 * Java 基准腿的 SQL 一路＝`jeeflow-repository-jdbc` 的 `JdbcTaskActorDeleteFormsTest`。
 *
 * 本腿的比较是 `DELETE ... WHERE process_task_id = ? AND actor_id = ?`——**列值精确比较**，
 * 所以两形缺一不可：只取 trim 形 ⇒ 门面按语义 6 交出的历史脏行原值 `" 9101 "` 被削成 `9101`，
 * `actor_id = '9101'` 打不中 `' 9101 '` 那一行，删不掉而门面报成功（**假成功**，改前本栈正是这个形状，
 * {@see self::testUntrimmedLegacyRowIsDeletedByItsRawForm()} 改前必红）；只取原值 ⇒ 第三方绕过门面
 * 直连仓储传 `" 8601 "` 时删不掉写侧归一后落库的规范行 `8601`（issues/142 §9.2 那一路，
 * {@see self::testNormalizedRowIsStillDeletedByTrimmedForm()} 改前改后都要绿）。
 *
 * 断言一律落在**库里的真实列值**上（直查 `wf_process_task_actor.actor_id`），不看返回码也不看内存对象
 * ——返回码绿不代表库里干净（issues/141 门禁姿势①"判写侧不判返回码"）。
 *
 * ⚠️ 用 **SQLite 内存库**跑：本仓 MySQL 真库腿默认连 `127.0.0.1:3306`（`tests/RepositoryPDO/PdoTestDb.php`
 * 走 `JEFFLOW_DB_*`），本机没有 ⇒ 会**静默 skip**，判据打不上去。SQLite 的 `TEXT` 默认 BINARY 排序，
 * `' 9101 '` 与 `'9101'` 逐字节不等，与 MySQL 8.0 NO PAD 同形 ⇒ 脏行判据不会漂。
 * 真 MySQL 那一路仍走仓内既有 env（`REQUIRE_MYSQL=1` / `SKIP_MYSQL=1`，见 `tests/MysqlSmoke`），
 * **本文件不连任何真库**。
 *
 * ⚠️ 脏行夹具一律用**前导空格**：MySQL 5.7 的 PAD SPACE 只忽略尾部空格、8.0 的 NO PAD 连尾部也算，
 * 前导空格在任何排序规则下都与规范行不等。种脏行必须**绕开写侧归一**（`addTaskActor` 会 trim＋丢空，
 * 正常路径建不出脏行）——本文件直接 `INSERT` 库行。
 */
final class PdoSqliteTaskActorDeleteFormsTest extends TestCase
{
    private const TASK = '7301';

    private \PDO $pdo;
    private PdoProcessRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // 与 `PdoSqliteTaskActorBlankTest` 同一份内联 DDL（形状对齐 `packages/repository-pdo/sql/schema-sqlite.sql`）
        $this->pdo->exec(<<<'SQL'
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
SQL);
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        $this->repo = new PdoProcessRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 夹具辅助 ──

    /**
     * 直插参与者行（**绕开写侧归一**）：`addTaskActor` 会 trim＋丢空，正常路径建不出
     * `" 9101 "`／`''` 这类脏行。
     *
     * @param string[] $actorIds
     */
    private function seedActors(string $taskId, array $actorIds): void
    {
        $this->pdo->prepare('INSERT INTO wf_process_instance (id, operator) VALUES (?, ?)')
            ->execute(['inst-' . $taskId, 'user1']);
        $this->pdo->prepare('INSERT INTO wf_process_task (id, process_instance_id, task_name, display_name, task_state)
             VALUES (?,?,?,?,?)')->execute([$taskId, 'inst-' . $taskId, 'task1', '上级审批', 10]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time) VALUES (?,?,?,?)');
        foreach ($actorIds as $i => $actorId) {
            $stmt->execute(['row-' . $taskId . '-' . $i, $taskId, $actorId, '2020-01-01 00:00:00']);
        }
    }

    /** @return string[] 该任务下按落库顺序排出的 actor_id（**读库里的列值**，脏行原样可见） */
    private function actorRows(string $taskId): array
    {
        $stmt = $this->pdo->prepare(
            // ROWID＝落库顺序；id 是 TEXT 列，字典序不等于插入序
            'SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ? ORDER BY ROWID');
        $stmt->execute([$taskId]);
        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function totalActorRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM wf_process_task_actor')->fetchColumn();
    }

    // ═══ 仓储删除腿：PDO/SQLite 仓 removeTaskActor 确实走了 CcActorUtil::deleteForms ═══

    /**
     * **N 档（假成功修复，本栈改前必红）**：库里躺着修复前落下的未 trim 历史脏行 `" 9101 "`，
     * 门面按语义 6 交出**行上的原值**去删 ⇒ 那一行必须真从库里消失。
     *
     * 改前的形状（`CcActorUtil::normalizeActors` 只留 trim 形）把 `" 9101 "` 削成 `9101`，
     * `actor_id = '9101'` 打不中脏行 ⇒ 库里那一行还在，而门面报成功——被摘的人待办还在。
     */
    public function testUntrimmedLegacyRowIsDeletedByItsRawForm(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ']);

        $this->assertSame(['leader'], $this->actorRows(self::TASK),
            '未 trim 的历史脏行必须被原值形删掉（否则是门面报成功的假成功）');
    }

    /**
     * **N 档（142 §9.2 那一路不破，改前改后都要绿）**：库里是写侧归一后的规范行 `8601`，
     * 第三方绕过门面直连仓储传 `" 8601 "` ⇒ 也必须删得掉（靠 trim 形命中）。
     */
    public function testNormalizedRowIsStillDeletedByTrimmedForm(): void
    {
        $this->seedActors(self::TASK, ['8601', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 8601 ']);

        $this->assertSame(['leader'], $this->actorRows(self::TASK),
            '规范行由 trim 形命中（issues/142 §9.2 的既有判据不破）');
    }

    /** 两形同时在库里（脏行＋规范行并存）⇒ 同一个人名下两行都要摘掉，其余人一行不动。 */
    public function testBothFormsOfTheSamePersonAreRemovedTogether(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', '9101', 'leader', 'boss']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ']);

        $this->assertSame(['leader', 'boss'], $this->actorRows(self::TASK),
            '归一后是同一个人 ⇒ 两行都摘（§2.11 口径），其余参与人原样保留（语义 1）');
    }

    /** 两种脏法并存（一个空格 / 两个空格）⇒ 各自的原值形都得命中，缺一种就删不掉那一行。 */
    public function testTwoDifferentRawPaddingsAreBothRemoved(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', '  9101  ', '9101', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [' 9101 ', '  9101  ']);

        $this->assertSame(['leader'], $this->actorRows(self::TASK),
            '去重按字面做、不按"trim 后相同"折叠原值形 ⇒ 两种脏法都删得掉');
    }

    /**
     * **P 档（脏行保护）**：空串／纯空白／`null` 入参**一律不参与匹配**——历史 `actor_id=''`
     * 脏行是待另案清洗的取证痕迹，不得被一次空值入参批量做掉（`WHERE actor_id = ''` 正是
     * "裸传"那一派在本腿上的误删形状）。
     */
    public function testBlankInputNeverDeletesEmptyActorIdDirtyRow(): void
    {
        $this->seedActors(self::TASK, ['', '   ', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['', '   ', null]);

        $this->assertSame(['', '   ', 'leader'], $this->actorRows(self::TASK),
            '空值入参一行都不许删（含历史 actor_id=\'\'/纯空白脏行）');
    }

    /**
     * **P 档（不得退化成清空）**：展开后为空 ⇒ 早退，**一条 `DELETE` 都不发**。
     * 少了这一条，一次误传空串就会把该任务全部参与者清空（`DELETE ... WHERE process_task_id = ?`
     * 少了 actor_id 条件那一形），留下永远无人可办的死任务。
     */
    public function testAllBlankInputIsNoOpAndNeverClearsAllActors(): void
    {
        $this->seedActors(self::TASK, ['zhangsan', 'leader']);
        $before = $this->totalActorRows();

        $this->repo->removeTaskActor(self::TASK, ['', '  ']);
        $this->repo->removeTaskActor(self::TASK, []);
        $this->repo->removeTaskActor(self::TASK, [null, "\t", "\r\n"]);

        $this->assertSame(['zhangsan', 'leader'], $this->actorRows(self::TASK),
            '空入参各形都是零删除，不得清空参与者');
        $this->assertSame($before, $this->totalActorRows(), '全表行数一动没动');
    }

    /**
     * `null` 元素**不得**被串化成 `"null"` 再去删——那会删掉一个真名叫 `null` 的人。
     * 本腿的旧形状 `(string) $actorId` 正是把 `null` 串成 `''`（会去删 `actor_id=''` 脏行）。
     */
    public function testNullElementIsNeverStringifiedIntoAMatch(): void
    {
        $this->seedActors(self::TASK, ['null', '', 'leader']);

        $this->repo->removeTaskActor(self::TASK, [null, 'leader']);

        $this->assertSame(['null', ''], $this->actorRows(self::TASK),
            'null 元素丢弃：既不串成 "null" 删掉那个人，也不串成 \'\' 删掉脏行');
    }

    /** 非参与者静默忽略（语义 7 幂等）：一个都没命中 ⇒ 零删除＋不抛异常。 */
    public function testUnknownActorIsSilentlyIgnored(): void
    {
        $this->seedActors(self::TASK, ['zhangsan', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['stranger', ' 9999 ']);

        $this->assertSame(['zhangsan', 'leader'], $this->actorRows(self::TASK),
            '非参与者静默忽略，既有参与者一行不动');
    }

    /** 任务不存在 ⇒ 零操作、不抛异常（`DELETE` 命中 0 行是正常档，别的任务的行也不许被牵连）。 */
    public function testUnknownTaskIsNoOpWithoutThrowing(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', 'leader']);
        $before = $this->totalActorRows();

        $this->repo->removeTaskActor('404404', [' 9101 ', '9101', 'leader']);

        $this->assertSame([], $this->actorRows('404404'));
        $this->assertSame($before, $this->totalActorRows(),
            'WHERE 必须同时限定 process_task_id：别的任务的行一行不许动');
        $this->assertSame([' 9101 ', 'leader'], $this->actorRows(self::TASK));
    }

    /**
     * 哨兵（§2.11 硬要求④）：删 `'0'` **不得**连带删 `'00'`——本腿是 `actor_id = ?` 列值精确比较，
     * 且 `deleteForms` 的去重是 `in_array(..., true)` **严格**比较；松散比较在 PHP 8 把
     * `'0' == '00'` 判真 ⇒ 第二个人被静默折叠掉、删不掉（issues/141 G2 本栈实测踩点）。
     */
    public function testRemovingZeroDoesNotTakeDownDoubleZero(): void
    {
        $this->seedActors(self::TASK, ['0', '00', '1', '01', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['0']);

        $this->assertSame(['00', '1', '01', 'leader'], $this->actorRows(self::TASK),
            "'0'/'00'/'1'/'01' 是四个不同的人，删一个不许带走另一个");
    }

    /** 带空格入参删真人 ∧ 历史 `actor_id=''` 脏行原样还在（两件事不互相挡）。 */
    public function testBlankValuesAreSkippedWhileRealActorsAreStillRemoved(): void
    {
        $this->seedActors(self::TASK, ['', ' 9101 ', '9101', 'leader']);

        $this->repo->removeTaskActor(self::TASK, ['', null, '   ', ' 9101 ']);

        $this->assertSame(['', 'leader'], $this->actorRows(self::TASK),
            '脏行两形都摘掉，actor_id=\'\' 的历史脏行原样保留');
    }

    /** 逗号串腿与数组腿在删除腿上同判据（形态收敛与写侧共用 `CcActorUtil::itemsOf`）。 */
    public function testCommaStringShapeRemovesTheSameRows(): void
    {
        $this->seedActors(self::TASK, [' 9101 ', '9102', 'leader']);

        // 仓储签名是 array，串形由调用方（门面）拆；这里直连仓储验"拆好之后"的数组腿，
        // 串腿的收敛判据钉在 `Jeeflow\Tests\Core\TaskActorDeleteFormsTest`。
        $this->repo->removeTaskActor(self::TASK, [' 9101 ', ' 9102 ']);

        $this->assertSame(['leader'], $this->actorRows(self::TASK),
            '脏行按原值形命中、规范行按 trim 形命中');
    }

    /**
     * **两仓同答案（issues/117 场景 27）**：同一份原始入参 + 同一份库内行值，
     * 内存仓与 PDO/SQLite 仓删完剩下的行值必须**逐字相同**（含脏行原样保留那一份）。
     */
    public function testBothReposGiveTheSameAnswer(): void
    {
        $rows = ['', ' 9101 ', '9101', '8601', '0', '00', 'leader'];
        $this->seedActors(self::TASK, $rows);

        $memory = new InMemoryProcessRepository();
        $task = ProcessTask::create('inst-' . self::TASK, 'task1', '上级审批', 0, 0, null,
            $rows, 'user1', null, true);
        $task->setTaskId(self::TASK);
        $memory->saveTask($task);

        $input = ['', null, ' 9101 ', ' 8601 ', '0'];
        $this->repo->removeTaskActor(self::TASK, $input);
        $memory->removeTaskActor(self::TASK, $input);

        $sqlRows = $this->actorRows(self::TASK);
        $memoryRows = array_values(array_map(strval(...), $memory->findTaskById(self::TASK)->getActorIds()));

        $this->assertSame($sqlRows, $memoryRows, '两仓必须同判据同答案（只修一边不算修完）');
        $this->assertSame(['', '00', 'leader'], $sqlRows,
            '同一条判据下的期望结果：脏行两形都摘、8601 规范行按 trim 形摘、\'0\' 摘掉而 \'00\' 留下、'
            . 'actor_id=\'\' 历史脏行不被空值入参误删');
    }
}
