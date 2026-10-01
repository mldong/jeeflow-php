<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/126 案 A · 任务行 `expire_time` 由**建单路径**按节点到期表达式真算（php 栈 T0）。
 *
 * 基准＝boot2 内置版 `ProcessTaskServiceImpl` 的三处写（:213 普通建单 / :386 回退新建 /
 * :524 会签建单），三处都是 `FlowUtil.processTime(node.getExpireTime(), args)`；
 * 节点没配就留 NULL（owner 2026-09-28：不造默认值）。参考实现＝jeeflow-java `cb541d4`
 * （`ProcessInstance.applyExpireTime` / `applyNodeExpireTime` + `ExpireTimeOnCreateTest`）。
 *
 * 本栈原形状：`JeeflowEngine::startProcessInstanceById` 只把**定义级** expireTime 原串搬到实例列，
 * 注释自承"简化：不处理变量替换"，而任务行那一列五处写点**一处都没人工过** ⇒ 配了到期表达式的
 * 节点建出来的待办永远"无到期"，逾期类统计（overdueTaskCount / onTimeRate）在常规流上恒失真。
 *
 * 断言纪律（§1.7 / §2）：①档判据是**同一行内** `expire − create ≈ 2h`，不是"非空"——
 * 只判非空就会被 `now()` 占位写法蒙过（差值≈0），而那正是本病灶的形状。
 */
class ExpireTimeOnCreateTest extends TestCase
{
    /** 相对档 "2h" 的秒数 */
    private const TWO_HOURS = 7200;

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

    // ═══ T0 四格（聚合根级，照 java ExpireTimeOnCreateTest 的形状） ═══

    /** 正向①：节点配 "2h" ⇒ 到期时间＝建单那一刻 + 2 小时（不是 now，也不是 0 点） */
    public function testRelativeExpressionIsAppliedAtCreation(): void
    {
        $task = $this->createOn($this->instance(), '2h');
        $this->assertExpireAbout2HAfterCreate($task->getExpireTime(), $task->getCreateTime(), '普通建单');
    }

    /** 正向②：表达式是个变量名 ⇒ 取该变量的值当到期时间（processTime 第 1 档） */
    public function testExpressionNamingAVariableTakesItsValue(): void
    {
        // 字符串档：变量值本身就是 "Y-m-d H:i:s"
        $byString = $this->createOn($this->instance(['dueAt' => '2026-12-31 10:00:00']), 'dueAt');
        $this->assertSame('2026-12-31 10:00:00', $byString->getExpireTime(),
            '变量档命中 ⇒ 到期时间必须等于该变量的值');

        // 毫秒时间戳档：epoch + 该毫秒，转本地时区（与 Java toLocalDateTime(new Date(l)) 同档）
        $millis = 1798725600000; // 2026-12-31 14:00:00.000（UTC 视角）
        $byMillis = $this->createOn($this->instance(['dueAt' => $millis]), 'dueAt');
        $this->assertSame(date('Y-m-d H:i:s', intdiv($millis, 1000)), $byMillis->getExpireTime(),
            '毫秒时间戳变量档 ⇒ epoch+该毫秒转本地时区、秒级 floor（本栈 datetime 列是秒精度）');

        // 变量档**优先于**相对档：args 里真有个键叫 "2h" 时取变量值，而不是算 now+2h
        $shadow = $this->createOn($this->instance(['2h' => '2026-12-31 10:00:00']), '2h');
        $this->assertSame('2026-12-31 10:00:00', $shadow->getExpireTime(),
            '同名变量存在时变量档赢过相对档');
    }

    /** 负向①：节点没配 ⇒ 这一列必须留 NULL，不许造默认值（含不许写 now()；null / 空串 / 纯空白三档） */
    public function testUnconfiguredNodeKeepsColumnNull(): void
    {
        $this->assertNull($this->createOn($this->instance(), null)->getExpireTime(),
            '未配到期表达式的行不得被赋任何时间');
        $this->assertNull($this->createOn($this->instance(), '')->getExpireTime(),
            '空串同样算没配');
        $this->assertNull($this->createOn($this->instance(), " \t ")->getExpireTime(),
            '去空白后为空同样算没配');
        // 对照：行本身是正常建出来的（createTime 有值），NULL 不是"整行没建"
        $row = $this->createOn($this->instance(), '');
        $this->assertNotNull($row->getCreateTime(), '对照：未配的那行 createTime 仍应有值');
    }

