<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 码 2 `PROCESS_INSTANCE_END` 的**落库时机**回归（spec §11.2 原则 3／§11.3 码 2
 * 「实例 state 更新为 20/30/40/45/50/99 之一并**落库之后**」／08-compliance 场景 32）。
 *
 * 逐字移植 Java `test/InstanceEndEventTimingTest`（四档判据形状与探针仓一起搬）。
 *
 * 本案要还的债：`EndProcessHandler` 曾在 `getProcessInstance()->finish()/reject()` 之后
 * **立刻** fire——那一刻只是内存聚合根改了 state，实例那一行要等引擎 `persistTasks` 的
 * `updateInstance`（或发起路径那次）才落库。监听器（站内信反查、待办角标、persist 回写）
 * 在回调当下反查实例会读到旧 state，与 issues/121／126 两轮"回写序"同族。
 *
 * **判据形状**：recorder 在**回调那一刻**用仓储反查实例，读到的 `state` 必须已经等于
 * 事件载荷里的 `state`（fire-before-persist 的旧形状在这条断言下必红：那一刻那一行还是 10）。
 *
 * **为什么自带 {@see WriteOrderRepository} 而不是直接用兄弟测试的内存仓**：
 * `InMemoryProcessRepository::findInstanceById` 返回聚合根的**活引用**（`$this->instances[$id]`
 * 存的就是那个对象），内存里改完 state 立刻可读 ⇒ 证不了写序（`ProcessEventTest` 那套 recorder
 * 只能拿"参与者集合／cc 行数"这类独立存储的读值当证据，正因此它对本案完全失明）。
 * 真实 SQL 仓（`PdoProcessRepository`）的语义是"SELECT 出的是落库那一行的值"，
 * 故本用例的探针仓把 `findInstanceById` 改成返回**最后一次写库时刻的快照副本**，
 * 只有 `saveInstance/updateInstance` 会推进它——这才是"用仓储反查"能照出写序的形状。
 *
 * **父实例那一支同时钉住本栈与 java 同病的那个真缺陷**：子实例办结时级联把父实例推到 end，
 * 父实例**不走**子流程这次的 `updateInstance` ⇒ 修复前父实例终态只改内存，内存仓储照不出来
 * （存的是引用），SQL 仓储下那一行永远停在 10。现在 flush 对"不是本次 execution own 的实例"
 * 先补写再播，故本用例既断"两支都在"、也断"父实例那一行真的落到 20"。
 */
class InstanceEndEventTimingTest extends TestCase
{
    private const INSTANCE_START = 'PROCESS_INSTANCE_START';
    private const INSTANCE_END = 'PROCESS_INSTANCE_END';
    private const TASK_START = 'PROCESS_TASK_START';
    private const TASK_COMPLETE = 'TASK_COMPLETE';
    private const TASK_REJECT = 'TASK_REJECT';

