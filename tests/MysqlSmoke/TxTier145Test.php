<?php

declare(strict_types=1);

namespace Jeeflow\Tests\MysqlSmoke;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Interceptor\FlowInterceptorRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Persist\DynamicTableWriter;
use Jeeflow\Persist\Interceptor\PersistPostInterceptor;
use Jeeflow\Persist\PdoDynamicTableWriter;
use Jeeflow\RepositoryPDO\PdoProcessExtRepository;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * audit8 R9 145-7（php 半）＋ C99 ⑥ php 腿（spec/12 §12.4 三档行为判据）· 真库档。
 *
 * 连接口径与 MysqlSmokeTest 同：独立库 `jeeflow_php_t1`（160 MySQL，JEFFLOW_DB_* 可覆盖），
 * 不写业务库。php 栈的覆盖面支点是**装配约定**（C99 N-4）：模板 `beginTransaction()` 的那条连接
 * 必须就是注入给仓储/扩展仓/writer 的**同一枚 \PDO`——本档用"同 PDO / 另开连接"两形状把这条
 * 约定钉成可执行判据。运行：
 *
 *     REQUIRE_MYSQL=1 php vendor/bin/phpunit --filter TxTier145Test
 *
 * 覆盖档位（六段）：
 *  ① 空转档——事务内写：同连接读得到 / 独立连接提交前读不到 / 回滚后不存在；
 *    另含 join 支格：嵌套 inTx 并入最外层（内层不提交、外层回滚连坐）
 *  ② 原子档——引擎入口（executeProcessTask 办理腿；真实发起作前置）：抄送漏斗注入失败
 *    ⇒ 任务行回到 DOING＋实例仍 DOING＋cc 零残留（动作内写整体回滚）
 *  ③ 动作档——门面复合动作（startAndExecute，入参带 f_ccActors＋tf_ccActors 两腿）：
 *    第二拍抄送漏斗注入失败 ⇒ 整次 dispatch 回滚（两拍的实例/任务行一并消失）；
 *    ⚠ 裸拼对照：**与 ③-a 同参**（failCc=2），仅不经门面、两次引擎入口各一拍 ⇒
 *    第一拍已提交、第二拍失败 ⇒ 半完成留存 = "门面不包"的修前形态（红样形态本身，不是缺陷）
 *  ④ 归属档——嵌套并入（外层模板事务包住整次门面调用，外层回滚连坐）＋回滚后下一拍照常提交
 *    （防 inTransaction 卡死 true ⇒ 永不 commit）；php 单进程串行，无 py/node 那种
 *    "并发切开"面——事务绑在连接对象上，同一时刻只有一条活动连接，不存在跨上下文互盖
 *  ⑤ ext 腿——主仓+扩展仓同 PDO 同事务：回滚双消失 / 提交双落库（扩展仓不是事务豁免面）
 *  ⑥ persist 腿（C99 ⑥）——SYNC 流 + 真 PersistPostInterceptor：
 *    (a) writer 与仓储同 PDO ⇒ 业务行随动作回滚（其余节点写已先行，注入点＝实例终结写）；
 *    (b) 对照：writer 建在另一条连接 ⇒ 行逃逸留存（§12.1 条 4 允许的降级运行态，
 *        正是"集成方必须同 PDO 接线"的理由）；
 *    (c) 正向：同 PDO 且动作成功 ⇒ 业务行必须真落库（补"写了"读数——防 (a) 的 n==0 被
 *        "写了又回滚"与"压根没写"两种根因同形骗过）
 *
 * 注入点＝FlakyRepo 两处（都位于"真中途"且错误真传播）：
 *  - createCcInstanceIfAbsent（②③）：任务完成写之后、persist/事件之前；
 *  - updateInstance（⑥，state != DOING 的终结写）：persist 已写业务行之后。
 *
 * 阳性对照（2026-10-12 逐处摘除，跑完即字节级还原、cmp 与副本全等，复跑 10/10 绿）：
 *  M1 摘 join 支（inTx 的 inTransaction 复用分支）⇒ 6 格红：②(内层未跑＋PDO 抛"已有活动事务")
 *    ＋③＋④＋⑥(a/b/c)——凡走门面/嵌套的路径全灭（它们同过 inTx，双开即抛）；
 *  M2 摘门面包裹（flow 直调 dispatch）⇒ 2 格红：③(半完成留存 1)＋⑥-a(业务行逃逸 n=1)；
 *  M3 摘五入口包裹（inTxBare 替五处 inTx）⇒ 1 格红：②(任务完成写留存)。
 *  ⑥ 的 writer 连线纪律由配对格承担对照：⑧(同 PDO⇒0) 与 ⑨(另开连接⇒逃逸 1) 互为反证，
 *  ⑩(同 PDO＋成功⇒1) 封"写了又回滚 vs 压根没写"的同形口。
 */
class TxTier145Test extends TestCase
{
    private const ISOLATED_DB = 'jeeflow_php_t1';
    private const DEFINE_SIMPLE = '900146';   // 简单流（flows/01-simple.json）
    private const DEFINE_SYNC = '900147';     // SYNC 入库流（内联，relTableName=biz_tx_probe）
    private const OPERATOR = 'phptier145';

    private static ?\PDO $pdo = null;
    private static ?\PDO $pdo2 = null;   // 独立连接（对照组：写入必须"逃逸"得掉才叫对照）
    private static string $skipReason = '';
    private static int $pkBase = 0;
    private static int $pkSeq = 0;

    private FlakyRepo $repo;
    private PdoProcessExtRepository $ext;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private PdoDynamicTableWriter $writer;
    private PdoDynamicTableWriter $writerEscape;
    /** @var TransactionTemplateInterface&object{inTransaction(): bool} */
    private object $template;

    public static function setUpBeforeClass(): void
    {
        if (getenv('SKIP_MYSQL') === '1') {
            self::$skipReason = 'SKIP_MYSQL=1';
            return;
        }

        $host = getenv('JEFFLOW_DB_HOST') ?: '192.168.1.160';
        $port = getenv('JEFFLOW_DB_PORT') ?: '3306';
        $user = getenv('JEFFLOW_DB_USER') ?: 'root';
        $pwd = getenv('JEFFLOW_DB_PWD');
        if ($pwd === false || $pwd === '') {
            $pwd = ($host === '127.0.0.1' || $host === 'localhost') ? '' : '8Eli#gr#AUk';
        }

        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pwd, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo->exec('DROP DATABASE IF EXISTS `' . self::ISOLATED_DB . '`');
            $pdo->exec('CREATE DATABASE `' . self::ISOLATED_DB . '` DEFAULT CHARSET utf8mb4');
            $pdo->exec('USE `' . self::ISOLATED_DB . '`');
            $pdo->exec(self::schemaSql());
            self::$pdo = $pdo;

            $pdo2 = new \PDO($dsn, $user, $pwd, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo2->exec('USE `' . self::ISOLATED_DB . '`');
            self::$pdo2 = $pdo2;

            self::$pkBase = (int) (microtime(true) * 1000) * 1000;
            self::insertDefines($pdo);
        } catch (\PDOException $e) {
            self::$skipReason = 'MySQL 不可用: ' . $e->getMessage();
            if (getenv('REQUIRE_MYSQL') === '1') {
                throw new \RuntimeException(self::$skipReason, 0, $e);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo === null) {
            return;
        }
        try {
            self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::ISOLATED_DB . '`');
        } catch (\PDOException) {
            // ignore
        }
        self::$pdo = null;
        self::$pdo2 = null;
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
        $this->assertNotNull(self::$pdo);
        foreach (['wf_process_task_actor', 'wf_process_task', 'wf_process_instance',
                     'wf_process_cc_instance', 'wf_process_surrogate', 'biz_tx_probe'] as $t) {
            self::$pdo->exec("DELETE FROM `{$t}`");
        }

        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ModelParser::reset();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());

        // 事务模板＝demo 同款形状（同一枚 PDO＋inTransaction 探测口）
        $this->template = new class(self::$pdo) implements TransactionTemplateInterface {
            public function __construct(private \PDO $pdo)
            {
            }

            public function required(callable $action): mixed
            {
                $this->pdo->beginTransaction();
                try {
                    $result = $action();
                    $this->pdo->commit();
                    return $result;
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }

            public function inTransaction(): bool
            {
                return $this->pdo->inTransaction();
            }
        };
        ServiceContext::put(TransactionTemplateInterface::class, $this->template);

        $gen = static function (): int|string {
            return self::$pkBase + (++self::$pkSeq);
        };
        $idGen = new class($gen) implements IdGeneratorInterface {
            /** @var callable(): int|string */
            private $gen;

            public function __construct(callable $gen)
            {
                $this->gen = $gen;
            }

            public function nextId(): int|string
            {
                return ($this->gen)();
            }
        };

        $this->repo = new FlakyRepo(self::$pdo, $idGen);
        $this->ext = new PdoProcessExtRepository(self::$pdo, $idGen);
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->ext);

        $this->writer = new PdoDynamicTableWriter(self::$pdo, 'mysql');
        $this->writer->primaryKeyGenerator = $gen;
        $this->writerEscape = new PdoDynamicTableWriter(self::$pdo2, 'mysql');
        $this->writerEscape->primaryKeyGenerator = $gen;
        ServiceContext::put(DynamicTableWriter::class, $this->writer);
        FlowInterceptorRegistry::register(
            PersistPostInterceptor::JAVA_CLASS,
            new PersistPostInterceptor()
        );
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        FlowInterceptorRegistry::clear();
        ModelParser::reset();
    }

    // ─── ① 空转档 ───────────────────────────────────────────────────────────

    public function test01IdleSameConnectionVisibilityAndRollback(): void
    {
        $tag = 'phptier-idle';
        $thrown = null;
        try {
            $this->template->required(function () use ($tag) {
                $this->writer->insert('biz_tx_probe', ['id' => $this->nextPk(), 'tag' => $tag]);
                $this->assertSame(1, $this->countTag($tag),
                    '同事务连接提交前必须读得到（writer 与仓储同 PDO）');
                $this->assertSame(0, $this->countTag($tag, self::$pdo2),
                    '独立连接提交前必须读不到');
                throw new \RuntimeException('rollback on purpose');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, '回调报错必须外抛');
        $this->assertSame(0, $this->countTag($tag), '回滚后行必须不存在');
    }

    public function test02NestedInTxJoinsOutermost(): void
    {
        $tag = 'phptier-join';
        $innerRan = false;
        $thrown = null;
        try {
            $this->engine->inTx(function () use ($tag, &$innerRan) {
                $r = $this->engine->inTx(function () use ($tag, &$innerRan) {
                    $innerRan = true;
                    $this->writer->insert('biz_tx_probe', ['id' => $this->nextPk(), 'tag' => $tag]);
                    $this->assertSame(0, $this->countTag($tag, self::$pdo2),
                        '内层返回时独立连接仍读不到（并入最外层，未提前提交）');
                    return 'inner-ok';
                });
                $this->assertSame('inner-ok', $r, '嵌套 inTx 正常返回');
                throw new \RuntimeException('outer rollback');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown,
            '外层报错必须外抛（join 支：不二次 begin，PDO 不抛"已有活动事务"）');
        $this->assertTrue($innerRan, '内层必须真跑（join 支生效）');
        $this->assertSame(0, $this->countTag($tag), '外层回滚必须连坐嵌套写');
    }

    // ─── ② 原子档（引擎入口）───────────────────────────────────────────────

    public function test03EngineEntryAtomicOnMidActionFailure(): void
    {
        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR, FlowData::create());
        $iid = (string) $inst->getInstanceId();
        $doing = $this->repo->findDoingTasks($iid);
        $this->assertCount(1, $doing, '前置：发起后应有 apply 待办');
        $taskId = (string) $doing[0]->getTaskId();

        $this->repo->resetInject(failCc: 1);
        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $args->set(FlowConst::CC_ACTORS, 'phptier-wangwu');
        $thrown = null;
        try {
            $this->engine->executeProcessTask($taskId, self::OPERATOR, $args);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, '中途注入失败后必须报错（注入点命中）');

        $this->assertSame(ProcessTaskState::DOING,
            (int) $this->scalar('SELECT task_state FROM wf_process_task WHERE id = ?', [$taskId]),
            '动作失败后任务行必须回到 DOING(10)');
        $this->assertSame(ProcessInstanceState::DOING,
            (int) $this->scalar('SELECT state FROM wf_process_instance WHERE id = ?', [$iid]),
            '动作失败后实例仍 DOING');
        $this->assertSame(0, $this->countCcForInstance($iid), 'cc 零残留');
    }

    // ─── ③ 动作档（门面复合）＋ 裸拼对照 ────────────────────────────────────

    public function test04FacadeCompositeRollsBackWholeDispatch(): void
    {
        $this->repo->resetInject(failCc: 2);
        $r = $this->facade->flow('processDefine/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SIMPLE,
            'operator' => self::OPERATOR,
            FlowConst::CC_ACTORS_START => 'phptier-wangwu',
            FlowConst::CC_ACTORS => 'phptier-lisi',
        ]);
        $this->assertNotSame(0, $r['code'],
            '中途失败的动作不得报成功: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $this->repo->ccCalls,
            '两张抄送腿都必须真走到（注入点＝第 2 次 cc 调用）');
        $this->assertActionResidueZero(self::DEFINE_SIMPLE, '动作档（门面整次 dispatch 包裹）');
    }

    public function test05BareTwoLegsPartialPersistenceShape(): void
    {
        $this->repo->resetInject(failCc: 2);
        $flowArgs = FlowData::create();
        $flowArgs->set(FlowConst::CC_ACTORS_START, 'phptier-wangwu');
        $flowArgs->set(FlowConst::CC_ACTORS, 'phptier-lisi');
        $inst = $this->engine->startProcessInstanceById(self::DEFINE_SIMPLE, self::OPERATOR, $flowArgs);
        $doing = $this->repo->findDoingTasks($inst->getInstanceId());

        $thrown = null;
        try {
            foreach ($doing as $task) {
                $this->repo->addTaskActor($task->getTaskId(), [self::OPERATOR]);
                $flowArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
                $this->engine->executeProcessTask($task->getTaskId(), self::OPERATOR, $flowArgs);
            }
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, '第二拍必须失败（注入点）');
        $this->assertSame(1, $this->countInstances(self::DEFINE_SIMPLE),
            '裸拼对照：第一拍已提交 ⇒ 实例行必须留存 1（两拍各一事务的边界证据）');
    }

    // ─── ④ 归属档（嵌套并入）───────────────────────────────────────────────

    public function test06NestedFacadeInsideOuterTemplateTx(): void
    {
        $thrown = null;
        try {
            $this->template->required(function () {
                $r = $this->facade->flow('processDefine/startAndExecute', [
                    FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SIMPLE,
                    'operator' => self::OPERATOR,
                ]);
                $this->assertSame(0, $r['code'],
                    '嵌套于外层事务内的一次门面动作必须成功（join 支：不二次 begin）: '
                    . json_encode($r, JSON_UNESCAPED_UNICODE));
                $this->assertSame(1, $this->countInstances(self::DEFINE_SIMPLE),
                    '外层事务内（同连接）应读到引擎写的实例行');
                $this->assertSame(0, $this->countInstances(self::DEFINE_SIMPLE, self::$pdo2),
                    '外层事务内独立连接读不到（未提前提交）');
                throw new \RuntimeException('outer rollback');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, '外层报错必须外抛');
        $this->assertSame(0, $this->countInstances(self::DEFINE_SIMPLE),
            '外层回滚必须连坐嵌套动作（实例行实得 0）');

        // 回滚后模板状态必须复原：下一拍照常提交（防 inTransaction 卡死 true ⇒ 永不 commit）
        $r2 = $this->facade->flow('processDefine/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SIMPLE,
            'operator' => self::OPERATOR,
        ]);
        $this->assertSame(0, $r2['code'], json_encode($r2, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->countInstances(self::DEFINE_SIMPLE, self::$pdo2),
            '回滚后的下一拍必须真提交（独立连接可见）');
    }

    // ─── ⑤ ext 腿（主仓+扩展仓同事务）──────────────────────────────────────

    public function test07ExtRepositoryJoinsSameTransaction(): void
    {
        $fakeInst = 'phptier-ext-1';
        $thrown = null;
        try {
            $this->template->required(function () use ($fakeInst) {
                $this->repo->createCcInstance($fakeInst, self::OPERATOR, ['phptier-cc-r']);
                $this->ext->saveSurrogate([
                    'id' => $this->nextPk(), 'operator' => self::OPERATOR, 'surrogate' => 'phptier-lisi',
                    'processName' => 'phptier-ext-rollback', 'startTime' => null, 'endTime' => null, 'enabled' => 1,
                ]);
                $this->assertSame(1, $this->countCcForInstance($fakeInst),
                    '事务内主仓 cc 行应可见');
                $this->assertSame(1, $this->countSurrogates('phptier-ext-rollback'),
                    '事务内扩展仓委托行应可见');
                $this->assertSame(0, $this->countCcForInstance($fakeInst, self::$pdo2),
                    '独立连接读不到主仓未提交行');
                $this->assertSame(0, $this->countSurrogates('phptier-ext-rollback', self::$pdo2),
                    '独立连接读不到扩展仓未提交行');
                throw new \RuntimeException('boom');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, '回调报错必须外抛');
        $this->assertSame(0, $this->countCcForInstance($fakeInst), '回滚后主仓 cc 行必须不在');
        $this->assertSame(0, $this->countSurrogates('phptier-ext-rollback'),
            '回滚后扩展仓委托行必须不在——扩展仓不是事务豁免面');

        $this->template->required(function () use ($fakeInst) {
            $this->repo->createCcInstance($fakeInst . '-c', self::OPERATOR, ['phptier-cc-c']);
            $this->ext->saveSurrogate([
                'id' => $this->nextPk(), 'operator' => self::OPERATOR, 'surrogate' => 'phptier-lisi',
                'processName' => 'phptier-ext-commit', 'startTime' => null, 'endTime' => null, 'enabled' => 1,
            ]);
        });
        $this->assertSame(1, $this->countCcForInstance($fakeInst . '-c', self::$pdo2),
            '提交后主仓 cc 行应在（独立连接可见）');
        $this->assertSame(1, $this->countSurrogates('phptier-ext-commit', self::$pdo2),
            '提交后扩展仓委托行应在');
    }

    // ─── ⑥ persist 腿（C99 ⑥）─────────────────────────────────────────────

    public function test08PersistSamePdoJoinsAndRollsBackWithAction(): void
    {
        $this->repo->resetInject(failFinish: true);
        $r = $this->facade->flow('processDefine/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SYNC,
            'operator' => self::OPERATOR,
            'f_tag' => 'phptier-persist-join',
        ]);
        $this->assertNotSame(0, $r['code'],
            '实例终结写注入 ⇒ 动作应报失败: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThanOrEqual(1, $this->repo->finHits, '实例终结写注入确已命中');
        $this->assertSame(0, $this->countTag('phptier-persist-join'),
            '并入事务的业务行必须随动作回滚（实得须 0）');
    }

    public function test09PersistSecondConnectionEscapesRollback(): void
    {
        ServiceContext::put(DynamicTableWriter::class, $this->writerEscape);
        $this->repo->resetInject(failFinish: true);
        $r = $this->facade->flow('processDefine/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SYNC,
            'operator' => self::OPERATOR,
            'f_tag' => 'phptier-persist-escape',
        ]);
        $this->assertNotSame(0, $r['code'], '对照档：动作同样报失败');
        $this->assertSame(1, $this->countTag('phptier-persist-escape'),
            'writer 另开连接时行应逃逸留存 1（§12.1 条 4 降级运行态红样——同 PDO 接线的理由）');
    }

    public function test10PersistSamePdoSuccessLandsRow(): void
    {
        $this->repo->resetInject();
        $r = $this->facade->flow('processDefine/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => self::DEFINE_SYNC,
            'operator' => self::OPERATOR,
            'f_tag' => 'phptier-persist-ok',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $iid = (string) $r['data'][FlowConst::PROCESS_INSTANCE_ID_KEY];
        $this->assertSame(1, $this->countTagForInstance('phptier-persist-ok', $iid),
            '同 PDO 且动作成功时业务行必须真落库（⑥-a 的 n==0 不得是"压根没写"）');
        $this->assertSame(ProcessInstanceState::FINISHED,
            (int) $this->scalar('SELECT state FROM wf_process_instance WHERE id = ?', [$iid], self::$pdo2),
            '短流应办结（独立连接核终态）');
    }

    // ─── 夹具 ──────────────────────────────────────────────────────────────

    private function nextPk(): int
    {
        return self::$pkBase + (++self::$pkSeq);
    }

    private function scalar(string $sql, array $args = [], ?\PDO $pdo = null): mixed
    {
        $stmt = ($pdo ?? self::$pdo)->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn();
    }

    private function countTag(string $tag, ?\PDO $pdo = null): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM biz_tx_probe WHERE tag = ?', [$tag], $pdo);
    }

    private function countTagForInstance(string $tag, string $iid, ?\PDO $pdo = null): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM biz_tx_probe WHERE tag = ? AND process_instance_id = ?',
            [$tag, $iid], $pdo);
    }

    private function countInstances(string $defineId, ?\PDO $pdo = null): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM wf_process_instance WHERE process_define_id = ?', [$defineId], $pdo);
    }

    private function countCcForInstance(string $iid, ?\PDO $pdo = null): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM wf_process_cc_instance WHERE process_instance_id = ?', [$iid], $pdo);
    }

    private function countSurrogates(string $processName, ?\PDO $pdo = null): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM wf_process_surrogate WHERE operator = ? AND process_name = ?',
            [self::OPERATOR, $processName], $pdo);
    }

    /** 动作失败后：实例/任务/参与者/cc 四表必须全 0（独立连接反查） */
    private function assertActionResidueZero(string $defineId, string $why): void
    {
        $checks = [
            '实例' => ['SELECT COUNT(*) FROM wf_process_instance WHERE process_define_id = ?', [$defineId]],
            '任务' => ['SELECT COUNT(*) FROM wf_process_task WHERE process_instance_id IN'
                       . ' (SELECT id FROM wf_process_instance WHERE process_define_id = ?)', [$defineId]],
            '参与者' => ['SELECT COUNT(*) FROM wf_process_task_actor WHERE process_task_id IN'
                         . ' (SELECT id FROM wf_process_task WHERE process_instance_id IN'
                         . ' (SELECT id FROM wf_process_instance WHERE process_define_id = ?))', [$defineId]],
            'cc行' => ['SELECT COUNT(*) FROM wf_process_cc_instance WHERE process_instance_id IN'
                       . ' (SELECT id FROM wf_process_instance WHERE process_define_id = ?)', [$defineId]],
        ];
        foreach ($checks as $name => [$sql, $args]) {
            $n = (int) $this->scalar($sql, $args, self::$pdo2);
            $this->assertSame(0, $n, "{$why}：{$name} 行必须随动作整体回滚（n={$n}）");
        }
    }

    private static function insertDefines(\PDO $pdo): void
    {
        $simple = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        if ($simple === false) {
            throw new \RuntimeException('flows/01-simple.json 读不到：' . jeeflow_flows_dir());
        }
        $sync = <<<'JSON'
{
  "name": "php_tier_sync",
  "displayName": "R9 事务档 SYNC 流",
  "type": "approval",
  "persistMode": "SYNC",
  "relTableName": "biz_tx_probe",
  "postInterceptors": "com.mldong.jeeflow.persist.interceptor.PersistPostInterceptor",
  "nodes": [
    {"id":"start","type":"snaker:start","x":0,"y":0,"properties":{},"text":{"value":"开始"}},
    {"id":"apply","type":"snaker:task","x":0,"y":0,"properties":{"assignee":"applicant"},"text":{"value":"申请"}},
    {"id":"end","type":"snaker:end","x":0,"y":0,"properties":{},"text":{"value":"结束"}}
  ],
  "edges": [
    {"id":"e0","sourceNodeId":"start","targetNodeId":"apply","properties":{}},
    {"id":"e1","sourceNodeId":"apply","targetNodeId":"end","properties":{}}
  ]
}
JSON;
        $stmt = $pdo->prepare('DELETE FROM wf_process_define WHERE id IN (?, ?)');
        $stmt->execute([self::DEFINE_SIMPLE, self::DEFINE_SYNC]);
        $ins = $pdo->prepare('INSERT INTO wf_process_define (id, name, display_name, type, state,'
            . ' content, version, create_time, create_user, update_time, update_user)'
            . ' VALUES (?,?,?,?,1,?,1, NOW(3), ?, NOW(3), ?)');
        $ins->execute([self::DEFINE_SIMPLE, 'php_tier_simple', 'R9 事务档简单流', 'approval',
            $simple, 'phptier145', 'phptier145']);
        $ins->execute([self::DEFINE_SYNC, 'php_tier_sync', 'R9 事务档 SYNC 流', 'approval',
            $sync, 'phptier145', 'phptier145']);
    }

    private static function schemaSql(): string
    {
        return <<<'SQL'
CREATE TABLE wf_process_define (
  id BIGINT NOT NULL,
  name VARCHAR(64) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  type VARCHAR(32) NULL,
  state INT NULL,
  content TEXT NULL,
  version INT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_instance (
  id BIGINT NOT NULL,
  parent_id VARCHAR(64) NULL,
  process_define_id BIGINT NULL,
  state INT NULL,
  parent_node_name VARCHAR(100) NULL,
  business_no VARCHAR(64) NULL,
  operator VARCHAR(64) NULL,
  expire_time DATETIME(3) NULL,
  variable TEXT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_task (
  id BIGINT NOT NULL,
  process_instance_id BIGINT NOT NULL,
  task_name VARCHAR(100) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  task_type INT NULL,
  perform_type INT NULL,
  task_state INT NULL,
  operator VARCHAR(64) NULL,
  finish_time DATETIME(3) NULL,
  expire_time DATETIME(3) NULL,
  form_key VARCHAR(100) NULL,
  task_parent_id VARCHAR(64) NULL,
  variable TEXT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_task_actor (
  id VARCHAR(64) NOT NULL,
  process_task_id BIGINT NOT NULL,
  actor_id VARCHAR(64) NOT NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_cc_instance (
  id VARCHAR(64) NOT NULL,
  process_instance_id VARCHAR(64) NOT NULL,
  actor_id VARCHAR(64) NOT NULL,
  state INT NULL DEFAULT 0,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wf_process_surrogate (
  id BIGINT NOT NULL,
  process_name VARCHAR(100) NULL,
  operator VARCHAR(64) NOT NULL,
  surrogate VARCHAR(64) NOT NULL,
  start_time DATETIME(3) NULL,
  end_time DATETIME(3) NULL,
  enabled INT NULL DEFAULT 1,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE biz_tx_probe (
  id BIGINT NOT NULL,
  tag VARCHAR(64) NULL,
  process_instance_id VARCHAR(64) NULL,
  create_time DATETIME(3) NULL,
  create_user VARCHAR(64) NULL,
  update_time DATETIME(3) NULL,
  update_user VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }
}

/**
 * 注入失败（策略：第 N 次 cc 调用报错 / 实例终结写报错）——判据是"失败了就必须整体不存在"。
 *
 * 嵌入真仓储：其余方法透传。两个注入点都位于"真中途"：cc 在任务完成写之后、persist/事件之前；
 * 终结写在 persist 已写业务行之后。错误以 RuntimeException 真传播（引擎 inTx 的 required
 * 捕获后 rollBack 再外抛）。
 */
final class FlakyRepo extends PdoProcessRepository
{
    public int $failCc = 0;
    public int $ccCalls = 0;
    public bool $failFinish = false;
    public int $finHits = 0;

    public function resetInject(int $failCc = 0, bool $failFinish = false): void
    {
        $this->failCc = $failCc;
        $this->ccCalls = 0;
        $this->failFinish = $failFinish;
        $this->finHits = 0;
    }

    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array
    {
        $this->ccCalls++;
        if ($this->failCc !== 0 && $this->ccCalls === $this->failCc) {
            throw new \RuntimeException('injected: cc write failed mid-action');
        }
        return parent::createCcInstanceIfAbsent($instanceId, $operator, $actorIds);
    }

    public function updateInstance(object $instance): void
    {
        if ($this->failFinish && method_exists($instance, 'getState')
            && (int) $instance->getState() !== ProcessInstanceState::DOING) {
            $this->finHits++;
            throw new \RuntimeException('injected: instance finalize failed mid-action');
        }
        parent::updateInstance($instance);
    }
}
