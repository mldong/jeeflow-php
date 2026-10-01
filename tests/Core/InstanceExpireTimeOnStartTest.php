<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Util\FlowUtil;
use PHPUnit\Framework\TestCase;

/**
 * issues/137 A · 批二 §3-4 php 腿 —— **实例级** `expire_time` 必须是「定义级表达式的求值结果」。
 *
 * 裁定口径 A（owner 2026-09-28：「实例的处理方式和任务的差不多吧，算法一致」），基准＝java
 * `JeeflowEngineImpl.java:93-96`（先判非空再写）＝ boot2 内置版 `ProcessInstanceServiceImpl.java:157-160`：
 * 发起时取流程定义**顶层** `model.getExpireTime()`，非空才
 * `instance.setExpireTime(FlowUtil.processTime(expireTime, args))`。
 *
 * 本栈原形状（`JeeflowEngine.php:122-125`）：
 *   $expireTime = $model->getExpireTime();
 *   if ($expireTime !== '') { $instance->setExpireTime($expireTime); // 简化：不处理变量替换 }
 * ⇒ **搬运原串**、注释自承没求值。这一列在库上是 `DATETIME`：把 `"2h"` 塞进 datetime 列，
 * 在 160 那台 MySQL（实测 `@@sql_mode` 含 `STRICT_TRANS_TABLES`）是硬错 1292/1366
 * ——真库腿见 `MysqlSmokeTest::testM8InstanceExpireTimeLandsAsEvaluatedMoment`（正向）与
 * `testM9RawRelativeStringIsRejectedByStrictDatetimeColumn`（病灶探针），
 * 内存里"绿"的这列在真库上直接把发起打崩，比留 NULL 更糟（issues/137 §状态行）。
 *
 * 五条判据（逐条打在**值**上，不打"非空"空判）：
 *  1. 写进去的是**求值结果**（时刻），不是表达式原串；
 *  2. 求值的 args＝**发起参数**（已注入用户信息与 autoGenTitle 的那份）——与节点级表达式用的
 *     实例变量是两码事，故同时钉「定义级取定义级、节点级取节点级」不串行；
 *  3. 定义**没配**顶层 expireTime（缺键／空串／纯空白）⇒ 该列保持 NULL，不赋 now()、不赋空串；
 *  4. 配了但**算不出**（误配／负数档）⇒ NULL（沿用 §3-2/§3-3 已定的落穿语义），同样不兜底 now；
 *  5. 求值器复用本栈既有那一枚 {@see FlowUtil::processTime}，**不新造第二把尺子**。
 */
class InstanceExpireTimeOnStartTest extends TestCase
{
    /** 相对档 "2h" 的秒数 */
    private const TWO_HOURS = 7200;

    /** 定义 id 计数器（见 {@see self::start()} 的说明） */
    private static int $defineSeq = 0;

    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ═══ 判据 1：写进去的是求值结果，不是原串 ═══

