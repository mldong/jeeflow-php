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
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\Spi\UserSearchProviderInterface;
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
    private ?ProcessExtRepositoryInterface $extRepository;
    private JeeflowQueryParser $queryParser;
    private ?UserSearchProviderInterface $userSearchProvider = null;

    public function __construct(JeeflowEngine $engine, ProcessRepositoryInterface $repository,
                                ?ProcessExtRepositoryInterface $extRepository = null)
    {
        $this->engine = $engine;
        $this->repository = $repository;
        $this->extRepository = $extRepository;
        $this->queryParser = new JeeflowQueryParser();
    }

    public function setUserSearchProvider(?UserSearchProviderInterface $provider): void
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
            $result = match ($action) {
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
                'processTask/candidatePage' => $this->candidatePage($args),
                // ── 视图端点 ──
                'processDefine/getLastByName' => $this->getLastByName($args),
                'processInstance/highLight' => $this->highLight($args),
                'processInstance/approvalRecord' => $this->approvalRecord($args),
                'processInstance/getAssigneeTextData' => $this->getAssigneeTextData($args),
                'processInstance/createCCInstance' => $this->createCCInstance($args),
                'processInstance/updateCCStatus' => $this->updateCCStatus($args),
                'processInstance/ccList' => $this->ccList($args),
                'processInstance/bizData' => $this->bizData($args),
                // ── 流程设计（需扩展仓储）──
                'processDesign/page' => $this->designPage($args),
                'processDesign/detail' => $this->designDetail($args),
                'processDesign/save' => $this->designSave($args),
                'processDesign/update' => $this->designUpdate($args),
                'processDesign/updateDefine' => $this->designUpdateDefine($args),
                'processDesign/remove' => $this->designRemove($args),
                'processDesign/deploy' => $this->designDeploy($args),
                'processDesign/redeploy' => $this->designRedeploy($args),
                'processDesign/listByType' => $this->designListByType($args),
                // ── 委托代理（需扩展仓储）──
                'processSurrogate/page' => $this->surrogatePage($args),
                'processSurrogate/save' => $this->surrogateSave($args),
                'processSurrogate/update' => $this->surrogateUpdate($args), // issues/77
                'processSurrogate/detail' => $this->surrogateDetail($args), // issues/77
                'processSurrogate/remove' => $this->surrogateRemove($args),
                default => $this->error('未知 action: ' . $action),
            };
            // issues/75：所有响应出口统一 id 字符串化（对齐四语言全局 exit hook，
            // 覆盖 designDetail 单记录 + 嵌套 his 列表等此前漏掉的 int id 泄漏面）
            if (isset($result['data'])) {
                $result['data'] = $this->stringifyIds($result['data']);
            }
            return $result;
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
            'variables' => $inst->getVariables()->toArray() ?: (object)[],
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
                    // issues/62：taskModel 补 form/ext（字段权限，对齐 boot2 setTaskModel）
                    $vo['taskModel'] = [
                        'name' => $node->getName(),
                        'displayName' => $node->getDisplayName(),
                        'type' => $node instanceof TaskModel ? 'task' : 'unknown',
                        'form' => $node instanceof TaskModel ? ($node->getForm() ?: null) : null,
                        'ext' => $node instanceof TaskModel ? $node->getExt()->toArray() : null,
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

    // ═══ issues/61：候选分页 + 业务数据读取 ═══

    /**
     * 候选用户分页（对齐 Java JeeflowFacade#candidatePage）
     *
     * 优先从流程定义解析下一任务节点候选（candidateUsers/candidateGroups），
     * 命中则逐个映射用户信息（UserSearchProviderInterface 优先，其次 UserProviderInterface，兜底原样）；
     * 未命中则走 UserSearchProviderInterface::page 用户分页搜索（未配置明确报错）。
     */
    private function candidatePage(array $args): array
    {
        $taskId = $this->toStr($args[FlowConst::PROCESS_TASK_ID_KEY] ?? $args['id'] ?? '');
        if ($taskId === '') return $this->error('processTaskId 缺失');
        $task = $this->repository->findTaskById($taskId);
        if ($task === null) return $this->error('任务不存在');
        $inst = $this->repository->findInstanceById($task->getProcessInstanceId());
        if ($inst === null) return $this->error('流程实例不存在');
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->error('流程定义不存在');

        $candidateIds = [];
        try {
            $model = ModelParser::parse((string) ($def['content'] ?? ''));
            $candidateIds = $model->getNextTaskModelCandidates($task->getTaskName());
        } catch (\Throwable $ignored) {}

        if ($candidateIds !== []) {
            // 候选配置命中 → 用户信息映射（UserSearchProviderInterface 优先，其次 UserProviderInterface）
            $rows = [];
            foreach ($candidateIds as $actorId) {
                $u = null;
                if ($this->userSearchProvider !== null) {
                    $u = $this->userSearchProvider->findById($actorId);
                }
                if ($u === null) {
                    $userProvider = ServiceContext::find(UserProviderInterface::class);
                    if ($userProvider !== null) {
                        $user = $userProvider->getUser($actorId);
                        if ($user !== null) {
                            $u = ['userId' => $actorId, 'realName' => $user['realName'] ?? ''];
                        }
                    }
                }
                if ($u === null) {
                    $u = ['userId' => $actorId, 'realName' => $actorId];
                }
                $rows[] = $u;
            }
            return $this->pageResult(new PageResult(1, 10, count($rows), $rows));
        }
        // 无模型候选 → 用户分页搜索（依赖 UserSearchProviderInterface）
        if ($this->userSearchProvider === null) {
            return $this->error('未配置 UserSearchProviderInterface（用户搜索钩子）');
        }
        return $this->pageResult($this->userSearchProvider->page($this->queryParser->parse($args)));
    }

    /**
     * 业务数据回显（对齐 Java JeeflowFacade#bizData）
     *
     * 表名取流程定义 content 顶层 relTableName（缺省回落 name）；
     * MetaTableReader 由集成方经 ServiceContext::put("metaTableReader", ...) 注册（需引入 persist 模块），
     * 未注册明确报错。
     */
    private function bizData(array $args): array
    {
        $instanceId = $this->toStr($args['processInstanceId'] ?? $args['id'] ?? '');
        if ($instanceId === '') return $this->error('processInstanceId 缺失');
        $inst = $this->repository->findInstanceById($instanceId);
        if ($inst === null) return $this->error('流程实例不存在');
        $def = $this->repository->findDefineById($inst->getDefineId());
        if ($def === null) return $this->error('流程定义不存在');
        $tableName = $this->resolveRelTableName((string) ($def['content'] ?? ''));
        if ($tableName === null) return $this->error('流程定义未配置 relTableName');
        // issues/61：core 不编译期依赖 persist——按名查找，未注册明确报错
        $reader = ServiceContext::find('metaTableReader');
        if ($reader === null) {
            return $this->error('业务数据读取器未注册（ServiceContext::put("metaTableReader", new MetaTableReader(...))，需引入 jeeflow-persist）');
        }
        try {
            $result = $reader->readByProcessInstance($tableName, $instanceId);
            return $result === null ? $this->ok() : $this->ok($result);
        } catch (\Throwable $e) {
            return $this->error('业务数据读取失败: ' . ($e->getMessage() ?: (string) $e));
        }
    }

    /** 从流程定义 content 顶层解析 relTableName（缺省回落 name） */
    private function resolveRelTableName(string $content): ?string
    {
        if ($content === '') return null;
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return null;
        $tableName = isset($decoded['relTableName']) ? trim((string) $decoded['relTableName']) : '';
        if ($tableName === '') {
            $tableName = isset($decoded['name']) ? trim((string) $decoded['name']) : '';
        }
        return $tableName === '' ? null : $tableName;
    }

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
            'nodeProgress' => $nodeProgress ?: (object)[],
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
                'variable' => $t->getVariables()->toArray() ?: (object)[],
                'ext' => $t->getVariables()->toArray() ?: (object)[],
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

    private function formDataOf(array $variables, string $prefix): array|\stdClass
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
        return $result ?: (object)[];
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

    // issues/75：id 类字段统一字符串化出口（对齐四语言全局 exit hook）。
    // 19 位雪花 id > JS Number.MAX_SAFE_INTEGER(2^53)，若以 JSON 数字下发，前端
    // JSON.parse 走 float64 会丢精度（奇数尾被四舍五入），导致 designer 保存
    // 时 processDesignId 指向不存在的记录、静默 no-op（S8a 偶发根因）。
    // Java 用全局 Jackson Long→String、Go 用 okResult(stringifyIDs)、Node 用字符串型 id；
    // PHP 此前仅列表行 (string)$row['id']，单记录端点(designDetail 等)漏了 → 此处统一补齐。
    private function stringifyIds(mixed $v): mixed
    {
        if (!is_array($v)) return $v; // 标量/对象原样
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k) && $this->isIdKey($k)) {
                $out[$k] = $this->toIdString($val);
            } else {
                $out[$k] = $this->stringifyIds($val); // 递归（嵌套数组/行列表/其结构）
            }
        }
        return $out;
    }

    // id 类键：camelCase（id/*Id，对齐 Go isIDKey）+ snake_case（id/*_id，PDO 原始行）。
    private function isIdKey(string $k): bool
    {
        return $k === 'id' || str_ends_with($k, 'Id') || str_ends_with($k, '_id');
    }

    // id 值转字符串：null 保持 null；字符串直通；整数→十进制；整数值 float→十进制。
    private function toIdString(mixed $v): mixed
    {
        if ($v === null) return null;
        if (is_string($v)) return $v;
        if (is_int($v)) return (string) $v;
        if (is_float($v) && floor($v) === $v) return (string) (int) $v;
        return (string) $v;
    }

    private function pageResult(PageResult $page): array
    {
        return $this->ok($page->toArray());
    }

    private function toStr(mixed $v): string
    {
        return $v !== null ? (string) $v : '';
    }

    // ═══ 流程设计（需扩展仓储） ═══

    private function requireExt(): ProcessExtRepositoryInterface
    {
        if ($this->extRepository === null) {
            throw new \RuntimeException('未接入 IProcessExtRepository，设计/委托 action 不可用');
        }
        return $this->extRepository;
    }

    // issues/63：时间格式化（§2.4 契约 yyyy-MM-dd HH:mm:ss）
    private function fmtTime(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return $v;
        $ts = is_string($v) ? strtotime($v) : false;
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : (string) $v;
    }

    // issues/63：设计行转换（兼容 PDO snake_case / InMemory camelCase）
    private function designRowToMap(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? '',
            'displayName' => $row['displayName'] ?? $row['display_name'] ?? '',
            'type' => $row['type'] ?? '',
            'icon' => $row['icon'] ?? null,
            'isDeployed' => (int) ($row['isDeployed'] ?? $row['is_deployed'] ?? 0),
            'remark' => $row['remark'] ?? null,
            'createTime' => $this->fmtTime($row['createTime'] ?? $row['create_time'] ?? null),
            'createUser' => $row['createUser'] ?? $row['create_user'] ?? null,
            'updateTime' => $this->fmtTime($row['updateTime'] ?? $row['update_time'] ?? null),
            'updateUser' => $row['updateUser'] ?? $row['update_user'] ?? null,
        ];
    }

    private function designPage(array $args): array
    {
        $ext = $this->requireExt();
        $query = $this->queryParser->parse($args);
        $page = $ext->pageDesigns($query);
        $result = $page->toArray();
        $result['rows'] = array_map([$this, 'designRowToMap'], $page->getRows());
        return $this->ok($result);
    }

    private function designDetail(array $args): array
    {
        $ext = $this->requireExt();
        $id = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($id);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($id);
        $jsonObject = $his !== null ? $this->parseGraph($his['content'] ?? '') : null;
        // 如果 jsonObject 缺失基本信息，从 design 补齐
        if (is_array($jsonObject)) {
            if (empty($jsonObject['name'])) $jsonObject['name'] = $design['name'] ?? '';
            if (empty($jsonObject['displayName'])) $jsonObject['displayName'] = $design['displayName'] ?? '';
        }
        $data = [
            'id' => $design['id'],
            'name' => $design['name'] ?? '',
            'displayName' => $design['displayName'] ?? '',
            'type' => $design['type'] ?? null,
            'icon' => $design['icon'] ?? null,
            'isDeployed' => $design['isDeployed'] ?? 0,
            'remark' => $design['remark'] ?? null,
            'jsonObject' => $jsonObject,
            'his' => $ext->findDesignHisList($id),
        ];
        return $this->ok($data);
    }

    private function designSave(array $args): array
    {
        $ext = $this->requireExt();
        $id = $args['id'] ?? null;
        if ($id !== null) {
            // 更新基本信息
            $ext->updateDesign(['id' => $this->toStr($id)] + $args);
            // 如果有 content，存快照并置未部署
            if (isset($args['content'])) {
                $content = is_string($args['content']) ? $args['content'] : json_encode($args['content'], JSON_UNESCAPED_UNICODE);
                $ext->saveDesignHis($this->toStr($id), $content, $args['operator'] ?? null);
                $ext->updateDesignDeployed($this->toStr($id), 0);
            }
            return $this->ok(['id' => $this->toStr($id)]);
        }
        // 新建
        $designId = $ext->saveDesign([
            'name' => $args['name'] ?? '',
            'displayName' => $args['displayName'] ?? '',
            'type' => $args['type'] ?? 'approval',
            'icon' => $args['icon'] ?? null,
            'remark' => $args['remark'] ?? null,
            'createUser' => $args['operator'] ?? null,
        ]);
        if (isset($args['content'])) {
            $content = is_string($args['content']) ? $args['content'] : json_encode($args['content'], JSON_UNESCAPED_UNICODE);
            $ext->saveDesignHis($designId, $content, $args['operator'] ?? null);
        }
        return $this->ok(['id' => $designId]);
    }

    private function designUpdate(array $args): array
    {
        $ext = $this->requireExt();
        $ext->updateDesign($args);
        return $this->ok();
    }

    private function designUpdateDefine(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['processDesignId'] ?? '');
        $content = $this->contentString($args);
        // 存快照
        $ext->saveDesignHis($designId, $content, $args['operator'] ?? null);
        // 同步 name/displayName/type
        try {
            $model = ModelParser::parse($content);
            $ext->updateDesign([
                'id' => $designId,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
            ]);
        } catch (\Throwable $ignored) {}
        // 置未部署
        $ext->updateDesignDeployed($designId, 0);
        return $this->ok();
    }

    private function designRemove(array $args): array
    {
        $ext = $this->requireExt();
        $ids = $args['ids'] ?? null;
        if (is_array($ids)) {
            foreach ($ids as $id) $ext->removeDesign($this->toStr($id));
        } else {
            $ext->removeDesign($this->toStr($args['id'] ?? ''));
        }
        return $this->ok();
    }

    private function designDeploy(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($designId);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($designId);
        if ($his === null) return $this->error('设计稿为空，无法发布');
        $content = $his['content'];
        $model = ModelParser::parse($content);
        // 版本管理
        $existing = $this->repository->findLatestDefineByName($model->getName());
        $version = 0;
        if ($existing !== null) $version = ($existing['version'] ?? 0) + 1;
        $defineId = (string) $this->repository->getIdGenerator()->nextId();
        $this->repository->addDefine([
            'id' => $defineId,
            'name' => $model->getName(),
            'displayName' => $model->getDisplayName(),
            'type' => $model->getType(),
            'state' => 1,
            'content' => $content,
            'version' => $version,
        ]);
        $ext->updateDesignDeployed($designId, 1);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function designRedeploy(array $args): array
    {
        $ext = $this->requireExt();
        $designId = $this->toStr($args['id'] ?? '');
        $design = $ext->findDesignById($designId);
        if ($design === null) return $this->error('设计不存在');
        $his = $ext->findLatestDesignHis($designId);
        if ($his === null) return $this->error('设计稿为空');
        $content = $his['content'];
        $model = ModelParser::parse($content);
        // 按 name 找现有定义
        $existing = $this->repository->findLatestDefineByName($model->getName());
        if ($existing !== null) {
            // 原地替换
            $this->repository->updateDefine([
                'id' => $existing['id'],
                'content' => $content,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
            ]);
            $defineId = $existing['id'];
        } else {
            $defineId = (string) $this->repository->getIdGenerator()->nextId();
            $this->repository->addDefine([
                'id' => $defineId,
                'name' => $model->getName(),
                'displayName' => $model->getDisplayName(),
                'type' => $model->getType(),
                'state' => 1,
                'content' => $content,
                'version' => 0,
            ]);
        }
        $ext->updateDesignDeployed($designId, 1);
        return $this->ok([FlowConst::PROCESS_DEFINE_ID_KEY => $defineId]);
    }

    private function designListByType(array $args): array
    {
        $ext = $this->requireExt();
        $grouped = $ext->listDesignsByType();
        $result = [];
        foreach ($grouped as $type => $items) {
            foreach ($items as $d) {
                $def = $this->repository->findLatestDefineByName($d['name'] ?? '');
                $his = $ext->findLatestDesignHis($d['id'] ?? '');
                $result[$type][] = [
                    'processDesignId' => (string) ($d['id'] ?? ''),
                    'name' => $d['name'] ?? '',
                    'displayName' => $d['displayName'] ?? '',
                    'icon' => $d['icon'] ?? null,
                    'remark' => $d['remark'] ?? null,
                    'processDefineId' => $def['id'] ?? null,
                    'processDefineState' => $def['state'] ?? null,
                    'jsonObject' => $his !== null ? $this->parseGraph($his['content'] ?? '') : null,
                ];
            }
        }
        return $this->ok($result);
    }

    // ═══ 委托代理 ═══

    private function surrogatePage(array $args): array
    {
        $ext = $this->requireExt();
        $query = $this->queryParser->parse($args);
        $page = $ext->pageSurrogates($query);
        $result = $page->toArray();
        // issues/77：行走 surrogateRowToMap（时间格式化 + 键归一），与 detail 同构
        $result['rows'] = array_map([$this, 'surrogateRowToMap'], $page->getRows());
        return $this->ok($result);
    }

    private function surrogateSave(array $args): array
    {
        $ext = $this->requireExt();
        $operator = $this->operatorOf($args);
        $id = $this->toStr($args['id'] ?? '');
        $surrogate = $id !== '' ? $ext->findSurrogateById($id) : null;
        if ($id !== '' && $surrogate === null) {
            return $this->error('委托记录不存在');
        }
        if ($surrogate === null) {
            $surrogate = ['createUser' => $operator, 'createTime' => date('Y-m-d H:i:s')];
            $surrogate['operator'] = $operator; // 授权人 = 操作人（新建必有）
        }
        $surrogate = $this->applySurrogateFields($surrogate, $args, $operator);
        if ($id === '') {
            $id = $ext->saveSurrogate($surrogate);
        } else {
            $ext->updateSurrogate($surrogate);
        }
        return $this->ok(['id' => $id]);
    }

    /** 委托更新（issues/77）：按 id 全字段更新，授权人缺省时保留原值（前端编辑表单不带 operator） */
    private function surrogateUpdate(array $args): array
    {
        $ext = $this->requireExt();
        $operator = $this->operatorOf($args);
        $id = $this->toStr($args['id'] ?? '');
        if ($id === '') {
            return $this->error('id 缺失或非法');
        }
        $surrogate = $ext->findSurrogateById($id);
        if ($surrogate === null) {
            return $this->error('委托记录不存在');
        }
        $surrogate = $this->applySurrogateFields($surrogate, $args, $operator);
        $surrogate['id'] = $id;
        $ext->updateSurrogate($surrogate);
        return $this->ok(['id' => $id]);
    }

    /** 委托详情（issues/77）：按 id 查单条，返回行结构（时间格式化） */
    private function surrogateDetail(array $args): array
    {
        $id = $this->toStr($args['id'] ?? '');
        if ($id === '') {
            return $this->error('id 缺失或非法');
        }
        $surrogate = $this->requireExt()->findSurrogateById($id);
        if ($surrogate === null) {
            return $this->error('委托记录不存在');
        }
        return $this->ok($this->surrogateRowToMap($surrogate));
    }

    private function surrogateRemove(array $args): array
    {
        $ext = $this->requireExt();
        $ext->removeSurrogate($this->toStr($args['id'] ?? ''));
        return $this->ok();
    }

    // 操作人兜底（对齐 Java toStr(args.get("operator"), "user1")）
    private function operatorOf(array $args): string
    {
        return array_key_exists('operator', $args) && $args['operator'] !== null
            ? $this->toStr($args['operator'])
            : 'user1';
    }

    /** 委托写入公共字段。授权人（operator）仅在显式传入时覆盖，避免 update 时清空原授权人 */
    private function applySurrogateFields(array $s, array $args, string $operator): array
    {
        $s['processName'] = $this->toStr($args['processName'] ?? '');
        if (array_key_exists('operator', $args)) {
            $s['operator'] = $this->toStr($args['operator']); // 授权人 = 操作人
        }
        $s['surrogate'] = $this->toStr($args['surrogate'] ?? '');
        $s['startTime'] = $this->parseSurrogateTime($args['startTime'] ?? null);
        $s['endTime'] = $this->parseSurrogateTime($args['endTime'] ?? null);
        $s['enabled'] = ($args['enabled'] ?? null) !== null ? (int) $args['enabled'] : 1; // 显式 0 不得被吞
        $s['updateUser'] = $operator;
        return $s;
    }

    // 时间窗解析：§2.4 契约 yyyy-MM-dd HH:mm:ss（空格），兼容 ISO T 与纯日期
    private function parseSurrogateTime(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    // issues/77：委托行转换（兼容 PDO snake_case / InMemory camelCase，时间格式化）
    private function surrogateRowToMap(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'processName' => $row['processName'] ?? $row['process_name'] ?? '',
            'operator' => $row['operator'] ?? '',
            'surrogate' => $row['surrogate'] ?? '',
            'startTime' => $this->fmtTime($row['startTime'] ?? $row['start_time'] ?? null),
            'endTime' => $this->fmtTime($row['endTime'] ?? $row['end_time'] ?? null),
            'enabled' => (int) ($row['enabled'] ?? 1),
            'createTime' => $this->fmtTime($row['createTime'] ?? $row['create_time'] ?? null),
            'createUser' => $row['createUser'] ?? $row['create_user'] ?? null,
            'updateTime' => $this->fmtTime($row['updateTime'] ?? $row['update_time'] ?? null),
            'updateUser' => $row['updateUser'] ?? $row['update_user'] ?? null,
        ];
    }
}
