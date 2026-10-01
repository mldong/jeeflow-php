<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 门面第 **47** 个 action `processTask/removeTaskActor`（issues/115 残留 · PHP 腿，八栈同批）。
 * 逐字判据依据＝spec 06-facade.md **§processTask/removeTaskActor**（七条语义＋守卫次序）与 §2.11
 * （归属值归一单点）。Java 基准腿＝`RemoveTaskActorActionTest`（17 格），本文件按同等判据复刻。
 *
 * 门面级判据 ⇒ 放 web-contract 套件（与 `JeeflowFacadeTransferTest` 同层；仓储写侧归一那两层
 * 另有 `tests/Core/TaskActorBlankDroppedTest.php` 与 `tests/RepositoryPDO/PdoSqliteTransferTest.php`
 * 各自守着，本文件不重复下探）。
 *
 * 三个兄弟 action 的分工是判据主线：`surrogate`/`addCandidate` 只加、`transfer` 换人＋留痕、
 * 本 action **只摘不加零留痕**（不写变量、不覆写任务 actor_id/operator 列、**不 fire 事件**——
 * issues/132 §11.3 定稿事件集没有"摘人"码）。每条负向都同时断言"参与者一动不动"：
 * 摘人是删除操作，报错却删了一半比报错更糟。
 *
 * {@see DirtyRowSpyRepo} 对应门禁新格「带空格入参删得掉真人 ∧ 空值不误删 actor_id='' 脏行 ∧
 * 喂进仓储的实参是行上的原值」：内存仓写侧（`addTaskActor`）过 `CcActorUtil::normalizeList`
 * 归一，正常路径**建不出**空串行，故脏行只能从外部塞进来；spy 同时复刻
 * `DELETE ... WHERE actor_id IN (...)` 的逐字语义（按实参**原值**精确命中，绝不 trim 再比——
 * 这正是 PDO 仓 `actor_id = ?` 那一腿的比较方式）并记录每次喂进删除的实参。
 */
