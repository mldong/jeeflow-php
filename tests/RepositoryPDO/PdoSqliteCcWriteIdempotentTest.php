<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 抄送写侧判重＝幂等空操作（issues/141 G2 · PHP 栈，PDO/SQLite 仓一路）。
 *
 * 与内存仓那件（`Jeeflow\Tests\Core\CcWriteIdempotentTest`）同一条判据、同一批格：
 * 同一 `(实例, 被抄送人)` 已有 cc 行 ⇒ ①不新增行 ②不重置未读 ③不更新原行时间
 * ④不 fire CC_CREATE（码 4）；逐人 fire 的入参＝实际新建子集，子集空整支不发。
 * 查询侧不加 DISTINCT、历史重复行不清理（spec 06-facade.md §4，owner 2026-09-29 拍）。
 *
 * ③这一档本件用**哨兵时间**取证：先用 SQL 直插一条 `create_time='2020-01-01 00:00:00'`
 * 的 cc 行，再重复抄送同一个人——改前（无判重、每次都 INSERT）必然多出一行"今天"的记录，
 * 断言当场红；改后原行两列逐字不动。这比"等墙钟跨秒"更硬（内存仓那件因为没有 SQL 旁路，
 * 只能用 `awaitSecondBoundary()` 把秒精度造出可分档）。
 *
 * 用 SQLite 内存库跑：判据打在 INSERT/SELECT 构造层，与 160 真 MySQL 同一条 SQL 形状。
 */
