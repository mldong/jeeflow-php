<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Support\AddSignSupport;
use PHPUnit\Framework\TestCase;

/**
 * 「后加签（可重复）」验收（PHP 栈，第五语言）——16 格语义逐字移植 jeeflow-java
 * `AddSignAfterTest`（基准＝spec 04「后加签（可重复）」＋ spec 06 §2.7）。
 *
 * 覆盖：链式续办、一轮多选等同圈兄弟等待、加给已办过的人合法、四条写入口报错且**零写入**、
 * 三条解析期硬校验、拒绝／退回发起人废弃未完成加签、回归（不开开关逐字不变）、
 * 并发只放行一次、`tf_` 复活根因守卫、共享夹具 {@code flows/19-add-sign-after.json}。
 *
 * ⚠️ 第 14 格（并发）在 PHP 的形状与 java 不同，是**语言运行时差异**而不是判据差异：
 * java 用两条线程 + CountDownLatch 真并发办结；PHP 没有线程，本栈的并发口径一直是
 * "把并发落成串行重放 + 重复提交"（同仓 ReturnToSourceTest 的「汇合只放行一次」同一姿势）。
 * 判据仍打在三件事上：① 两条兄弟待办按任意顺序办结，merged 只可能由**最后一条 DOING**
 * 的行成立（{@see AddSignSupport::hasDoingOnNode()}）；② 已办结的行再提交即被
 * `prepareExecution` 的进行中守卫拦下 ⇒ 重复提交至多一个成功；③ 后续节点只落一行。
 * 多进程跨进程锁不是本契约的承诺面（spec 04「并发与事务」如实声明为已知边界，继承 reapply 轮同处置）。
 *
 * 夹具走内联 JSON（{@see self::register()}），**不碰共享 flows 目录**——除了第 16 格，
 * 那一格存在的意义就是"共享夹具真有生产者"（各语言仓 flows/ 是它的入库副本）。
 */
final class AddSignAfterTest extends TestCase
{
    /** 审批节点开加签的 properties 片段 */
    private const ADD_SIGN_ON = ['allowAddSign' => true];

    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $seq = 0;

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

    // ═══ 夹具 ═══