    /** 单任务流：start → approval(leader) → end。 */
    private const ONE_TASK_FLOW = <<<'JSON'
{"name":"one-task","displayName":"终态时机流程","type":"approval","nodes":[
 {"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},
 {"id":"approval","type":"snaker:task","x":300,"y":200,"properties":{"form":"leave-form","assignee":"leader","taskType":0,"performType":0},"text":{"value":"审批"}},
 {"id":"end","type":"snaker:end","x":500,"y":200,"properties":{},"text":{"value":"结束"}}],
 "edges":[
  {"id":"e1","sourceNodeId":"start","targetNodeId":"approval","properties":{}},
  {"id":"e2","sourceNodeId":"approval","targetNodeId":"end","properties":{}}]}
JSON;

    /** 无待办短流：start → end —— 覆盖发起路径（`startProcessInstanceById`）的 flush 收口。 */
    private const DIRECT_END_FLOW = <<<'JSON'
{"name":"direct-end","displayName":"发起即办结","type":"approval","nodes":[
 {"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},
 {"id":"end","type":"snaker:end","x":300,"y":200,"properties":{},"text":{"value":"结束"}}],
 "edges":[
  {"id":"e1","sourceNodeId":"start","targetNodeId":"end","properties":{}}]}
JSON;

    /**
     * 父流程：start → approval(leader) → **subprocess** → end —— 子实例办结后级联走这一段。
     *
     * ⚠️ 节点类型这里写 `snaker:subprocess`（全小写）而不是 `docs/flow-definition.md` 表里的
     * `snaker:subProcess`：本栈 `ModelParser` 的类型表键是小写 `'subprocess'`，而查表
     * `self::$parsers[$type]` 是**大小写敏感**的 PHP 数组下标 ⇒ `snaker:subProcess` 解析不出节点
     * （连带它的出边一起丢）。那是解析器的既有形状，与本次事件腿无关，本用例不顺手改，
     * 只在注释里记账；换 java 那份逐字相同的 JSON 时只有这一个字母差别。
     */
    private const PARENT_FLOW_WITH_SUBPROCESS = <<<'JSON'
{"name":"parent-sub","displayName":"父流程(含子流程)","type":"approval","nodes":[
 {"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},
 {"id":"approval","type":"snaker:task","x":260,"y":200,"properties":{"assignee":"leader","taskType":0,"performType":0},"text":{"value":"父审批"}},
 {"id":"subprocess","type":"snaker:subprocess","x":420,"y":200,"properties":{},"text":{"value":"子流程"}},
 {"id":"end","type":"snaker:end","x":580,"y":200,"properties":{},"text":{"value":"结束"}}],
 "edges":[
  {"id":"e1","sourceNodeId":"start","targetNodeId":"approval","properties":{}},
  {"id":"e2","sourceNodeId":"approval","targetNodeId":"subprocess","properties":{}},
  {"id":"e3","sourceNodeId":"subprocess","targetNodeId":"end","properties":{}}]}
JSON;

    /** 子流程：start → childTask(leader) → end —— 它办结时把父实例一路推到 end。 */
    private const CHILD_FLOW = <<<'JSON'
{"name":"child","displayName":"子流程","type":"approval","nodes":[
 {"id":"start","type":"snaker:start","x":100,"y":200,"properties":{},"text":{"value":"开始"}},
 {"id":"childTask","type":"snaker:task","x":300,"y":200,"properties":{"assignee":"leader","taskType":0,"performType":0},"text":{"value":"子审批"}},
 {"id":"end","type":"snaker:end","x":500,"y":200,"properties":{},"text":{"value":"结束"}}],
 "edges":[
  {"id":"e1","sourceNodeId":"start","targetNodeId":"childTask","properties":{}},
  {"id":"e2","sourceNodeId":"childTask","targetNodeId":"end","properties":{}}]}
JSON;

    private WriteOrderRepository $repo;
    private JeeflowEngine $engine;
    private EndTimingListener $listener;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();

        $this->repo = new WriteOrderRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->listener = new EndTimingListener($this->repo);
        ProcessEventListenerRegistry::register($this->listener);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    private function addDefine(string $id, string $json): void
    {
        $this->repo->addDefine([
            'id' => $id,
            'name' => 'end-timing-' . $id,
            'displayName' => '实例终态时机流程',
            'type' => 'approval',
            'state' => 1,
            'content' => $json,
            'version' => 1,
        ]);
    }

    private function doingTaskId(string $instanceId, string $taskName): string
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $task) {
            if ($task->getTaskName() === $taskName) {
                return (string) $task->getTaskId();
            }
        }
        $this->fail("夹具前提：应有进行中任务 {$taskName}");
    }

    // ── 读档工具 ──

    /** @return string[] 按 fire 顺序的规范名 */
    private function names(): array
    {
        return array_map(fn(array $r) => $r['event']->getType()->name, $this->listener->records);
    }

    /**
     * 按实例筛码 2 的落档（**fire 顺序**）。
     * @return array<int, array{event: ProcessEvent, rowState: ?int, writes: int}>
     */
    private function ends(): array
    {
        return array_values(array_filter(
            $this->listener->records,
            fn(array $r) => $r['event']->getType() === ProcessEventTypeEnum::PROCESS_INSTANCE_END,
        ));
    }

    private function firstEnd(): array
    {
        $ends = $this->ends();
        $this->assertNotEmpty($ends, '应收到 PROCESS_INSTANCE_END，实际序列=' . implode(',', $this->names()));
        return $ends[0];
    }

    /**
     * 时机判据（本类的全部要点）：回调那一刻反查实例那一行的 state，必须**已经等于**
     * 载荷里承诺的 state；且那一行至少被写过一次。fire-before-persist 时读到的还是上一档
     * （10 进行中），本断言必红。
     *
     * @param array{event: ProcessEvent, rowState: ?int, writes: int} $end
     */
    private function assertEndEventFiresAfterRowWritten(array $end, string $expectedInstanceId, int $expectState): void
    {
        $this->assertSame($expectedInstanceId, $end['event']->getSourceId(), '码 2 sourceId 应为 instanceId');
        $this->assertSame($expectedInstanceId, $end['event']->datum(ProcessPublisher::KEY_INSTANCE_ID),
            '码 2 载荷 instanceId');
        $this->assertSame($expectState, $end['event']->datum(ProcessPublisher::KEY_STATE),
            '码 2 载荷 state 应为承诺的终态整数');
        $this->assertGreaterThanOrEqual(1, $end['writes'],
            '码 2 回调时该实例行必须已被写过（' . $expectedInstanceId . ' 写库次数=' . $end['writes']
                . '）——写在播之后 ⇒ 次数至少 1');
        $this->assertSame($end['event']->datum(ProcessPublisher::KEY_STATE), $end['rowState'],
            '§11.2 原则 3／场景 32：监听器回调那一刻反查实例，读到的 state 必须已等于载荷 state'
            . '（旧形状 fire 在 updateInstance 之前 ⇒ 这一刻读到的还是 10，本断言必红）');
        $this->assertSame($expectState, $end['rowState'],
            '反查值还必须是承诺的那个终态（不是恰好相等的旧值）');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 码 2 时机：正常办理路径（办结 20 / 拒绝 45）
    // ═══════════════════════════════════════════════════════════════════

    /** 办结：码 2 排在实例行 `updateInstance(20)` 之后，且名字序列不被这次改动打散。 */
    public function testFinishEndEventFiresAfterInstanceRowIsWritten(): void
    {
        $this->addDefine('101', self::ONE_TASK_FLOW);
        $instance = $this->engine->startProcessInstanceById('101', 'user1', FlowData::create());
        $instanceId = (string) $instance->getInstanceId();
        $taskId = $this->doingTaskId($instanceId, 'approval');
        $this->listener->records = [];

        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($taskId, 'leader', $args);

        $this->assertSame([self::TASK_COMPLETE, self::INSTANCE_END], $this->names(),
            '规范名序列：任务办结 → 实例终态（本次只挪码 2，不新增不丢支）');
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->rowState($instanceId),
            '实例行落库快照应为 20 已完成');
        $this->assertEndEventFiresAfterRowWritten($this->firstEnd(), $instanceId, ProcessInstanceState::FINISHED);
    }

    /** 拒绝：同一支码 2（规范名不拆，§11.6），载荷 45 ＋ 回调时那一行也已是 45。 */
    public function testRejectEndEventFiresAfterRejectedRowIsWritten(): void
    {
        $this->addDefine('101', self::ONE_TASK_FLOW);
        $instance = $this->engine->startProcessInstanceById('101', 'user1', FlowData::create());
        $instanceId = (string) $instance->getInstanceId();
        $taskId = $this->doingTaskId($instanceId, 'approval');
        $this->listener->records = [];

        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::REJECT);
        $this->engine->executeProcessTask($taskId, 'leader', $args);

        $this->assertSame([self::TASK_REJECT, self::INSTANCE_END], $this->names(),
            '拒绝这一次只发 6，随后实例终态 2');
        $this->assertSame(ProcessInstanceState::REJECTED, $this->repo->rowState($instanceId),
            '实例行落库快照应为 45 已拒绝');
        $this->assertEndEventFiresAfterRowWritten($this->firstEnd(), $instanceId, ProcessInstanceState::REJECTED);
    }

