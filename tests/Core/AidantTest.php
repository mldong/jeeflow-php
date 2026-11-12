<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Enum\TaskType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\DefaultActionPermissionProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Support\AidantSupport;
use Jeeflow\Core\Support\CollectSupport;
use Jeeflow\Tests\Fixture\RecordingCustomListener;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 「动态协办（aidant）」验收（PHP 栈，issues/253）——逐字移植 jeeflow-java `test/AidantTest.java`（34 例）。
 *
 * 契约出处：spec 04「动态协办（aidant）」＋ spec 06 §2.7 协议键与 §processTask/aidant（协办六行逐字文案）。
 * 行为基准＝java 参考实现（issues/253 实施轮，AidantSupport / JeeflowEngineImpl / JeeflowFacade）。
 *
 * 覆盖：派生形状（task_type=1／parent＝主办行／同节点同名／performType=NORMAL／expire_time 同尺／
 * 委托并入／码 3 既有腿）、推进闸（协办行办结不驱动，execute 与 jump 两路同闸）、等待闸两档
 * （waitCompletion=true 逐字文案＋零写入／缺省 false 随时推进＋离开节点清场记账）、两跳账本存活、
 * detail 投影形状、协办行能力边界（可加/摘人、可被转办、禁递归）、负向六文案＋越权＋非 DOING、
 * 回归（会签票齐即推进不被协办挡／与征询并存两闸各数／撤回清场／transfer·surrogate·addCandidate 不变）。
 *
 * ⚠️ 夹具内联 JSON（**不碰共享 flows 目录**，与 {@see RecallTest}／{@see DynamicCountersignTest} 同款纪律）；
 * 内存替身与 setUp 形状照这两个兄弟用例——既有测试期望值一字未改（仅 OR 表条数断言按 253 +1）。
 */
