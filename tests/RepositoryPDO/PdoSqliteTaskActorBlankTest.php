<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 任务参与者写侧归属值归一（issues/142 B 批 · spec 06-facade.md §2.11 · PHP 栈，PDO/SQLite 仓一路）。
 *
 * 与内存仓那件（`Jeeflow\Tests\Core\TaskActorBlankDroppedTest`）**同一条判据、同一批格**：
 * 同一输入必须给同一个答案（issues/117 场景 27；issues/142 B 表点名本栈"两仓零空值守卫"，
 * PDO 仓 `addTaskActor` 连"空列表早退"都没有，而同文件 `removeTaskActor` 反而有）。
 *
 * 断言一律落在**真行**上（直查 `wf_process_task_actor.actor_id`），不断返回码——
 * 返回码绿不代表库里干净（issues/141 门禁姿势①"判写侧不判返回码"）。
 *
 * 用 SQLite 内存库跑：判据打在 INSERT/SELECT 构造层，与 160 真 MySQL 同一条 SQL 形状。
 * 真 MySQL 那一路仍走仓内既有 env（`REQUIRE_MYSQL=1` / `SKIP_MYSQL=1`，见 `tests/MysqlSmoke`）。
 */
final class PdoSqliteTaskActorBlankTest extends TestCase
{
    private \PDO $pdo;
    private PdoProcessRepository $repo;

    /** 既有参与者（与内存仓那件的 'leader' 互为对照，好让两仓期望值逐字同形） */
    private const SEEDED = 'leader';

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

    // ── 取证辅助 ──