    /**
     * start → apply → review → final → end；两参数分别控制申请节点与审批节点的 properties。
     *
     * ⚠️ 覆盖片段用 `array_merge` 而不是 PHP 的 `+`：`+` 是**左键优先**，
     * 夹具里节点自带的 `performType => 0` 会把覆盖用的 `performType => 1` 静默吃掉
     * （java 侧那串手拼 JSON 是同键后写覆盖前写，两形在这里必须同答案）。
     *
     * @param array<string,mixed> $applyProps
     * @param array<string,mixed> $reviewProps
     */
    private function chainJson(array $applyProps, array $reviewProps): string
    {
        $json = json_encode([
            'name' => 'addsign-' . (++$this->seq),
            'displayName' => '后加签夹具',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => array_merge(['form' => 'apply-form', 'taskType' => 0, 'performType' => 0], $applyProps),
                 'text' => ['value' => '发起申请']],
                ['id' => 'review', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => array_merge(['form' => 'form-r', 'assignee' => 'userA', 'taskType' => 0,
                     'performType' => 0], $reviewProps),
                 'text' => ['value' => '审批']],
                ['id' => 'final', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'form-f', 'assignee' => 'userF', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '终审']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e1', 'type' => 'snaker:transition', 'sourceNodeId' => 'start',
                 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e2', 'type' => 'snaker:transition', 'sourceNodeId' => 'apply',
                 'targetNodeId' => 'review', 'properties' => []],
                ['id' => 'e3', 'type' => 'snaker:transition', 'sourceNodeId' => 'review',
                 'targetNodeId' => 'final', 'properties' => []],
                ['id' => 'e4', 'type' => 'snaker:transition', 'sourceNodeId' => 'final',
                 'targetNodeId' => 'end', 'properties' => []],
            ],
        ]);
        $this->assertNotFalse($json);
        return $json;
    }

    /** @return string 流程定义 id */
    private function register(string $flowJson): string
    {
        $defineId = 'as-' . $this->seq;
        $this->repo->addDefine([
            'id' => $defineId, 'name' => 'addsign-' . $this->seq, 'displayName' => '后加签夹具',
            'type' => 'approval', 'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }

    /**
     * 发起并办结申请节点，返回停在 review 的实例 id。
     *
     * @param array<string,mixed> $reviewProps 审批节点的 properties 片段
     */
    private function startToReview(array $reviewProps): string
    {
        $defineId = $this->register($this->chainJson(['assignee' => 'applicant'], $reviewProps));
        $inst = $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::create());
        $iid = (string) $inst->getInstanceId();
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant', $this->plain(SubmitType::APPLY));
        }
        return $iid;
    }

    private function doing(string $iid, string $name): ?ProcessTask
    {
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($name === $t->getTaskName()) return $t;
        }
        return null;
    }

    private function doingCount(string $iid, string $name): int
    {
        return count(array_filter($this->repo->findDoingTasks($iid),
            fn(ProcessTask $t) => $name === $t->getTaskName()));
    }

    /** 该节点的全部行（内存仓的 findHistoryTasks＝实例全部任务，含进行中，与 java 同口径） */
    private function rows(string $iid, string $name): int
    {
        return count(array_filter($this->repo->findHistoryTasks($iid),
            fn(ProcessTask $t) => $name === $t->getTaskName()));
    }

    /** 进行中任务的 operator 列恒空是家族不变量（办结才写），取办理人要用参与者列表。 */
    private function firstActor(ProcessTask $t): string
    {
        $ids = $t->getActorIds();
        return $ids === [] ? '' : $ids[0];
    }

    private function agreeWith(mixed $operators): FlowData
    {
        return FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => $operators,
        ]);
    }

    private function plain(int $submitType): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => $submitType]);
    }

    // ═══ 正向 ═══

    /** 链式续办：A→B→C→不点人即流出；血缘串成链、轮次递增、账本落在已完成行。 */
    public function test01ChainThenStop(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);

        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB'));
        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b, '被加签人应在同一节点收到新待办');
        $this->assertSame((string) $a->getTaskId(), (string) $b->getParentTaskId(), '血缘 parent＝刚办结那条');
        $this->assertSame(1, $b->getVariables()->getInt(FlowConst::ADD_SIGN_ROUND . '_review'), '轮次 1');
        $this->assertNull($this->doing($iid, 'final'), '令牌不得流出本节点');
        $this->assertSame(1, $this->doingCount($iid, 'review'), '本节点只应有 1 条待办');

        $completedA = $this->repo->findTaskById((string) $a->getTaskId());
        $this->assertNotNull($completedA);
        $histA = AddSignSupport::historyOf($completedA);
        $this->assertCount(1, $histA);
        $entry = $histA[0];
        $this->assertSame('userA', $entry['fromActor']);
        $this->assertSame(['userB'], $entry['toActors']);
        $this->assertSame(1, $entry['round']);
        $this->assertSame('userA', $entry['operator']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $entry['time'],
            '时间须是全生态格式串');

        $this->engine->executeProcessTask((string) $b->getTaskId(), 'userB', $this->agreeWith('userC'));
        $c = $this->doing($iid, 'review');
        $this->assertNotNull($c);
        $this->assertSame(2, $c->getVariables()->getInt(FlowConst::ADD_SIGN_ROUND . '_review'), '轮次递增');
        $this->assertSame((string) $b->getTaskId(), (string) $c->getParentTaskId());

        // C 不再点人 → 停止条件成立，流出本节点
        $this->engine->executeProcessTask((string) $c->getTaskId(), 'userC', $this->plain(SubmitType::AGREE));
        $this->assertNull($this->doing($iid, 'review'), '不点人后不得再有本节点待办');
        $fin = $this->doing($iid, 'final');
        $this->assertNotNull($fin, '应流转到下一审批节点');
        $this->assertSame(3, $this->rows($iid, 'review'), 'review 共三行');

        $this->engine->executeProcessTask((string) $fin->getTaskId(), 'userF', $this->plain(SubmitType::AGREE));
        $this->assertSame(ProcessInstanceState::FINISHED,
            $this->repo->findInstanceById($iid)?->getState(), '实例办结');
    }

    /** 数组形（spec 06 §2.11 两形同判据）：一次点两人 ⇒ 同圈建两行，最后一位办结且没点人才放行。 */
    public function test02MultiSelectWaitsSiblings(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);

        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA',
            $this->agreeWith(['userB', 'userC']));
        $this->assertSame(2, $this->doingCount($iid, 'review'), '同圈应建两行待办');
        $this->assertNull($this->doing($iid, 'final'));

        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        $this->engine->executeProcessTask((string) $b->getTaskId(), $this->firstActor($b),
            $this->plain(SubmitType::AGREE));
        $this->assertNull($this->doing($iid, 'final'), '兄弟待办未完成时不得放行');
        $this->assertSame(1, $this->doingCount($iid, 'review'));

        $c = $this->doing($iid, 'review');
        $this->assertNotNull($c);
        $this->engine->executeProcessTask((string) $c->getTaskId(), $this->firstActor($c),
            $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'final'), '最后一位办结且没点人才放行');
        $this->assertSame(1, $this->doingCount($iid, 'final'), '放行只能发生一次');
    }

    /** 加给已办过的人合法（A→B→A 乒乓），轮次继续计数。 */
    public function test03ReAddPreviouslyHandledIsLegal(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB'));
        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        $this->engine->executeProcessTask((string) $b->getTaskId(), 'userB', $this->agreeWith('userA'));
        $back = $this->doing($iid, 'review');
        $this->assertNotNull($back);
        $this->assertContains('userA', $back->getActorIds(), '加给已办过的人应合法');
        $this->assertSame(2, $back->getVariables()->getInt(FlowConst::ADD_SIGN_ROUND . '_review'));
    }

    // ═══ 负向：写入口报错且零写入 ═══

    /** 与 tf_nextNodeOperator 同传 ⇒ 报错，且任务行/实例零改动。 */
    public function test04MutexWithNextNodeOperatorRejected(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $before = $this->rows($iid, 'review');
        $args = $this->agreeWith('userB');
        $args->set(FlowConst::NEXT_NODE_OPERATOR, 'userF');
        try {
            $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $args);
            $this->fail('同传两键必须报错');
        } catch (JeeflowException $e) {
            $this->assertSame(AddSignSupport::MSG_MUTEX, $e->getMessage());
        }
        $this->assertTrue($this->repo->findTaskById((string) $a->getTaskId())?->isDoing(),
            '报错时任务行不得被办结');
        $this->assertSame($before, $this->rows($iid, 'review'), '不得新增行');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)?->getState());
    }

    /** 节点未开开关却传键 ⇒ 报错（不得静默忽略），零写入。 */
    public function test05SwitchOffRejected(): void
    {
        $iid = $this->startToReview([]);   // review 不开 allowAddSign
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        try {
            $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB'));
            $this->fail('开关没开却传键必须报错');
        } catch (JeeflowException $e) {
            $this->assertStringContainsString('未开启 allowAddSign', $e->getMessage());
        }
        $this->assertTrue($this->repo->findTaskById((string) $a->getTaskId())?->isDoing());
        $this->assertNull($this->doing($iid, 'final'));
    }

    /** 加给自己 ⇒ 报错（死循环入口），零写入。 */
    public function test06SelfRejected(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        try {
            $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userA'));
            $this->fail('加给自己必须报错');
        } catch (JeeflowException $e) {
            $this->assertSame(AddSignSupport::MSG_SELF, $e->getMessage());
        }
        $this->assertTrue($this->repo->findTaskById((string) $a->getTaskId())?->isDoing());
        $this->assertSame(1, $this->doingCount($iid, 'review'), '报错不得多出待办');
    }

    /** 超上限 ⇒ 报错不强制放行（放行＝伪造"审批完成"），零写入。 */
    public function test07MaxRoundsRejected(): void
    {
        $iid = $this->startToReview(array_merge(self::ADD_SIGN_ON, ['addSignMaxRounds' => 1]));
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB')); // 第 1 轮，合法
        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        try {
            $this->engine->executeProcessTask((string) $b->getTaskId(), 'userB', $this->agreeWith('userC'));
            $this->fail('超过 addSignMaxRounds 必须报错');
        } catch (JeeflowException $e) {
            $this->assertStringContainsString('已达上限 1', $e->getMessage());
        }
        $this->assertTrue($this->repo->findTaskById((string) $b->getTaskId())?->isDoing(),
            '越界那次不得办结任务行');
        $this->assertNull($this->doing($iid, 'final'));
        // 但第 1 轮那位不点人可以正常流出
        $this->engine->executeProcessTask((string) $b->getTaskId(), 'userB', $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'final'));
    }

    // ═══ 解析期硬校验 ═══

    public function test08CountersignNodeRejectedAtParse(): void
    {
        $this->assertParseFails($this->chainJson(['assignee' => 'applicant'],
            array_merge(self::ADD_SIGN_ON, ['performType' => 1, 'countersignType' => 'PARALLEL'])), '会签节点');
    }

    public function test09FirstTaskNodeRejectedAtParse(): void
    {
        $this->assertParseFails($this->chainJson(array_merge(['assignee' => 'applicant'], self::ADD_SIGN_ON),
            self::ADD_SIGN_ON), '首任务节点');
    }

    public function test10InvalidConfigValuesRejectedAtParse(): void
    {
        $this->assertParseFails($this->chainJson(['assignee' => 'applicant'],
            ['allowAddSign' => 'yes']), 'allowAddSign 配置非法');
        $this->assertParseFails($this->chainJson(['assignee' => 'applicant'],
            array_merge(self::ADD_SIGN_ON, ['addSignMaxRounds' => -1])), '不得为负');
        $this->assertParseFails($this->chainJson(['assignee' => 'applicant'],
            array_merge(self::ADD_SIGN_ON, ['addSignMaxRounds' => '1.5'])), '必须是整数');
    }

    private function assertParseFails(string $flowJson, string $expectedFragment): void
    {
        try {
            ModelParser::parse($flowJson);
            $this->fail('应报解析期配置错误：' . $expectedFragment);
        } catch (JeeflowException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }

    // ═══ 废弃未完成加签任务 ═══

    /** 不同意(2)：实例结束，本节点未完成的兄弟加签任务全部废弃，不留孤儿待办。 */
    public function test11RejectAbandonsSiblings(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith(['userB', 'userC']));
        $this->assertSame(2, $this->doingCount($iid, 'review'));

        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        $this->engine->executeAndJumpToEnd((string) $b->getTaskId(), $this->firstActor($b),
            $this->plain(SubmitType::REJECT));

        $this->assertSame(ProcessInstanceState::REJECTED, $this->repo->findInstanceById($iid)?->getState(),
            '实例应已结束（拒绝档 45）');
        $this->assertSame(0, $this->doingCount($iid, 'review'), '未完成的加签兄弟不得留待办');
        $abandoned = count(array_filter($this->repo->findHistoryTasks($iid),
            fn(ProcessTask $t) => 'review' === $t->getTaskName()
                && $t->getTaskState() === ProcessTaskState::ABANDON));
        $this->assertSame(1, $abandoned, '被废弃的那条应留废弃态');
    }

    /** 退回发起人(6)：申请待办生成，本节点未完成加签任务废弃。 */
    public function test12ReturnToApplicantAbandonsSiblings(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith(['userB', 'userC']));
        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        $this->engine->executeAndJumpToFirstTaskNode((string) $b->getTaskId(), $this->firstActor($b),
            $this->plain(SubmitType::ROLLBACK_TO_OPERATOR));

        $this->assertNotNull($this->doing($iid, 'apply'), '应生成申请待办');
        $this->assertSame(0, $this->doingCount($iid, 'review'), '本节点未完成加签任务应被废弃');
        $this->assertNull($this->doing($iid, 'final'));
    }

    // ═══ 回归与并发 ═══

    /** 不开开关的节点行为逐字不变：同意即流出，且不因新判据受影响。 */
    public function test13PlainNodeUnchanged(): void
    {
        $iid = $this->startToReview([]);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'final'));
        $this->assertSame(1, $this->rows($iid, 'review'));
        $done = $this->repo->findTaskById((string) $a->getTaskId());
        $this->assertNotNull($done);
        $this->assertSame([], AddSignSupport::historyOf($done));
    }

    /**
     * 两个同圈加签任务并发办结：只放行一次（后续节点恰好一条待办，不重复创建）。
     *
     * PHP 无线程档 ⇒ 并发按本栈既有口径落成"串行重放 + 重复提交"（类注释第 14 格说明）。
     */
    public function test14ConcurrentFinishReleasesOnce(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith(['userB', 'userC']));
        $ids = [];
        $actors = [];
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            if ($t->getTaskName() !== 'review') continue;
            $ids[] = (string) $t->getTaskId();
            $actors[] = $this->firstActor($t);
        }
        $this->assertCount(2, $ids);

        // 任意顺序各办结一次（＝并发办结在单进程下的等价形状）
        foreach ($ids as $i => $taskId) {
            $this->engine->executeProcessTask($taskId, $actors[$i], $this->plain(SubmitType::AGREE));
        }
        $this->assertSame(0, $this->doingCount($iid, 'review'));
        $this->assertSame(1, $this->doingCount($iid, 'final'), '后续节点只应有一条待办');

        // 重复提交至多一个成功：已办结那条再提一次必须被"进行中"守卫拦下，且不产生第二行
        try {
            $this->engine->executeProcessTask($ids[0], $actors[0], $this->plain(SubmitType::AGREE));
            $this->fail('重复提交必须报错');
        } catch (JeeflowException $e) {
            $this->assertStringContainsString('未找到进行中的任务', $e->getMessage());
        }
        $this->assertSame(1, $this->rows($iid, 'final'), '重复提交不得再放行一次');
    }

    /**
     * 根因守卫：控制类 `tf_*` 参数不得进实例变量（跨请求复活的病根），
     * 但任务行留痕必须完整——两者是同一枚判据的两面。
     *
     * 这一格是"承重陷阱"的直接取证：把 `ProcessInstance::completeTask` 改回整包
     * `setAll($args)`，本格与 test15b（复活守卫）里至少有一格变红。
     */
    public function test15ControlKeysNeverEnterInstanceVariables(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $args = $this->agreeWith('userB');
        $args->set(FlowConst::APPROVAL_COMMENT, '请 B 复核');
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $args);

        $instance = $this->repo->findInstanceById($iid);
        $this->assertNotNull($instance);
        $instanceVars = $instance->getVariables();
        $this->assertNull($instanceVars->get(FlowConst::ADD_SIGN_OPERATORS),
            'tf_addSignOperators 不得进实例变量');
        $this->assertNull($instanceVars->get(FlowConst::APPROVAL_COMMENT),
            'tf_approvalComment 不得进实例变量');
        foreach ($instanceVars->keys() as $key) {
            $this->assertStringStartNotWithTf((string) $key);
        }
        // 留痕仍在任务行上（修根因不得把审计信息一起改掉）
        $row = $this->repo->findTaskById((string) $a->getTaskId());
        $this->assertNotNull($row);
        $rowVars = $row->getVariables();
        $this->assertSame('请 B 复核', $rowVars->get(FlowConst::APPROVAL_COMMENT));
        $this->assertSame('userB', $rowVars->get(FlowConst::ADD_SIGN_OPERATORS));
        $this->assertCount(1, AddSignSupport::historyOf($row));
    }

    /**
     * 复活的第二面：加了签之后，**下一次**办结不点人绝不得凭空多建一条本节点待办。
     * （test15 判的是"键没进实例变量"，本格判的是那件事的业务后果——上一轮加的 B 不会复活。）
     */
    public function test15bResurrectedKeyDoesNotSpawnGhostTask(): void
    {
        $iid = $this->startToReview(self::ADD_SIGN_ON);
        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a);
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB'));
        $b = $this->doing($iid, 'review');
        $this->assertNotNull($b);
        // B 不点人 → 应直接流出本节点；若 tf_addSignOperators 从实例变量复活，这里会多出一条 review 待办
        $this->engine->executeProcessTask((string) $b->getTaskId(), 'userB', $this->plain(SubmitType::AGREE));
        $this->assertSame(0, $this->doingCount($iid, 'review'), '上一轮的加签人不得复活成新的本节点待办');
        $this->assertSame(1, $this->doingCount($iid, 'final'));
    }

    /**
     * 共享夹具 {@code flows/19-add-sign-after.json} 必须真被本栈消费——
     * 各语言仓 flows/ 是 java 编辑源的入库副本，没有生产者核对过的夹具会在传播轮里
     * 各语言各自"改一份能跑的"。
     */
    public function test16SharedFixtureChainRuns(): void
    {
        $file = jeeflow_flows_dir() . '/19-add-sign-after.json';
        $this->assertFileExists($file);
        $json = file_get_contents($file);
        $this->assertNotFalse($json);
        $this->seq++;
        $defineId = $this->register($json);
        $inst = $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::create());
        $iid = (string) $inst->getInstanceId();
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant',
                $this->plain(SubmitType::APPLY));
        }

        $a = $this->doing($iid, 'review');
        $this->assertNotNull($a, '共享夹具的申请节点办结后应到审批节点');
        $this->engine->executeProcessTask((string) $a->getTaskId(), 'userA', $this->agreeWith('userB,userC'));
        $this->assertSame(2, $this->doingCount($iid, 'review'), '逗号串两形应建两行');
        $this->assertNull($this->doing($iid, 'final'));

        // 先取快照再逐条办结：办结会改写仓储里的进行中集合，边遍历边办是并发修改
        $siblingIds = [];
        $siblingActors = [];
        foreach ($this->repo->findDoingTasks($iid) as $sib) {
            if ($sib->getTaskName() !== 'review') continue;
            $siblingIds[] = (string) $sib->getTaskId();
            $siblingActors[] = $this->firstActor($sib);
        }
        foreach ($siblingIds as $i => $taskId) {
            $this->engine->executeProcessTask($taskId, $siblingActors[$i], $this->plain(SubmitType::AGREE));
        }
        $fin = $this->doing($iid, 'final');
        $this->assertNotNull($fin, '同圈兄弟全部办结后应放行');
        $this->assertSame(1, $this->rows($iid, 'final'));
        $this->engine->executeProcessTask((string) $fin->getTaskId(), 'userF', $this->plain(SubmitType::AGREE));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($iid)?->getState());
    }

    /** 实例变量出现任何 tf_ 键即红（test15 的逐键判据体，判据只在 AddSignSupport 之外这一处用）。 */
    private function assertStringStartNotWithTf(string $key): void
    {
        $this->assertFalse(str_starts_with($key, FlowConst::TASK_FORM_DATA_PREFIX),
            '实例变量里不应有任何 tf_ 键: ' . $key);
    }
}
