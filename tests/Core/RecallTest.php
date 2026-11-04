<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Support\RecallSupport;
use Jeeflow\Tests\Fixture\RecordingCustomListener;
use PHPUnit\Framework\TestCase;

/**
 * 「已办撤回（recall）」验收（PHP 栈，issues/246）——逐字移植 jeeflow-java
 * `test/RecallTest.java`（9 格）＋ 本栈补的两格（并发只放行一次、零写入三面自证）。
 *
 * 契约出处：spec 02 §4.4（allowRecall 与两条解析期禁配）＋ spec 04「已办撤回」＋
 * spec 06 §processTask/recall（判据位序与五条逐字文案）＋ spec 11 码 10 TASK_RECALL。
 * 行为基准＝java 参考实现 90469a2 ＋ 14674d7（复活行补 fire 码 3）。
 *
 * 覆盖：复活三件套（原行留 FINISHED＋`tf_recallHistory`、下游 DOING 废弃、同节点新行名册照旧）、
 * 窗口两档（宽口径放行／下游已办结拦且**零写入**）、四条逐字文案、非参与者拦／特权放行、
 * 实例非 DOING、后加签行连带废弃、并行分支全废弃、解析期禁配两格、码 10 与码 3 的 fire 面。
 *
 * ⚠️ **承重判据三条**（java 测试照出来的，本栈同形）：
 *  1. 成员行识别＝parent **且同节点同名**（test10 那一格照这条——只按 parent 认会把主办推进后的
 *     下游行误当成员；本体在 {@see \Jeeflow\Core\Support\CollectSupport::memberRows()}）；
 *  2. 闸门收齐＝无 DOING 成员行，计数与投影一律跳过 99 行（在 DynamicCountersignTest 里）；
 *  3. **撤回的参与人判断按名册，不能用 isAllowed**——本栈
 *     {@see ProcessTask::isAllowed()} 只认 DOING 行，主办行办结后会把合法撤回判成无权
 *     （test04 那一格照这条：FINISHED 行按 actorIds 放行）。
 *
 * 夹具走内联 JSON（{@see self::register()}），**不碰共享 flows 目录**——与
 * {@see AddSignAfterTest} 同款纪律（`flows/` 有镜像与漂移门禁）。
 */