class RemoveTaskActor115Test extends TestCase
{
    private DirtyRowSpyRepo $repo;
    private JeeflowFacade $facade;
    private RemoveActorRecordingListener $listener;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        $this->repo = new DirtyRowSpyRepo();
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        // 全码表录音：语义 2「不 fire 事件」的判据要能看见**任何**一条事件，不能只盯码 7
        $this->listener = new RemoveActorRecordingListener();
        ProcessEventListenerRegistry::register($this->listener);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
    }

    // ── 夹具 ──

    /** 起一单 01-simple（startAndExecute 自动办掉 apply）⇒ 停在 task1，参与者＝leader */
    private function startTask(): string
    {
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $this->repo->findDoingTasks($start['data']['processInstanceId']);
        $this->assertCount(1, $doing, '前置：实例应停在一个进行中任务');
        return $doing[0]->getTaskId();
    }

    /** 加签成人手（用兄弟 action 造多参与者现场，不直接塞仓储） */
    private function addActors(string $taskId, string ...$actors): void
    {
        $r = $this->facade->flow('processTask/addCandidate', ['processTaskId' => $taskId, 'actorIds' => $actors]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    /** 办结 task1 ⇒ 该任务离开 DOING（历史任务那一档的夹具） */
    private function finish(string $taskId): void
    {
        $r = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    /** @param string[]|string|null $actorIds */
    private function remove(mixed $taskId, mixed $actorIds, mixed $operator): array
    {
        return $this->facade->flow('processTask/removeTaskActor', [
            'processTaskId' => $taskId, 'actorIds' => $actorIds, 'operator' => $operator,
        ]);
    }

    /** 参与者取证走仓储而不是返回值——判据必须打在"落库的值"上 */
    private function actors(string $taskId): array
    {
        return $this->repo->findRealActors($taskId);
    }

    private function assertOk(array $resp): void
    {
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
    }

    // ═══ 语义 1「只摘不加」＋ 正向核心 ═══

    public function testRemovesOnlyTheNamedActorAndKeepsTheRest(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001', '9002');
        $this->assertSame(['leader', '9001', '9002'], $this->actors($taskId));

        $resp = $this->remove($taskId, ['9001'], 'flow.admin');

        $this->assertOk($resp);
        $this->assertSame('成功', $resp['msg'], '成功信封 msg 逐字');
        $this->assertNull($resp['data'], 'data → null（spec 同节：前端消费面不读 data）');
        $this->assertSame(['leader', '9002'], $this->actors($taskId),
            '只删点名的 9001，其余参与人原样保留（含顺序）');
    }

    /** 多支一起摘（集合语义，不是"一次只能摘一个人"） */
    public function testRemovesSeveralActorsInOneCall(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001', '9002', '9003');

        $this->assertOk($this->remove($taskId, ['9001', '9002'], 'flow.admin'));

        $this->assertSame(['leader', '9003'], $this->actors($taskId));
    }

    /** 逗号串腿与数组腿同判据（§2.11 第 1 行"两形一把尺子"，摘人腿不得另抄一份） */
    public function testCommaStringShapeRemovesTheSamePeople(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001', '9002');

        $this->assertOk($this->remove($taskId, '9001, 9002 ', 'flow.admin'));

        $this->assertSame(['leader'], $this->actors($taskId), '逗号串带空格照样命中');
    }

    // ═══ 语义 3「归属判据同 transfer」：只能摘自己那一票，auto/admin 例外 ═══

    /** 本人摘自己的那一票：无需特权 */
    public function testSelfRemovalNeedsNoPrivilege(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001');

        $this->assertOk($this->remove($taskId, ['9001'], '9001'));

        $this->assertSame(['leader'], $this->actors($taskId));
    }

    /** 借道摘他人必须拦下（transfer 能"摘 A 加 B"是因为 A＝操作人本人，本 action 同理） */
    public function testRemovingSomeoneElseWithoutPrivilegeIsRejected(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001');

        $resp = $this->remove($taskId, ['9001'], 'leader');

        $this->assertSame(99999999, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame('无权限摘除该任务参与人', $resp['msg']);
        $this->assertSame(['leader', '9001'], $this->actors($taskId), '报错后一条都不许删');
    }

    /** flow.auto 与 flow.admin 同档放行（isPrivilegedOperator 既有口径，strcasecmp 大小写不敏感） */
    public function testAutoAndAdminOperatorsAreBothPrivileged(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001', '9002');

        $this->assertOk($this->remove($taskId, ['9001'], 'flow.auto'));
        $this->assertOk($this->remove($taskId, ['9002'], 'FLOW.ADMIN'));

        $this->assertSame(['leader'], $this->actors($taskId));
    }

    // ═══ 语义 5「不得摘空」：判据是集合差，不是入参条数 ═══

    public function testNeverEmptiesTheTask(): void
    {
        $taskId = $this->startTask();
        $this->assertSame(['leader'], $this->actors($taskId));

        $resp = $this->remove($taskId, ['leader'], 'leader');

        $this->assertSame('至少需保留一名参与人', $resp['msg'], '摘空会造出无人可办又无法重派的死单');
        $this->assertSame(['leader'], $this->actors($taskId), '人还在');
    }

    /**
     * 绕过档：actorIds 里混进非参与者 id，"入参条数 < 参与人数"这种判据会放过去，
     * 集合差判据必须照样拦下（spec 语义 5 的第二句）。
     */
    public function testMixedNonParticipantIdCannotBypassTheKeepOneFloor(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001');

        $resp = $this->remove($taskId, ['leader', '9001', 'ghost'], 'leader');

        $this->assertSame('至少需保留一名参与人', $resp['msg']);
        $this->assertSame(['leader', '9001'], $this->actors($taskId));
    }

    // ═══ 语义 4「只作用于进行中任务」：历史参与人行是审批链的取证依据 ═══

    public function testFinishedTaskIsProtected(): void
    {
        $taskId = $this->startTask();
        $this->finish($taskId);
        $this->assertSame(ProcessTaskState::FINISHED, $this->repo->findTaskById($taskId)->getTaskState(),
            '前置：任务已离开 DOING');

        $resp = $this->remove($taskId, ['leader'], 'flow.admin');

        $this->assertSame(99999999, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame('任务非进行中，不可摘除参与人', $resp['msg']);
        $this->assertSame(['leader'], $this->actors($taskId), '已办结任务的参与人行不得被改写历史');
    }

    // ═══ 语义 2「不留痕、不 fire 事件」 ═══

    public function testLeavesNoTraceAndFiresNoEvent(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001');
        // 任务行留痕列的"改前读数"：建单路径本来就会写 update_user（不是摘人写的），
        // 判据只能是"摘人这一步没动它"，不能假定它是 null。
        $before = $this->repo->findTaskById($taskId);
        $updateUserBefore = $before->getUpdateUser();
        $updateTimeBefore = $before->getUpdateTime();
        $this->listener->events = [];

        $this->assertOk($this->remove($taskId, ['9001'], 'flow.admin'));

        $this->assertSame([], $this->listener->events,
            '摘人不在 132 定稿事件集里，一律不 fire（码 7 的语义是「参与者被替换」）');
        $after = $this->repo->findTaskById($taskId);
        $vars = $after->getVariables();
        $this->assertNull($vars->get(FlowConst::SUBMIT_TYPE), '不置 submitType');
        $this->assertNull($vars->get(FlowConst::TRANSFER_HISTORY), '不写 tf_transferHistory');
        $this->assertNull($vars->get(FlowConst::TRANSFER_TO), '不写 tf_transferTo');
        $this->assertSame($updateUserBefore, $after->getUpdateUser(), '不覆写任务留痕列 update_user');
        $this->assertSame($updateTimeBefore, $after->getUpdateTime(), '不覆写任务留痕列 update_time');
        $this->assertSame(ProcessTaskState::DOING, $after->getTaskState(), '不新建任务、不改任务态');
    }

    // ═══ 语义 7「幂等」：非参与者静默忽略，重放第二次仍成功 ═══

    public function testRemovingANonParticipantIsIdempotent(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001');

        $this->assertOk($this->remove($taskId, ['9001'], 'flow.admin'));
        $this->assertSame(['leader'], $this->actors($taskId));
        // 第二次：一个都没命中 ⇒ 空操作（前端双点/集成层重放不再报错）
        $this->assertOk($this->remove($taskId, ['9001'], 'flow.admin'));
        $this->assertSame(['leader'], $this->actors($taskId));
        // 非参与者 id 单独喂进来也不报错
        $this->assertOk($this->remove($taskId, ['ghost'], 'flow.admin'));
        $this->assertSame(['leader'], $this->actors($taskId));
    }

    // ═══ 必填档与守卫次序（spec 同节钉死，八栈不接受自行排序） ═══

    /**
     * 三档逐字文案：operator 缺省/纯空白 ⇒ `operator 必填`（严禁回落 user1）；
     * 主键缺失或 actorIds 丢完为空 ⇒ 与 surrogate 同族的 `processTaskId/actorIds 缺失`；
     * 任务不存在 ⇒ `任务不存在`。
     */
    public function testMissingArmsReuseTheExistingErrorEnvelope(): void
    {
        $taskId = $this->startTask();
        $before = $this->actors($taskId);

        $cases = [
            '缺省 operator ⇒ 必填档' => [$taskId, ['leader'], null, 'operator 必填'],
            '纯空白 operator 也不给过' => [$taskId, ['leader'], '   ', 'operator 必填'],
            '主键空串 ⇒ 兄弟 action 同文案' => ['', ['9001'], 'flow.admin', 'processTaskId/actorIds 缺失'],
            '主键纯空白 ⇒ 同一档' => ['   ', ['9001'], 'flow.admin', 'processTaskId/actorIds 缺失'],
            // spec 语义 8：缺参数档收齐 缺键/空串/纯空白/0/负数 —— 0 与负数不得改口成「任务不存在」
            '主键 0 ⇒ 缺参数档（不拿 0 当 id 去查）' => ['0', ['9001'], 'flow.admin', 'processTaskId/actorIds 缺失'],
            '主键负数 ⇒ 缺参数档' => ['-1', ['9001'], 'flow.admin', 'processTaskId/actorIds 缺失'],
            'actorIds 丢完为空 ⇒ 兄弟 action 同文案' => [$taskId, ['', '  ', null], 'flow.admin', 'processTaskId/actorIds 缺失'],
            '按 processTaskId 查不到任务' => ['424242', ['9001'], 'flow.admin', '任务不存在'],
        ];
        foreach ($cases as $case => [$tid, $actorIds, $operator, $expectMsg]) {
            $resp = $this->remove($tid, $actorIds, $operator);
            $this->assertSame(99999999, $resp['code'], "{$case}: " . json_encode($resp, JSON_UNESCAPED_UNICODE));
            $this->assertSame($expectMsg, $resp['msg'], $case);
            $this->assertNull($resp['data'], "{$case} 失败不得返回 data");
        }

        $this->assertSame($before, $this->actors($taskId), '九个报错档一条都不许删');
    }

    /**
     * 守卫次序（spec 同节末尾那段）：`operator 必填` 排在缺参数之前——否则"参数全缺"会先报主键缺失，
     * 把鉴权缺口藏进参数报错里；权限档排在 DOING 档之前。
     */
    public function testGuardOrderIsFixedAcrossStacks(): void
    {
        $taskId = $this->startTask();

        $this->assertSame('operator 必填', $this->remove('', ['9001'], null)['msg'],
            'operator 必填排在主键缺失档之前（否则鉴权缺口会被参数报错藏起来）');

        $this->finish($taskId);   // task1 已非 DOING，operator 又不是参与者
        $this->assertSame('无权限摘除该任务参与人', $this->remove($taskId, ['leader'], 'outsider')['msg'],
            '权限档先于非进行中档（否则外人可以靠「任务已完成」探到别人的任务状态）');
    }

    // ═══ 门禁新格：带空格入参可删 ∧ 空值不误删 actor_id='' 脏行 ═══

    /**
     * `" 9001 "` 必须命中库里的人（硬要求②"落库与比较一律取 trim 后的值"）；同时喂进 DELETE 的
     * 实参永不能含空串/纯空白——历史 `actor_id=''` 脏行是 `DELETE ... actor_id IN (...)` 的受害者，
     * 判据打在实参与脏行存活两处。
     */
    public function testWhitespacePaddedIdsAreRemovedAndDirtyRowsSurvive(): void
    {
        $taskId = $this->startTask();
        $this->addActors($taskId, '9001', '9002');
        $this->repo->seedDirtyRow($taskId, '');      // 复刻历史脏行（内存仓写侧归一后建不出来）
        $this->repo->seedDirtyRow($taskId, '   ');   // 纯空白那一支也算脏行

        $this->assertOk($this->remove($taskId, [' 9001 ', '', null, '   ', '9002'], 'flow.admin'));

        $this->assertSame(['leader'], $this->actors($taskId), '带空格的入参删得掉真人，其余参与人不动');
        $this->assertSame(['', '   '], $this->repo->dirtyRemaining($taskId),
            '空串/纯空白绝不能喂进 DELETE ⇒ 历史脏行必须原样还在');
        $this->assertNotEmpty($this->repo->removeCalls, '应至少有一次删除');
        foreach ($this->repo->removeCalls as $call) {
            foreach ($call as $actor) {
                $this->assertNotSame('', trim($actor),
                    '喂给 DELETE 的实参不得含空串/纯空白: ' . json_encode($call, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    // ═══ 语义 6「匹配取归一值、DELETE 取行上的原值」＋语义 5「脏行不算一个人」 ═══

    /**
     * 库里的行是修复前落下的未 trim 原值 `" 9101 "`，入参给 `"9101"`：判据必须把它当成同一个人
     * **并真删掉**，且喂进仓储的删除值是**那一行的原值**。反面形状＝拿归一值去删：判成同一人
     * 却一条没删，门面报成功而被摘的人待办还在（**假成功**，本栈 PDO 腿 `actor_id = ?` 精确比较，
     * 归一值打不中原值）。
     */
    public function testUntrimmedHistoricalRowIsMatchedAndDeletedByRowValue(): void
    {
        $taskId = $this->startTask();
        $this->repo->seedDirtyRow($taskId, ' 9101 ');

        $this->assertOk($this->remove($taskId, ['9101'], 'flow.admin'));

        $this->assertSame(['leader'], $this->actors($taskId), '未 trim 的历史行被归一匹配命中并删除，真人不受牵连');
        $this->assertSame([], $this->repo->dirtyRemaining($taskId), '脏行清单里那一行确实没了');
        $last = $this->repo->removeCalls[array_key_last($this->repo->removeCalls)];
        $this->assertSame([' 9101 '], $last,
            'DELETE 的实参是行上的原值，不是归一后的值（否则本栈删除腿一条都删不掉）');
    }

    /**
     * "至少剩一人"的下限按**能办单的人数**算：库里只剩 `actor_id=''` 脏行时，摘走最后一个真人必须
     * 报错——脏行谁也办不了，拿它撑住下限等于让"摘空"伪装成成功。
     */
    public function testDirtyRowsDoNotPropUpTheKeepOneFloor(): void
    {
        $taskId = $this->startTask();
        $this->repo->seedDirtyRow($taskId, '');
        $this->repo->seedDirtyRow($taskId, '   ');

        $resp = $this->remove($taskId, ['leader'], 'flow.admin');

        $this->assertSame('至少需保留一名参与人', $resp['msg'], '脏行不算一个人');
        $this->assertSame(['leader'], $this->actors($taskId), '报错后真人那行还在');
        $this->assertSame([], $this->repo->removeCalls, '报错后一次删除都不许发生');
    }

    // ═══ 兄弟 action 回归 ═══

    public function testSiblingActionsKeepTheirOwnSemantics(): void
    {
        $taskId = $this->startTask();

        $this->assertOk($this->facade->flow('processTask/surrogate',
            ['processTaskId' => $taskId, 'actorIds' => ['9101']]));
        $this->assertSame(['leader', '9101'], $this->actors($taskId), 'surrogate 仍旧只加不摘');

        $this->assertOk($this->remove($taskId, ['9101'], 'flow.admin'));
        $this->assertSame(['leader'], $this->actors($taskId), '摘人不带加人');

        $this->assertOk($this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => 'leader', 'toActor' => 'boss',
        ]));
        $this->assertSame(['boss'], $this->actors($taskId), 'transfer 换人语义不变');
        $this->assertSame(SubmitType::TRANSFER,
            $this->repo->findTaskById($taskId)->getVariables()->get(FlowConst::SUBMIT_TYPE),
            'transfer 仍写 submitType=7 留痕');
    }
}

/**
 * 参与者 spy 仓储：见类头说明。三件事——
 * ① 脏行只能从外部塞进来（内存仓 `addTaskActor` 写侧归一，正常路径造不出空串/未 trim 行）；
 * ② 读侧把真人那一半与脏行并起来返回（与 JDBC 一条裸 `SELECT` 同形，脏行本来就会被读出来）；
 * ③ 删除腿记录每一次喂进来的实参，并按 `IN (...)` 的逐字语义**按原值精确命中**删除。
 *
 * ⚠️ 内存仓 `findTaskById` 返回的是**活对象**（不克隆），故并入脏行前必须 `clone`，
 * 否则测试自己就把脏行写进库了。
 */
class DirtyRowSpyRepo extends InMemoryProcessRepository
{
    /** @var array<string, string[]> */
    private array $dirty = [];

    /** @var array<int, string[]> 每次 removeTaskActor 的实参留档（＝DELETE 的绑定值） */
    public array $removeCalls = [];

    public function seedDirtyRow(string $taskId, string $actorId): void
    {
        $this->dirty[$taskId][] = $actorId;
    }

    /** @return string[] */
    public function dirtyRemaining(string $taskId): array
    {
        return array_values($this->dirty[$taskId] ?? []);
    }

    /** 只取"真人"那一半（脏行与真行分开取证，判据才指得准是谁被删了） */
    public function findRealActors(string $taskId): array
    {
        $task = parent::findTaskById($taskId);
        return $task === null ? [] : array_values(array_map(strval(...), $task->getActorIds()));
    }

    public function findTaskById(int|string $id): ?object
    {
        $task = parent::findTaskById($id);
        if ($task === null) return null;
        $dirty = $this->dirty[(string) $id] ?? [];
        if ($dirty === []) return $task;
        $view = clone $task;
        $view->setActorIds([...$task->getActorIds(), ...$dirty]);
        return $view;
    }

    public function removeTaskActor(int|string $taskId, array $actorIds): void
    {
        $this->removeCalls[] = array_values(array_map(strval(...), $actorIds));
        $rows = $this->dirty[(string) $taskId] ?? [];
        if ($rows !== []) {
            // DELETE ... WHERE actor_id IN (...) 的逐字语义：按实参原值精确命中，绝不 trim 再比
            $bound = array_map(strval(...), $actorIds);
            $this->dirty[(string) $taskId] = array_values(array_filter(
                $rows, static fn(string $row): bool => !in_array($row, $bound, true)
            ));
        }
        parent::removeTaskActor($taskId, $actorIds);
    }
}

/** 记录所有收到的事件的假监听器（语义 2 的判据要能看见任何一条事件，不能只盯码 7） */
class RemoveActorRecordingListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
    }
}
