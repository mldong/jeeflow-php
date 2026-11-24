<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Enum\TaskType;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\SubProcessModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Support\ReapplySupport;
use Jeeflow\Core\Support\StartAndExecuteSupport;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/257 子流程主链定向 T0（全内存，不启动服务、不连 DB、不改共享 flows）。
 */
final class SubProcessIssue257Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;
    private SubProcessRecordingListener $listener;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
        $this->listener = new SubProcessRecordingListener();
        ProcessEventListenerRegistry::register($this->listener);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    // ── 正向：父子闭环、子 apply 自办、父任务用父模型/变量落库、事件各一次 ──

    public function testParentChildClosesLoopAndChildApplyIsAutoExecutedOnce(): void
    {
        $child = $this->register($this->linear('child257', ['apply', 'childReview', 'end']));
        $parent = $this->register($this->parentFlow('parent257', [
            'processName' => 'child257', 'version' => 1, 'form' => '__wrong__',
        ], ['apply', 'sub257', 'parentAfter', 'end']));

        $parentId = $this->startAndApply($parent, 'applicant', FlowData::of([
            'f_parentOnly' => 'parent-value',
        ]));
        $childInstance = $this->onlyChild($parentId);
        $childInstance->getVariables()->set('f_childOnly', 'child-value');
        $this->repo->updateInstance($childInstance);
        $this->assertSame($parentId, $childInstance->getParentId());
        $this->assertSame('sub257', $childInstance->getParentNodeName());
        $this->assertSame((string) $child, $childInstance->getDefineId());
        $this->assertSame('applicant', $childInstance->getOperator());
        $this->assertSame(ProcessInstanceState::DOING, $childInstance->getState());

        $childApply = $this->task($childInstance->getInstanceId(), 'apply');
        $this->assertSame(ProcessTaskState::FINISHED, $childApply->getTaskState());
        $this->assertSame(FlowConst::AUTO_ID, $childApply->getActorId());
        $this->assertContains('applicant', $childApply->getActorIds());
        $this->assertSame(1, $this->countTaskStart($childInstance->getInstanceId(), 'apply'));
        $this->assertSame('childReview', $this->onlyDoing($childInstance->getInstanceId())->getTaskName());
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($parentId)->getState());

        $childReview = $this->onlyDoing($childInstance->getInstanceId());
        $this->engine->executeProcessTask($childReview->getTaskId(), 'leader', $this->plain(SubmitType::AGREE));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($childInstance->getInstanceId())->getState());
        $this->assertSame('parentAfter', $this->onlyDoing($parentId)->getTaskName());

        // 子办理参数不得覆盖父实例已有业务值；父任务 operator 取本次真实办理人。
        $parentAfter = $this->onlyDoing($parentId);
        $this->assertSame('manager', $parentAfter->getActorIds()[0]);
        $this->assertSame('parent-value', $this->repo->findInstanceById($parentId)->getVariables()->get('f_parentOnly'));
        $this->assertSame('child-value', $this->repo->findInstanceById($childInstance->getInstanceId())->getVariables()->get('f_childOnly'));
        $this->assertNull($this->repo->findInstanceById($parentId)->getVariables()->get('f_childOnly'), '子变量不得回写父聚合');
        $this->engine->executeProcessTask($parentAfter->getTaskId(), 'manager', $this->plain(SubmitType::AGREE));
        $this->assertSame(ProcessInstanceState::FINISHED, $this->repo->findInstanceById($parentId)->getState());

        foreach ([$parentId, $childInstance->getInstanceId()] as $instanceId) {
            $this->assertSame(1, $this->countEvent(ProcessEventTypeEnum::PROCESS_INSTANCE_START, $instanceId));
            $this->assertSame(1, $this->countEvent(ProcessEventTypeEnum::PROCESS_INSTANCE_END, $instanceId));
        }
    }

    public function testChildArgsAreCopiedAndControlKeysStripped(): void
    {
        $this->register($this->linear('childArgs257', ['first257', 'second257', 'end']));
        $parent = $this->register($this->parentFlow('parentArgs257', [
            'processName' => 'childArgs257', 'version' => 1,
        ], ['apply', 'sub257', 'end']));
        $parentId = $this->startAndApply($parent, 'applicant', FlowData::of([
            'f_business' => 'kept',
            'f_parentOnly' => 'parent-value',
            'submitType' => SubmitType::REJECT,
            'f_nextNodeOperator' => 'parentWrong',
            'tf_nextNodeOperator' => 'parentWrong2',
            'tf_parentOnly' => 'drop',
            ReapplySupport::VAR_BRANCH_CTX => [['root' => 'bad']],
            ReapplySupport::VAR_REAPPLY_CTX => ['sourceTaskId' => 'bad'],
        ]));
        $child = $this->onlyChild($parentId);
        $child->getVariables()->set('f_childOnly', 'child-value');
        $this->repo->updateInstance($child);
        $vars = $child->getVariables()->toArray();
        $this->assertArrayNotHasKey(FlowConst::NEXT_NODE_OPERATOR, $vars);
        $this->assertSame(SubmitType::APPLY, $vars[FlowConst::SUBMIT_TYPE], 'childArgs 先剥父 submitType；首任务自办再写 APPLY');
        $this->assertArrayNotHasKey(FlowConst::PROCESS_START_NEXT_NODE_OPERATOR, $vars);
        $this->assertArrayNotHasKey('tf_nextNodeOperator', $vars);
        $this->assertArrayNotHasKey('tf_parentOnly', $vars);
        $this->assertSame(SubmitType::APPLY, $child->getVariables()->getInt(FlowConst::SUBMIT_TYPE));
        $this->assertArrayNotHasKey(ReapplySupport::VAR_BRANCH_CTX, $vars);
        $this->assertArrayNotHasKey(ReapplySupport::VAR_REAPPLY_CTX, $vars);
        $this->assertSame('second257', $this->onlyDoing($child->getInstanceId())->getTaskName());
        $childRows = $this->repo->findHistoryTasks($child->getInstanceId());
        $first = null;
        foreach ($childRows as $row) {
            if ($row->getTaskName() === 'first257') {
                $first = $row;
                break;
            }
        }
        $this->assertNotNull($first);
        $this->assertSame(FlowConst::AUTO_ID, $first->getActorId());
        $this->assertContains('applicant', $first->getActorIds(), '无 apply 的首任务也必须追加真实发起人');
        $this->assertSame(SubmitType::APPLY, $first->getVariables()->getInt(FlowConst::SUBMIT_TYPE));
    }

    public function testAutoOperatorFallsBackToParentOperator(): void
    {
        $this->register($this->linear('childAuto257', ['apply', 'childReview', 'end']));
        $parent = $this->register($this->parentFlow('parentAuto257', [
            'processName' => 'childAuto257', 'version' => 1,
        ], ['apply', 'sub257', 'parentAfter', 'end']));
        $parentInstance = $this->engine->startProcessInstanceById($parent, 'applicant', FlowData::create());
        $parentId = (string) $parentInstance->getInstanceId();
        $apply = $this->onlyDoing($parentId);
        $this->engine->executeProcessTask($apply->getTaskId(), FlowConst::AUTO_ID, $this->plain(SubmitType::APPLY));
        $this->assertSame('applicant', $this->onlyChild($parentId)->getOperator());
    }

    // ── 解析、版本裁决、form 不参与查找 ──

    public function testParserRegistersThreeAliasesAndProcessNameFallbacks(): void
    {
        foreach (['subprocess', 'subProcess', 'wfSubProcess'] as $type) {
            $model = ModelParser::parse($this->parentFlow('alias-' . $type, [
                'processName' => 'target', 'name' => 'wrong', 'form' => '__wrong__', 'version' => 1,
            ], ['apply', 'subAlias', 'end'], $type));
            $node = $model->getNode('subAlias');
            $this->assertInstanceOf(SubProcessModel::class, $node);
            $this->assertSame('target', $node->getProcessName());
            $this->assertSame('__wrong__', $node->getForm());
            $this->assertSame(1, $node->getVersion());
        }
        $byName = ModelParser::parse($this->parentFlow('by-name', ['name' => 'target'], ['apply', 'sub1', 'end']));
        $nameNode = $byName->getNode('sub1');
        $this->assertInstanceOf(SubProcessModel::class, $nameNode);
        $this->assertSame('target', $nameNode->getProcessName());
        $byId = ModelParser::parse($this->parentFlow('by-id', [], ['apply', 'target', 'end']));
        $idNode = $byId->getNode('target');
        $this->assertInstanceOf(SubProcessModel::class, $idNode);
        $this->assertSame('target', $idNode->getProcessName());
    }

    public function testNullVersionUsesLatestVersionThenIdAndExplicitVersionIsExactWithoutStateFilter(): void
    {
        $v1 = $this->register($this->linear('versioned257', ['reviewV1', 'end']), 1, 'v1');
        $v2 = $this->register($this->linear('versioned257', ['reviewV2', 'end']), 2, 'v2-low-id');
        $v2High = $this->register($this->linear('versioned257', ['reviewV2High', 'end']), 2, 'v2-high-id');
        $this->repo->updateDefineState($v2High, 0); // 子流程不额外过滤 state

        $parentLatest = $this->register($this->parentFlow('parentLatest257', [
            'processName' => 'versioned257', 'form' => '__wrong__',
        ], ['apply', 'sub257', 'end']));
        $parentId = $this->startAndApply($parentLatest, 'applicant');
        $this->assertSame((string) $v2High, $this->onlyChild($parentId)->getDefineId());

        $parentExact = $this->register($this->parentFlow('parentExact257', [
            'processName' => 'versioned257', 'version' => 1, 'form' => '__wrong__',
        ], ['apply', 'sub257', 'end']));
        $exactId = $this->startAndApply($parentExact, 'applicant');
        $this->assertSame((string) $v1, $this->onlyChild($exactId)->getDefineId());
    }

    // ── 负向：定义缺失在父任务完成前抛 20010002，且内存零写入 ──

    public function testMissingDefinitionFailsBeforeParentTaskIsWritten(): void
    {
        $parent = $this->register($this->parentFlow('parentMissing257', [
            'processName' => 'missing257', 'version' => 1,
        ], ['apply', 'sub257', 'parentAfter', 'end']));
        $instance = $this->engine->startProcessInstanceById($parent, 'applicant', FlowData::create());
        $apply = $this->onlyDoing($instance->getInstanceId());
        $beforeTasks = count($this->repo->getAllTasks());
        $beforeInstances = count($this->repo->getAllInstances());
        $beforeEvents = count($this->listener->events);
        $applyId = (string) $apply->getTaskId();

        try {
            StartAndExecuteSupport::applyInitialTasks(
                $this->engine,
                (string) $instance->getInstanceId(),
                'applicant',
                FlowData::create(),
            );
            $this->fail('缺子流程定义必须抛错');
        } catch (JeeflowException $e) {
            $this->assertSame(20010002, $e->getCode());
            $this->assertSame('子流程missing257定义不存在', $e->getMessage());
        }
        $this->assertSame(ProcessTaskState::DOING, $this->repo->findTaskById($applyId)->getTaskState());
        $this->assertSame($beforeTasks, count($this->repo->getAllTasks()));
        $this->assertSame($beforeInstances, count($this->repo->getAllInstances()));
        $this->assertSame($beforeEvents, count($this->listener->events), '预检失败不得 fire 子实例或父任务事件');
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($instance->getInstanceId())->getState());
    }

    // ── 结束守卫与回退边界 ──

    public function testEndWithDoingMainTaskThrowsBeforeAnyWrite(): void
    {
        $childDefinition = $this->register($this->linear('childMain257', ['childMain', 'end']));
        $parent = $this->register($this->parentFlow('parentChildMain257', [
            'processName' => 'childMain257', 'version' => 1,
        ], ['apply', 'sub257', 'parentAfter', 'end']));
        $childInstance = $this->engine->startProcessInstanceById(
            $childDefinition,
            'applicant',
            FlowData::create(),
            'parent-fixture',
            'sub257',
        );
        $childInstance->setTasks([]);
        $beforeTasks = count($this->repo->getAllTasks());
        $beforeEvents = count($this->listener->events);

        try {
            // 在已完成子任务形状上补一条 DOING 主办行，验证结束处理器自身的写前闸。
            $guard = ProcessTask::create($childInstance->getInstanceId(), 'unfinishedMain', '未完成主办',
                TaskType::MAIN, PerformType::NORMAL, null, ['leader'], 'leader', '0', false);
            $this->repo->saveTask($guard);
            $beforeTasks = count($this->repo->getAllTasks());
            $beforeEvents = count($this->listener->events);
            $model = ModelParser::parse($this->repo->findDefineById($childDefinition)['content']);
            $exec = new \Jeeflow\Core\Execution();
            $exec->setEngine($this->engine);
            $exec->setProcessModel($model);
            $exec->setProcessInstance($childInstance);
            $exec->setProcessInstanceId($childInstance->getInstanceId());
            $exec->setArgs($this->plain(SubmitType::AGREE));
            $exec->setOperator('leader');
            $model->getModels(\Jeeflow\Core\Model\EndModel::class)[0]->execute($exec);
            $this->fail('有未完成主办任务时不得结束');
        } catch (JeeflowException $e) {
            $this->assertSame('存在未完成的主办任务,请确认.', $e->getMessage());
        }
        $this->assertSame($beforeTasks, count($this->repo->getAllTasks()));
        $this->assertSame($beforeEvents, count($this->listener->events));
        $this->assertSame(ProcessTaskState::DOING, $this->repo->findTaskById($guard->getTaskId())->getTaskState());
        $this->assertSame(ProcessInstanceState::DOING, $this->repo->findInstanceById($childInstance->getInstanceId())->getState());
    }

    public function testEndAutoCompletesAssistTasksBeforeChildReturnsToParent(): void
    {
        $this->register($this->linear('childAssist257', ['apply', 'childReview', 'childDone', 'end']));
        $parent = $this->register($this->parentFlow('parentAssist257', [
            'processName' => 'childAssist257', 'version' => 1,
        ], ['apply', 'sub257', 'parentAfter', 'end']));
        $parentInstance = $this->engine->startProcessInstanceById($parent, 'applicant', FlowData::create());
        $parentId = (string) $parentInstance->getInstanceId();
        $apply = $this->onlyDoing($parentId);
        $this->engine->executeProcessTask($apply->getTaskId(), FlowConst::AUTO_ID, $this->plain(SubmitType::APPLY));
        $childInstance = $this->onlyChild($parentId);
        $childDoing = $this->repo->findDoingTasks($childInstance->getInstanceId());
        $childReview = $childDoing[0];
        $this->engine->executeProcessTask($childReview->getTaskId(), 'leader', $this->plain(SubmitType::AGREE));
        $childDone = $this->repo->findDoingTasks($childInstance->getInstanceId());
        $this->assertCount(1, $childDone, 'childReview 办结后应留下协办 childDone');
        $childDone = $childDone[0];
        $childDoneId = (string) $childDone->getTaskId();
        $assist = ProcessTask::create($childInstance->getInstanceId(), 'childAssist', '子协办', TaskType::ASSIST,
            PerformType::NORMAL, null, ['helper'], 'leader', $childDoneId, false);
        $this->repo->saveTask($assist);
        $childInstance = $this->repo->findInstanceById($childInstance->getInstanceId());
        $childTasks = $childInstance->getTasks();
        $childTasks[] = $assist;
        $childInstance->setTasks($childTasks);
        $this->repo->updateInstance($childInstance);

        $this->engine->executeProcessTask($childDoneId, 'leader', $this->plain(SubmitType::AGREE));
        $assistRow = $this->repo->findTaskById($assist->getTaskId());
        $this->assertSame(ProcessTaskState::FINISHED, $assistRow->getTaskState());
        $this->assertSame(FlowConst::AUTO_ID, $assistRow->getActorId());
        $this->assertSame('parentAfter', $this->onlyDoing($parentId)->getTaskName());
    }

    public function testRejectCannotCrossSubProcessAndCountersignParentCannotReject(): void
    {
        $parent = $this->register($this->parentFlow('parentGuardOnly257', [
            'processName' => 'unused',
        ], ['apply', 'after257', 'sub257', 'end']));
        $model = ModelParser::parse($this->repo->findDefineById($parent)['content']);
        $this->assertFalse(NodeModel::canRejected($model->getNode('end'), $model->getNode('apply')));

        $counter = ModelParser::parse($this->linear('counter257', [
            'apply', ['id' => 'cs', 'props' => ['assignee' => 'leader', 'performType' => PerformType::COUNTERSIGN]],
            'after257', 'end',
        ]));
        $this->assertFalse(NodeModel::canRejected($counter->getNode('after257'), $counter->getNode('cs')));
        $this->assertTrue(NodeModel::canRejected($counter->getNode('after257'), $counter->getNode('apply')));
    }

    public function testChildTasksArePersistedAndStartedExactlyOnce(): void
    {
        $this->register($this->linear('childOnce257', ['apply', 'childReview', 'end']));
        $parent = $this->register($this->parentFlow('parentOnce257', [
            'processName' => 'childOnce257', 'version' => 1,
        ], ['apply', 'sub257', 'parentAfter', 'end']));
        $parentId = $this->startAndApply($parent, 'applicant');
        $child = $this->onlyChild($parentId);
        $childTasks = $this->repo->findHistoryTasks($child->getInstanceId());
        $this->assertCount(2, $childTasks, '子 apply + childReview 各一行，不得被父重复保存');
        foreach ($childTasks as $task) {
            $this->assertSame(1, count(array_filter(
                $this->listener->events,
                fn(ProcessEvent $e) => $e->getType() === ProcessEventTypeEnum::PROCESS_TASK_START
                    && $e->getSourceId() === $task->getTaskId(),
            )), '每个子任务码 3 恰好一次');
        }
        $this->assertSame(1, $this->countTaskStart($child->getInstanceId(), 'childReview'));
    }

    // ── 夹具与读取助手 ──

    /** @param array<int,string|array{id:string,props:array<string,mixed>}> $steps */
    private function linear(string $name, array $steps): string
    {
        $nodes = [$this->node('start', 'snaker:start', [])];
        $edges = [];
        $prev = 'start';
        foreach ($steps as $step) {
            if (is_string($step)) {
                $id = $step;
                $props = [];
            } else {
                $id = $step['id'];
                $props = $step['props'];
            }
            if ($id === 'end') {
                $nodes[] = $this->node($id, 'snaker:end', []);
            } else {
                if ($id === 'apply') {
                    $props['assignee'] = 'applicant';
                }
                $props = array_merge([
                    'taskType' => TaskType::MAIN,
                    'performType' => PerformType::NORMAL,
                    'assignee' => (str_contains($id, 'child') || $id === 'first257') ? 'leader' : 'manager',
                ], $props);
                $nodes[] = $this->node($id, 'snaker:task', $props);
            }
            $edges[] = $this->edge('e-' . $prev . '-' . $id, $prev, $id);
            $prev = $id;
        }
        return $this->encode(['name' => $name, 'displayName' => $name, 'type' => 'approval', 'nodes' => $nodes, 'edges' => $edges]);
    }

    /** @param array<string,mixed> $subProps @param array<int,string|array{id:string,props:array<string,mixed>}> $steps */
    private function parentFlow(string $name, array $subProps, array $steps, string $subType = 'snaker:subProcess'): string
    {
        $nodes = [$this->node('start', 'snaker:start', [])];
        $edges = [];
        $prev = 'start';
        foreach ($steps as $step) {
            if (is_string($step)) {
                $id = $step;
                $props = [];
            } else {
                $id = $step['id'];
                $props = $step['props'];
            }
            if ($id === 'end') {
                $nodes[] = $this->node($id, 'snaker:end', []);
            } elseif (str_starts_with($id, 'sub') || $id === 'target') {
                $nodes[] = $this->node($id, $subType, $subProps);
            } else {
                $props = array_merge([
                    'taskType' => TaskType::MAIN,
                    'performType' => PerformType::NORMAL,
                    'assignee' => $id === 'apply' ? 'applicant' : 'manager',
                ], $props);
                $nodes[] = $this->node($id, 'snaker:task', $props);
            }
            $edges[] = $this->edge('e-' . $prev . '-' . $id, $prev, $id);
            $prev = $id;
        }
        return $this->encode(['name' => $name, 'displayName' => $name, 'type' => 'approval', 'nodes' => $nodes, 'edges' => $edges]);
    }

    /** @param array<string,mixed> $props */
    private function node(string $id, string $type, array $props): array
    {
        return ['id' => $id, 'type' => $type, 'x' => 0, 'y' => 0, 'properties' => $props, 'text' => ['value' => $id]];
    }

    private function edge(string $id, string $from, string $to): array
    {
        return ['id' => $id, 'sourceNodeId' => $from, 'targetNodeId' => $to, 'properties' => []];
    }

    /** @param array<string,mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        return $json;
    }

    private function register(string $json, int $version = 1, ?string $id = null): string
    {
        $decoded = json_decode($json, true);
        $id ??= 'd-' . (++$this->seq);
        $this->repo->addDefine([
            'id' => $id,
            'name' => $decoded['name'],
            'displayName' => $decoded['displayName'] ?? $decoded['name'],
            'type' => 'approval',
            'state' => 1,
            'content' => $json,
            'version' => $version,
        ]);
        return $id;
    }

    private function startAndApply(string $defineId, string $operator, ?FlowData $args = null): string
    {
        $response = $this->facade->flow('processInstance/startAndExecute', [
            FlowConst::PROCESS_DEFINE_ID_KEY => $defineId,
            'operator' => $operator,
        ] + ($args?->toArray() ?? []));
        $this->assertSame(0, $response['code'], $response['msg']);
        return (string) $response['data'][FlowConst::PROCESS_INSTANCE_ID_KEY];
    }

    private function plain(int $submitType): FlowData
    {
        return FlowData::of([FlowConst::SUBMIT_TYPE => $submitType]);
    }

    private function onlyChild(string $parentId): ProcessInstance
    {
        $children = array_values(array_filter(
            $this->repo->getAllInstances(),
            fn(ProcessInstance $instance) => $instance->getParentId() === $parentId,
        ));
        $this->assertCount(1, $children);
        return $children[0];
    }

    private function onlyDoing(string $instanceId): ProcessTask
    {
        $tasks = $this->repo->findDoingTasks($instanceId);
        $this->assertCount(1, $tasks);
        return $tasks[0];
    }

    private function task(string $instanceId, string $name): ProcessTask
    {
        foreach ($this->repo->findHistoryTasks($instanceId) as $task) {
            if ($task->getTaskName() === $name) {
                return $task;
            }
        }
        $this->fail("未找到任务 {$instanceId}/{$name}");
    }

    private function countEvent(ProcessEventTypeEnum $type, string $instanceId): int
    {
        return count(array_filter(
            $this->listener->events,
            fn(ProcessEvent $event) => $event->getType() === $type && $event->getSourceId() === $instanceId,
        ));
    }

    private function countTaskStart(string $instanceId, string $taskName): int
    {
        $count = 0;
        foreach ($this->listener->events as $event) {
            if ($event->getType() !== ProcessEventTypeEnum::PROCESS_TASK_START) continue;
            if ($event->datum(ProcessPublisher::KEY_INSTANCE_ID) !== $instanceId) continue;
            $task = $this->repo->findTaskById($event->getSourceId());
            if ($task !== null && $task->getTaskName() === $taskName) {
                $count++;
            }
        }
        return $count;
    }
}

final class SubProcessRecordingListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
    }
}