final class PdoSqliteCcWriteIdempotentTest extends TestCase
{
    /** 哨兵时间：与"今天"永远可分，重复抄送若真去建行/刷时间必然撞上它 */
    private const SENTINEL = '2020-01-01 00:00:00';

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
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        $this->repo = new PdoProcessRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        ProcessEventListenerRegistry::clear();
        ServiceContext::clear();
    }

    // ── 取证辅助 ──

    /** @return array<int, array<string, mixed>> 某 (实例, 人) 的全部 cc 行（按 id 升序） */
    private function rowsOf(string $instanceId, string $actorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, state, create_time, create_user, update_time FROM wf_process_cc_instance
             WHERE process_instance_id = ? AND actor_id = ? ORDER BY id');
        $stmt->execute([$instanceId, $actorId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function ccRowCount(?string $instanceId = null): int
    {
        if ($instanceId === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM wf_process_cc_instance')->fetchColumn();
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM wf_process_cc_instance WHERE process_instance_id = ?');
        $stmt->execute([$instanceId]);
        return (int) $stmt->fetchColumn();
    }

    /** 直插一条已读（state=1）的哨兵 cc 行，绕过写侧——专门给②③档做取证底座。 */
    private function seedReadRow(string $instanceId, string $actorId, string $state = '1'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_cc_instance (id, process_instance_id, actor_id, state,
                create_time, create_user, update_time, update_user) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute(['seed-' . $actorId, $instanceId, $actorId, (int) $state,
                        self::SENTINEL, 'zhangsan', self::SENTINEL, 'zhangsan']);
    }

    // ═══ 读侧：findCcActorIds ═══

    public function testFindCcActorIdsReadsTheRealRows(): void
    {
        $this->repo->createCcInstance('inst-1', 'zhangsan', ['8001', '8002']);
        $this->repo->createCcInstance('inst-2', 'zhangsan', ['8003']);

        $this->assertSame(['8001', '8002'], $this->repo->findCcActorIds('inst-1'),
            '读侧须返回该实例真实的 cc 行 actor 集合');
        $this->assertSame(['8003'], $this->repo->findCcActorIds('inst-2'), '判重作用域按实例隔离');
        $this->assertSame([], $this->repo->findCcActorIds('inst-none'), '无行 ⇒ 空集，不是 null');
    }

    // ═══ 正向对照：全新的一次抄送照旧建行 ═══

    public function testFirstCcStillCreatesRowsAndReturnsFullSubset(): void
    {
        $created = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6101', '6102']);

        $this->assertSame(['6101', '6102'], $created, '全新的人 ⇒ 返回的实际新建子集＝全量');
        $this->assertSame(2, $this->ccRowCount('inst-1'), '全新抄送应逐人落行');
        $this->assertCount(1, $this->rowsOf('inst-1', '6101'));
        $this->assertSame(0, (int) $this->rowsOf('inst-1', '6101')[0]['state'], '新行应是未读');
    }

    // ═══ 四档：重复抄送是幂等空操作 ═══

    /** ①不新增行：已有 (实例, 人) 的行时，无论走 createCcInstance 还是 IfAbsent 都不追加。 */
    public function testRepeatCcAddsNoRow(): void
    {
        $this->repo->createCcInstance('inst-1', 'zhangsan', ['6201']);
        $this->assertSame(1, $this->ccRowCount('inst-1'), '首次抄送落 1 行');

        $this->repo->createCcInstance('inst-1', 'zhangsan', ['6201']);
        $created = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6201']);

        $this->assertSame(1, $this->ccRowCount('inst-1'), '①重复抄送不得新增行（两条入口同判据）');
        $this->assertSame([], $created, '④没新建 ⇒ 返回空子集，调用方整支不 fire');
    }

    /** ②不重置未读：哨兵行 state=1（已读），重复抄送后仍是 1，也没冒出新未读行。 */
    public function testRepeatCcDoesNotResetUnreadState(): void
    {
        $this->seedReadRow('inst-1', '6301');
        $this->assertSame(1, (int) $this->rowsOf('inst-1', '6301')[0]['state'], '前置：哨兵行是已读');

        $this->repo->createCcInstance('inst-1', 'zhangsan', ['6301']);
        $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6301']);

        $this->assertCount(1, $this->rowsOf('inst-1', '6301'), '②重复抄送不得新增第二行（新行天然是未读）');
        $this->assertSame(1, (int) $this->rowsOf('inst-1', '6301')[0]['state'],
            '②重复抄送不得把已读抹回未读（owner 2026-09-29 明确"不需要重置"）');
    }

    /** ③不更新原行时间：哨兵 create_time/update_time 逐字不变（连 UPDATE 都不该发）。 */
    public function testRepeatCcDoesNotTouchOriginalRowTimes(): void
    {
        $this->seedReadRow('inst-1', '6401');

        $this->repo->createCcInstance('inst-1', 'zhangsan', ['6401']);
        $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6401', '6402']);

        $row = $this->rowsOf('inst-1', '6401')[0];
        $this->assertSame(self::SENTINEL, (string) $row['create_time'],
            '③重复抄送不得刷新原行 create_time（改前会多出一行"今天"的记录）');
        $this->assertSame(self::SENTINEL, (string) $row['update_time'],
            '③重复抄送不得刷新原行 update_time（不碰 UPDATE）');
        $this->assertCount(1, $this->rowsOf('inst-1', '6401'), '③之后仍只有一行');
    }

    /** ④的子集档：第二次给「已知人＋新人」⇒ 只新建/只返回那一个新人。 */
    public function testRepeatCcReturnsOnlyNewlyCreatedSubset(): void
    {
        $this->repo->createCcInstance('inst-1', 'zhangsan', ['6501', '6502']);

        $created = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6501', '6503', '6502']);

        $this->assertSame(['6503'], $created, '实际新建子集须只含那个新人（顺序照入参）');
        $this->assertSame(3, $this->ccRowCount('inst-1'), '总行数只 +1');
        $this->assertSame(['6501', '6502', '6503'], $this->repo->findCcActorIds('inst-1'));
    }

    /** 同一次调用内重复给同一个人 ⇒ 也按幂等处理（只落一行）。 */
    public function testDuplicateWithinOneCallCollapses(): void
    {
        $created = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6601', '6601']);

        $this->assertSame(['6601'], $created, '同一次调用内的重复只算一次新建');
        $this->assertSame(1, $this->ccRowCount('inst-1'), '同一次调用内的重复只落一行');
    }

    /** 反向哨兵：判重作用域是 (实例, 人)，不是全局——不同实例上同一个人各自建行。 */
    public function testDedupIsScopedToInstanceNotGlobal(): void
    {
        $first = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6701']);
        $second = $this->repo->createCcInstanceIfAbsent('inst-2', 'zhangsan', ['6701']);

        $this->assertSame(['6701'], $first);
        $this->assertSame(['6701'], $second, '实例二不受实例一影响，同一个人照样建行');
        $this->assertSame(2, $this->ccRowCount(), '两条 cc 行');
    }

    /** 查询侧不去重（owner 拍「接受既成事实」）：历史重复行照旧逐行放出。 */
    public function testQuerySideDoesNotDedupLegacyRows(): void
    {
        $this->pdo->exec("INSERT INTO wf_process_instance (id, process_define_id, state, operator, variable, create_time)
            VALUES ('inst-1','def-1',10,'zhangsan','{}','2026-09-29 10:00:00')");
        $this->seedReadRow('inst-1', '6801', '0');
        // 再造一条历史重复行（G2 之前留下的既成事实）
        $this->pdo->exec("INSERT INTO wf_process_cc_instance
            (id, process_instance_id, actor_id, state, create_time, create_user, update_time, update_user)
            VALUES ('seed-dup','inst-1','6801',0,'2020-01-02 00:00:00','zhangsan','2020-01-02 00:00:00','zhangsan')");

        $this->assertCount(2, $this->rowsOf('inst-1', '6801'),
            '查询侧不加 DISTINCT：历史重复行必须照旧放出两行（G2 只保证今后不再新增）');

        $created = $this->repo->createCcInstanceIfAbsent('inst-1', 'zhangsan', ['6801']);
        $this->assertSame([], $created, '写侧判重也不清理脏行，只是不再新增');
        $this->assertCount(2, $this->rowsOf('inst-1', '6801'), '脏行原样保留（不清理）');
    }

    // ═══ 引擎/门面腿（SQL 后端）同一条判据：手动腿重复抄送不再 fire 码 4 ═══

    public function testFacadeManualCcLegUsesTheDedupRuleOnSqlBackend(): void
    {
        $this->pdo->exec("INSERT INTO wf_process_define (id, name, display_name, type, state, content, version)
            VALUES ('def-1','cc-dedup-141-sql','抄送判重流程','approval',1,'{}',1)");
        $facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        $listener = new PdoCcCreateRecorder();
        ProcessEventListenerRegistry::register($listener);

        $resp = $facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => 'inst-1', 'operator' => 'zhangsan', 'actorIds' => ['6901', '6902'],
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['6901', '6902'], $listener->actorIds(), '首轮逐人 fire 码 4');

        $resp2 = $facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => 'inst-1', 'operator' => 'zhangsan', 'actorIds' => ['6901', '6903'],
        ]);
        $this->assertSame(0, $resp2['code'], json_encode($resp2, JSON_UNESCAPED_UNICODE));

        $this->assertSame(['6901', '6902', '6903'], $listener->actorIds(),
            'SQL 后端的手动腿也须"拿子集去 fire"：已知人 6901 不得在第二轮再发码 4');
        $this->assertSame(3, $this->ccRowCount('inst-1'), 'cc 行只多一条（6901/6902/6903）');
    }
}

/** 只收 CC_CREATE 的监听器（SQL 后端那格用）。 */
class PdoCcCreateRecorder implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        if ($event->getType() === ProcessEventTypeEnum::CC_CREATE) {
            $this->events[] = $event;
        }
    }

    /** @return string[] 按 fire 顺序的 ccActorId */
    public function actorIds(): array
    {
        return array_map(fn(ProcessEvent $e) => (string) $e->getCcActorId(), $this->events);
    }
}