    /** 负向②：表达式解析不出来 ⇒ NULL，而不是退回成 now()（那等于静默造一个"建单即逾期"的值） */
    public function testUnparsableExpressionStaysNull(): void
    {
        $this->assertNull($this->createOn($this->instance(), 'not-a-time')->getExpireTime());
        $this->assertNull($this->createOn($this->instance(), '2026-13-45 99:99:99')->getExpireTime(),
            '越界的绝对值算解析不出 ⇒ NULL（java 的 SimpleDateFormat 会 lenient 滚成 2027-02-18，本栈按 C# 严格档）');
        // §1.9 第 3 条：相对档前缀不是整数（误配成 xh）⇒ **落穿**到绝对档最终 NULL，不打断建单
        $typo = $this->createOn($this->instance(), 'xh');
        $this->assertNull($typo->getExpireTime(), '前缀非整数的相对档应落穿 ⇒ NULL（与 Java 抛异常打断建单是故意差异）');
        // 变量档"落穿"：键存在但类型不认识（bool）⇒ 不能当解析失败，继续走第 2/3 档
        $fallthrough = $this->createOn($this->instance(['dueAt' => true]), 'dueAt');
        $this->assertNull($fallthrough->getExpireTime(), '落穿后绝对档解析不出 ⇒ NULL');
    }

    /**
     * 负向③（issues/137 D，owner 2026-10-01 拍"判非负" · spec 04 §相对档前缀须非负整数）：
     * 负数相对档不是合法偏移，四档 `s/m/h/d` 各自钉一格。
     *
     * 放行 `-5h` 会算出一个**过去**的时刻 ⇒ 新建的行当场即逾期，比"没配到期时间"更难发现；
     * 判据是 NULL，**不是异常**（误配不该打断建单，issues/137 C 口径）、**不是当前时间**（issues/126
     * 病灶）、也**不是回拨后的那个时刻**。`d` 档单独一格：它走 DateTime 日历加天而非乘 86400，
     * 是"历日倒退"这条独立病灶（本栈四档共用 FlowUtil::tryInt 这一个前缀解析点，无旁路）。
     *
     * 判据形状照 jeeflow-java `ExpireTimeOnCreateTest::negativeRelativeExpressionStaysNull`（1649955）。
     */
    public function testNegativeRelativeExpressionStaysNull(): void
    {
        foreach (['-30s', '-5m', '-5h', '-5d'] as $expr) {
            $task = $this->createOn($this->instance(), $expr);
            // "行没读到"与"值为空"分开断：否则这条负向会拿"根本没建行"恒真通过
            $this->assertNotNull($task->getCreateTime(), "对照：\"{$expr}\" 那行的 createTime 仍应有值");
            $this->assertNull($task->getExpireTime(),
                "负数前缀 \"{$expr}\" 必须算解析不出 ⇒ 落穿绝对档 ⇒ NULL；"
                . '退化成当前时间等于静默造一个"建单即逾期"，放行负数则是真算出一个过去时刻');
        }
    }

