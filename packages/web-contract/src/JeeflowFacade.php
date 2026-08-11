<?php

declare(strict_types=1);

namespace Jeeflow\WebContract;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Util\JeeflowQueryParser;

/**
 * 统一门面 —— 对齐 Java JeeflowFacade
 *
 * 40 action 路由入口。集成方只需实现一个转发 controller：
 * 把 body JSON 转成 array 传入 flow()，所有流程能力按 action 路由。
 *
 * 返回统一结构 {code: 0, msg: "成功", data: {...}}
 */
class JeeflowFacade
{
    private JeeflowEngine $engine;
    private ProcessRepositoryInterface $repository;
    private JeeflowQueryParser $queryParser;
    private ?object $userSearchProvider = null;

    public function __construct(JeeflowEngine $engine, ProcessRepositoryInterface $repository)
    {
        $this->engine = $engine;
        $this->repository = $repository;
        $this->queryParser = new JeeflowQueryParser();
    }

    public function setUserSearchProvider(?object $provider): void
    {
        $this->userSearchProvider = $provider;
    }

    /**
     * 统一入口
     * @param array<string, mixed> $args
     * @return array{code:int, msg:string, data:mixed}
     */
    public function flow(string $action, ?array $args = null): array
    {
        try {
            if ($args === null) $args = [];
            return match ($action) {
                // ── 流程定义 ──
                'processDefine/page' => $this->definePage($args),
                'processDefine/detail' => $this->defineDetail($args),
                'processDefine/startAndExecute' => $this->startAndExecute($args),
                'processDefine/deploy' => $this->deploy($args),
                'processDefine/redeploy' => $this->redeploy($args),
                'processDefine/remove' => $this->defineRemove($args),
                'processDefine/upAndDown' => $this->defineUpAndDown($args),
                // ── 流程实例 ──
                'processInstance/page' => $this->instancePage($args),
                'processInstance/detail' => $this->instanceDetail($args),
                'processInstance/startAndExecute' => $this->startAndExecute($args),
                'processInstance/withdraw' => $this->withdraw($args),
                // ── 流程任务 ──
                'processTask/todoList' => $this->todoList($args),
                'processTask/doneList' => $this->doneList($args),
                'processTask/execute' => $this->execute($args),
                'processTask/detail' => $this->taskDetail($args),
                'processTask/jumpAbleTaskNameList' => $this->jumpAbleTaskNameList($args),
                'processTask/surrogate' => $this->taskSurrogate($args),
                'processTask/addCandidate' => $this->taskSurrogate($args),
                'processTask/latest' => $this->taskLatest($args),
                // ── 视图端点 ──
                'processDefine/getLastByName' => $this->getLastByName($args),
                'processInstance/highLight' => $this->highLight($args),
                'processInstance/approvalRecord' => $this->approvalRecord($args),
                'processInstance/getAssigneeTextData' => $this->getAssigneeTextData($args),
                'processInstance/createCCInstance' => $this->createCCInstance($args),
                'processInstance/updateCCStatus' => $this->updateCCStatus($args),
                'processInstance/ccList' => $this->ccList($args),
                default => $this->error('未知 action: ' . $action),
            };
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: (string) $e);
        }
    }

    // ═══ 流程定义 ═══

    private function definePage(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $page = $this->repository->pageDefines($query);
        return $this->pageResult($page);
    }

    private function defineDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? null);
        $def = $this->repository->findDefineById($id);
        if ($def === null) return $this->error('流程定义不存在');
        $data = [
            'id' => $def['id'],
            'name' => $def['name'],
            'displayName' => $def['displayName'] ?? '',
            'type' => $def['type'] ?? null,
            'state' => $def['state'],
            'version' => $def['version'],
            'jsonObject' => $this->parseGraph($def['content'] ?? ''),
        ];
        return $this->ok($data);
    }

    private function startAndExecute(array $args): array
    {
        $defineId = $this->toStr($args[FlowConst::PROCESS_DEFINE_ID_KEY] ?? '');
        $operator = $this->toStr($args['operator'] ?? 'user1');

        $flowArgs = FlowData::create();
        foreach ($args as $k => $v) {
            if ($k !== FlowConst::PROCESS_DEFINE_ID_KEY && $k !== 'operator') {
                $flowArgs->set($k, $v);
            }
        }

        $inst = $this->engine->startProcessInstanceById($defineId, $operator, $flowArgs);

        // 自动完成申请节点（assignee="applicant" → 发起人）
        $doingTasks = $this->repository->findDoingTasks($inst->getInstanceId());
        foreach ($doingTasks as $task) {
            $this->repository->addTaskActor($task->getTaskId(), [$operator]);
            $flowArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
            // f_nextNodeOperator → tf_nextNodeOperator
            $startNextOp = $flowArgs->get(FlowConst::PROCESS_START_NEXT_NODE_OPERATOR);
            if ($startNextOp !== null && $startNextOp !== '') {
                $flowArgs->set(FlowConst::NEXT_NODE_OPERATOR, $startNextOp);
            }
            $this->engine->executeProcessTask($task->getTaskId(), $operator, $flowArgs);
        }

        return $this->ok([FlowConst::PROCESS_INSTANCE_ID_KEY => $inst->getInstanceId()]);
    }

    private function deploy(array $args): array
    {
        $content = $this->contentString($args);
        $model = ModelParser::parse($content);
        $name = $model->getName();
        // 查找同名最新定义
        $existing = $this->repository->findLatestDefineByName($name);
        $version = 0;
        if ($existing !== null) {
            $version = ($existing['version'] ?? 0) + 1;
        }
        $defineId = (string) $this->repository->getIdGenerator()->nextId();
        $this->repository->addDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'state' => 1,
            'content' => $content,
            'version' => $version,
            'createUser' => $args['operator'] ?? null,
            'updateUser' => $args['operator'] ?? null,
        ]);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function redeploy(array $args): array
    {
        $defineId = $this->toStr($args[FlowConst::PROCESS_DEFINE_ID_KEY] ?? '');
        $content = $this->contentString($args);
        $model = ModelParser::parse($content);
        $this->repository->updateDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'content' => $content,
            'updateUser' => $args['operator'] ?? 'system',
        ]);
        return $this->ok();
    }

    private function defineRemove(array $args): array
    {
        $ids = $args['ids'] ?? null;
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $this->repository->removeDefine($this->toStr($id));
            }
        } else {
            $this->repository->removeDefine($this->toStr($args['id'] ?? ''));
        }
        return $this->ok();
    }

    private function defineUpAndDown(array $args): array
    {
        $state = (int) ($args['opType'] ?? $args['state'] ?? 1);
        $ids = $args['ids'] ?? null;
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $this->repository->updateDefineState($this->toStr($id), $state);
            }
        } else {
            $this->repository->updateDefineState($this->toStr($args['id'] ?? ''), $state);
        }
        return $this->ok();
    }

    // ═══ 流程实例 ═══

    private function instancePage(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $userId = $this->toStr($args['operator'] ?? 'user1');
        $query->add('t.operator', 'EQ', $userId);
        $page = $this->repository->pageInstances($query);
        return $this->pageResult($page);
    }

    private function instanceDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        $inst = $this->repository->findInstanceById($id);
        if ($inst === null) return $this->error('流程实例不存在');

        $def = $this->repository->findDefineById($inst->getDefineId());
        $jsonObject = $def !== null ? $this->parseGraph($def['content'] ?? '') : null;
        $firstTaskNodeId = $this->firstTaskNodeId($jsonObject);

        $tasks = [];
        $activeTaskList = [];
        foreach ($inst->getTasks() as $t) {
            $vo = $this->taskVo($t);
            $ext = $t->getVariables()->toArray();
            $doing = $t->getTaskState() === ProcessTaskState::DOING;
            $ext['isFirstTaskNode'] = $doing && $t->getTaskName() === $firstTaskNodeId;
            $vo['ext'] = $ext;
            $tasks[] = $vo;
            if ($doing) $activeTaskList[] = $vo;
        }

        $data = [
            'id' => $inst->getInstanceId(),
            'parentId' => $inst->getParentId(),
            'processDefineId' => $inst->getDefineId(),
            'state' => $inst->getState(),
            'parentNodeName' => $inst->getParentNodeName(),
            'businessNo' => $inst->getBusinessNo(),
            'operator' => $inst->getOperator(),
            'variables' => $inst->getVariables()->toArray(),
            'formData' => $this->formDataOf($inst->getVariables()->toArray(), FlowConst::FORM_DATA_PREFIX),
            'createTime' => $inst->getCreateTime(),
            'createUser' => $inst->getCreateUser(),
            'displayName' => $def['displayName'] ?? null,
            'name' => $def['name'] ?? null,
            'version' => $def['version'] ?? null,
            'jsonObject' => $jsonObject,
            'tasks' => $tasks,
            'activeTaskList' => $activeTaskList,
        ];
        return $this->ok($data);
    }

    private function withdraw(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $operator = $this->toStr($args['operator'] ?? 'user1');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');
        $inst->withdraw($operator);
        $this->repository->updateInstance($inst);
        return $this->ok();
    }

    // ═══ 流程任务 ═══

    private function todoList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $userId = $this->toStr($args['operator'] ?? 'user1');
        $query->add('pta.actor_id', 'EQ', $userId);
        $page = $this->repository->pageTodoTasks($query);
        return $this->pageResult($page);
    }

    private function doneList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $userId = $this->toStr($args['operator'] ?? 'user1');
        $query->add('t.operator', 'EQ', $userId);
        $page = $this->repository->pageDoneTasks($query);
        return $this->pageResult($page);
    }

    private function execute(array $args): array
    {
        $taskId = $this->toStr($args[FlowConst::PROCESS_TASK_ID_KEY] ?? '');
        $operator = $this->toStr($args['operator'] ?? 'user1');
        $submitType = (int) ($args[FlowConst::SUBMIT_TYPE] ?? SubmitType::AGREE);

        $flowArgs = FlowData::create();
        foreach ($args as $k => $v) {
            if ($k !== FlowConst::PROCESS_TASK_ID_KEY && $k !== 'operator') {
                $flowArgs->set($k, $v);
            }
        }
        $flowArgs->set(FlowConst::SUBMIT_TYPE, $submitType);

        // 分发逻辑（spec §2.8）
        if ($submitType === SubmitType::REJECT) {
            $this->engine->executeAndJumpToEnd($taskId, $operator, $flowArgs);
        } elseif ($submitType === SubmitType::ROLLBACK) {
            $this->engine->executeAndJumpTask($taskId, $operator, $flowArgs, null);
        } elseif ($submitType === SubmitType::JUMP) {
            $taskName = $this->toStr($args[FlowConst::TASK_NAME] ?? '');
            $this->engine->executeAndJumpTask($taskId, $operator, $flowArgs, $taskName);
        } elseif ($submitType === SubmitType::ROLLBACK_TO_OPERATOR) {
            $this->engine->executeAndJumpToFirstTaskNode($taskId, $operator, $flowArgs);
        } elseif ($submitType === 20) { // COUNTERSIGN_DISAGREE
            $flowArgs->set(FlowConst::COUNTERSIGN_DISAGREE_FLAG, 1);
            $this->engine->executeProcessTask($taskId, $operator, $flowArgs);
        } else {
            // 默认执行（0 APPLY / 1 AGREE / 5 RE_APPLY）
            $this->engine->executeProcessTask($taskId, $operator, $flowArgs);
        }
        return $this->ok();
    }

    private function taskDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        $operator = $this->toStr($args['operator'] ?? '');
        $task = $this->repository->findTaskById($id);
        if ($task === null) return $this->error('任务不存在');

        $inst = $this->repository->findInstanceById($task->getProcessInstanceId());
        $def = $inst !== null ? $this->repository->findDefineById($inst->getDefineId()) : null;

        $vo = $this->taskVo($task);
        $vo['executable'] = $task->isAllowed($operator);
        $vo['jsonObject'] = $def !== null ? $this->parseGraph($def['content'] ?? '') : null;
        // taskModel
        if ($def !== null) {
            try {
                $model = ModelParser::parse((string) ($def['content'] ?? ''));
                $node = $model->getNode($task->getTaskName());
                if ($node !== null) {
                    $vo['taskModel'] = [
                        'name' => $node->getName(),
                        'displayName' => $node->getDisplayName(),
                        'type' => $node instanceof TaskModel ? 'task' : 'unknown',
                    ];
                }
            } catch (\Throwable $ignored) {}
        }
        return $this->ok($vo);
    }

    private function jumpAbleTaskNameList(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->ok([]);
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->ok([]);

        $result = [];
        try {
            $model = ModelParser::parse((string) ($def['content'] ?? ''));
            foreach ($model->getNodes() as $node) {
                if ($node instanceof TaskModel) {
                    $result[] = [
                        'label' => $node->getDisplayName(),
                        'value' => $node->getName(),
                    ];
                }
            }
        } catch (\Throwable $ignored) {}
        return $this->ok($result);
    }

    private function taskSurrogate(array $args): array
    {
        $taskId = $this->toStr($args['processTaskId'] ?? $args['id'] ?? '');
        $actorIds = $args['actorIds'] ?? [];
        if (is_string($actorIds)) {
            $actorIds = array_filter(array_map('trim', explode(',', $actorIds)));
        }
        $this->repository->addTaskActor($taskId, $actorIds);
        return $this->ok();
    }

    private function taskLatest(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $doingTasks = $this->repository->findDoingTasks($instanceId);
        if (empty($doingTasks)) return $this->ok(null);
        return $this->ok($this->taskVo($doingTasks[0]));
    }

    // ═══ 视图端点 ═══

    private function getLastByName(array $args): array
    {
        $name = $this->toStr($args['processDefineName'] ?? '');
        $def = $this->repository->findLatestDefineByName($name);
        if ($def === null) return $this->error('流程定义不存在: ' . $name);
        return $this->ok([
            'id' => $def['id'],
            'name' => $def['name'],
            'displayName' => $def['displayName'] ?? '',
            'type' => $def['type'] ?? null,
            'state' => $def['state'],
            'version' => $def['version'],
        ]);
    }

    private function highLight(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');

        $activeNodeNames = [];
        $historyNodeNames = [];
        $historyEdgeNames = [];

        // 活跃节点 = 进行中任务
        $doing = $this->repository->findDoingTasks($instanceId);
        foreach ($doing as $t) {
            if (!in_array($t->getTaskName(), $activeNodeNames, true)) {
                $activeNodeNames[] = $t->getTaskName();
            }
        }

        // 历史节点 = 已完成任务
        $history = $this->repository->findHistoryTasks($instanceId);
        foreach ($history as $t) {
            if (!in_array($t->getTaskName(), $activeNodeNames, true) &&
                !in_array($t->getTaskName(), $historyNodeNames, true)) {
                $historyNodeNames[] = $t->getTaskName();
            }
        }

        // nodeProgress
        $nodeProgress = [];
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def !== null) {
            try {
                $model = ModelParser::parse((string) ($def['content'] ?? ''));
                $nodeProgress = $this->buildNodeProgress($history);
            } catch (\Throwable $ignored) {}
        }

        return $this->ok([
            'activeNodeNames' => $activeNodeNames,
            'historyNodeNames' => $historyNodeNames,
            'historyEdgeNames' => $historyEdgeNames,
            'nodeProgress' => $nodeProgress,
        ]);
    }

    private function approvalRecord(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');

        $rows = [];
        foreach ($inst->getTasks() as $t) {
            $rows[] = [
                'taskName' => $t->getTaskName(),
                'displayName' => $t->getDisplayName(),
                'taskType' => $t->getTaskType(),
                'performType' => $t->getPerformType(),
                'taskState' => $t->getTaskState(),
                'operator' => $t->getActorId(),
                'finishTime' => $t->getFinishTime(),
                'variable' => $t->getVariables()->toArray(),
                'ext' => $t->getVariables()->toArray(),
            ];
        }
        return $this->ok($rows);
    }

    private function getAssigneeTextData(array $args): array
    {
        $instanceId = $this->toStr($args['id'] ?? '');
        $includeNodeName = (bool) ($args['includeNodeName'] ?? true);
        $doing = $this->repository->findDoingTasks($instanceId);
        $result = [];
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        foreach ($doing as $t) {
            foreach ($t->getActorIds() as $actorId) {
                $name = '';
                if ($userProvider !== null) {
                    $user = $userProvider->getUser($actorId);
                    $name = $user['realName'] ?? '';
                }
                $label = $includeNodeName
                    ? $t->getDisplayName() . ':' . ($name ?: $actorId)
                    : ($name ?: $actorId);
                $result[] = ['value' => $actorId, 'label' => $label];
            }
        }
        return $this->ok($result);
    }

    private function createCCInstance(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $actorIds = (array) ($args['actorIds'] ?? []);
        $operator = $this->toStr($args['operator'] ?? '');
        if (empty($actorIds)) return $this->error('抄送人不能为空');
        $this->repository->createCcInstance($instanceId, $operator, $actorIds);
        return $this->ok();
    }

    private function updateCCStatus(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? '');
        $operator = $this->toStr($args['operator'] ?? '');
        $this->repository->updateCcStatus($instanceId, $operator);
        return $this->ok();
    }

    private function ccList(array $args): array
    {
        $query = $this->queryParser->parse($args);
        $userId = $this->toStr($args['operator'] ?? 'user1');
        $query->add('t.actor_id', 'EQ', $userId);
        $page = $this->repository->pageCcInstances($query);
        return $this->pageResult($page);
    }

    // ═══ 内部辅助方法 ═══

    private function taskVo(object $task): array
    {
        return [
            'id' => $task->getTaskId(),
            'processInstanceId' => $task->getProcessInstanceId(),
            'taskName' => $task->getTaskName(),
            'displayName' => $task->getDisplayName(),
            'taskType' => $task->getTaskType(),
            'performType' => $task->getPerformType(),
            'taskState' => $task->getTaskState(),
            'operator' => $task->getActorId(),
            'formKey' => $task->getFormKey(),
            'taskParentId' => $task->getParentTaskId(),
            'taskActorIdList' => $task->getActorIds(),
            'taskFormData' => $this->formDataOf($task->getVariables()->toArray(), FlowConst::TASK_FORM_DATA_PREFIX),
            'createTime' => $task->getCreateTime(),
        ];
    }

    private function parseGraph(string $content): mixed
    {
        if ($content === '') return null;
        $decoded = json_decode($content, true);
        return $decoded !== null ? $decoded : $content;
    }

    private function contentString(array $args): string
    {
        if (isset($args['content'])) {
            $c = $args['content'];
            return is_string($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE);
        }
        // 平铺模式：把整个 args 作为流程 JSON
        $filtered = [];
        foreach ($args as $k => $v) {
            if ($k !== 'operator') $filtered[$k] = $v;
        }
        return json_encode($filtered, JSON_UNESCAPED_UNICODE);
    }

    private function firstTaskNodeId(mixed $jsonObject): ?string
    {
        if (is_array($jsonObject) && isset($jsonObject['nodes'])) {
            foreach ($jsonObject['nodes'] as $n) {
                if (isset($n['type']) && ($n['type'] === 'sn:task' || str_ends_with($n['type'] ?? '', ':task'))) {
                    return $n['id'] ?? null;
                }
            }
        }
        return null;
    }

    private function formDataOf(array $variables, string $prefix): array
    {
        $result = [];
        foreach ($variables as $k => $v) {
            if (str_starts_with($k, $prefix)) {
                $result[$k] = $v;
                // 去前缀副本
                $stripped = substr($k, strlen($prefix));
                $result[$stripped] = $v;
            }
        }
        return $result;
    }

    private function buildNodeProgress(array $historyTasks): array
    {
        $progress = [];
        $names = [];
        $seen = [];
        foreach ($historyTasks as $t) {
            if (!isset($seen[$t->getTaskName()])) {
                $names[] = $t->getTaskName();
                $seen[$t->getTaskName()] = true;
            }
        }
        $userProvider = ServiceContext::find(UserProviderInterface::class);
        foreach ($names as $name) {
            $ts = array_filter($historyTasks, fn($t) => $t->getTaskName() === $name);
            if (empty($ts)) continue;
            $memberSet = [];
            foreach ($ts as $t) {
                foreach ($t->getActorIds() as $aid) {
                    $memberSet[$aid] = true;
                }
            }
            if (empty($memberSet)) continue;
            $members = array_keys($memberSet);
            $doneSet = [];
            foreach ($ts as $t) {
                if ($t->getTaskState() === ProcessTaskState::FINISHED) {
                    foreach ($t->getActorIds() as $aid) {
                        $doneSet[$aid] = true;
                    }
                }
            }
            $nodeData = ['members' => []];
            foreach ($members as $mid) {
                $entry = ['id' => $mid, 'name' => ''];
                if ($userProvider !== null) {
                    $user = $userProvider->getUser($mid);
                    $entry['name'] = $user['realName'] ?? '';
                }
                if (isset($doneSet[$mid])) {
                    $entry['done'] = true;
                } else {
                    $entry['active'] = true;
                }
                $nodeData['members'][] = $entry;
            }
            // 会签节点带 type
            $firstTask = reset($ts);
            if ($firstTask->getPerformType() === 1) {
                $nodeData['type'] = 'PARALLEL';
            }
            $progress[$name] = $nodeData;
        }
        return $progress;
    }

    // ── 响应构造 ──

    private function ok(mixed $data = null): array
    {
        return ['code' => 0, 'msg' => '成功', 'data' => $data];
    }

    private function error(string $msg): array
    {
        return ['code' => 99999999, 'msg' => $msg, 'data' => null];
    }

    private function pageResult(PageResult $page): array
    {
        return $this->ok($page->toArray());
    }

    private function toStr(mixed $v): string
    {
        return $v !== null ? (string) $v : '';
    }
}
