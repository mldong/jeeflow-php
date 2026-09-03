<?php

declare(strict_types=1);

namespace Jeeflow\WebContract;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Model\ProcessModel;
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

    private const DEFAULT_STATE_IN = [10, 20, 30, 40, 45, 50];
    private const DEFAULT_STATS_LIMIT = 10;
    private const VALID_GRANULARITY = ['hour' => true, 'day' => true, 'week' => true, 'month' => true];
    private const VALID_DIMENSION = [
        'state' => true, 'define' => true, 'category' => true,
        'approver' => true, 'applicant' => true, 'node' => true,
        'stuckNode' => true, 'stuckApprover' => true, 'durationBucket' => true,
    ];

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
                // ── 统计（issues/103） ──
                'processInstance/stats/overview' => $this->statsOverview($args),
                'processInstance/stats/trend' => $this->statsTrend($args),
                'processInstance/stats/group' => $this->statsGroup($args),
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
        foreach ($this->idListArgs($args) as $id) {
            $this->repository->removeDefine($id);
        }
        return $this->ok();
    }

    private function defineUpAndDown(array $args): array
    {
        $state = (int) ($args['opType'] ?? $args['state'] ?? 1);
        foreach ($this->idListArgs($args) as $id) {
            $this->repository->updateDefineState($id, $state);
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

        // issues/82-5：任务级 ext.isFirstTaskNode（前端 detail.vue 双兜底 record.ext?.isFirstTaskNode）
        // 首个任务节点且 DOING → true，与 instance detail 的 activeTaskList 行语义一致
        $tExt = $task->getVariables()->toArray();
        $doing = $task->getTaskState() === ProcessTaskState::DOING;
        $tExt['isFirstTaskNode'] = false;
        $vo = $this->taskVo($task);
        $vo['ext'] = $tExt;
        $vo['executable'] = $task->isAllowed($operator);
        $vo['jsonObject'] = $def !== null ? $this->parseGraph($def['content'] ?? '') : null;
        if ($def !== null) {
            $tExt['isFirstTaskNode'] = $doing && $task->getTaskName() === $this->firstTaskNodeId($vo['jsonObject']);
            $vo['ext'] = $tExt;
        }
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
                            if (!empty($user['deptName'])) {
                                $u['deptName'] = $user['deptName'];
                            }
                        }
                    }
                }
                if ($u === null) {
                    $u = ['userId' => $actorId, 'realName' => $actorId];
                }
                $rows[] = $this->candidateRow($actorId, $u);
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
     * candidatePage 模型候选行键归一（issues/80，对齐 Java candidateRow）
     *
     * 前端 UserSelect 按 valueField='id'/labelField='realName' 取值：
     * 主键 id（取 src.id → src.userId → actorId），realName 兜底 id，
     * 保留 userId 兼容旧消费方；userName/deptName 有则透传。
     */
    private function candidateRow(string $actorId, array $src): array
    {
        $id = $src['id'] ?? $src['userId'] ?? $actorId;
        $row = ['id' => (string) $id, 'realName' => $src['realName'] ?? (string) $id];
        if (isset($src['userId'])) {
            $row['userId'] = $src['userId'];
        }
        if (isset($src['userName'])) {
            $row['userName'] = $src['userName'];
        }
        if (isset($src['deptName'])) {
            $row['deptName'] = $src['deptName'];
        }
        return $row;
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
                $nodeProgress = $this->buildNodeProgress($model, $history);
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

    /** 节点成员进度（issues/41/82-10，对齐 Java/Go/Python）：按任务状态组装 nodeProgress——
     *  会签节点带 type（PARALLEL/SEQUENTIAL），成员 done 按完成状态逐人标记、active 仅进行中任务
     *  首位（非"所有未完成成员"）；动态参与人（无静态成员）不返回；name 走 UserProvider SPI
     *  （未注册/查不到缺省空串，前端降级显示 id）。成员取任务 actorIds 并集
     *  （PHP 引擎会签逐人建任务表驱动，无 operatorList 变量——与 Java/Go/Python 同构）。
     *  会签判定与 type 取**模型节点属性**（引擎建任务时 performType 未落任务表，取模型为准）。 */
    private function buildNodeProgress(ProcessModel $model, array $historyTasks): array
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
            $ts = array_values(array_filter($historyTasks, fn($t) => $t->getTaskName() === $name));
            if (empty($ts)) continue;
            // 完整成员列表：会签串行任务变量 operatorList_{node} 优先（逐个创建时仅 1 个任务，
            // 全量办理人存于其变量——对齐 Go/Java buildNodeProgress），否则任务 actorIds 并集
            $csMembers = $this->readCountersignOperatorList($ts, $name);
            if (!empty($csMembers)) {
                $members = $csMembers;
            } else {
                $memberSet = [];
                foreach ($ts as $t) {
                    foreach ($t->getActorIds() as $aid) {
                        $memberSet[$aid] = true;
                    }
                }
                if (empty($memberSet)) continue; // 动态参与人：无静态成员，不返回
                $members = array_keys($memberSet);
            }
            $doneSet = [];
            foreach ($ts as $t) {
                if ($t->getTaskState() === ProcessTaskState::FINISHED) {
                    foreach ($t->getActorIds() as $aid) {
                        $doneSet[$aid] = true;
                    }
                }
            }
            // active 仅进行中任务的首位处理人（其余未完成成员不带任何标记）
            $activeActor = null;
            foreach ($ts as $t) {
                if ($t->getTaskState() === ProcessTaskState::DOING && !empty($t->getActorIds())) {
                    $activeActor = $t->getActorIds()[0];
                    break;
                }
            }
            // 会签判定：模型节点属性（非任务表——任务 performType 未落库）
            $isCs = false;
            $csType = null;
            $node = $model->getNode($name);
            if ($node instanceof TaskModel) {
                $isCs = $node->getPerformType() === PerformType::COUNTERSIGN;
                if ($node->getCountersignType() !== null) {
                    $csType = $node->getCountersignType() === CountersignType::SERIAL
                        ? 'SEQUENTIAL' : 'PARALLEL';
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
                } elseif ($mid === $activeActor) {
                    $entry['active'] = true;
                }
                $nodeData['members'][] = $entry;
            }
            if ($isCs && $csType !== null) {
                $nodeData['type'] = $csType;
            }
            $progress[$name] = $nodeData;
        }
        return $progress;
    }

    /** 会签全量办理人：从任务变量 operatorList_{node} 还原（issues/93 串行逐个创建时仅 1 个任务，
     *  全量办理人存于该任务变量，对齐 Go/Java）。遍历节点全部任务，返回第一个非空列表（首位
     *  任务必带）；兼容 JSON 反序列化后的数组形态 */
    private function readCountersignOperatorList(array $ts, string $name): array
    {
        $key = FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $name;
        foreach ($ts as $t) {
            $value = $t->getVariables()->get($key);
            if (is_array($value)) {
                $list = [];
                foreach ($value as $o) {
                    $s = trim((string) $o);
                    if ($s !== '') $list[] = $s;
                }
                if (!empty($list)) return $list;
            } elseif ($value !== null && trim((string) $value) !== '') {
                return [trim((string) $value)];
            }
        }
        return [];
    }

    // ═══ 统计（issues/103） ═══

    private function statsOverview(array $args): array
    {
        $stateIn = $this->statsParseStateIn($args);
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);

        $allInsts = $this->repository->getAllInstances();
        $insts = $this->statsFilterInstances($allInsts, $stateIn, $start, $end);
        $total = count($insts);
        $inProgress = $completed = $withdrawn = $rejected = $suspended = 0;
        foreach ($insts as $inst) {
            match ($inst->getState()) {
                ProcessInstanceState::DOING => $inProgress++,
                ProcessInstanceState::FINISHED => $completed++,
                ProcessInstanceState::WITHDRAW => $withdrawn++,
                ProcessInstanceState::REJECTED => $rejected++,
                ProcessInstanceState::PENDING => $suspended++,
                default => null,
            };
        }

        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
        $todayNew = 0;
        foreach ($allInsts as $inst) {
            $ct = $inst->getCreateTime();
            if ($ct !== null && $ct >= $todayStart && $ct < $todayEnd) $todayNew++;
        }

        // avgDurationSeconds：state=20 完成实例平均时长，不受 stateIn 影响（对齐内置线 avgCompletedInstanceDurationSeconds）
        $instsForAvg = $this->statsFilterInstances($allInsts, null, $start, $end);
        $totalDur = 0;
        $durCount = 0;
        foreach ($instsForAvg as $inst) {
            if ($inst->getState() !== ProcessInstanceState::FINISHED) continue;
            $maxFinish = null;
            foreach ($inst->getTasks() as $task) {
                $ft = $task->getFinishTime();
                if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
            }
            if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                $totalDur += max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                $durCount++;
            }
        }
        $avgDur = $durCount > 0 ? intdiv($totalDur, $durCount) : 0;

        $rejectRate = self::statsRound4($rejected / max(1, $completed + $rejected));

        $allTasks = $this->repository->getAllTasks();
        $pending = $overdue = 0;
        $nowStr = date('Y-m-d H:i:s');
        foreach ($allTasks as $task) {
            if ($task->getTaskState() === ProcessTaskState::DOING) {
                $pending++;
                $exp = $task->getExpireTime();
                if ($exp !== null && $exp < $nowStr) $overdue++;
            }
        }

        // countersignRate/onTimeRate：全量已完成任务聚合，不限时间、不受 stateIn 影响（对齐内置线 countCompletedTask）
        $csTotal = $csCount = $onTime = $onTimeDenom = 0;
        foreach ($this->repository->getAllTasks() as $task) {
            if ($task->getTaskState() !== ProcessTaskState::FINISHED) continue;
            $csTotal++;
            if ($task->getPerformType() === PerformType::COUNTERSIGN) $csCount++;
            $exp = $task->getExpireTime();
            if ($exp !== null) {
                $onTimeDenom++;
                $ft = $task->getFinishTime();
                if ($ft !== null && $ft <= $exp) $onTime++;
            }
        }
        $countersignRate = $csTotal > 0 ? self::statsRound4($csCount / $csTotal) : 0.0;
        $onTimeRate = $onTimeDenom > 0 ? self::statsRound4($onTime / $onTimeDenom) : 0.0;

        return $this->ok([
            'total' => $total, 'inProgress' => $inProgress, 'completed' => $completed,
            'rejected' => $rejected, 'withdrawn' => $withdrawn, 'suspended' => $suspended,
            'todayNew' => $todayNew, 'avgDurationSeconds' => $avgDur,
            'rejectRate' => $rejectRate, 'pendingTaskCount' => $pending,
            'overdueTaskCount' => $overdue, 'countersignRate' => $countersignRate,
            'onTimeRate' => $onTimeRate,
        ]);
    }

    private function statsTrend(array $args): array
    {
        $granularity = (string)($args['granularity'] ?? '');
        if (!isset(self::VALID_GRANULARITY[$granularity])) {
            return $this->error('不支持的 granularity: ' . $granularity);
        }
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);
        // C：start/end 必填（对齐内置线 20010012 缺参语义），不再静默返回空 series
        if ($start === null || $end === null) {
            return $this->error('trend 缺少必填参数：start/end/granularity');
        }

        // 实例侧无 state 过滤（对齐内置线 countInstanceStartedByBucket）
        $insts = $this->statsFilterInstances($this->repository->getAllInstances(), null, $start, $end);
        $doneTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::FINISHED], $start, $end, 'finish');

        $buckets = self::statsEnumerateBuckets($start, $end, $granularity);
        $startedMap = [];
        foreach ($insts as $inst) {
            $ct = $inst->getCreateTime();
            if ($ct !== null) {
                $bk = self::statsBucketKey($ct, $granularity);
                $startedMap[$bk] = ($startedMap[$bk] ?? 0) + 1;
            }
        }
        $finishedMap = [];
        foreach ($doneTasks as $task) {
            $ft = $task->getFinishTime();
            if ($ft !== null) {
                $bk = self::statsBucketKey($ft, $granularity);
                $finishedMap[$bk] = ($finishedMap[$bk] ?? 0) + 1;
            }
        }

        $series = [];
        foreach ($buckets as $b) {
            $series[] = ['bucket' => $b, 'started' => $startedMap[$b] ?? 0, 'finished' => $finishedMap[$b] ?? 0];
        }
        // A：data 本体为裸数组（去掉 {granularity, series} 包装，对齐契约 spec 06 §4.2 / 内置线）
        return $this->ok($series);
    }

    private function statsGroup(array $args): array
    {
        $dimension = (string)($args['dimension'] ?? '');
        if (!isset(self::VALID_DIMENSION[$dimension])) {
            return $this->error('不支持的 dimension: ' . $dimension);
        }
        $start = $this->parseSurrogateTime($args['start'] ?? null);
        $end = $this->parseSurrogateTime($args['end'] ?? null);
        $limit = isset($args['limit']) ? (int)$args['limit'] : self::DEFAULT_STATS_LIMIT;
        // 无 state 过滤（对齐内置线 groupByDimension：仅按时间限定，契约 group 无 stateIn 入参）
        $insts = $this->statsFilterInstances($this->repository->getAllInstances(), null, $start, $end);
        $doneTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::FINISHED], $start, $end, 'finish');
        $doingTasks = $this->statsFilterTasks($this->repository->getAllTasks(), [ProcessTaskState::DOING], null, null, 'create');

        $rows = [];
        if ($dimension === 'define') {
            // D 对齐内置线 mapper：count 全实例、avg 仅对 state=20 且有 finish 的实例聚合（除数=完成数）
            $grouped = [];
            foreach ($insts as $inst) {
                $did = $inst->getDefineId();
                if (!isset($grouped[$did])) $grouped[$did] = ['count' => 0, 'totalDur' => 0, 'durCount' => 0];
                $grouped[$did]['count']++;
                if ($inst->getState() === ProcessInstanceState::FINISHED) {
                    $maxFinish = null;
                    foreach ($inst->getTasks() as $task) {
                        $ft = $task->getFinishTime();
                        if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
                    }
                    if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                        $grouped[$did]['totalDur'] += max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                        $grouped[$did]['durCount']++;
                    }
                }
            }
            $entries = [];
            foreach ($grouped as $did => $agg) {
                $def = $this->repository->findDefineById($did);
                $entries[] = [
                    'key' => $def['name'] ?? (string)$did,
                    'label' => $def['displayName'] ?? $def['display_name'] ?? null,
                    'count' => $agg['count'],
                    'avgDurationSeconds' => $agg['durCount'] > 0 ? intdiv($agg['totalDur'], $agg['durCount']) : null,
                ];
            }
            usort($entries, fn($a, $b) => $b['count'] - $a['count']);
            $rows = array_slice($entries, 0, $limit);

        } elseif ($dimension === 'state') {
            $grouped = [];
            foreach ($insts as $inst) {
                $k = (string)$inst->getState();
                $grouped[$k] = ($grouped[$k] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => (string)$k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'category') {
            $defineTypes = [];
            foreach ($insts as $inst) {
                $did = $inst->getDefineId();
                if (!isset($defineTypes[$did])) {
                    $def = $this->repository->findDefineById($did);
                    $defineTypes[$did] = $def['type'] ?? '';
                }
            }
            $grouped = [];
            foreach ($insts as $inst) {
                $tp = $defineTypes[$inst->getDefineId()] ?? '';
                $grouped[$tp] = ($grouped[$tp] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'approver') {
            $grouped = [];
            foreach ($doneTasks as $task) {
                $op = $task->getActorId();
                if ($op === null || $op === '') continue;
                $grouped[$op] = ($grouped[$op] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'applicant') {
            $grouped = [];
            foreach ($insts as $inst) {
                $op = $inst->getOperator();
                if ($op === null || $op === '') continue;
                $grouped[$op] = ($grouped[$op] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'node') {
            $nodeAgg = [];
            foreach ($doneTasks as $task) {
                $dn = $task->getDisplayName();
                if ($dn === null || $dn === '') continue;
                $dur = 0;
                $ft = $task->getFinishTime();
                $ct = $task->getCreateTime();
                if ($ft !== null && $ct !== null) $dur = max(0, strtotime($ft) - strtotime($ct));
                if (!isset($nodeAgg[$dn])) $nodeAgg[$dn] = ['count' => 0, 'totalDur' => 0];
                $nodeAgg[$dn]['count']++;
                $nodeAgg[$dn]['totalDur'] += $dur;
            }
            uasort($nodeAgg, fn($a, $b) => $b['count'] - $a['count']);
            $rows = [];
            $i = 0;
            foreach ($nodeAgg as $name => $agg) {
                if ($i++ >= $limit) break;
                $rows[] = [
                    'key' => $name, 'label' => null, 'count' => $agg['count'],
                    'avgDurationSeconds' => $agg['count'] > 0 ? intdiv($agg['totalDur'], $agg['count']) : null,
                ];
            }

        } elseif ($dimension === 'stuckNode') {
            $grouped = [];
            foreach ($doingTasks as $task) {
                $dn = $task->getDisplayName();
                if ($dn === null || $dn === '') continue;
                $grouped[$dn] = ($grouped[$dn] ?? 0) + 1;
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'stuckApprover') {
            $grouped = [];
            foreach ($doingTasks as $task) {
                foreach ($task->getActorIds() as $actorId) {
                    if ($actorId === null || $actorId === '') continue;
                    $grouped[$actorId] = ($grouped[$actorId] ?? 0) + 1;
                }
            }
            arsort($grouped);
            $rows = [];
            $i = 0;
            foreach ($grouped as $k => $c) {
                if ($i++ >= $limit) break;
                $rows[] = ['key' => $k, 'label' => null, 'count' => $c, 'avgDurationSeconds' => null];
            }

        } elseif ($dimension === 'durationBucket') {
            $durations = [];
            foreach ($insts as $inst) {
                if ($inst->getState() !== ProcessInstanceState::FINISHED) continue;
                $maxFinish = null;
                foreach ($inst->getTasks() as $task) {
                    $ft = $task->getFinishTime();
                    if ($ft !== null && ($maxFinish === null || $ft > $maxFinish)) $maxFinish = $ft;
                }
                if ($maxFinish !== null && $inst->getCreateTime() !== null) {
                    $durations[] = max(0, strtotime($maxFinish) - strtotime($inst->getCreateTime()));
                }
            }
            $sameDay = $d1to3 = $d3to7 = $over7d = 0;
            foreach ($durations as $dur) {
                if ($dur < 86400) $sameDay++;
                elseif ($dur < 259200) $d1to3++;
                elseif ($dur < 604800) $d3to7++;
                else $over7d++;
            }
            $keys = ['sameDay', '1to3d', '3to7d', 'over7d'];
            $counts = [$sameDay, $d1to3, $d3to7, $over7d];
            $rows = [];
            foreach ($keys as $idx => $k) {
                $rows[] = ['key' => $k, 'label' => null, 'count' => $counts[$idx], 'avgDurationSeconds' => null];
            }
        }

        // A：data 本体为裸数组（去掉 {dimension, rows} 包装，对齐契约 spec 06 §4.2 / 内置线）
        return $this->ok($rows);
    }

    // ── 统计辅助函数 ──

    private static function statsRound4(float $v): float
    {
        return round($v, 4);
    }

    /** @return int[] */
    private function statsParseStateIn(array $args): array
    {
        if (isset($args['stateIn']) && is_array($args['stateIn']) && count($args['stateIn']) > 0) {
            return array_map('intval', $args['stateIn']);
        }
        return self::DEFAULT_STATE_IN;
    }

    /** @param ProcessInstance[] $insts  @param int[]|null $stateIn null=无 state 过滤（对齐内置线：仅 overview 用 stateIn） */
    private function statsFilterInstances(array $insts, ?array $stateIn, ?string $start, ?string $end): array
    {
        $stateSet = $stateIn !== null ? array_flip($stateIn) : null;
        $result = [];
        foreach ($insts as $inst) {
            if ($stateSet !== null && !isset($stateSet[$inst->getState()])) continue;
            $ct = $inst->getCreateTime();
            if ($start !== null && ($ct === null || $ct < $start)) continue;
            if ($end !== null && ($ct === null || $ct >= $end)) continue;
            $result[] = $inst;
        }
        return $result;
    }

    /** @param ProcessTask[] $tasks  @param int[] $states  @param 'finish'|'create' $timeField */
    private function statsFilterTasks(array $tasks, array $states, ?string $start, ?string $end, string $timeField): array
    {
        $stateSet = array_flip($states);
        $result = [];
        foreach ($tasks as $task) {
            if (!isset($stateSet[$task->getTaskState()])) continue;
            $t = $timeField === 'finish' ? $task->getFinishTime() : $task->getCreateTime();
            if ($start !== null && ($t === null || $t < $start)) continue;
            if ($end !== null && ($t === null || $t >= $end)) continue;
            $result[] = $task;
        }
        return $result;
    }

    /** @return string[] */
    private static function statsEnumerateBuckets(string $start, string $end, string $granularity): array
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) return [];

        $buckets = [];
        $cur = new \DateTimeImmutable($start);
        $endDt = new \DateTimeImmutable($end);

        while ($cur < $endDt) {
            $buckets[] = self::statsBucketKey($cur->format('Y-m-d H:i:s'), $granularity);
            $cur = match ($granularity) {
                'hour' => $cur->modify('+1 hour'),
                'day' => $cur->modify('+1 day'),
                'week' => $cur->modify('+7 days'),
                'month' => $cur->modify('+1 month'),
            };
        }
        return array_values(array_unique($buckets));
    }

    private static function statsBucketKey(string $datetime, string $granularity): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) return '';
        return match ($granularity) {
            'hour' => date('Y-m-d H:00', $ts),
            'day' => date('Y-m-d', $ts),
            'week' => self::statsWeekKey($ts),
            'month' => date('Y-m', $ts),
            default => '',
        };
    }

    private static function statsWeekKey(int $ts): string
    {
        $isoYear = (int)date('o', $ts);
        $isoWeek = (int)date('W', $ts);
        return sprintf('%d-W%02d', $isoYear, $isoWeek);
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
        // issues/98：行键双读（PDO snake_case / InMemory camelCase），与 designRowToMap 同构
        if (is_array($jsonObject)) {
            if (empty($jsonObject['name'])) $jsonObject['name'] = $design['name'] ?? '';
            if (empty($jsonObject['displayName'])) $jsonObject['displayName'] = $design['displayName'] ?? $design['display_name'] ?? '';
        }
        $data = [
            'id' => $design['id'],
            'name' => $design['name'] ?? '',
            'displayName' => $design['displayName'] ?? $design['display_name'] ?? '',
            'type' => $design['type'] ?? null,
            'icon' => $design['icon'] ?? null,
            'isDeployed' => (int) ($design['isDeployed'] ?? $design['is_deployed'] ?? 0),
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
        foreach ($this->idListArgs($args) as $id) {
            $ext->removeDesign($id);
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
                    // issues/98：行键双读（PDO snake_case / InMemory camelCase），与 designRowToMap 同构
                    'displayName' => $d['displayName'] ?? $d['display_name'] ?? '',
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

    /** 删除委托（issues/95：前端「我的委托」行内/批量删除统一发 {ids}，与 define/design remove 同惯例） */
    private function surrogateRemove(array $args): array
    {
        $ext = $this->requireExt();
        foreach ($this->idListArgs($args) as $id) {
            $ext->removeSurrogate($id);
        }
        return $this->ok();
    }

    /** 删除/启停类 action 的批量主键：mldong IdsParam 惯例下 {ids} 数组优先，兼容单 {id}；
     *  两者皆缺失、空数组或含非法值一律报错，不得静默成功（issues/95，对齐 Java idListArgs） */
    private function idListArgs(array $args): array
    {
        if (isset($args['ids']) && is_array($args['ids'])) {
            $out = [];
            foreach ($args['ids'] as $id) {
                $s = $this->toStr($id);
                if ($s === '') {
                    throw new \InvalidArgumentException('id 缺失或非法');
                }
                $out[] = $s;
            }
            if ($out === []) {
                throw new \InvalidArgumentException('id 缺失或非法');
            }
            return $out;
        }
        $single = $this->toStr($args['id'] ?? '');
        if ($single === '') {
            throw new \InvalidArgumentException('id 缺失或非法');
        }
        return [$single];
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