final class RecallTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    // ═══ 夹具 ═══

    /**
     * 通用五节点链：start→apply→review→final→end。
     *
     * ⚠️ 覆盖片段用 `array_merge` 而不是 PHP 的 `+`（同 {@see AddSignAfterTest::chainJson()}：
     * `+` 是左键优先，节点自带的 `performType => 0` 会把覆盖用的 `performType => 1` 静默吃掉）。
     *
     * @param array<string,mixed> $applyProps
     * @param array<string,mixed> $reviewProps
     */
    private function chainJson(array $applyProps, array $reviewProps, string $tag = 'chain'): string
    {
        return $this->encode($this->chainShape($applyProps, $reviewProps, $tag));
    }

    /** @param array<string,mixed> $reviewProps */
    private function chain(array $reviewProps): string
    {
        return $this->chainJson(['assignee' => 'applicant'], $reviewProps);
    }

    /** 六节点链：start→apply→review→final→pay→end，供「下游已办结」窗口档。 */
    private function chainDeep(array $reviewProps): string
    {
        $body = $this->chainShape(['assignee' => 'applicant'], $reviewProps, 'deep');
        // 在 final 与 end 之间插一个 pay 节点
        $pay = ['id' => 'pay', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
            'properties' => ['form' => 'form-p', 'assignee' => 'userP', 'taskType' => 0, 'performType' => 0],
            'text' => ['value' => '支付']];
        array_splice($body['nodes'], 4, 0, [$pay]);
        $body['edges'] = [
            $this->edge('e1', 'start', 'apply'),
            $this->edge('e2', 'apply', 'review'),
            $this->edge('e3', 'review', 'final'),
            $this->edge('e4', 'final', 'pay'),
            $this->edge('e5', 'pay', 'end'),
        ];
        $body['name'] = 'recalldeep-' . (++$this->seq);
        $body['displayName'] = '已办撤回深链';
        return $this->encode($body);
    }

    /** 并行双支：review 出两条边到 payA/payB，各自汇入 end；供「分支全废弃」档。 */
    private function chainFork(array $reviewProps): string
    {
        $body = $this->chainShape(['assignee' => 'applicant'], $reviewProps, 'fork');
        $body['nodes'] = [
            $body['nodes'][0], // start
            $body['nodes'][1], // apply
            $body['nodes'][2], // review
            ['id' => 'payA', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
             'properties' => ['form' => 'form-a', 'assignee' => 'userPA', 'taskType' => 0, 'performType' => 0],
             'text' => ['value' => '支付甲']],
            ['id' => 'payB', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
             'properties' => ['form' => 'form-b', 'assignee' => 'userPB', 'taskType' => 0, 'performType' => 0],
             'text' => ['value' => '支付乙']],
            $body['nodes'][4], // end
        ];
        $body['edges'] = [
            $this->edge('e1', 'start', 'apply'),
            $this->edge('e2', 'apply', 'review'),
            $this->edge('e3', 'review', 'payA'),
            $this->edge('e4', 'review', 'payB'),
            $this->edge('e5', 'payA', 'end'),
            $this->edge('e6', 'payB', 'end'),
        ];
        $body['name'] = 'recallfork-' . (++$this->seq);
        $body['displayName'] = '已办撤回并行';
        return $this->encode($body);
    }

    /**
     * 链的形状本体（start→apply→review→final→end）。
     *
     * @param array<string,mixed> $applyProps
     * @param array<string,mixed> $reviewProps
     * @return array<string,mixed>
     */
    private function chainShape(array $applyProps, array $reviewProps, string $tag): array
    {
        return [
            'name' => 'recall' . $tag . '-' . (++$this->seq),
            'displayName' => '已办撤回夹具',
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
                $this->edge('e1', 'start', 'apply'),
                $this->edge('e2', 'apply', 'review'),
                $this->edge('e3', 'review', 'final'),
                $this->edge('e4', 'final', 'end'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function edge(string $id, string $from, string $to): array
    {
        return ['id' => $id, 'type' => 'snaker:transition', 'sourceNodeId' => $from,
            'targetNodeId' => $to, 'properties' => []];
    }

    /** @param array<string,mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        return $json;
    }

    /** @return string 流程定义 id */
    private function register(string $flowJson): string
    {
        $defineId = 'rc-' . $this->seq;
        $decoded = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => $decoded['name'] ?? $defineId,
            'displayName' => $decoded['displayName'] ?? '已办撤回夹具',
            'type' => 'approval', 'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }

    /** 发起并办结申请节点，返回停在 review 的实例 id。@param array<string,mixed> $reviewProps */
    private function startToReview(array $reviewProps, ?string $flowJson = null): string
    {
        $defineId = $this->register($flowJson ?? $this->chain($reviewProps));
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

    /** 该节点的全部行（内存仓 findHistoryTasks＝实例全部任务，含进行中，与 java 同口径） */
    private function rows(string $iid, string $name): int
    {
        return count(array_filter($this->repo->findHistoryTasks($iid),
            fn(ProcessTask $t) => $name === $t->getTaskName()));
    }

    private function finishedRow(string $iid, string $name): ?ProcessTask
    {
        foreach ($this->repo->findHistoryTasks($iid) as $t) {
            if ($name === $t->getTaskName() && $t->isFinished()) return $t;
        }
        return null;
    }

    /**
     * 该节点**最后**一条 FINISHED 行（＝insertion order 最靠后的那条）。
     *
     * 撤回链上同一节点会有多条 FINISHED 历史行（最早那条撤回后仍留 20，复活再办结又落一条 20），
     * {@see self::finishedRow()} 取的是第一条＝**已被撤过的那一手**，拿它做第二次撤回的目标
     * 会把"撤的是哪一手"这件事测歪。第二次撤回一律用本方法。
     */
    private function lastFinishedRow(string $iid, string $name): ?ProcessTask
    {
        $found = null;
        foreach ($this->repo->findHistoryTasks($iid) as $t) {
            if ($name === $t->getTaskName() && $t->isFinished()) $found = $t;
        }
        return $found;
    }

    /** 不按状态滤——撤回后的下游行是 ABANDON(99)，不走 FINISHED。 */
    private function anyRow(string $iid, string $name): ?ProcessTask
    {
        foreach ($this->repo->findHistoryTasks($iid) as $t) {
            if ($name === $t->getTaskName()) return $t;
        }
        return null;
    }

    /** @return array<int,array<string,mixed>>|null */
    private function recallLedger(ProcessTask $row): ?array
    {
        $v = $row->getVariables()->get(FlowConst::RECALL_HISTORY);
        return is_array($v) ? $v : null;
    }

    private function plain(int $submitType): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => $submitType]);
    }

    // ═══ 格 1：正向复活三件套 ═══

    /** 正向：办结→撤回→原行留账本、复活行名册照旧、下游废弃、重办后再推进。 */
    public function test01RecallRevivesAbandonsDownstreamAndAllowsRedo(): void
    {
        $iid = $this->startToReview(['allowRecall' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $this->assertSame(1, $this->doingCount($iid, 'final'));

        $resurrected = $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');

        $this->assertSame('review', $resurrected->getTaskName());
        $this->assertTrue($resurrected->isDoing());
        $this->assertContains('userA', $resurrected->getActorIds(), '复活行名册照旧（不重解析 assignee）');
        // 下游废弃
        $finalRow = $this->anyRow($iid, 'final');
        $this->assertNotNull($finalRow);
        $this->assertSame(ProcessTaskState::ABANDON, $finalRow->getTaskState(), '下游 DOING 行废弃成 99，不是 20');
        $this->assertSame(0, $this->doingCount($iid, 'final'));
        // 原行不改写＋账本指向复活行
        $this->assertTrue($oldReview->isFinished(), '原 FINISHED 行一律不改写（历史即任务行）');
        $ledger = $this->recallLedger($oldReview);
        $this->assertNotNull($ledger);
        $this->assertCount(1, $ledger);
        $this->assertSame('userA', $ledger[0]['operator']);
        $this->assertSame((string) $resurrected->getTaskId(), (string) $ledger[0]['recalledTaskId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $ledger[0]['time'],
            '时间须是全生态格式串（规范 §2.4）');
        // 实例仍进行中，重办后再推进
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)?->getState());
        $this->engine->executeProcessTask((string) $resurrected->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'final'));
        $this->assertSame(2, $this->rows($iid, 'final'), '复活重办会再产一条 final 行（旧的停在 99，不被改写）');
    }

    // ═══ 格 2：窗口拦＋零写入 ═══

    /**
     * 窗口：下游已办结 ⇒ 逐字文案报错且**零写入**。
     *
     * 三面零写入断言（本项目立的跨栈判据，只钉文案不算做到）：
     * ① 当前行状态不变、② 任务行总数不变、③ 实例状态不变。
     */
    public function test02RecallBlockedWhenDownstreamFinishedWithZeroWrite(): void
    {
        $iid = $this->startToReview(['allowRecall' => true], $this->chainDeep(['allowRecall' => true]));
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $finalTask = $this->doing($iid, 'final');
        $this->assertNotNull($finalTask);
        $this->engine->executeProcessTask((string) $finalTask->getTaskId(), 'userF', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $this->assertNotNull($this->doing($iid, 'pay'));
        // 报错前的三面快照
        $totalRows = count($this->repo->findHistoryTasks($iid));
        $oldReviewState = $oldReview->getTaskState();

        try {
            $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');
            $this->fail('应拦：下游 final 已办结');
        } catch (JeeflowException $e) {
            $this->assertSame('任务[review]的后续节点已办理，不能撤回', $e->getMessage());
        }
        // ── 零写入三面 ──
        $this->assertNull($this->recallLedger($oldReview), '① 原行没被盖账本');
        $this->assertSame($oldReviewState, $this->repo->findTaskById((string) $oldReview->getTaskId())?->getTaskState(),
            '① 当前行状态不变（仍 FINISHED，没被翻回 DOING）');
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)),
            '② 任务行总数不变（没建复活行、没多建任何行）');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)?->getState(),
            '③ 实例状态不变');
        // 下游原样
        $downstream = $this->finishedRow($iid, 'final');
        $this->assertNotNull($downstream);
        $this->assertTrue($downstream->isFinished());
        $this->assertNotNull($this->doing($iid, 'pay'), 'pay 那条待办没被误废弃');
        $this->assertSame(0, $this->doingCount($iid, 'review'), '没造出复活的 review 待办');
    }

    // ═══ 格 3：开关未开 ═══

    /** 开关未开 ⇒ 逐字文案。 */
    public function test03RecallBlockedWhenSwitchOff(): void
    {
        $iid = $this->startToReview([]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $totalRows = count($this->repo->findHistoryTasks($iid));
        try {
            $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');
            $this->fail('应拦：开关未开');
        } catch (JeeflowException $e) {
            $this->assertSame('节点[review]未开启 allowRecall，不能撤回', $e->getMessage());
        }
        // 静默放行＝伪造撤回能力；这一档同样报错零写入
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)));
        $this->assertNull($this->recallLedger($oldReview));
        $this->assertSame(0, $this->doingCount($iid, 'review'));
    }

    // ═══ 格 4：承重判据三（按名册，不用 isAllowed）＋进行中行拦 ═══

    /**
     * 非参与者拦、特权放行；进行中行拦。
     *
     * ⚠️ 本格同时照**承重判据三**：目标行是 FINISHED，{@see ProcessTask::isAllowed()} 只认
     * DOING 行 ⇒ 用它会把 userA 的合法撤回判成无权。引擎按**名册**（actorIds）判断，
     * 所以 `userZ` 拦、`userA`／`flow.admin` 放行。摘掉名册那一条 ⇒ 本格第三句变红。
     */
    public function test04RecallPermissionAndNotFinishedGuards(): void
    {
        $iid = $this->startToReview(['allowRecall' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        // 前提自证：isAllowed 在 FINISHED 行上是 false——所以不能用它做参与人判据
        $this->assertFalse($oldReview->isAllowed('userA'),
            '承重判据三的前提：isAllowed 只认 DOING 行，FINISHED 行上一律 false');
        try {
            $this->engine->recallTask((string) $oldReview->getTaskId(), 'userZ');
            $this->fail('应拦：非参与者');
        } catch (JeeflowException $e) {
            $this->assertSame('无权限撤回该任务', $e->getMessage());
        }
        // flow.admin 特权放行（与转办／撤回整单同一条约定）
        $resurrected = $this->engine->recallTask((string) $oldReview->getTaskId(), 'flow.admin');
        $this->assertTrue($resurrected->isDoing());
        // 进行中行拦（复活行还没办结）
        try {
            $this->engine->recallTask((string) $resurrected->getTaskId(), 'userA');
            $this->fail('应拦：目标行未办结');
        } catch (JeeflowException $e) {
            $this->assertSame('任务非已完成状态，不可撤回', $e->getMessage());
        }
    }

    // ═══ 格 5：实例非 DOING ═══

    /** 实例已走完 ⇒ 实例非进行中档。 */
    public function test05RecallBlockedWhenInstanceFinished(): void
    {
        $iid = $this->startToReview(['allowRecall' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $finalTask = $this->doing($iid, 'final');
        $this->assertNotNull($finalTask);
        $this->engine->executeProcessTask((string) $finalTask->getTaskId(), 'userF', $this->plain(SubmitType::AGREE));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($iid)?->getState());
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $totalRows = count($this->repo->findHistoryTasks($iid));
        try {
            $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');
            $this->fail('应拦：实例已结束');
        } catch (JeeflowException $e) {
            $this->assertSame('流程实例非进行中状态，不可撤回', $e->getMessage());
        }
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)), '报错零写入：行总数不变');
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($iid)?->getState(),
            '报错零写入：实例停在 20，没被改回 10');
    }

    // ═══ 格 6：后加签行连带废弃 ═══

    /** 后加签行属下游 DOING：撤回连带废弃，不留孤儿待办；复活后可正常推进。 */
    public function test06RecallAbandonsAddSignRowsToo(): void
    {
        $iid = $this->startToReview(['allowRecall' => true, 'allowAddSign' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => 'userX',
        ]));
        // 加签行 userX 在 review 节点续办
        $addSignRow = $this->doing($iid, 'review');
        $this->assertNotNull($addSignRow);
        $this->assertContains('userX', $addSignRow->getActorIds());
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);

        $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');

        // 加签行废弃、复活行是节点上唯一 DOING
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById((string) $addSignRow->getTaskId())?->getTaskState());
        $this->assertSame(1, $this->doingCount($iid, 'review'));
        // 复活行照常推进（无兄弟待办 ⇒ merged 出边）
        $resurrected = $this->doing($iid, 'review');
        $this->assertNotNull($resurrected);
        $this->engine->executeProcessTask((string) $resurrected->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'final'));
    }

    // ═══ 格 7：并行分支全废弃 ═══

    /** 并行分支：撤回后两支全部废弃。 */
    public function test07RecallAbandonsBothForkBranches(): void
    {
        $iid = $this->startToReview(['allowRecall' => true], $this->chainFork(['allowRecall' => true]));
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $this->assertNotNull($this->doing($iid, 'payA'));
        $this->assertNotNull($this->doing($iid, 'payB'));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);

        $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');

        $payARow = $this->anyRow($iid, 'payA');
        $this->assertNotNull($payARow);
        $this->assertSame(ProcessTaskState::ABANDON, $payARow->getTaskState());
        $payBRow = $this->anyRow($iid, 'payB');
        $this->assertNotNull($payBRow);
        $this->assertSame(ProcessTaskState::ABANDON, $payBRow->getTaskState(),
            '两支都要废弃——只认直接子行会漏掉更深的那一支');
        $this->assertSame(1, $this->doingCount($iid, 'review'));
    }

    // ═══ 格 8：解析期禁配 ═══

    /** 解析期禁配：会签节点/申请节点配 allowRecall 直接报错。 */
    public function test08ParseBansAllowRecallOnCountersignAndApplyNodes(): void
    {
        try {
            $this->startToReview(['allowRecall' => true, 'performType' => 1, 'countersignType' => 'PARALLEL']);
            $this->fail('应拦：会签节点禁配');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('不允许配置 allowRecall', $e->getMessage());
            $this->assertStringContainsString('会签节点', $e->getMessage());
        }
        try {
            // 申请节点＝start 直接后继：apply 上配 allowRecall 应被解析期拦
            $this->startToReview([], $this->chainJson(
                ['assignee' => 'applicant', 'allowRecall' => true],
                []
            ));
            $this->fail('应拦：申请节点禁配');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('首任务节点', $e->getMessage());
            $this->assertStringContainsString('allowRecall', $e->getMessage());
        }
    }

    // ═══ 格 9：逐字文案自证 ═══

    /** 消息常量与 spec 06 逐字文案一致性自证（防常量被改后测试仍绿）。 */
    public function test09PinnedMessagesMatchSpec(): void
    {
        $this->assertSame("任务[%s]的后续节点已办理，不能撤回", RecallSupport::MSG_WINDOW);
        $this->assertSame("节点[%s]未开启 allowRecall，不能撤回", RecallSupport::MSG_SWITCH_OFF);
        $this->assertSame('任务非已完成状态，不可撤回', RecallSupport::MSG_NOT_FINISHED);
        $this->assertSame('无权限撤回该任务', RecallSupport::MSG_NO_PERMISSION);
        $this->assertSame('流程实例非进行中状态，不可撤回', RecallSupport::MSG_INSTANCE_NOT_DOING);
        // 两条解析期禁配文案同样逐字钉住（规范 02 §4.4）
        $this->assertSame("会签节点[%s]不允许配置 allowRecall：撤\"谁的一票\"语义不清",
            RecallSupport::MSG_COUNTERSIGN_FORBID);
        $this->assertSame('首任务节点[%s]不允许配置 allowRecall：申请节点走实例撤回＋重新发起，不需要同实例复活',
            RecallSupport::MSG_FIRST_TASK_FORBID);
    }

    // ═══ 格 10：码 10 与码 3 的 fire 面（规范 11 码表在 PHP 侧的落点）═══

    /**
     * 撤回落库后 fire **码 10 TASK_RECALL 一次**（sourceId＝被撤的旧行），
     * 复活行自己的待办**另走码 3**（java 14674d7 补的那三行）；且**不补发 5/6**。
     */
    public function test10FiresTaskRecallOnceAndResurrectedRowFiresTaskStart(): void
    {
        $l = new RecordingCustomListener();
        ProcessEventListenerRegistry::register($l);

        $iid = $this->startToReview(['allowRecall' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $l->events = [];   // 只判这次撤回动作 fire 的东西

        $resurrected = $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');

        $names = array_map(fn(ProcessEvent $e) => $e->getType()->name . '|' . (string) $e->getSourceId(), $l->events);
        $this->assertContains('TASK_RECALL|' . (string) $oldReview->getTaskId(), $names,
            '码 10 fire 一次，sourceId＝被撤回的旧行（规范 11 码 10 触发时机列）');
        $this->assertContains('PROCESS_TASK_START|' . (string) $resurrected->getTaskId(), $names,
            '复活行的待办另走码 3（java 14674d7 补的那三行，本栈同腿）');
        $this->assertCount(1, array_filter($l->events,
            fn(ProcessEvent $e) => $e->getType() === ProcessEventTypeEnum::TASK_RECALL),
            '一次撤回只发一次码 10');
        $this->assertSame([], array_values(array_filter($l->events,
            fn(ProcessEvent $e) => $e->getType() === ProcessEventTypeEnum::TASK_COMPLETE
                || $e->getType() === ProcessEventTypeEnum::TASK_REJECT)),
            '撤回不是办结也不是拒绝 ⇒ **只发 10，不补发 5/6**（规范 11 码 10 行逐字）');
    }

    // ═══ 格 11：并发只放行一次（PHP 形状的并发档）═══

    /**
     * 「并发只放行一次」在 PHP 的形状与 java 不同，是**语言运行时差异**而不是判据差异：
     * java 用两条线程＋CountDownLatch 真并发；PHP 没有线程，本栈口径一直是
     * 「把并发落成串行重放＋重复提交」（同 {@see AddSignAfterTest::test14()} 的姿势）。
     * 判据仍打在两件事上：① 撤回只有一次成功，第二次的目标行已不是唯一待办；
     * ② 复活行只有一条 ⇒ 下游 final 只可能来自这一条，重复撤不产生第二条复活行。
     */
    public function test11ConcurrentRecallAdmitsExactlyOnce(): void
    {
        $iid = $this->startToReview(['allowRecall' => true]);
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $oldReview = $this->finishedRow($iid, 'review');
        $this->assertNotNull($oldReview);
        $first = $this->engine->recallTask((string) $oldReview->getTaskId(), 'userA');

        // 同一目标行再撤一次：复活行已是本节点唯一 DOING，旧行已带账本，但血缘判据仍允许——
        // 因此这一档的"只放行一次"打在**复活行**上：它没办结，撤不动。
        try {
            $this->engine->recallTask((string) $first->getTaskId(), 'userA');
            $this->fail('复活行未办结，第二次撤回必须被非已完成档拦下');
        } catch (JeeflowException $e) {
            $this->assertSame('任务非已完成状态，不可撤回', $e->getMessage());
        }
        $this->assertSame(1, $this->doingCount($iid, 'review'), '本节点始终只有一条活待办');
        $this->assertSame(1, $this->rows($iid, 'final'),
            '下游 final 只有一条（停在 99 的那条）——重复撤没多放行一次');
        $this->assertSame(1, count($this->recallLedger($oldReview) ?? []), '账本只追加一条');

        // 办结复活行后下游照常只落一行
        $this->engine->executeProcessTask((string) $first->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $this->assertSame(1, $this->doingCount($iid, 'final'));
        $this->assertSame(2, $this->rows($iid, 'final'));
    }

    // ═══ 格 12：成员行识别的承重判据一（撤回侧的对应面）═══

    /**
     * 第二次撤回不得把**主办推进后的下游行**误当成员/后代误废弃——
     * 这是 java 照出来的**承重判据一**在撤回侧的可观测面：
     * 复活行的血缘 parent 与原行相同，第二次撤回废弃的只应是"复活行之后"的行，
     * 不能顺着旧行的血缘把已经被撤成 99 的行再撤一遍，也不能漏撤新支。
     */
    public function test12SecondRecallDoesNotReabandonOrMissRows(): void
    {
        $iid = $this->startToReview(['allowRecall' => true], $this->chainDeep(['allowRecall' => true]));
        $review = $this->doing($iid, 'review');
        $this->assertNotNull($review);
        $this->engine->executeProcessTask((string) $review->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $firstOld = $this->finishedRow($iid, 'review');
        $this->assertNotNull($firstOld);
        $firstBack = $this->engine->recallTask((string) $firstOld->getTaskId(), 'userA');
        // 第一次撤回后：final 停在 99，pay 没被建出来（下游被清场）
        $this->assertSame(0, $this->doingCount($iid, 'pay'));
        $this->assertSame(1, $this->rows($iid, 'final'), '99 那条仍在，不是被改写掉');

        // 复活行重办 → 又推进出 final/pay
        $this->engine->executeProcessTask((string) $firstBack->getTaskId(), 'userA', $this->plain(SubmitType::AGREE));
        $secondOld = $this->lastFinishedRow($iid, 'review');
        $this->assertNotNull($secondOld);
        $this->assertNotSame((string) $firstOld->getTaskId(), (string) $secondOld->getTaskId(),
            '第二次撤的是**复活并重新办结**的那一条，不是最早那条历史行');
        $newFinal = $this->doing($iid, 'final');
        $this->assertNotNull($newFinal);

        $secondBack = $this->engine->recallTask((string) $secondOld->getTaskId(), 'userA');
        $this->assertTrue($secondBack->isDoing());
        $this->assertSame(0, $this->doingCount($iid, 'final'),
            '只废弃本次撤回之后的那条 final（第一条早已是 99）');
        $this->assertSame(2, $this->rows($iid, 'final'), '两条 final 行都在（一条 99、一条本轮 99）');
        $this->assertCount(1, $this->recallLedger($firstOld) ?? [], '最早那条历史行的账本不被第二次撤回污染');
        $this->assertCount(1, $this->recallLedger($secondOld) ?? [], '第二次撤回的账本落在它自己那一行');
        $this->assertSame(1, $this->doingCount($iid, 'review'));
    }
}
