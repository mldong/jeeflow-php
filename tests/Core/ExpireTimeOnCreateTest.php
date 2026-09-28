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
}