    /**
     * 上一格的对照面：判非负**只**裁第 2 档的负前缀，其余四档一律照旧。
     *
     * ②加号档仍合法——"只裁负、不裁加号"：各栈整数解析（python `[+-]?`、node `[-+]?\d+`、java
     *   Integer.parseInt）都收 '+'，正则里的 `+` 保留；这一格就是挡住顺手把加号也裁掉。
     *   天档正数（`2d`/`+1d`）同时把"跨天/跨月的日历加天算术"用正方向补回覆盖。
     * ③变量档与绝对档不变。
     * ④坏前缀（小数/带字母）行为不变，本来就落穿。
     */
    public function testNegativeTierCheckKeepsOtherTiersWorking(): void
    {
        $plus = $this->createOn($this->instance(), '+2h');
        $this->assertExpireAbout2HAfterCreate($plus->getExpireTime(), $plus->getCreateTime(), '正向对照（+2h 收加号）');

        $this->assertExpireAboutDays($this->createOn($this->instance(), '2d'), 2, '天档 2d');
        $this->assertExpireAboutDays($this->createOn($this->instance(), '+1d'), 1, '天档 +1d');

        // 变量档：表达式是变量名 ⇒ 取变量值（第 1 档排在相对档之前，不受本次改动影响）
        $byVar = $this->createOn($this->instance(['dueAt' => '2026-12-31 10:00:00']), 'dueAt');
        $this->assertSame('2026-12-31 10:00:00', $byVar->getExpireTime(), '变量档照旧命中');

        // 绝对档：表达式本身就是 "Y-m-d H:i:s"
        $abs = $this->createOn($this->instance(), '2026-12-31 10:00:00');
        $this->assertSame('2026-12-31 10:00:00', $abs->getExpireTime(), '第 3 档绝对时刻照旧成功');

        foreach (['2.5h', 'xh', '-2.5h'] as $expr) {
            $task = $this->createOn($this->instance(), $expr);
            $this->assertNotNull($task->getCreateTime(), "对照：\"{$expr}\" 那行的 createTime 仍应有值");
            $this->assertNull($task->getExpireTime(),
                "\"{$expr}\" 前缀非整数 ⇒ 落穿绝对档 ⇒ NULL（issues/137 C 误配落穿口径不变）");
        }
    }

    // ═══ issues/137 E（相对档前缀允许两端空白）═══

    /**
     * issues/137 E（owner 2026-10-01 拍"统一 trim" · spec 04 §相对档前缀允许两端空白）：
     * ①前缀带空白的相对档**照样算得出**，②单位符后面带空白仍落穿，③trim 不是裁容错。
     *
     * 本栈病灶在 `FlowUtil::tryInt` 的正则是 `^…$` 锚死的、不吃空白 ⇒ 改前 `" 2h"` 的前缀 `" 2"`
     * 被正则拒（java 同形是 `Integer.parseInt` 抛 NFE）。各栈整数解析对空白的容忍度天然不同
     * （go `Atoi` 前 `TrimSpace`、rust `.trim()`、.NET `TryParse` 与 python `int()` 默认收），
     * 不显式 trim 就是"同一份流程定义别家有到期时间、php 没有"。
     *
     * 三条分界（基准＝jeeflow-java `ExpireTimeOnCreateTest::paddedRelativePrefixStillApplies`
     * `bf1f401`）的判据形状都是**同一行** `expire − create`，不是"非空"空判：
     * ① `" 2h"` / `"	2h"`（tab 也算空白）/ `"2 h"`（空格落在前缀区内、末位仍是单位符）⇒ ≈2h；
     *    `d` 档同尺（`" 2d"`），证明两个调用点走的是同一把尺子；
     * ② `"2h "` 末位是空格 ⇒ 认不出单位 ⇒ 落穿绝对档 ⇒ NULL（**这一格钉住"只裁前缀不裁整串"**——
     *    整串去空白后它就变成合法的 `2h` 了，而"整体裁空白"没立过法）；
     * ③ `" 2.5h"` ⇒ trim 之后仍是小数误配 ⇒ 仍落穿。
     */
    public function testPaddedRelativePrefixStillApplies(): void
    {
        $leading = $this->createOn($this->instance(), ' 2h');
        $this->assertExpireAbout2HAfterCreate($leading->getExpireTime(), $leading->getCreateTime(),
            '前缀带一个空格的 2h');

        $tabbed = $this->createOn($this->instance(), "\t2h");
        $this->assertExpireAbout2HAfterCreate($tabbed->getExpireTime(), $tabbed->getCreateTime(),
            '前缀带 tab 的 2h');

        $inside = $this->createOn($this->instance(), '2 h');
        $this->assertExpireAbout2HAfterCreate($inside->getExpireTime(), $inside->getCreateTime(),
            '空白落在前缀区内（数字与单位符之间）');

        // 天档经的是另一个调用点（`$unit === 'd'` 那一支），同一把尺子 ⇒ 空白同样该裁
        $this->assertExpireAboutDays($this->createOn($this->instance(), ' 2d'), 2, '天档带空前缀 2d');

        $trailing = $this->createOn($this->instance(), '2h ');
        $this->assertNotNull($trailing->getCreateTime(), '对照："2h " 那行的 createTime 仍应有值');
        $this->assertNull($trailing->getExpireTime(),
            '单位符后面带空白 ⇒ 末位不是 s/m/h/d、认不出单位 ⇒ 落穿绝对档 ⇒ NULL；'
            . '这里若算出了值，说明裁空白被做成了"整个表达式去空白"');

        $decimal = $this->createOn($this->instance(), ' 2.5h');
        $this->assertNotNull($decimal->getCreateTime(), '对照：" 2.5h" 那行的 createTime 仍应有值');
        $this->assertNull($decimal->getExpireTime(),
            'trim 之后照样是误配（小数）⇒ 仍落穿；trim 不是把"裁空白"顺手做成"裁容错"');
    }