    /** 建一条进行中任务，并预置既有参与者（写侧判重的对照底座）。 */
    private function seedTask(string $taskId, bool $withSeededActor = true): void
    {
        $this->pdo->prepare('INSERT INTO wf_process_instance (id, operator) VALUES (?, ?)')
            ->execute(['inst-' . $taskId, 'user1']);
        $this->pdo->prepare('INSERT INTO wf_process_task (id, process_instance_id, task_name, display_name, task_state)
             VALUES (?,?,?,?,?)')->execute([$taskId, 'inst-' . $taskId, 'task1', '上级审批', 10]);
        if ($withSeededActor) {
            $this->pdo->prepare('INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time)
                 VALUES (?,?,?,?)')
                ->execute(['seed-' . $taskId . '-' . self::SEEDED, $taskId, self::SEEDED, '2020-01-01 00:00:00']);
        }
    }

    /** @return string[] 该任务下按落库顺序排出的 actor_id（读的是库里的列，不是返回值） */
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

    private function facade(): JeeflowFacade
    {
        return new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
    }

    // ═══ 写侧兜底（SQL 仓一路，两层里的第二层） ═══

    /** 直连仓储灌空值 ⇒ 一行不进（普查 B 表：本栈 PDO 仓 `addTaskActor` 零空值守卫）。 */
    public function testPdoRepoWritePathDropsBlankActors(): void
    {
        $this->seedTask('t-blank-1');

        $this->repo->addTaskActor('t-blank-1', ['', '   ', null, '8501']);

        $this->assertSame([self::SEEDED, '8501'], $this->actorRows('t-blank-1'),
            '§2.11①：PDO 写侧空串/纯空白/null 一律丢弃，库里不得有 actor_id=\'\' 的归属行');
    }

    /** 写侧的同一枚哨兵：'0'／'00'／'1'／'01' 四个人都得真落库。 */
    public function testPdoRepoWritePathSentinelsAreFourDistinctPeople(): void
    {
        $this->seedTask('t-zero-1');

        $this->repo->addTaskActor('t-zero-1', ['0', '', '00', '  ', '1', '01']);

        $this->assertSame([self::SEEDED, '0', '00', '1', '01'], $this->actorRows('t-zero-1'),
            '§2.11④：SQL 判重按列值精确比，四个都是不同的人；空值一个不进');
    }

    /** 写侧 trim＋判重同一把尺子：' 8601 ' 与 '8601' 是同一个人，库里只有一行。 */
    public function testPdoRepoWritePathTrimsAndDedupes(): void
    {
        $this->seedTask('t-trim-1');

        $this->repo->addTaskActor('t-trim-1', [' 8601 ', '8601', '8601 ']);

        $this->assertSame([self::SEEDED, '8601'], $this->actorRows('t-trim-1'),
            '§2.11②：落库取 trim 后的值，同一人不落第二行');
    }

    /** 既有行也是判重底座：库里已有 'leader'，再追加 ' leader ' ⇒ 不新增。 */
    public function testPdoRepoWritePathDedupesAgainstExistingRow(): void
    {
        $this->seedTask('t-exist-1');

        $this->repo->addTaskActor('t-exist-1', [' ' . self::SEEDED . ' ']);

        $this->assertSame([self::SEEDED], $this->actorRows('t-exist-1'), '判重取 trim 后的值（同一人一行）');
    }

    /** 空列表早退（B 表点名：PDO 仓连这个都没有，而同文件 removeTaskActor 有）。 */
    public function testPdoRepoWritePathEmptyListIsNoOp(): void
    {
        $this->seedTask('t-empty-1');
        $before = $this->totalActorRows();

        $this->repo->addTaskActor('t-empty-1', []);
        $this->repo->addTaskActor('t-empty-1', ['', '  ', null]);

        $this->assertSame($before, $this->totalActorRows(), '空集合/全空白 ⇒ 一条 INSERT 都不发');
    }

    // ═══ 门面腿（SQL 后端）与内存仓同一条判据 ═══

    /** 逗号串腿：'0' 存活（本栈旧 `array_filter()` 假值判据吃掉的正是它），空元素不进库。 */
    public function testFacadeSurrogateCommaStringOnSqlBackend(): void
    {
        $this->seedTask('t-leg-1');

        $resp = $this->facade()->flow('processTask/surrogate', [
            'processTaskId' => 't-leg-1', 'actorIds' => '0,, 00 , ,1,01',
        ]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame([self::SEEDED, '0', '00', '1', '01'], $this->actorRows('t-leg-1'),
            '§2.11④：串腿 trim＋丢空，\'0\' 不得被假值判据吃掉');
    }

    /** 数组腿与串腿同判据（旧形状数组腿原样直连仓储）。 */
    public function testFacadeSurrogateArrayFormOnSqlBackend(): void
    {
        $this->seedTask('t-leg-2');

        $resp = $this->facade()->flow('processTask/surrogate', [
            'processTaskId' => 't-leg-2', 'actorIds' => [' 9001 ', '', '  ', null, '9002'],
        ]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame([self::SEEDED, '9001', '9002'], $this->actorRows('t-leg-2'),
            '§2.11：数组腿与串腿同判据，库里只有 trim 后的两个人');
    }

    /** 错误语义缺口①（SQL 后端同档）：空 actorIds ⇒ 报错而非 code=0，库里零新增。 */
    public function testFacadeSurrogateEmptyActorIdsErrorsOnSqlBackend(): void
    {
        $this->seedTask('t-leg-3');
        $before = $this->totalActorRows();
        $facade = $this->facade();

        $ref = null;
        foreach ([[], ['', '   '], null, '   '] as $raw) {
            $resp = $facade->flow('processTask/surrogate', ['processTaskId' => 't-leg-3', 'actorIds' => $raw]);
            $this->assertSame(99999999, $resp['code'],
                '§2.11③：空 actorIds 必须报错：' . var_export($raw, true));
            $this->assertSame('actorIds 缺失', $resp['msg'], '沿用既有"缺参数"信封，不新造');
            if ($ref === null) $ref = $resp;
            $this->assertSame($ref, $resp, '各空档逐字同判');
        }
        $this->assertSame($before, $this->totalActorRows(), '报错档不得落任何行');
    }

    /** 错误语义缺口②（SQL 后端同档）：processTaskId 缺失/空串 ⇒ 响亮报错，不落孤儿行。 */
    public function testFacadeSurrogateBlankTaskIdErrorsOnSqlBackend(): void
    {
        $this->seedTask('t-leg-4');
        $before = $this->totalActorRows();
        $facade = $this->facade();

        foreach ([null, '', '   '] as $raw) {
            $args = ['actorIds' => ['9401']];
            if ($raw !== null) $args['processTaskId'] = $raw;
            $resp = $facade->flow('processTask/surrogate', $args);
            $this->assertSame(99999999, $resp['code'], '主键档必须响亮报错：' . var_export($raw, true));
            $this->assertSame('processTaskId 缺失或非法', $resp['msg']);
        }

        $this->assertSame($before, $this->totalActorRows(), '主键为空 ⇒ 一条 INSERT 都不发');
        $this->assertSame([], $this->actorRows(''), "库里不得有 process_task_id='' 的孤儿行");
    }

    /**
     * 两仓同答案（issues/117 场景 27）：同一份原始入参，内存仓与 PDO 仓给出的参与者集合逐字相同。
     * B 表的本栈形状是"两仓零空值守卫"，收口后必须同判据、同答案。
     */
    public function testBothReposGiveTheSameAnswer(): void
    {
        $taskId = 't-both-1';
        $this->seedTask($taskId, false);
        $raw = ['0', '', ' 00 ', null, '1', '01', '1'];

        $this->repo->addTaskActor($taskId, $raw);

        $memory = new InMemoryProcessRepository();
        $task = ProcessTask::create('inst-' . $taskId, 'task1', '上级审批', 0, 0, null,
            [], 'user1', null, true);
        $task->setTaskId($taskId);
        $memory->saveTask($task);
        $memory->addTaskActor($taskId, $raw);

        $this->assertSame($this->actorRows($taskId), $memory->findTaskById($taskId)->getActorIds(),
            '两仓必须同判据同答案（只修一边不算修完）');
        $this->assertSame(['0', '00', '1', '01'], $this->actorRows($taskId),
            '同一条判据下的期望集合：四个哨兵，无空值');
    }
}