final class AidantTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private InMemoryProcessExtRepository $extRepo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private RecordingCustomListener $listener;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
        $this->repo = new InMemoryProcessRepository();
        $this->extRepo = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        // 门面构造把扩展仓储桥接进 ServiceContext（委托并入测试要用；空仓对其余用例零影响）
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->extRepo);
        $this->listener = new RecordingCustomListener();
        ProcessEventListenerRegistry::register($this->listener);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    // ═══ 夹具 ═══

    /**
     * 通用五节点链：start→apply→review→final→end；reviewProps 控制 review 的 properties。
     * 覆盖片段用 array_merge（同 RecallTest：`+` 左键优先会吃掉覆盖值）。
     *
     * @param array<string,mixed> $reviewProps
     */
    private function chain(array $reviewProps): string
    {
        return $this->encode([
            'name' => 'aidant-' . (++$this->seq),
            'displayName' => '动态协办夹具',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'apply-form', 'taskType' => 0, 'performType' => 0, 'assignee' => 'applicant'],
                 'text' => ['value' => '发起申请']],
                ['id' => 'review', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => array_merge(['form' => 'form-r', 'taskType' => 0, 'performType' => 0,
                     'assignee' => 'userA'], $reviewProps),
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
        ]);
    }

    /** 并行会签链：start→apply→cs（userA,userB）→end。 */
    private function countersignChain(): string
    {
        return $this->encode([
            'name' => 'aidant-cs-' . (++$this->seq),
            'displayName' => '协办×会签夹具',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'apply-form', 'taskType' => 0, 'performType' => 0, 'assignee' => 'applicant'],
                 'text' => ['value' => '发起申请']],
                ['id' => 'cs', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'form-c', 'taskType' => 0, 'performType' => 1,
                     'assignee' => 'userA,userB', 'countersignType' => 'PARALLEL'],
                 'text' => ['value' => '会签审批']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                $this->edge('e1', 'start', 'apply'),
                $this->edge('e2', 'apply', 'cs'),
                $this->edge('e3', 'cs', 'end'),
            ],
        ]);
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
        $defineId = 'ad-' . $this->seq;
        $decoded = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => $decoded['name'] ?? $defineId,
            'displayName' => $decoded['displayName'] ?? '动态协办夹具',
            'type' => 'approval', 'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }

    /** 发起并办结申请节点，返回实例 id（停在第一个审批节点）。 */
    private function start(string $flowJson): string
    {
        $defineId = $this->register($flowJson);
        $inst = $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::create());
        $iid = (string) $inst->getInstanceId();
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant', $this->plain(SubmitType::APPLY));
        }
        return $iid;
    }

    /** @param array<string,mixed> $reviewProps */
    private function startToReview(array $reviewProps): string
    {
        return $this->start($this->chain($reviewProps));
    }

    // ═══ 驱动助手 ═══

    /**
     * 经门面派生协办。actorIds 收串/数组/null（null＝不传键）；wait 收 bool/null（null＝缺省 false）。
     * @return array{code:int,msg:string,data:mixed}
     */
    private function aidant(ProcessTask $host, ?string $operator, mixed $actorIds, ?string $reason, ?bool $wait): array
    {
        $a = ['processTaskId' => (string) $host->getTaskId()];
        if ($operator !== null) $a['operator'] = $operator;
        if ($actorIds !== null) $a['actorIds'] = $actorIds;
        if ($reason !== null) $a['reason'] = $reason;
        if ($wait !== null) $a['waitCompletion'] = $wait;
        return $this->facade->flow('processTask/aidant', $a);
    }

    /** @return array{code:int,msg:string,data:mixed} */
    private function execute(ProcessTask $row, string $operator, int $submitType): array
    {
        return $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $row->getTaskId(),
            'operator' => $operator,
            FlowConst::SUBMIT_TYPE => $submitType,
        ]);
    }

    private function plain(int $submitType): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => $submitType]);
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

    /** @return ProcessTask[] */
    private function aidantRows(string $iid, ProcessTask $host): array
    {
        return AidantSupport::aidantRows(
            $this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $host->getTaskId()));
    }

    /** @return array<int,array<string,mixed>> */
    private function ledger(ProcessTask $row): array
    {
        return AidantSupport::historyOf($this->repo->findTaskById((string) $row->getTaskId()));
    }

    /** @param array{code:int,msg:string,data:mixed} $resp */
    private function assertMsg(array $resp, string $msg): void
    {
        $this->assertSame(99999999, $resp['code']);
        $this->assertSame($msg, $resp['msg']);
    }

    /** @return int[] 事件码序列（既有码 3/5 取证；不新增事件码） */
    private function codes(): array
    {
        return array_map(fn(ProcessEvent $e) => $e->getType()->value, $this->listener->events);
    }

    // ═══ 正向：派生形状 ═══

    /** 派生：每人一行、task_type=1、parent＝主办行、同节点同名、performType=NORMAL、主办保留 DOING、账本键序。 */
    public function testDeriveShapesSecondaryRowsAndKeepsHostDoing(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->listener->events = [];

        $resp = $this->aidant($host, 'userA', ['userX', 'userY'], '需两部门协办', null);
        $this->assertSame(0, $resp['code']);
        $this->assertNull($resp['data'], '成功 data → null（规范 06）');

        // 主办行原样保留 DOING，名册不动
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertTrue($hostRow->isDoing(), '主办行保留 DOING');
        $this->assertSame(['userA'], $hostRow->getActorIds());
        $this->assertNull($hostRow->getActorId(), '严禁覆写任务 actor_id 列（转办那条 ⚠️ 同尺）');

        $rows = $this->aidantRows($iid, $host);
        $this->assertCount(2, $rows, '多人每人一行');
        $actors = [];
        foreach ($rows as $r) {
            $this->assertTrue($r->isDoing());
            $this->assertSame(TaskType::ASSIST, $r->getTaskType());
            $this->assertSame(1, $r->getTaskType(), 'task_type=1');
            $this->assertSame((string) $host->getTaskId(), $r->getParentTaskId());
            $this->assertSame($host->getTaskName(), $r->getTaskName(), '同节点');
            $this->assertSame($host->getDisplayName(), $r->getDisplayName(), '同名');
            $this->assertSame(PerformType::NORMAL, $r->getPerformType(), 'performType=NORMAL');
            $this->assertSame('需两部门协办', $r->getVariables()->get(FlowConst::AIDANT_REASON));
            $this->assertNull($r->getVariables()->get(FlowConst::APPROVAL_COMMENT), '协办行不占 tf_approvalComment');
            $this->assertNull($r->getActorId(), '进行中任务 actor_id 列恒无值');
            $actors[] = $r->getActorIds()[0];
        }
        $this->assertSame(['userX', 'userY'], $actors);

        // 账本：一条、六键锁定键序、time 为 §2.4 字符串
        $lg = $this->ledger($host);
        $this->assertCount(1, $lg);
        $e0 = $lg[0];
        $this->assertSame(['round', 'fromActor', 'toActors', 'reason', 'time', 'operator'], array_keys($e0));
        $this->assertSame(1, $e0['round']);
        $this->assertSame('userA', $e0['fromActor']);
        $this->assertSame(['userX', 'userY'], $e0['toActors']);
        $this->assertSame('需两部门协办', $e0['reason']);
        $this->assertSame('userA', $e0['operator']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $e0['time'],
            'time 一律 yyyy-MM-dd HH:mm:ss');

        // 事件：只走既有码 3（逐协办行一次），不新增事件码
        $this->assertSame([3, 3], $this->codes());
    }

    /** reason 选填：空串兜底进账本，协办行不盖空戳。 */
    public function testReasonOptionalFallsBackToEmptyString(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertSame(0, $this->aidant($host, 'userA', 'userX', null, null)['code']);
        $this->assertSame('', $this->ledger($host)[0]['reason']);
        $this->assertNull($this->aidantRows($iid, $host)[0]->getVariables()->get(FlowConst::AIDANT_REASON));
        $this->assertNull($this->repo->findTaskById((string) $host->getTaskId())
            ->getVariables()->get(FlowConst::APPROVAL_COMMENT), '主办行也不占审批意见');
    }

    /** actorIds 逗号串与数组两形同归一（§2.11 单点）；空白元素丢弃。 */
    public function testActorIdsAcceptsCommaStringAndArray(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertSame(0, $this->aidant($host, 'userA', ' userX , userY ,', null, null)['code']);
        $this->assertCount(2, $this->aidantRows($iid, $host));
    }

    /** expire_time 同尺重算（节点表达式），委托照既有腿并入协办行名册。 */
    public function testExpireTimeRecomputedAndSurrogateMerged(): void
    {
        $this->extRepo->saveSurrogate([
            'operator' => 'userX', 'surrogate' => 'userS', 'processName' => '',
            'enabled' => 1, 'startTime' => '2020-01-01 00:00:00', 'endTime' => '2099-12-31 23:59:59',
        ]);
        $iid = $this->startToReview(['expireTime' => '2d']);
        $host = $this->doing($iid, 'review');
        $this->assertSame(0, $this->aidant($host, 'userA', 'userX', null, null)['code']);
        $child = $this->aidantRows($iid, $host)[0];
        $this->assertNotNull($child->getExpireTime(), 'expire_time 按节点表达式同尺重算');
        $this->assertGreaterThan(date('Y-m-d H:i:s', strtotime('+47 hours')), $child->getExpireTime());
        $this->assertContains('userX', $child->getActorIds(), '委托并入不摘原人');
        $this->assertContains('userS', $child->getActorIds(), '委托照既有腿并入');
    }

    // ═══ 正向：推进闸 ═══

    /** 协办行办结不产生执行对象、不跑输出变迁；主办行仍 DOING；只 fire 既有码 5。 */
    public function testAidantCompletionDoesNotDrive(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $child = $this->aidantRows($iid, $host)[0];
        $this->listener->events = [];

        $created = $this->engine->executeProcessTask((string) $child->getTaskId(), 'userX', $this->plain(SubmitType::AGREE));
        $this->assertSame([], $created, '协办办结不产生执行对象（无新任务）');
        $this->assertTrue($this->repo->findTaskById((string) $child->getTaskId())->isFinished());
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing(), '主办行保留 DOING');
        $this->assertNull($this->doing($iid, 'final'), '不跑输出变迁：下游节点无待办');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)->getState());
        $this->assertSame([5], $this->codes(), '只走既有码 5，不新增事件码');
    }

    /** 两路同闸：executeAndJumpTask（JUMP／ROLLBACK）对协办行同样不驱动。 */
    public function testAidantJumpPathAlsoGated(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', ['userX', 'userY'], null, null);
        $rows = $this->aidantRows($iid, $host);

        // JUMP 路（门面 submitType=4）
        $resp = $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $rows[0]->getTaskId(),
            'operator' => 'userX',
            FlowConst::SUBMIT_TYPE => SubmitType::JUMP,
            FlowConst::TASK_NAME => 'final',
        ]);
        $this->assertSame(0, $resp['code']);
        $this->assertTrue($this->repo->findTaskById((string) $rows[0]->getTaskId())->isFinished());
        $this->assertNull($this->doing($iid, 'final'), '跳转路同闸：不得凭空建出 final 待办');

        // ROLLBACK 路（引擎直调，nodeName=null）
        $created = $this->engine->executeAndJumpTask((string) $rows[1]->getTaskId(), 'userY',
            $this->plain(SubmitType::ROLLBACK), null);
        $this->assertSame([], $created);
        $this->assertTrue($this->repo->findTaskById((string) $rows[1]->getTaskId())->isFinished());
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing(), '主办行仍在');
        $this->assertSame(1, $this->doingCount($iid, 'review'));
    }

    /** 协办行拒绝／退回发起人同样不驱动（任何 submitType 都只办结自己那一行）。 */
    public function testAidantRejectAndReturnDoNotDrive(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', ['userX', 'userY'], null, null);
        $rows = $this->aidantRows($iid, $host);

        $this->assertSame(0, $this->execute($rows[0], 'userX', SubmitType::REJECT)['code']);
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)->getState(), '拒绝不得结束整单');
        $this->assertSame(0, $this->execute($rows[1], 'userY', SubmitType::ROLLBACK_TO_OPERATOR)['code']);
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)->getState());
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing());
        $this->assertNull($this->doing($iid, 'apply'));
    }

    /** 主办再办结照常驱动（协办已办结）。 */
    public function testHostCompletionAfterAidantDrives(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $child = $this->aidantRows($iid, $host)[0];
        $this->execute($child, 'userX', SubmitType::AGREE);
        $this->assertSame(0, $this->execute($host, 'userA', SubmitType::AGREE)['code']);
        $this->assertNotNull($this->doing($iid, 'final'), '主办办结才推进');
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isFinished());
    }

    // ═══ 正向／负向：等待闸两档 ═══

    /** waitCompletion=true：未齐 ⇒ 逐字文案＋零写入；齐了 ⇒ 放行推进。 */
    public function testWaitCompletionTrueBlocksHostUntilAidantDone(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertSame(0, $this->aidant($host, 'userA', ['userX', 'userY'], null, true)['code']);
        $rows = $this->aidantRows($iid, $host);
        $ledgerBefore = count($this->ledger($host));

        $blocked = $this->execute($host, 'userA', SubmitType::AGREE);
        $this->assertMsg($blocked, '节点[review]协办未办结（已办结 0/2），不能办理');
        // 零写入：主办仍 DOING、无下游待办、账本不增、协办行不动
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing());
        $this->assertNull($this->doing($iid, 'final'));
        $this->assertCount($ledgerBefore, $this->ledger($host));
        $this->assertCount(2, $this->aidantRows($iid, $host));
        foreach ($this->aidantRows($iid, $host) as $r) $this->assertTrue($r->isDoing());

        // 办结一条 ⇒ 计数 1/2，仍拦
        $this->execute($rows[0], 'userX', SubmitType::AGREE);
        $this->assertMsg($this->execute($host, 'userA', SubmitType::AGREE), '节点[review]协办未办结（已办结 1/2），不能办理');

        // 全办结 ⇒ 放行
        $this->execute($rows[1], 'userY', SubmitType::AGREE);
        $this->assertSame(0, $this->execute($host, 'userA', SubmitType::AGREE)['code']);
        $this->assertNotNull($this->doing($iid, 'final'));
    }

    /** 等待闸挡全部 submitType（含拒绝／退回），不只挡同意。 */
    public function testWaitGateBlocksEverySubmitType(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, true);
        foreach ([SubmitType::AGREE, SubmitType::REJECT, SubmitType::ROLLBACK_TO_OPERATOR] as $st) {
            $this->assertMsg($this->execute($host, 'userA', $st), '节点[review]协办未办结（已办结 0/1），不能办理');
            $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing());
        }
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)->getState());
    }

    /** waitCompletion 缺省 false：主办可随时推进，未办结协办行随废弃腿清场＋账本记废弃。 */
    public function testWaitDefaultFalseCleansPendingAidantOnLeave(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', ['userX', 'userY'], null, null);
        $rows = $this->aidantRows($iid, $host);
        // 只办结一条，另一条仍 DOING
        $this->execute($rows[0], 'userX', SubmitType::AGREE);

        $this->assertSame(0, $this->execute($host, 'userA', SubmitType::AGREE)['code'], '缺省 false 主办可随时推进');
        $this->assertNotNull($this->doing($iid, 'final'));
        $pending = $this->repo->findTaskById((string) $rows[1]->getTaskId());
        $this->assertSame(ProcessTaskState::ABANDON, $pending->getTaskState(), '未办结协办行清场为废弃(99)');
        $this->assertSame(ProcessTaskState::FINISHED,
            $this->repo->findTaskById((string) $rows[0]->getTaskId())->getTaskState(), '已办结那条不改写');
        // 账本记废弃：末条带 abandoned 标记、toActors＝被废弃的人
        $lg = $this->ledger($host);
        $this->assertCount(2, $lg);
        $last = $lg[1];
        $this->assertTrue($last['abandoned']);
        $this->assertSame(['userY'], $last['toActors']);
        $this->assertSame(1, $last['round'], '清场不消耗轮次');
        $this->assertSame(0, $this->doingCount($iid, 'review'), '不留孤儿待办');
    }

    /** waitCompletion=false 显式传值同缺省；字符串 "true" 两形也认。 */
    public function testWaitCompletionAcceptsStringForm(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $resp = $this->facade->flow('processTask/aidant', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
            'actorIds' => 'userX',
            'waitCompletion' => 'true',
        ]);
        $this->assertSame(0, $resp['code']);
        $this->assertMsg($this->execute($host, 'userA', SubmitType::AGREE), '节点[review]协办未办结（已办结 0/1），不能办理');
    }

    /** 拒绝／退回发起人离开节点：协办行同样清场（废弃腿覆盖）。 */
    public function testRejectAndReturnToOperatorCleanAidant(): void
    {
        $iid1 = $this->startToReview([]);
        $h1 = $this->doing($iid1, 'review');
        $this->aidant($h1, 'userA', 'userX', null, null);
        $c1 = $this->aidantRows($iid1, $h1)[0];
        $this->assertSame(0, $this->execute($h1, 'userA', SubmitType::REJECT)['code']);
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById((string) $c1->getTaskId())->getTaskState());
        $this->assertTrue($this->ledger($h1)[1]['abandoned']);

        $iid2 = $this->startToReview([]);
        $h2 = $this->doing($iid2, 'review');
        $this->aidant($h2, 'userA', 'userX', null, null);
        $c2 = $this->aidantRows($iid2, $h2)[0];
        $this->assertSame(0, $this->execute($h2, 'userA', SubmitType::ROLLBACK_TO_OPERATOR)['code']);
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById((string) $c2->getTaskId())->getTaskState());
        $this->assertNotNull($this->doing($iid2, 'apply'), '退回发起人后申请节点复活');
    }

    // ═══ 正向：账本与投影 ═══

    /** 两跳账本＝2，且主办办结后仍在（variables 合并序保证存活）。 */
    public function testTwoRoundsLedgerSurvivesHostFinish(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', '第一轮', false);
        $first = $this->aidantRows($iid, $host)[0];
        $this->execute($first, 'userX', SubmitType::AGREE);
        $this->aidant($host, 'userA', 'userY', '第二轮', false);

        $lg = $this->ledger($host);
        $this->assertCount(2, $lg, '两跳账本＝2');
        $this->assertSame(1, $lg[0]['round']);
        $this->assertSame(2, $lg[1]['round']);
        $this->assertSame('第一轮', $lg[0]['reason']);
        $this->assertSame('第二轮', $lg[1]['reason']);
        $this->assertSame(['userX'], $lg[0]['toActors']);
        $this->assertSame(['userY'], $lg[1]['toActors']);

        $second = $this->aidantRows($iid, $host)[1];
        $this->execute($second, 'userY', SubmitType::AGREE);
        $this->assertSame(0, $this->execute($host, 'userA', SubmitType::AGREE)['code']);
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isFinished());
        $this->assertCount(2, $this->ledger($host), '办结后账本仍在');
    }

    /** 投影 ext.aidant＝{active, round, total, submitted, actors, wait}，从 parent＋task_type 现算。 */
    public function testDetailProjectionShape(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', ['userX', 'userY'], '协办原因', true);
        $rows = $this->aidantRows($iid, $host);

        $detail = $this->facade->flow('processTask/detail', ['id' => (string) $host->getTaskId()]);
        $this->assertSame(0, $detail['code']);
        $p = $detail['data']['ext']['aidant'];
        $this->assertNotNull($p, '主办行输出 ext.aidant');
        $this->assertSame(['active', 'round', 'total', 'submitted', 'actors', 'wait'], array_keys($p));
        $this->assertTrue($p['active']);
        $this->assertSame(1, $p['round']);
        $this->assertSame(2, $p['total']);
        $this->assertSame(0, $p['submitted']);
        $this->assertTrue($p['wait']);
        $actors = $p['actors'];
        $this->assertCount(2, $actors);
        $this->assertSame(['actor', 'taskId', 'state'], array_keys($actors[0]));
        $this->assertSame('userX', $actors[0]['actor']);
        $this->assertSame((string) $rows[0]->getTaskId(), (string) $actors[0]['taskId']);
        $this->assertSame(ProcessTaskState::DOING, $actors[0]['state']);

        // 办结一条 ⇒ submitted 现算推进；清场行不进投影
        $this->execute($rows[0], 'userX', SubmitType::AGREE);
        $d2 = $this->facade->flow('processTask/detail', ['id' => (string) $host->getTaskId()]);
        $p2 = $d2['data']['ext']['aidant'];
        $this->assertSame(1, $p2['submitted']);
        $this->assertTrue($p2['active']);

        // 无协办行的普通任务不输出该投影
        $other = $this->startToReview([]);
        $plain = $this->doing($other, 'review');
        $d3 = $this->facade->flow('processTask/detail', ['id' => (string) $plain->getTaskId()]);
        $this->assertArrayNotHasKey('aidant', $d3['data']['ext']);
    }

    // ═══ 负向：六文案＋越权＋非 DOING ═══

    public function testNegativeActorIdsRequired(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertMsg($this->aidant($host, 'userA', null, null, null), 'actorIds 必填');
        $this->assertMsg($this->aidant($host, 'userA', [], null, null), 'actorIds 必填');
        $this->assertMsg($this->aidant($host, 'userA', '  ,  ', null, null), 'actorIds 必填');
        $this->assertCount(0, $this->aidantRows($iid, $host), '零写入');
    }

    public function testNegativeOperatorRequired(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertMsg($this->aidant($host, null, 'userX', null, null), 'operator 必填');
        $this->assertMsg($this->aidant($host, '   ', 'userX', null, null), 'operator 必填');
    }

    public function testNegativeTaskNotFound(): void
    {
        $this->startToReview([]);
        $resp = $this->facade->flow('processTask/aidant', [
            'processTaskId' => '999999', 'operator' => 'userA', 'actorIds' => 'userX',
        ]);
        $this->assertMsg($resp, '任务不存在');
    }

    public function testNegativeUnauthorizedOperator(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->assertMsg($this->aidant($host, 'userZ', 'userX', null, null), '无权限派生协办任务');
        $this->assertCount(0, $this->aidantRows($iid, $host), '零写入');
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing());
    }

    /** 名册内参与人（含加签进来的）可派生；flow.auto／flow.admin 例外放行。 */
    public function testRosterMemberAndPrivilegedAllowed(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        // 加签进来的第二参与人同样在名册内
        $this->facade->flow('processTask/addCandidate', [
            'processTaskId' => (string) $host->getTaskId(), 'actorIds' => ['userB'],
        ]);
        $this->assertSame(0, $this->aidant($host, 'userB', 'userX', null, null)['code']);
        $this->assertSame(0, $this->aidant($host, 'flow.auto', 'userY', null, null)['code']);
        $this->assertSame(0, $this->aidant($host, 'flow.admin', 'userZ', null, null)['code']);
        $this->assertCount(3, $this->aidantRows($iid, $host));
    }

    public function testNegativeHostNotDoing(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->execute($host, 'userA', SubmitType::AGREE);
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isFinished());
        $this->assertMsg($this->aidant($host, 'userA', 'userX', null, null), '任务非进行中，不可派生协办');
    }

    public function testNegativeDuplicateActor(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $this->assertMsg($this->aidant($host, 'userA', 'userX', null, null), '该人已有未办结的协办任务');
        $this->assertCount(1, $this->aidantRows($iid, $host), '零写入：不重复建行');
        $this->assertCount(1, $this->ledger($host));

        // 已办结的那位可以再派生（去重只认未办结）
        $child = $this->aidantRows($iid, $host)[0];
        $this->execute($child, 'userX', SubmitType::AGREE);
        $this->assertSame(0, $this->aidant($host, 'userA', 'userX', null, null)['code']);
        $this->assertCount(2, $this->aidantRows($iid, $host));
    }

    public function testNegativeRecursionForbidden(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $child = $this->aidantRows($iid, $host)[0];
        $this->assertMsg($this->aidant($child, 'userX', 'userY', null, null), '协办任务不能再派生协办');
        $this->assertCount(1, $this->aidantRows($iid, $host), '零写入');
        $this->assertCount(0, AidantSupport::aidantRows(
            $this->repo->findInstanceById($iid)->getTasks(), $child));
    }

    // ═══ 协办行能力边界 ═══

    /** 协办行可加/摘参与人、可被转办（走既有 actor 写腿／transfer，不造特例）。 */
    public function testAidantRowSupportsActorWritesAndTransfer(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $child = $this->aidantRows($iid, $host)[0];
        $cid = (string) $child->getTaskId();

        // 加参与人（surrogate / addCandidate 同一腿）
        $this->assertSame(0, $this->facade->flow('processTask/surrogate',
            ['processTaskId' => $cid, 'actorIds' => ['userK']])['code']);
        $this->assertContains('userK', $this->repo->findTaskById($cid)->getActorIds());
        $this->assertSame(0, $this->facade->flow('processTask/addCandidate',
            ['processTaskId' => $cid, 'actorIds' => ['userL']])['code']);
        $this->assertContains('userL', $this->repo->findTaskById($cid)->getActorIds());

        // 摘参与人（只能摘自己那一票）
        $this->assertSame(0, $this->facade->flow('processTask/removeTaskActor',
            ['processTaskId' => $cid, 'operator' => 'userK', 'actorIds' => ['userK']])['code']);
        $this->assertNotContains('userK', $this->repo->findTaskById($cid)->getActorIds());

        // 转办（摘原人＋换新参与人，同一行不新建）
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $cid, 'operator' => 'userX',
            'fromActor' => 'userX', 'toActor' => 'userT', 'reason' => '协办转办',
        ])['code']);
        $after = $this->repo->findTaskById($cid);
        $this->assertNotContains('userX', $after->getActorIds());
        $this->assertContains('userT', $after->getActorIds());
        $this->assertSame($cid, (string) $after->getTaskId(), '转办不新建任务行');
        $this->assertTrue($after->isDoing());
        $this->assertSame(TaskType::ASSIST, $after->getTaskType());
        // 转办后新办理人可办结，且仍不驱动流程
        $this->assertSame(0, $this->execute($after, 'userT', SubmitType::AGREE)['code']);
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())->isDoing());
        $this->assertNull($this->doing($iid, 'final'));
    }

    // ═══ 回归：会签合并 ═══

    /** 会签票齐即推进，不被协办行挡（合并判据显式排除 task_type=1）。 */
    public function testCountersignMergeNotBlockedByAidant(): void
    {
        $iid = $this->start($this->countersignChain());
        $members = $this->repo->findDoingTasks($iid);
        $this->assertCount(2, $members);
        $rowA = null;
        $rowB = null;
        foreach ($members as $t) {
            if (in_array('userA', $t->getActorIds(), true)) $rowA = $t;
            if (in_array('userB', $t->getActorIds(), true)) $rowB = $t;
        }
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        // 在会签成员行上派生协办（协办行同节点同名，但 task_type=1）
        $this->assertSame(0, $this->aidant($rowA, 'userA', 'userX', null, null)['code']);
        $child = AidantSupport::aidantRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $rowA->getTaskId()))[0];
        $this->assertTrue($child->isDoing());
        $this->assertSame('cs', $child->getTaskName());

        // 两票齐 ⇒ 推进（改前协办行会被当第三张票，永远合并不了）
        $this->assertSame(0, $this->execute($rowA, 'userA', SubmitType::AGREE)['code']);
        $this->assertSame(0, $this->execute($rowB, 'userB', SubmitType::AGREE)['code']);
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($iid)->getState(), '票齐即推进：实例办结');
        $this->assertSame(ProcessTaskState::ABANDON,
            $this->repo->findTaskById((string) $child->getTaskId())->getTaskState(), '协办行随离开节点清场');
        $this->assertCount(0, $this->repo->findDoingTasks($iid));
    }

    /** 会签未齐时协办行办结也不得推进（推进闸＋合并判据两头都守住）。 */
    public function testAidantOnCountersignDoesNotAdvanceEarly(): void
    {
        $iid = $this->start($this->countersignChain());
        $members = $this->repo->findDoingTasks($iid);
        $rowA = in_array('userA', $members[0]->getActorIds(), true) ? $members[0] : $members[1];
        $this->aidant($rowA, 'userA', 'userX', null, null);
        $child = AidantSupport::aidantRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $rowA->getTaskId()))[0];
        $this->execute($child, 'userX', SubmitType::AGREE);
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)->getState(), '协办办结不推进');
        $this->assertSame(2, $this->doingCount($iid, 'cs'));
    }

    // ═══ 回归：与征询并存 ═══

    /** 与征询同节点并存：两族子行靠 task_type 分辨，等待闸与收齐闸各数各的行。 */
    public function testCoexistWithCollectTwoGatesCountOwnRows(): void
    {
        $iid = $this->startToReview(['dynamicCountersign' => true]);
        $host = $this->doing($iid, 'review');
        // 征询拉一人（成员行 task_type=0）
        $this->assertSame(0, $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => ['userC'],
        ])['code']);
        // 协办派生一人（协办行 task_type=1），等待闸开
        $this->assertSame(0, $this->aidant($host, 'userA', 'userX', null, true)['code']);

        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $all = $this->repo->findInstanceById($iid)->getTasks();
        $this->assertSame(1, CollectSupport::countMembers($all, $hostRow)[0], '征询收齐闸只数成员行');
        $this->assertSame(1, AidantSupport::countAidant($all, $hostRow)[0], '协办等待闸只数协办行');
        $this->assertCount(1, CollectSupport::memberRows($all, $hostRow));
        $this->assertCount(1, AidantSupport::aidantRows($all, $hostRow));
        $this->assertFalse(CollectSupport::isMemberRow($all, AidantSupport::aidantRows($all, $hostRow)[0]),
            '协办行不是征询成员行');

        // 协办先办结 ⇒ 征询闸仍拦（各数各的行），文案是征询那一族
        $aidantRow = AidantSupport::aidantRows($all, $hostRow)[0];
        $this->execute($aidantRow, 'userX', SubmitType::AGREE);
        $this->assertMsg($this->execute($hostRow, 'userA', SubmitType::AGREE),
            '节点[review]动态会签意见未收齐（已提交 0/1），不能办理');

        // 征询成员交意见 ⇒ 两闸齐过，主办推进
        $member = CollectSupport::memberRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $host->getTaskId()))[0];
        $this->assertSame(0, $this->execute($member, 'userC', SubmitType::AGREE)['code']);
        $this->assertSame(0, $this->execute($this->repo->findTaskById((string) $host->getTaskId()), 'userA', SubmitType::AGREE)['code']);
        $this->assertNotNull($this->doing($iid, 'final'));
    }

    /** 反向：征询已收齐、协办未办结 ⇒ 报协办那一族文案（证明两闸不串味）。 */
    public function testCollectDoneButAidantPendingReportsAidantMessage(): void
    {
        $iid = $this->startToReview(['dynamicCountersign' => true]);
        $host = $this->doing($iid, 'review');
        $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => ['userC'],
        ]);
        $this->aidant($host, 'userA', 'userX', null, true);
        $member = CollectSupport::memberRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $host->getTaskId()))[0];
        $this->execute($member, 'userC', SubmitType::AGREE);
        $this->assertMsg($this->execute($this->repo->findTaskById((string) $host->getTaskId()), 'userA', SubmitType::AGREE),
            '节点[review]协办未办结（已办结 0/1），不能办理');
    }

    /** 征询节点上 waitCompletion=false：主办收齐后可推进，未办结协办行清场（加签闸不被协办行挡）。 */
    public function testCollectNodeWaitFalseNotBlockedByAidant(): void
    {
        $iid = $this->startToReview(['dynamicCountersign' => true]);
        $host = $this->doing($iid, 'review');
        $this->facade->flow('processTask/execute', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => ['userC'],
        ]);
        $this->aidant($host, 'userA', 'userX', null, false);
        $member = CollectSupport::memberRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $host->getTaskId()))[0];
        $this->execute($member, 'userC', SubmitType::AGREE);
        $this->assertSame(0, $this->execute($this->repo->findTaskById((string) $host->getTaskId()), 'userA', SubmitType::AGREE)['code'],
            '缺省不等协办：收齐即推进');
        $this->assertNotNull($this->doing($iid, 'final'));
        $aidantRow = AidantSupport::aidantRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $host->getTaskId()))[0];
        $this->assertSame(ProcessTaskState::ABANDON, $this->repo->findTaskById((string) $aidantRow->getTaskId())->getTaskState());
    }

    // ═══ 回归：撤回清场 ═══

    /** 已办撤回：下游 DOING 协办行一并废弃（血缘自然覆盖），复活行是唯一活待办。 */
    public function testRecallCleansAidantRows(): void
    {
        $iid = $this->startToReview(['allowRecall' => true]);
        $host = $this->doing($iid, 'review');
        $this->assertSame(0, $this->execute($host, 'userA', SubmitType::AGREE)['code']);
        $finalRow = $this->doing($iid, 'final');
        $this->assertNotNull($finalRow);
        // 在下游 final 行上派生协办 ⇒ 它是 review 行的血缘后代
        $this->assertSame(0, $this->aidant($finalRow, 'userF', 'userX', null, null)['code']);
        $child = AidantSupport::aidantRows($this->repo->findInstanceById($iid)->getTasks(),
            $this->repo->findTaskById((string) $finalRow->getTaskId()))[0];
        $this->assertTrue($child->isDoing());

        $resurrected = $this->engine->recallTask((string) $host->getTaskId(), 'userA');
        $this->assertTrue($resurrected->isDoing());
        $this->assertSame('review', $resurrected->getTaskName());
        $this->assertSame(ProcessTaskState::ABANDON,
            $this->repo->findTaskById((string) $child->getTaskId())->getTaskState(), '下游协办行废弃');
        $this->assertSame(ProcessTaskState::ABANDON,
            $this->repo->findTaskById((string) $finalRow->getTaskId())->getTaskState(), '下游主办行废弃');
        $this->assertCount(1, $this->repo->findDoingTasks($iid), '唯一活待办');
    }

    /** 实例撤回：DOING 协办行随整单置 WITHDRAW(30)，不留孤儿待办。 */
    public function testInstanceWithdrawCleansAidantRows(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $this->aidant($host, 'userA', 'userX', null, null);
        $child = $this->aidantRows($iid, $host)[0];
        $this->assertSame(0, $this->facade->flow('processInstance/withdraw',
            ['id' => $iid, 'operator' => 'applicant'])['code']);
        $this->assertSame(ProcessTaskState::WITHDRAW, $this->repo->findTaskById((string) $child->getTaskId())->getTaskState());
        $this->assertCount(0, $this->repo->findDoingTasks($iid));
    }

    // ═══ 回归：既有 actor 写腿不变 ═══

    /** transfer／surrogate／addCandidate 在主办行上行为不变（协办存在也不影响）。 */
    public function testTransferSurrogateAddCandidateUnchanged(): void
    {
        $iid = $this->startToReview([]);
        $host = $this->doing($iid, 'review');
        $hid = (string) $host->getTaskId();
        $this->aidant($host, 'userA', 'userX', null, null);

        // surrogate：只追加，原人保留
        $this->assertSame(0, $this->facade->flow('processTask/surrogate',
            ['processTaskId' => $hid, 'actorIds' => ['userB']])['code']);
        $this->assertSame(['userA', 'userB'], $this->repo->findTaskById($hid)->getActorIds());
        // addCandidate：同一腿
        $this->assertSame(0, $this->facade->flow('processTask/addCandidate',
            ['processTaskId' => $hid, 'actorIds' => ['userC']])['code']);
        $this->assertSame(['userA', 'userB', 'userC'], $this->repo->findTaskById($hid)->getActorIds());
        // transfer：摘原人换新参与人，同一行不新建，留痕三件照旧
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $hid, 'operator' => 'userA',
            'fromActor' => 'userA', 'toActor' => 'userD', 'reason' => '转办原因',
        ])['code']);
        $after = $this->repo->findTaskById($hid);
        $this->assertNotContains('userA', $after->getActorIds());
        $this->assertContains('userD', $after->getActorIds());
        $this->assertSame($hid, (string) $after->getTaskId());
        $this->assertSame(SubmitType::TRANSFER, $after->getVariables()->getInt(FlowConst::SUBMIT_TYPE));
        $this->assertNotNull($after->getVariables()->get(FlowConst::TRANSFER_HISTORY));
        $this->assertNull($after->getActorId(), '转办不写 actor_id 列');
        // 协办行不受主办转办影响，仍 DOING 且不驱动
        $this->assertCount(1, $this->aidantRows($iid, $after));
        $this->assertTrue($this->aidantRows($iid, $after)[0]->isDoing());
        // 新办理人办结照常推进
        $this->assertSame(0, $this->execute($after, 'userD', SubmitType::AGREE)['code']);
        $this->assertNotNull($this->doing($iid, 'final'));
    }

    /** 权限码复用 wf:processTask:execute（OR 规则，照 246/247 B 案惯例，不建按钮种子）。 */
    public function testPermissionCodeRidesExecute(): void
    {
        $provider = new DefaultActionPermissionProvider();
        $this->assertSame(['wf:processTask:execute'], $provider->permissionCodes('processTask/aidant'));
        // 哨兵：没进表的 action 仍走默认派生
        $this->assertSame(['wf:processTask:transfer'], $provider->permissionCodes('processTask/transfer'));
    }

    /** 节点级 taskType=1 仍解析期禁配（issues/252 不因本轮运行期派生而放宽）。 */
    public function testNodeLevelSecondaryStillBanned(): void
    {
        $this->expectException(\Jeeflow\Core\JeeflowException::class);
        $this->expectExceptionMessage('节点[review]不允许配置协办任务类型（taskType=1）');
        $this->startToReview(['taskType' => 1]);
    }
}