    /**
     * issues/137 E 的两条边界对照面：
     * ④ 判负（137 D）在 trim **之后**照旧生效——`" -5h"` 裁成 `-5` 仍算不合法 ⇒ NULL，
     *   不许因为"加了 trim"就把负数档漏成放行（`[+-]?` 保留、负号仍被正则收进来再由 `$n < 0` 拦）；
     * 对照 不带空格的 `2h` / `+2h` 仍≈2h——上一格那批断言不是恒真，摘掉 tryInt 的 trim 也不会
     *   误伤它们，这一格才是"新格有牙"的参照系。
     */
    public function testPaddedNegativePrefixStillStaysNullWithUnpaddedControl(): void
    {
        // ④ trim 之后判负照旧（-5h）；'-5m ' 那档末位带空格、单位都认不出，同为 NULL
        foreach ([' -5h', '-5m ', ' -5d'] as $expr) {
            $task = $this->createOn($this->instance(), $expr);
            $this->assertNotNull($task->getCreateTime(), "对照：\"{$expr}\" 那行的 createTime 仍应有值");
            $this->assertNull($task->getExpireTime(),
                "\"{$expr}\" 必须 NULL：带空白不改变判负（137 D 在 trim 之后仍生效），"
                . '放行负数＝写进一个过去的时刻，新建即逾期');
        }

        $plain = $this->createOn($this->instance(), '2h');
        $this->assertExpireAbout2HAfterCreate($plain->getExpireTime(), $plain->getCreateTime(),
            '正向对照（不带空格的 2h）');
        $plus = $this->createOn($this->instance(), '+2h');
        $this->assertExpireAbout2HAfterCreate($plus->getExpireTime(), $plus->getCreateTime(),
            '正向对照（+2h，加号照旧收）');
    }

    /**
     * issues/137 E 的边界：裁的只到**相对档前缀**——变量档的键名与绝对档的字符串本身都不 trim。
     * `" dueAt "` 这个表达式**取不到**变量 `dueAt`（`$args->has($expr)` 吃的是原串），
     * 也不许因本次改动突然取到；落穿后末位是空格 ⇒ 相对档不认 ⇒ 绝对档解析不出 ⇒ NULL。
     *
     * 这一格同时是"整串 trim"变异的第二块试金石：真在 processTime 开头 trim 整串，
     * `" dueAt "` 就会命中变量档算出 2026-12-31 10:00:00 而当场红。
     */
    public function testVariableTierKeyIsNotTrimmedByTheNewPrefixTrim(): void
    {
        $inst = $this->instance(['dueAt' => '2026-12-31 10:00:00']);
        $task = $this->createOn($inst, ' dueAt ');
        $this->assertNotNull($task->getCreateTime(), '对照：这一行确实建过单');
        $this->assertNull($task->getExpireTime(),
            '变量档键名不做 trim ⇒ " dueAt " 取不到 dueAt，按既有误配落穿 ⇒ NULL'
            . '（若这里算出了变量值，说明裁空白被做成了整个表达式去空白）');

        // 绝对档同样不吃空白：带前导空格的合法时间串仍按未立法的宽容处理 ⇒ NULL
        $this->assertNull($this->createOn($this->instance(), ' 2026-12-31 10:00:00')->getExpireTime(),
            '绝对档的字符串本身不 trim');
    }