    /** 发起即办结的短流：码 2 排在发起路径那次 `updateInstance` 之后（另一个 flush 点）。 */
    public function testStartStraightToEndFiresEndAfterRowWritten(): void
    {
        $this->addDefine('102', self::DIRECT_END_FLOW);

        $instance = $this->engine->startProcessInstanceById('102', 'user1', FlowData::create());
        $instanceId = (string) $instance->getInstanceId();

        $this->assertSame([self::INSTANCE_START, self::INSTANCE_END], $this->names(),
            '短流序列：发起 → 直接终态');
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->rowState($instanceId));
        $this->assertEndEventFiresAfterRowWritten($this->firstEnd(), $instanceId, ProcessInstanceState::FINISHED);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 码 2 时机：子流程父实例路径
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 子实例办结 → 级联把父实例也推到 end ⇒ **两支**码 2，且父实例那一支同样排在
     * **父实例行落库之后**。
     *
     * 历史缺口有两处，本用例一并钉住：① 父实例的终态事件曾"顺路"在子流程级联里就地 fire，
     * 那一刻父实例的行根本没被写过（父实例不走子流程这次的 `updateInstance`，终态只改内存
     * ⇒ SQL 仓里父实例永远停在 10）；② 把 fire 简单挪到子流程的 updateInstance 之后，
     * 会把父实例这一支整丢掉。现在 flush 对"不是本次 execution own 的实例"先补写再播。
     *
     * 夹具说明：`StartSubProcessHandler` 本栈是"直接穿透"的简化实现（不创建子实例），
     * 正向"父 → 子"起不来 ⇒ 本用例按级联的反方向直造子实例
     * （`startProcessInstanceById(child, operator, args, parentId, 'subprocess')`，
     * 正是那个处理器本该做的事），要照的"子办结 → 父级联"这一段与生产同形。
     */
    public function testSubProcessParentEndEventFiresOnceAfterParentRowIsWritten(): void
    {
        $this->addDefine('103', self::PARENT_FLOW_WITH_SUBPROCESS);
        $this->addDefine('104', self::CHILD_FLOW);

        $parent = $this->engine->startProcessInstanceById('103', 'user1', FlowData::create());
        $parentId = (string) $parent->getInstanceId();
        $child = $this->engine->startProcessInstanceById('104', 'user1', FlowData::create(), $parentId, 'subprocess');
        $childId = (string) $child->getInstanceId();
        $childTaskId = $this->doingTaskId($childId, 'childTask');
        $this->listener->records = [];

        $args = FlowData::create();
        $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
        $this->engine->executeProcessTask($childTaskId, 'leader', $args);

        $this->assertSame([self::TASK_COMPLETE, self::INSTANCE_END, self::INSTANCE_END], $this->names(),
            '子办结这一支的序列：任务办结 → 子实例终态 → 父实例终态（父实例一支都不能少）');

        $ends = $this->ends();
        $this->assertCount(2, $ends, '两支码 2');
        $this->assertSame([$childId, $parentId],
            [(string) $ends[0]['event']->getSourceId(), (string) $ends[1]['event']->getSourceId()],
            '先子后父（登记顺序＝级联顺序，与修复前的 fire 顺序一致）');

        $this->assertEndEventFiresAfterRowWritten($ends[0], $childId, ProcessInstanceState::FINISHED);
        $this->assertEndEventFiresAfterRowWritten($ends[1], $parentId, ProcessInstanceState::FINISHED);

        // 父实例的终态不再"只改内存"：行真的落到 20（此前 SQL 语义下永远停在 10）
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->rowState($parentId),
            '父实例行落库快照应为 20');
        $reloadedParent = $this->repo->findInstanceById($parentId);
        $this->assertNotNull($reloadedParent);
        $this->assertSame(ProcessInstanceState::FINISHED, $reloadedParent->getState(), '重查父实例也应是 20');

