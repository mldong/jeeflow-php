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
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Support\AddSignSupport;
use Jeeflow\Core\Support\CollectSupport;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 「动态会签（征询模式 dynamicCountersign）」验收（PHP 栈，issues/247）——逐字移植
 * jeeflow-java `test/DynamicCountersignTest.java`（8 格）＋ 本栈补的六格（上限拦、互斥拦、
 * 撤回非参与者、承重判据一、废弃腿扩双开关、并发只放行一次）。
 *
 * 契约出处：spec 02 §4.4（开关与三条解析期禁配）＋ spec 04「动态会签（征询模式）」＋
 * spec 06 §2.7（投影 `ext.dynamicCountersign`）与 §processTask/revokeDynamicCountersign。
 * 行为基准＝java 参考实现 ffe2749。
 *
 * 语义一句话：主办在审批节点提交时动态拉若干人**收意见**，主办行**不办结**、闸门挡到收齐
 * （挡**全部** submitType，含驳回），唯一出口＝撤回动态会签。
 * **不新增 submitType 档、不加 DDL、不新增节点类型**——簿记全写在任务行 variable JSON 里，
 * 与后加签同键名（tf_addSignOperators / tf_addSignHistory / tf_addSignReason / addSignRound_{node}）。
 *
 * 发起／闸门／撤回在门面分流——测试经 {@see JeeflowFacade::flow()} 驱动；
 * 成员与主办办结走引擎原路径（与 java 同姿势）。夹具内联 JSON，**不碰共享 flows 目录**。
 */