    // ═══ §1.8 串行会签两格（引擎级，走共享夹具） ═══

    /**
     * 第五处写点：串行会签**首成员有到期 ∧ 推进出的第二成员也有到期**。
     *
     * java 这一支（`CountersignHandler.createNextCountersignTask`）绕过 `createTask` 直建任务行，
     * 所以聚合根开了公开入口 `applyNodeExpireTime` 让它上同一把尺子；漏了就正好是
     * "首位有、第二三位没有"这个形状。基准侧 boot2 的串行推进是回调 createCountersignTask
     * （`ProcessTaskServiceImpl:485`，内含 :524 那处到期写），所以第二成员同样带到期时间。
     */
    public function testSerialCountersignAdvanceWritesExpireOnNextMember(): void
    {
        $instanceId = $this->startCountersignFlow('06-countersign-sequential-expire.json', '900161');

        $first = $this->doingRow($instanceId, 'userA');
        $this->assertNotNull($first, '串行会签首成员行没读到');
        $this->assertExpireAbout2HAfterCreate($first->getExpireTime(), $first->getCreateTime(),
            '首成员（createCountersignTasks 串行分支）');

        $this->engine->executeProcessTask((string) $first->getTaskId(), 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $second = $this->doingRow($instanceId, 'userB');
        $this->assertNotNull($second, '推进后的第二成员行没读到');
        $this->assertExpireAbout2HAfterCreate($second->getExpireTime(), $second->getCreateTime(),
            '推进新建的第二成员（第五处写点）');
    }

    /**
     * 同一条会签节点的"未配"档：换成不带 expireTime 的原夹具（06-countersign-sequential.json），
     * 两行都必须留空（不造默认值）。
     *
     * ⚠️ "行没读到"与"值为空"**分开断**——否则这条负向会拿"根本没这两行"恒真通过。
     */
    public function testSerialCountersignUnconfiguredKeepsExpireNull(): void
    {
        $instanceId = $this->startCountersignFlow('06-countersign-sequential.json', '900162');

        $first = $this->doingRow($instanceId, 'userA');
        $this->assertNotNull($first, '首成员行本身要读到（否则这条断言恒真）');
        $this->assertNotNull($first->getCreateTime(), '对照：createTime 应有值');
        $this->assertNull($first->getExpireTime(), '未配到期表达式的会签节点，首成员行不该有到期时间');

        $this->engine->executeProcessTask((string) $first->getTaskId(), 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $second = $this->doingRow($instanceId, 'userB');
        $this->assertNotNull($second, '推进后的第二成员行本身要读到');
        $this->assertNotNull($second->getCreateTime(), '对照：第二成员 createTime 应有值');
        $this->assertNull($second->getExpireTime(), '推进出的第二成员同样不该有到期时间');
    }

    // ═══ 夹具与断言helper ═══

    /** 聚合根夹具（不走仓储，直接验写点行为） */
    private function instance(array $vars = []): ProcessInstance
    {
        $inst = ProcessInstance::create([
            'id' => '9001', 'name' => 'expire_t0', 'displayName' => '到期时间夹具',
            'type' => 'approval', 'state' => 1, 'content' => '{}', 'version' => 1,
        ], 'user1');
        $inst->setInstanceId('9001');
        if ($vars !== []) {
            $inst->addVariable(FlowData::of($vars));
        }
        return $inst;
    }

    /** 第一处写点（普通建单）落一个节点，expireExpr 按档位传 */
    private function createOn(ProcessInstance $inst, ?string $expireExpr): ProcessTask
    {
        return $inst->createTask('approve', '审批', 0, 0, null, ['u1'], 'op', null, false, $expireExpr);
    }

    /** 发起串行会签流程并办完 apply 节点，返回实例 ID（此时首位成员 DOING） */
    private function startCountersignFlow(string $flowFile, string $defineId): string
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/' . $flowFile);
        $this->assertNotFalse($json, "共享夹具 {$flowFile} 读不到");
        $this->repo->addDefine([
            'id' => $defineId,
            'name' => 'countersign-sequential',
            'displayName' => '串行会签流程',
            'type' => 'approval',
            'state' => 1,
            'content' => $json,
            'version' => 1,
        ]);

        $instance = $this->engine->startProcessInstanceById($defineId, 'user1', FlowData::create());
        $instanceId = (string) $instance->getInstanceId();

        $apply = $this->doingRow($instanceId, 'user1');
        $this->assertNotNull($apply, 'apply（首任务节点）行没读到');
        $this->engine->executeProcessTask((string) $apply->getTaskId(), 'user1',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        return $instanceId;
    }

    /**
     * 取该实例里 actor 为某用户的那条 DOING 任务**行本身**（找不到返回 null）——
     * "行不在"与"值为空"必须能分开，否则未配那一档会拿"根本没读到行"混成一谈。
     */
    private function doingRow(string $instanceId, string $actor): ?ProcessTask
    {
        foreach ($this->repo->findDoingTasks($instanceId, null) as $task) {
            if (in_array($actor, $task->getActorIds(), true)) {
                return $task;
            }
        }
        return null;
    }

    /**
     * 同行内 `expire − create ≈ 2h`（不是只判"非空"）。
     * 带宽 [2h−5s, 2h+60s]：本栈两列同为秒精度 `date('Y-m-d H:i:s')`，理论上正好 7200s，
     * 留 5s 下界与 60s 上界是给跨秒建单与慢机器留量——占位写法算出的≈0s 一定落在外面。
     */
    private function assertExpireAbout2HAfterCreate(?string $expire, ?string $create, string $who): void
    {
        $this->assertNotNull($expire, "{$who} 必须带到期时间");
        $this->assertNotNull($create, "{$who} 的 createTime 应有值（内部对照）");
        $delta = strtotime((string) $expire) - strtotime((string) $create);
        $this->assertGreaterThanOrEqual(self::TWO_HOURS - 5, $delta,
            "{$who} 的 expire − create 应≈2h（实得 {$delta}s）；占位写法会算出≈0 而把新建任务判成已逾期");
        $this->assertLessThanOrEqual(self::TWO_HOURS + 60, $delta,
            "{$who} 的 expire − create 应≈2h（实得 {$delta}s），不该超出偏移量");
    }

    /**
     * 天档判据：同一行 `expire − create ≈ n 天`。带宽 ±1h 而不是紧贴 n*86400——本栈 `d` 档走
     * DateTime **日历加天**（对齐 Java `Calendar.add(DAY_OF_MONTH)`，见 FlowUtil::processTime 的
     * `%+d days` 一支），跨夏令时/月末的自然日不是恒定 86400 秒。
     * 下界仍把"退回当前时间的占位写法算出≈0"夹在外面，不放宽成"非空"。
     */
    private function assertExpireAboutDays(ProcessTask $task, int $days, string $who): void
    {
        $expire = $task->getExpireTime();
        $create = $task->getCreateTime();
        $this->assertNotNull($expire, "{$who} 必须带到期时间");
        $this->assertNotNull($create, "{$who} 的 createTime 应有值（内部对照）");
        $delta = strtotime((string) $expire) - strtotime((string) $create);
        $this->assertGreaterThanOrEqual($days * 86400 - 3600, $delta,
            "{$who} 的 expire − create 应≈{$days} 天（日历加天），实得 {$delta}s；占位写法会算出≈0");
        $this->assertLessThanOrEqual($days * 86400 + 3600, $delta,
            "{$who} 的 expire − create 不该超出 {$days} 天偏移，实得 {$delta}s");
    }
}