        $this->assertSame(1, $this->countEndsFor($ends, $childId), '子实例一支只 fire 一次，不重复播');
        $this->assertSame(1, $this->countEndsFor($ends, $parentId), '父实例一支只 fire 一次，不重复播');
    }

    /** @param array<int, array{event: ProcessEvent, rowState: ?int, writes: int}> $ends */
    private function countEndsFor(array $ends, string $instanceId): int
    {
        $n = 0;
        foreach ($ends as $r) {
            if ((string) $r['event']->getSourceId() === $instanceId) $n++;
        }
        return $n;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 写序探针仓 ＋ recorder
    // ═══════════════════════════════════════════════════════════════════
}

/**
 * 只认"写库"的仓储探针（本类判据的支点）：
 *  - `saveInstance`/`updateInstance` ⇒ 记下该行的**落库快照**并累加写次数；
 *  - `findInstanceById` ⇒ 返回**快照的副本**（真 SQL 仓的 SELECT 语义：读到的是最后一次
 *    写进去的值，改内存聚合根不会泄漏进读结果）。
 * 其余方法全部继承 `InMemoryProcessRepository`（任务行／cc 行／分页等口径不变）。
 */
final class WriteOrderRepository extends InMemoryProcessRepository
{
    /** @var array<string, ProcessInstance> 落库快照（"库里那一行"） */
    private array $rows = [];