    /**
     * 正向：定义顶层配 `"2h"` ⇒ 实例那一列是**时刻**（同行 `expire − create ≈ 2h`），
     * 而且**逐字不等于** `"2h"`。
     *
     * 三条判据各挡一种写法：
     *  - 格式判据挡"原串搬运"（本栈病灶，`"2h"` 直接进列）；
     *  - 差值判据挡 `now()` 占位（差值≈0，正是 issues/126 那族病灶的形状）；
     *  - `assertNotSame('2h', …)` 是病灶的逐字对照，摘掉求值当场红。
     */
    public function testRelativeExpressionIsEvaluatedToAMomentNotCarriedRaw(): void
    {
        $instance = $this->start($this->flowWithExpire('2h'), FlowData::create(), 'user1');

        $expire = $instance->getExpireTime();
        $this->assertNotNull($expire, '定义配了 2h ⇒ 实例 expire_time 必须有值');
        $this->assertNotSame('2h', $expire,
            '实例列要的是**求值结果**（时刻），不是表达式原串；原串进 datetime 列在 STRICT_TRANS_TABLES 是 1366');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $expire,
            '实例 expire_time 必须是本栈的 "Y-m-d H:i:s" 时刻串，实得: ' . var_export($expire, true));
        $this->assertExpireAbout2HAfterCreate($expire, $instance->getCreateTime(), '定义级 2h');
    }

    /**
     * 判据 1 的另一半：**绝对档**原串本身是合法时刻时，搬运与求值读数相同 ⇒ 上面那格才是有牙的。
     * 这一格钉住"改成求值之后绝对档没退化"（回归档），摘掉求值它照样绿——所以它不能单独作判据，
     * 必须和上一格成对读。
     */
    public function testAbsoluteExpressionStillEvaluatesToItself(): void
    {
        $instance = $this->start($this->flowWithExpire('2026-12-31 10:00:00'), FlowData::create(), 'user1');
        $this->assertSame('2026-12-31 10:00:00', $instance->getExpireTime(),
            '绝对档求值结果＝它本身（第 3 档不变，回归判据）');
    }

    // ═══ 判据 2：求值的 args＝发起参数（注入后那份），且与节点级不串行 ═══

    /**
     * 表达式是**发起参数里的变量名** ⇒ 取该变量的值。
     *
     * 这一格钉"args 传对了"：写成 `processTime($expr)`（不带 args）或传空 FlowData，
     * 变量档取不到键 ⇒ 落穿绝对档 ⇒ NULL，当场红。
     */
    public function testExpressionResolvesAgainstTheStartArgs(): void
    {
        $args = FlowData::of(['dueAt' => '2026-12-31 10:00:00']);
        $instance = $this->start($this->flowWithExpire('dueAt'), $args, 'user1');

        $this->assertSame('2026-12-31 10:00:00', $instance->getExpireTime(),
            '变量档的变量源必须是**发起参数**（丢掉 args 就取不到 dueAt ⇒ NULL）');
    }

    /**
     * 表达式是**引擎注入**的那批键之一（`u_*` 由 {@see FlowUtil::addUserInfoToArgs} 写入）⇒ 同样取到值。
     *
     * 判据形状：调用方入参里**没有** `u_userId`（下面显式断一次），只有引擎注入之后才有 ⇒
     * "求值用的是注入前那份原始入参"这种实现会在这里落穿成 NULL。
     *
     * ⚠️ UserProvider 故意把 `userId` 返成一个**合法时刻串**：这是分辨探针，不是业务形状——
     * 变量档只对能解析成时刻的串给非 NULL 读数，用 'user1' 这种普通 id 的话"注入前/注入后"两档
     * 都是 NULL，这一格就恒真了。
     */
    public function testExpressionResolvesAgainstUserEnrichedArgs(): void
    {
        ServiceContext::put(UserProviderInterface::class, new class () implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                return ['userId' => '2026-12-31 10:00:00', 'realName' => '张三', 'deptId' => '10',
                        'deptName' => '研发部', 'postId' => '20', 'postName' => '工程师'];
            }
        });
        $args = FlowData::of(['f_days' => 3]);
        $this->assertArrayNotHasKey(FlowConst::USER_USER_ID, $args->toArray(),
            '前置条件：调用方入参里没有 u_userId（这个键只能由引擎注入进来）');

        $instance = $this->start($this->flowWithExpire(FlowConst::USER_USER_ID), $args, 'user1');

        $this->assertSame('2026-12-31 10:00:00', $instance->getExpireTime(),
            '求值用的 args 必须是**注入用户信息之后**的那份（u_userId 只在注入后存在）');
    }

    /**
     * 定义级与节点级**各配各的**（定义 `3h`／节点 `2h`）⇒ 实例列按定义级算、任务行按节点级算。
     *
     * 这是"两码事"的判点：若实现把定义级表达式拿去走节点写点（或反过来），两列的差值会同为 2h 或
     * 同为 3h，两格各咬一侧。单节点单表达式的夹具挡不住这一类，故两份表达式刻意不同。
     */
    public function testDefinitionLevelExpressionIsNotTheNodeLevelOne(): void
    {
        $content = $this->flowWithExpire('3h', '2h');
        $instance = $this->start($content, FlowData::create(), 'user1');
        $task = $this->repo->findDoingTasks((string) $instance->getInstanceId(), null)[0] ?? null;

        $this->assertNotNull($task, '前置条件：发起要建出首任务节点的行');
        $this->assertExpireAboutAfterCreate($instance->getExpireTime(), $instance->getCreateTime(),
            3 * 3600, 60, '实例列（定义级 3h）');
        $this->assertExpireAboutAfterCreate($task->getExpireTime(), $task->getCreateTime(),
            2 * 3600, 60, '任务行（节点级 2h）');
        $this->assertNotSame((string) $instance->getExpireTime(), (string) $task->getExpireTime(),
            '两列各按自己的表达式算 ⇒ 不该是同一个时刻');
    }

    // ═══ 判据 3：没配 ⇒ 该列保持 NULL（不赋 now、不赋空串）═══

    /**
     * 三档"没配"：JSON 里**根本没这个键** ／ 值为空串 ／ 值为纯空白。
     *
     * 判据打在值上而不是"非空"：这一列必须是 `null`，不是 `''`、不是时刻。
     * 每档都先断"实例确实建出来了"（createTime 有值），否则"根本没建行"会让断言恒真。
     */
    public function testUnconfiguredDefinitionKeepsInstanceColumnNull(): void
    {
        // ① 缺键
        $noKey = $this->start($this->flowWithoutExpireKey(), FlowData::create(), 'user1');
        $this->assertNotNull($noKey->getCreateTime(), '对照：缺键那条实例仍应建出来');
        $this->assertNull($noKey->getExpireTime(), '定义没配顶层 expireTime ⇒ 该列保持 NULL');

        // ② 空串
        $empty = $this->start($this->flowWithExpire(''), FlowData::create(), 'user1');
        $this->assertNotNull($empty->getCreateTime(), '对照：空串那条例仍应建出来');
        $this->assertNull($empty->getExpireTime(), '空串同样算没配 ⇒ NULL（不许赋空串、不许赋 now）');

        // ③ 纯空白
        $blank = $this->start($this->flowWithExpire('   '), FlowData::create(), 'user1');
        $this->assertNotNull($blank->getCreateTime(), '对照：纯空白那条例仍应建出来');
        $this->assertNull($blank->getExpireTime(),
            '去空白后为空同样算没配 ⇒ NULL；这里若出现时刻，就是拿 now() 兜了底');
    }

    // ═══ 判据 4：配了但算不出 ⇒ NULL（落穿语义，不兜底 now）═══

    /**
     * 误配三串：认不出的绝对串（`not-a-time`）／相对档前缀非整数（`xh`）／负数相对档（`-5h`，
     * issues/137 D 判非负）。四档 `d` 的负数同样落穿。
     *
     * 判据是 NULL，**不是异常**（误配不该打断发起）、**不是当前时间**（那等于静默造一个"发起即逾期"）、
     * 也**不是回拨后的那个时刻**——都是 issues/137 已定过的口径，本格只钉实例这一列同池。
     */
    public function testMisconfiguredExpressionKeepsInstanceColumnNull(): void
    {
        foreach (['not-a-time', 'xh', '-5h', '-5d', '2026-13-45 99:99:99'] as $expr) {
            $instance = $this->start($this->flowWithExpire($expr), FlowData::create(), 'user1');
            $this->assertNotNull($instance->getCreateTime(), "对照：\"{$expr}\" 那条实例仍应建出来");
            $this->assertNull($instance->getExpireTime(),
                "\"{$expr}\" 算不出 ⇒ 实例 expire_time 必须 NULL，不许兜底 now()、不许写原串");
        }
    }

    // ═══ 判据 5：复用本栈既有求值器，不新造第二把尺子 ═══

    /**
     * 实例列的读数必须与 {@see FlowUtil::processTime} 对同一表达式、同一份 args 的读数**逐字相同**。
     *
     * 为什么这一格有牙：本栈已经有 issues/126 那一枚三档求值器（变量档 → 相对档 → 绝对档，
     * 相对档判非负＋裁前缀空白）。实现若"顺手"在引擎里另写一份（例如硬编码 `time() + 7200`、
     * 或只认 `s/m/h/d` 不做变量档），下面**每一档**都可能分叉——尤其变量档与误配档。
     * 这里刻意用**确定性档位**（变量档／绝对档／误配档）做逐字比对，相对档只比带宽：
     * 两次 `time()` 取值可以差一秒，那是取时噪声不是尺子噪声。
     */
    public function testInstanceUsesTheSameEvaluatorAsEveryOtherWritePoint(): void
    {
        $args = FlowData::of(['dueAt' => '2026-12-31 10:00:00']);

        // 确定性档位：求值器返回定值 ⇒ 逐字比
        foreach (['dueAt', '2026-12-31 10:00:00', 'not-a-time', 'xh', '-5h', '2h ', ''] as $expr) {
            $expected = FlowUtil::processTime($expr, $args->copy());
            $instance = $this->start($this->flowWithExpire($expr), $args->copy(), 'user1');
            $this->assertSame($expected, $instance->getExpireTime(),
                "表达式 \"{$expr}\" 的实例列读数必须与 FlowUtil::processTime 一致（同一把尺子）");
        }

        // 相对档：只比带宽，避开两次取时的秒级噪声
        $relative = $this->start($this->flowWithExpire('2h'), FlowData::create(), 'user1');
        $this->assertExpireAbout2HAfterCreate($relative->getExpireTime(), $relative->getCreateTime(),
            '相对档与 processTime 同尺');
    }

    // ═══ 夹具与断言 helper ═══

    /**
     * 注册定义并发起，返回**仓储里那条实例**（读的是持久化路径上的聚合根，不是本地临时对象）。
     *
     * 定义 id 用自增计数而不是内容哈希：同一份内容在本格里要发起多次（判据 5 的循环、判据 3/4 的
     * 多档），内容哈希会让"同内容的两次发起"复用同一条定义，读起来像在测两条定义。
     *
     * @param array<string,mixed> $content
     */
    private function start(array $content, FlowData $args, string $operator): ProcessInstance
    {
        $defineId = '137-' . (++self::$defineSeq);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => 'instance_expire_t0', 'displayName' => '实例级到期时间夹具',
            'type' => 'approval', 'state' => 1, 'version' => 1,
            'content' => (string) json_encode($content),
        ]);
        $started = $this->engine->startProcessInstanceById($defineId, $operator, $args);
        $row = $this->repo->findInstanceById((string) $started->getInstanceId());
        $this->assertInstanceOf(ProcessInstance::class, $row, '发起的实例应能从仓储读回');
        return $row;
    }

    /**
     * 两节点流程：顶层 expireTime ＋ 首任务节点 expireTime（默认不配）。
     *
     * @return array<string,mixed>
     */
    private function flowWithExpire(string $rootExpire, ?string $nodeExpire = null): array
    {
        $flow = $this->flowWithoutExpireKey();
        $flow['expireTime'] = $rootExpire;
        if ($nodeExpire !== null) {
            $flow['nodes'][1]['properties']['expireTime'] = $nodeExpire;
        }
        return $flow;
    }

    /** 同一份流程，但顶层**根本没有** expireTime 这个键（判据 3 的"缺键"档）
     *
     * @return array<string,mixed>
     */
    private function flowWithoutExpireKey(): array
    {
        return [
            'name' => 'instance_expire_t0',
            'displayName' => '实例级到期时间夹具',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'properties' => new \stdClass(),
                 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task',
                 'properties' => ['form' => 'apply-form', 'assignee' => 'applicant',
                                  'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'end', 'type' => 'snaker:end', 'properties' => new \stdClass(),
                 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => new \stdClass()],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'end', 'properties' => new \stdClass()],
            ],
        ];
    }

    /** 同行内 `expire − create ≈ 2h`（带宽 [2h−5s, 2h+60s]，与 ExpireTimeOnCreateTest 同尺） */
    private function assertExpireAbout2HAfterCreate(?string $expire, ?string $create, string $who): void
    {
        $this->assertNotNull($expire, "{$who} 必须带到期时间");
        $this->assertNotNull($create, "{$who} 的 createTime 应有值（内部对照）");
        $delta = strtotime((string) $expire) - strtotime((string) $create);
        $this->assertGreaterThanOrEqual(self::TWO_HOURS - 5, $delta,
            "{$who} 的 expire − create 应≈2h（实得 {$delta}s）；now() 占位会算出≈0");
        $this->assertLessThanOrEqual(self::TWO_HOURS + 60, $delta,
            "{$who} 的 expire − create 应≈2h（实得 {$delta}s），不该超出偏移量");
    }

    /**
     * 通用带宽判据：同一行 `expire − create ≈ $seconds`（容差 ±$tolerance）。
     * 下界始终比 0 高一截 ⇒ "写成 now()" 这种占位一定落在外面。
     */
    private function assertExpireAboutAfterCreate(?string $expire, ?string $create, int $seconds,
                                                  int $tolerance, string $who): void
    {
        $this->assertNotNull($expire, "{$who} 必须带到期时间");
        $this->assertNotNull($create, "{$who} 的 createTime 应有值（内部对照）");
        $delta = strtotime((string) $expire) - strtotime((string) $create);
        $this->assertGreaterThanOrEqual($seconds - $tolerance, $delta,
            "{$who} 的 expire − create 应≈{$seconds}s（实得 {$delta}s）");
        $this->assertLessThanOrEqual($seconds + $tolerance, $delta,
            "{$who} 的 expire − create 应≈{$seconds}s（实得 {$delta}s）");
    }
}