final class DynamicCountersignTest extends TestCase
{
    /** 审批节点开征询的 properties 片段 */
    private const COLLECT_ON = ['dynamicCountersign' => true];

    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
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
     * ⚠️ 覆盖片段用 `array_merge` 而不是 PHP 的 `+`（同 {@see AddSignAfterTest::chainJson()}：
     * `+` 是左键优先，节点自带的 `performType => 0` 会把覆盖用的 `performType => 1` 静默吃掉）。
     *
     * @param array<string,mixed> $applyProps
     * @param array<string,mixed> $reviewProps
     */
    private function chainJson(array $applyProps, array $reviewProps): string
    {
        $json = json_encode([
            'name' => 'collect-' . (++$this->seq),
            'displayName' => '动态会签夹具',
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
        $defineId = 'dc-' . $this->seq;
        $decoded = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => $decoded['name'] ?? $defineId,
            'displayName' => $decoded['displayName'] ?? '动态会签夹具',
            'type' => 'approval', 'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }

    /** 发起并办结申请节点，返回停在 review 的实例 id。@param array<string,mixed> $reviewProps */
    private function startToReview(array $reviewProps, ?array $applyProps = null): string
    {
        $defineId = $this->register($this->chainJson($applyProps ?? ['assignee' => 'applicant'], $reviewProps));
        $inst = $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::create());
        $iid = (string) $inst->getInstanceId();
        foreach ($this->repo->findDoingTasks($iid) as $t) {
            $this->engine->executeProcessTask((string) $t->getTaskId(), 'applicant',
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));
        }
        return $iid;
    }

    /** 经门面发起动态会签。@param string[] $operators @return array{code:int,msg:string,data:mixed} */
    private function collect(ProcessTask $host, string $operator, array $operators, ?string $reason): array
    {
        $a = [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => $operator,
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::ADD_SIGN_OPERATORS => $operators,
        ];
        if ($reason !== null) {
            $a[FlowConst::ADD_SIGN_REASON] = $reason;
        }
        return $this->facade->flow('processTask/execute', $a);
    }

    /** @param array<string,mixed> $extra @return array{code:int,msg:string,data:mixed} */
    private function exec(string $taskId, string $operator, int $submitType, array $extra = []): array
    {
        return $this->facade->flow('processTask/execute', array_merge([
            'processTaskId' => $taskId,
            'operator' => $operator,
            FlowConst::SUBMIT_TYPE => $submitType,
        ], $extra));
    }

    /** @return array<string,mixed>|null */
    private function data(array $resp): ?array
    {
        return is_array($resp['data'] ?? null) ? $resp['data'] : null;
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

    private function rows(string $iid, string $name): int
    {
        return count(array_filter($this->repo->findHistoryTasks($iid),
            fn(ProcessTask $t) => $name === $t->getTaskName()));
    }

    /** @return array<int,array<string,mixed>>|null */
    private function ledger(ProcessTask $row): ?array
    {
        $v = $row->getVariables()->get(FlowConst::ADD_SIGN_HISTORY);
        return is_array($v) ? $v : null;
    }

    /** @return ProcessTask[] 主办行下的成员行（走 CollectSupport 那一条判据，与门面同源） */
    private function members(string $iid, ProcessTask $host): array
    {
        return CollectSupport::memberRows($this->repo->findInstanceById($iid)?->getTasks() ?? [], $host);
    }

    private function firstActor(ProcessTask $t): string
    {
        $ids = $t->getActorIds();
        return $ids === [] ? '' : $ids[0];
    }

    // ═══ 格 1：发起不办结主办行＋成员行三戳 ═══

    /** 发起：不办结主办行、成员行三戳齐、账本含 reason、响应 marker。 */
    public function test01CollectSubmitKeepsHostDoingAndStampsMemberRows(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $resp = $this->collect($host, 'userA', ['userX', 'userY'], '涉及地规划需两部门意见');

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $dc = $this->data($resp)['dynamicCountersign'] ?? null;
        $this->assertIsArray($dc);
        $this->assertSame(1, $dc['round']);
        $this->assertSame(2, $dc['total']);
        $this->assertSame(0, $dc['submitted']);

        // 主办行仍 DOING（征询模式的命门：带人提交**不办结**主办）
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertNotNull($hostRow);
        $this->assertTrue($hostRow->isDoing(), '主办行不得被这次提交办结');
        $this->assertNull($this->doing($iid, 'final'), '令牌不得流出本节点');

        // 成员行三戳：parent／轮次／reason
        $members = $this->members($iid, $hostRow);
        $this->assertCount(2, $members);
        foreach ($members as $m) {
            $this->assertTrue($m->isDoing());
            $this->assertSame((string) $host->getTaskId(), (string) $m->getParentTaskId(), '血缘 parent＝主办行');
            $this->assertSame(1, $m->getVariables()->getInt(FlowConst::ADD_SIGN_ROUND . '_review'), '轮次戳');
            $this->assertSame('涉及地规划需两部门意见',
                $m->getVariables()->getStr(FlowConst::ADD_SIGN_REASON), '原因戳（单行出证自足）');
            $this->assertSame('review', $m->getTaskName(), '成员行同节点同名');
        }
        $this->assertSame(['userX', 'userY'], array_map(fn($m) => $this->firstActor($m), $members));

        // 账本含 reason、只一条，落在**主办行**
        $lg = $this->ledger($hostRow);
        $this->assertNotNull($lg);
        $this->assertCount(1, $lg);
        $this->assertSame('涉及地规划需两部门意见', $lg[0]['reason']);
        $this->assertSame('userA', $lg[0]['fromActor']);
        $this->assertSame(['userX', 'userY'], $lg[0]['toActors']);
        $this->assertSame(1, $lg[0]['round']);
        $this->assertArrayNotHasKey('revoked', $lg[0], '发起条不带 revoked 标记');
    }

    // ═══ 格 2：闸门挡全部 submitType＋零写入 ═══

    /**
     * 闸门：未收齐时主办按任意 submitType 办结 ⇒ 逐字文案报错且**零写入**。
     *
     * 三面零写入断言（当前行状态不变／任务行总数不变／实例状态不变）——
     * 「判据全部排在任何状态变更之前」在本栈是硬纪律，只钉文案不算做到。
     */
    public function test02GateBlocksAllSubmitTypesWithZeroWrite(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX', 'userY'], null);
        $totalRows = count($this->repo->findHistoryTasks($iid));

        // 0 APPLY / 1 AGREE / 2 REJECT / 3 退回上一步 / 4 跳转 / 6 退回发起人 / 20 会签拒绝——全挡
        foreach ([SubmitType::AGREE, SubmitType::REJECT, SubmitType::ROLLBACK,
                     SubmitType::JUMP, SubmitType::ROLLBACK_TO_OPERATOR, SubmitType::COUNTERSIGN_DISAGREE] as $st) {
            $resp = $this->exec((string) $host->getTaskId(), 'userA', $st);
            $this->assertSame(99999999, $resp['code'], 'submitType=' . $st . ' 必须被闸门挡下');
            $this->assertSame('节点[review]动态会签意见未收齐（已提交 0/2），不能办理', $resp['msg'],
                'submitType=' . $st . ' 的文案必须逐字');
        }
        // ── 零写入三面 ──
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertNotNull($hostRow);
        $this->assertTrue($hostRow->isDoing(), '① 主办行仍是 DOING');
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)), '② 任务行总数不变');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)?->getState(),
            '③ 实例状态不变');
        // 无成员办结
        foreach ($this->members($iid, $hostRow) as $m) {
            $this->assertTrue($m->isDoing(), '成员行一条都没被办结');
        }
        $this->assertSame(3, $this->doingCount($iid, 'review'), '主办＋两成员，三条待办原样');
        $this->assertNull($this->doing($iid, 'final'), '闸门没过不得流出本节点');
    }

    // ═══ 格 3：收齐后主办拍板推进＋投影翻转 ═══

    /** 收齐后主办拍板推进；投影 active 随提交翻转为 false。 */
    public function test03HostProceedsAfterAllSubmittedWithProjection(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX', 'userY'], null);
        $members = $this->members($iid, $host);
        $this->assertCount(2, $members);
        // 逐个提交意见（意见落各自行的 tf_approvalComment）
        $i = 0;
        foreach ($members as $m) {
            $this->engine->executeProcessTask((string) $m->getTaskId(), $this->firstActor($m), FlowData::of([
                FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
                FlowConst::APPROVAL_COMMENT => '意见' . (++$i),
            ]));
        }

        // 投影：submitted=2、active=false
        $detail = $this->facade->flow('processTask/detail', ['id' => (string) $host->getTaskId()]);
        $this->assertSame(0, $detail['code'], json_encode($detail, JSON_UNESCAPED_UNICODE));
        $ext = $this->data($detail)['ext'] ?? null;
        $dc = is_array($ext) ? ($ext['dynamicCountersign'] ?? null) : null;
        $this->assertIsArray($dc, '征询主办行的 ext.dynamicCountersign 必须投影出来');
        $this->assertFalse($dc['active']);
        $this->assertSame(2, $dc['total']);
        $this->assertSame(2, $dc['submitted']);
        $this->assertSame(1, $dc['round']);
        $this->assertCount(2, $dc['actors']);
        $this->assertSame(['userX', 'userY'], array_map(fn($a) => $a['actor'], $dc['actors']));
        $this->assertSame(ProcessTaskState::FINISHED, $dc['actors'][0]['state']);

        // 主办拍板 → 推进
        $resp = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($this->doing($iid, 'final'), '收齐后主办拍板才放行出边');
        $this->assertSame(1, $this->rows($iid, 'final'));
    }

    // ═══ 格 4：成员行禁拉人／重复／给自己 ═══

    /** 成员行携带拉人参数 ⇒ 报错；重复拉同一人 ⇒ 报错；加给自己 ⇒ 报错。 */
    public function test04MemberPullDuplicateAndSelfGuards(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX'], null);
        $totalRows = count($this->repo->findHistoryTasks($iid));
        $members = $this->members($iid, $host);
        $this->assertCount(1, $members);
        $member = $members[0];

        // 成员行拉人（成员不能再拉人 ⇒ 无更深层级）
        $resp = $this->exec((string) $member->getTaskId(), 'userX', SubmitType::AGREE,
            [FlowConst::ADD_SIGN_OPERATORS => 'userZ']);
        $this->assertSame('动态会签成员任务不能发起加签', $resp['msg']);
        $this->assertTrue($this->repo->findTaskById((string) $member->getTaskId())?->isDoing(),
            '报错零写入：成员行没被这次失败的提交办结');

        // 重复拉 pending 中的人
        $resp2 = $this->collect($host, 'userA', ['userX'], null);
        $this->assertSame('操作人[userX]已在节点[review]的动态会签中', $resp2['msg']);

        // 加给自己
        $resp3 = $this->collect($host, 'userA', ['userA'], null);
        $this->assertSame('不能把任务加签给自己', $resp3['msg']);

        // 三条报错合起来仍是零写入
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)),
            '三条报错都没多建一行（成员行仍只 userX 那一条）');
        $this->assertCount(1, $this->ledger($this->repo->findTaskById((string) $host->getTaskId()) ?? $host),
            '账本也没被污染，仍是发起那一条');
    }

    // ═══ 格 5：撤回恢复闸门、已交意见保留 ═══

    /** 撤回：未答废弃、已交保留、账本记撤回、闸门恢复；重复撤回与非参与者各归各文案。 */
    public function test05RevokeRestoresGateAndKeepsSubmittedOpinions(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX', 'userY'], null);
        $members = $this->members($iid, $host);
        $this->assertCount(2, $members);
        $xRow = $this->firstActor($members[0]) === 'userX' ? $members[0] : $members[1];
        $yRow = $xRow === $members[0] ? $members[1] : $members[0];
        $this->engine->executeProcessTask((string) $xRow->getTaskId(), 'userX', FlowData::of([
            FlowConst::SUBMIT_TYPE => SubmitType::AGREE,
            FlowConst::APPROVAL_COMMENT => '甲部门意见',
        ]));

        $resp = $this->facade->flow('processTask/revokeDynamicCountersign', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
            'reason' => '部门选错重新拉',
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->data($resp)['revoked']);
        $this->assertSame(['userY'], $this->data($resp)['actors']);

        // 已交保留（FINISHED）、未答废弃（99）
        $this->assertTrue($this->repo->findTaskById((string) $xRow->getTaskId())?->isFinished(),
            '已交的意见行原样保留——撤回只清未答的');
        $this->assertSame(ProcessTaskState::ABANDON,
            $this->repo->findTaskById((string) $yRow->getTaskId())?->getTaskState());

        // 账本两条（发起＋撤回，撤回条带 revoked 标记）；**轮次不消耗**
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertNotNull($hostRow);
        $lg = $this->ledger($hostRow);
        $this->assertNotNull($lg);
        $this->assertCount(2, $lg);
        $this->assertTrue($lg[1]['revoked']);
        $this->assertSame('部门选错重新拉', $lg[1]['reason']);
        $this->assertSame(1, $lg[1]['round'], '撤回不推进轮次（lastRound 读的是发起那条的 round）');
        $this->assertSame(['userY'], $lg[1]['toActors']);
        // 撤回**不新增事件码**（规范 11 码 10 行明写）
        $this->assertArrayNotHasKey('recall', $lg[1], '征询撤回靠账本留痕，不是另一本账');

        // 闸门恢复：主办可直接拍板推进（99 行不进分母——承重判据二）
        $go = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(0, $go['code'], json_encode($go, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($this->doing($iid, 'final'));

        // 已无待答 → 逐字文案
        $again = $this->facade->flow('processTask/revokeDynamicCountersign', [
            'processTaskId' => (string) $host->getTaskId(),
            'operator' => 'userA',
        ]);
        $this->assertSame(99999999, $again['code']);
        $this->assertSame('节点[review]没有进行中的动态会签', $again['msg']);
    }

    // ═══ 格 6：多轮征询跨轮计数 ═══

    /** 多轮征询：跨轮意见互不覆盖，轮次递增，收齐判据跨轮计数。 */
    public function test06MultiRoundCollectCountsAcrossRounds(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $r1 = $this->collect($host, 'userA', ['userX'], null);
        $this->assertSame(1, ($this->data($r1)['dynamicCountersign'] ?? [])['round']);
        $xRow = $this->members($iid, $host)[0];
        $this->engine->executeProcessTask((string) $xRow->getTaskId(), 'userX',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        // 第二轮（闸门已开时仍可再拉）
        $r2 = $this->collect($host, 'userA', ['userY'], null);
        $this->assertSame(2, ($this->data($r2)['dynamicCountersign'] ?? [])['round']);

        // 跨轮收齐判据：x 已交、y 未交 ⇒ 1/2
        $resp = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame('节点[review]动态会签意见未收齐（已提交 1/2），不能办理', $resp['msg']);

        $members = $this->members($iid, $host);
        $this->assertCount(2, $members);
        foreach ($members as $m) {
            if (!$m->isDoing()) {
                continue; // 第 1 轮已提交的行不再重复办结
            }
            $this->engine->executeProcessTask((string) $m->getTaskId(), $this->firstActor($m),
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        }
        $go = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(0, $go['code'], json_encode($go, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($this->doing($iid, 'final'));
        // 跨轮不覆盖：两条意见各落各行
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertNotNull($hostRow);
        $lg = $this->ledger($hostRow);
        $this->assertNotNull($lg);
        $this->assertCount(2, $lg);
        $this->assertSame(['userX'], $lg[0]['toActors']);
        $this->assertSame(['userY'], $lg[1]['toActors']);
    }

    // ═══ 格 7：三条解析期硬校验 ═══

    /** 三条解析期硬校验：双开／会签节点／申请节点。 */
    public function test07ParseBansMutexCountersignAndApplyNodes(): void
    {
        try {
            $this->startToReview(['dynamicCountersign' => true, 'allowAddSign' => true]);
            $this->fail('应拦：双开');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('不允许同时配置 allowAddSign 与 dynamicCountersign', $e->getMessage());
            $this->assertStringContainsString('双开＝行为歧义', $e->getMessage());
        }
        try {
            $this->startToReview(['dynamicCountersign' => true, 'performType' => 1, 'countersignType' => 'PARALLEL']);
            $this->fail('应拦：会签节点禁配');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('会签节点[review]不允许配置 dynamicCountersign', $e->getMessage());
        }
        try {
            $this->startToReview(self::COLLECT_ON,
                ['assignee' => 'applicant', 'dynamicCountersign' => true]);
            $this->fail('应拦：申请节点禁配');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('申请节点[apply]不允许配置 dynamicCountersign', $e->getMessage());
        }
    }

    // ═══ 格 8：逐字文案自证 ═══

    /** 消息常量与 spec 06 逐字文案一致性自证（防常量被改后测试仍绿）。 */
    public function test08PinnedMessagesMatchSpec(): void
    {
        $this->assertSame("节点[%s]动态会签意见未收齐（已提交 %d/%d），不能办理", CollectSupport::MSG_PENDING);
        $this->assertSame("节点[%s]没有进行中的动态会签", CollectSupport::MSG_NO_PENDING);
        $this->assertSame('无权限撤回该动态会签', CollectSupport::MSG_NO_PERMISSION_REVOKE);
        $this->assertSame('动态会签成员任务不能发起加签', CollectSupport::MSG_MEMBER_PULL);
        $this->assertSame("操作人[%s]已在节点[%s]的动态会签中", CollectSupport::MSG_DUPLICATE);
        // 三条解析期禁配文案同样逐字钉住（规范 02 §4.4）
        $this->assertSame('节点[%s]不允许同时配置 allowAddSign 与 dynamicCountersign：'
            . '两档共用 tf_addSignOperators，行为靠节点属性分流，双开＝行为歧义', CollectSupport::MSG_MUTEX_FORBID);
        $this->assertSame('会签节点[%s]不允许配置 dynamicCountersign：会签池推进语义与征询挂起语义打架',
            CollectSupport::MSG_COUNTERSIGN_FORBID);
        $this->assertSame('申请节点[%s]不允许配置 dynamicCountersign：申请节点那手走实例撤回＋重新发起，不需要征询挂起',
            CollectSupport::MSG_FIRST_TASK_FORBID);
        // 复用的后加签三条文案没被改写（同一把尺子，两档共用）
        $this->assertSame('tf_addSignOperators 与 tf_nextNodeOperator 互斥，不能同时提交', AddSignSupport::MSG_MUTEX);
        $this->assertSame('不能把任务加签给自己', AddSignSupport::MSG_SELF);
        $this->assertSame("节点[%s]的加签轮次已达上限 %d，本次提交被拒绝", AddSignSupport::MSG_MAX_ROUNDS);
    }

    // ═══ 格 9（本栈补）：轮次上限拦 ═══

    /** addSignMaxRounds 与征询档同尺（复用的是同一枚上限判据）：超上限逐字文案报错且零写入。 */
    public function test09MaxRoundsBlocksWithZeroWrite(): void
    {
        $iid = $this->startToReview(['dynamicCountersign' => true, 'addSignMaxRounds' => 1]);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $r1 = $this->collect($host, 'userA', ['userX'], null);
        $this->assertSame(0, $r1['code']);
        $xRow = $this->members($iid, $host)[0];
        $this->engine->executeProcessTask((string) $xRow->getTaskId(), 'userX',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        $totalRows = count($this->repo->findHistoryTasks($iid));

        $r2 = $this->collect($host, 'userA', ['userY'], null);
        $this->assertSame(99999999, $r2['code']);
        $this->assertSame('节点[review]的加签轮次已达上限 1，本次提交被拒绝', $r2['msg']);
        // 零写入三面
        $this->assertTrue($this->repo->findTaskById((string) $host->getTaskId())?->isDoing(), '① 主办行仍是 DOING');
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)), '② 行总数不变');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($iid)?->getState(), '③ 实例不变');
        $this->assertSame(1, $this->doingCount($iid, 'review'),
            '待办只剩主办那一条——userX 那条在上面已被办结（本档先交一份意见再拉第二轮，'
            . '所以 DOING 面是 1；已完成那条由上面 findHistoryTasks 的行总数不变那条钉住）');
        $this->assertCount(1, $this->ledger($this->repo->findTaskById((string) $host->getTaskId()) ?? $host),
            '账本仍是发起那一条');
    }

    // ═══ 格 10（本栈补）：与 tf_nextNodeOperator 互斥 ═══

    /** 征询发起腿复用了后加签的互斥判据（同传＝死参数，报错不静默）。 */
    public function test10MutexWithNextNodeOperator(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $totalRows = count($this->repo->findHistoryTasks($iid));
        $resp = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE, [
            FlowConst::ADD_SIGN_OPERATORS => ['userX'],
            FlowConst::NEXT_NODE_OPERATOR => 'userZ',
        ]);
        $this->assertSame(99999999, $resp['code']);
        $this->assertSame('tf_addSignOperators 与 tf_nextNodeOperator 互斥，不能同时提交', $resp['msg']);
        // 零写入：一条成员行都没建，主办仍是唯一待办
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)));
        $this->assertSame(1, $this->doingCount($iid, 'review'));
        $this->assertNull($this->ledger($this->repo->findTaskById((string) $host->getTaskId()) ?? $host));
    }

    // ═══ 格 11（本栈补）：撤回的参与人判据 ═══

    /**
     * 撤回动态会签的参与人判断**按名册**（承重判据三）：
     * 非主办参与者 ⇒ 逐字文案；`flow.admin` 特权放行；参数档缺失各归各文案。
     */
    public function test11RevokePermissionAndParamGuards(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX'], null);
        $totalRows = count($this->repo->findHistoryTasks($iid));

        // 非主办参与者
        $noPerm = $this->facade->flow('processTask/revokeDynamicCountersign', [
            'processTaskId' => (string) $host->getTaskId(), 'operator' => 'userZ']);
        $this->assertSame(99999999, $noPerm['code']);
        $this->assertSame('无权限撤回该动态会签', $noPerm['msg']);
        // operator 硬必填（严禁回落 user1）
        $blank = $this->facade->flow('processTask/revokeDynamicCountersign',
            ['processTaskId' => (string) $host->getTaskId(), 'operator' => '  ']);
        $this->assertSame('operator 必填', $blank['msg']);
        // 主键档缺失响亮报错
        $noId = $this->facade->flow('processTask/revokeDynamicCountersign', ['operator' => 'userA']);
        $this->assertSame('processTaskId 缺失', $noId['msg']);
        $this->assertSame('任务不存在',
            $this->facade->flow('processTask/revokeDynamicCountersign',
                ['processTaskId' => '99999999', 'operator' => 'userA'])['msg']);
        // 三条报错合起来零写入
        $this->assertSame($totalRows, count($this->repo->findHistoryTasks($iid)));
        $this->assertSame(2, $this->doingCount($iid, 'review'), '主办与成员两条待办都还在');

        // flow.admin 特权放行
        $ok = $this->facade->flow('processTask/revokeDynamicCountersign', [
            'processTaskId' => (string) $host->getTaskId(), 'operator' => 'flow.admin']);
        $this->assertSame(0, $ok['code'], json_encode($ok, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->data($ok)['revoked']);
    }

    // ═══ 格 12（本栈补）：承重判据一 ═══

    /**
     * **成员行识别＝parent 且同节点同名**（承重判据一）。
     *
     * 主办办结推进后，下一节点行（final）的 parent **同样**是主办行 id（建单不变量）。
     * 只按 `task_parent_id` 认成员会把 final 误当成员——撤回/闸门都会把它算进去。
     * 本格把这条判据直接打在 {@see CollectSupport::memberRows()} 上：
     * 摘掉同名过滤 ⇒ 这里立刻变红。
     */
    public function test12MemberRowsNeedSameNodeNameNotOnlyParent(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX'], null);
        foreach ($this->members($iid, $host) as $m) {
            $this->engine->executeProcessTask((string) $m->getTaskId(), $this->firstActor($m),
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        }
        // 主办拍板 → 下游 final 行建出来，parent 就是主办行 id
        $go = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(0, $go['code'], json_encode($go, JSON_UNESCAPED_UNICODE));
        $final = $this->doing($iid, 'final');
        $this->assertNotNull($final);
        $hostRow = $this->repo->findTaskById((string) $host->getTaskId());
        $this->assertNotNull($hostRow);
        $this->assertSame((string) $hostRow->getTaskId(), (string) $final->getParentTaskId(),
            '前提：下游 final 的 parent 就是主办行 id（只认 parent 必然误判）');

        $tasks = $this->repo->findInstanceById($iid)?->getTasks() ?? [];
        $members = CollectSupport::memberRows($tasks, $hostRow);
        $this->assertCount(1, $members, '成员行只有 userX 那条，final 不算');
        $this->assertSame('review', $members[0]->getTaskName());
        $this->assertFalse(CollectSupport::isMemberRow($tasks, $final), 'final 不是成员行（同 parent 但不同节点）');
        $this->assertTrue(CollectSupport::isMemberRow($tasks, $members[0]));
        // 计数与废弃集合同样不收 final
        $this->assertSame([1, 1], CollectSupport::countMembers($tasks, $hostRow));
        $this->assertSame([], CollectSupport::pendingMembers($tasks, $hostRow));
        // 已办结的主办行再撤一次征询 ⇒ 无待答，逐字文案，且没把 final 误废弃
        $again = $this->facade->flow('processTask/revokeDynamicCountersign', [
            'processTaskId' => (string) $host->getTaskId(), 'operator' => 'userA']);
        $this->assertSame('节点[review]没有进行中的动态会签', $again['msg']);
        $this->assertTrue($this->repo->findTaskById((string) $final->getTaskId())?->isDoing(),
            '第二次撤回没把下游行误当成员废弃（本案病灶）');
    }

    // ═══ 格 13（本栈补）：废弃腿扩双开关 ═══

    /**
     * `abandonDoingOnNode` 的开关条件从 allowAddSign 扩成 **allowAddSign ∥ dynamicCountersign**
     * （issues/247：征询成员行同属本节点 DOING，退回/拒绝路径一并清场，不留孤儿待办）。
     *
     * ⚠️ 可达性说明：门面的闸门把「未收齐时的驳回」挡在外面，所以这条腿从门面走不到；
     * 本格按**引擎入口直连**驱动（集成层/第三方门面正是这条腿的真实调用方），
     * 证明扩面真的生效而不是只写了个条件。
     */
    public function test13AbandonLegCoversDynamicCountersignNodes(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX', 'userY'], null);
        $this->assertSame(3, $this->doingCount($iid, 'review'));

        $this->engine->executeAndJumpToEnd((string) $host->getTaskId(), 'userA',
            FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::REJECT]));

        $this->assertSame(0, $this->doingCount($iid, 'review'),
            '征询成员行随拒绝一并废弃——开关条件没扩到 dynamicCountersign 时这里是 2');
        $this->assertSame(ProcessInstanceState::REJECTED, $this->repo->findInstanceById($iid)?->getState());
        foreach ($this->repo->findHistoryTasks($iid) as $t) {
            if ($t->getTaskName() === 'review' && (string) $t->getTaskId() !== (string) $host->getTaskId()) {
                $this->assertSame(ProcessTaskState::ABANDON, $t->getTaskState());
            }
        }
    }

    // ═══ 格 14（本栈补）：并发只放行一次 ═══

    /**
     * 「并发只放行一次」在 PHP 的形状与 java 不同，是**语言运行时差异**而不是判据差异：
     * java 用两条线程＋CountDownLatch 真并发办结；PHP 没有线程，本栈口径一直是
     * 「把并发落成串行重放＋重复提交」（同 {@see AddSignAfterTest::test14()} 的姿势）。
     * 判据仍打在三件事上：① 两条成员待办按任意顺序办结，主办拍板只可能在**全部办结**之后成立；
     * ② 已办结的行再提交即被 `prepareExecution` 的进行中守卫拦下 ⇒ 重复提交至多一个成功；
     * ③ 后续节点只落一行。
     * 多进程跨进程锁不是本契约的承诺面（spec 04「并发与事务」如实声明为已知边界）。
     */
    public function test14ConcurrentMemberSubmitProceedsExactlyOnce(): void
    {
        $iid = $this->startToReview(self::COLLECT_ON);
        $host = $this->doing($iid, 'review');
        $this->assertNotNull($host);
        $this->collect($host, 'userA', ['userX', 'userY'], null);

        // 取快照再逐条办结：办结会改写仓储里的进行中集合，边遍历边办是并发修改
        $ids = [];
        $actors = [];
        foreach ($this->members($iid, $host) as $m) {
            $ids[] = (string) $m->getTaskId();
            $actors[] = $this->firstActor($m);
        }
        $this->assertSame(['userX', 'userY'], $actors);
        foreach ($ids as $i => $taskId) {
            $this->engine->executeProcessTask($taskId, $actors[$i],
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
        }

        // ② 重复提交至多一个成功：第一条成员行再提交即被进行中守卫拦下
        try {
            $this->engine->executeProcessTask($ids[0], $actors[0],
                FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));
            $this->fail('重复提交必须报错');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('未找到进行中的任务', $e->getMessage());
        }

        // ① 主办拍板在收齐之后才放行，且只放行一次
        $go = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(0, $go['code'], json_encode($go, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->rows($iid, 'final'), '后续节点只落一行');
        $again = $this->exec((string) $host->getTaskId(), 'userA', SubmitType::AGREE);
        $this->assertSame(99999999, $again['code'], '主办行已办结，第二次拍板必须报错');
        $this->assertSame(1, $this->rows($iid, 'final'), '重复拍板不得再放行一次');
    }
}