    /** @var array<string, int> 每个实例行被写过的次数 */
    private array $instanceWrites = [];

    public function saveInstance(object $instance): void
    {
        parent::saveInstance($instance);
        $this->writeRow($instance);
    }

    public function updateInstance(object $instance): void
    {
        parent::updateInstance($instance);
        $this->writeRow($instance);
    }

    public function findInstanceById(int|string $id): ?object
    {
        $row = $this->rows[(string) $id] ?? null;
        if ($row === null) {
            return null;
        }
        $detached = self::snapshotOf($row);
        // 任务行按基类口径给最新的（与真库 JOIN 出来的行为一致），实例列只认落库快照
        $live = parent::findInstanceById($id);
        $detached->setTasks($live === null ? [] : $live->getTasks());
        return $detached;
    }

    /** 直接读"库里那一行"的 state（用例侧的独立证据，不经聚合根内存值） */
    public function rowState(string $instanceId): ?int
    {
        $row = $this->rows[$instanceId] ?? null;
        return $row === null ? null : $row->getState();
    }

    public function writeCount(string $instanceId): int
    {
        return $this->instanceWrites[$instanceId] ?? 0;
    }

    private function writeRow(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        if ($instance->getInstanceId() === null) {
            return;
        }
        $id = (string) $instance->getInstanceId();
        $this->rows[$id] = self::snapshotOf($instance);
        $this->instanceWrites[$id] = ($this->instanceWrites[$id] ?? 0) + 1;
    }

    private static function snapshotOf(ProcessInstance $src): ProcessInstance
    {
        $c = new ProcessInstance();
        $c->setInstanceId($src->getInstanceId());
        $c->setParentId($src->getParentId());
        $c->setDefineId($src->getDefineId());
        $c->setState($src->getState());
        $c->setParentNodeName($src->getParentNodeName());
        $c->setBusinessNo($src->getBusinessNo());
        $c->setOperator($src->getOperator());
        $c->setExpireTime($src->getExpireTime());
        $c->setVariables($src->getVariables()->copy());
        $c->setCreateTime($src->getCreateTime());
        $c->setCreateUser($src->getCreateUser());
        $c->setUpdateTime($src->getUpdateTime());
        $c->setUpdateUser($src->getUpdateUser());
        $c->setTasks($src->getTasks());
        return $c;
    }
}

/**
 * recorder：按 fire 顺序逐条落档 ＋ **回调那一刻**的仓储读值。
 *
 * @var array<int, array{event: ProcessEvent, rowState: ?int, writes: int}> $records
 */
final class EndTimingListener implements ProcessEventListener
{
    public array $records = [];

    public function __construct(private readonly WriteOrderRepository $repo)
    {
    }

    public function onEvent(ProcessEvent $event): void
    {
        $instanceId = $event->datum(ProcessPublisher::KEY_INSTANCE_ID);
        $rowState = null;
        $writes = 0;
        if ($instanceId !== null && $instanceId !== '') {
            // 「用仓储反查实例」——探针仓这里给的是落库快照的副本（SELECT 语义），
            // 所以读到的是"这一刻那一行是什么"，不是"内存聚合根正在被改成什么"
            $row = $this->repo->findInstanceById((string) $instanceId);
            $rowState = $row?->getState();
            $writes = $this->repo->writeCount((string) $instanceId);
        }
        $this->records[] = ['event' => $event, 'rowState' => $rowState, 'writes' => $writes];
    }
}
