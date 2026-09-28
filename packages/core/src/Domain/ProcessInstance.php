<?php

declare(strict_types=1);

namespace Jeeflow\Core\Domain;

use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 流程实例 —— DDD 聚合根（充血模型）
 *
 * 对齐 Java ProcessInstance。所有状态修改通过聚合根方法完成。
 */
class ProcessInstance
{
    private ?string $instanceId = null;
    private ?string $parentId = null;
    private ?string $defineId = null;
    private int $state = ProcessInstanceState::DOING;
    private ?string $parentNodeName = null;
    private ?string $businessNo = null;
    private string $operator = '';
    private ?string $expireTime = null;
    private FlowData $variables;
    /** @var ProcessTask[] */
    private array $tasks = [];
    private ?string $createTime = null;
    private ?string $createUser = null;
    private ?string $updateTime = null;
    private ?string $updateUser = null;

    /**
     * @param array<string,mixed> $define 流程定义行（id/name/displayName/type/state/content/version）
     */
    public static function create(array $define, string $operator, ?FlowData $args = null,
                                   ?string $parentId = null, ?string $parentNodeName = null): self
    {
        $inst = new self();
        $inst->parentId = $parentId;
        $inst->parentNodeName = $parentNodeName;
        $inst->defineId = (string) ($define['id'] ?? '');
        $inst->operator = $operator;
        $inst->state = ProcessInstanceState::DOING;
        $inst->businessNo = $args?->getStr(FlowConst::BUSINESS_NO);
        $inst->variables = $args !== null ? $args->copy() : FlowData::create();
        $inst->tasks = [];
        $now = date('Y-m-d H:i:s');
        $inst->createTime = $now;
        $inst->createUser = $operator;
        $inst->updateTime = $now;
        $inst->updateUser = $operator;
        return $inst;
    }

    // ═══ 命令方法 ═══

    public function completeTask(string $taskId, string $operator, ?FlowData $args): void
    {
        $task = $this->findDoingTask($taskId);
        $task->finish($operator, $args);
        if ($args !== null) {
            $this->variables->setAll($args->toArray());
            // 提取 f_ 前缀变量持久化到流程变量
            $formData = FlowData::create();
            foreach ($args->keys() as $key) {
                if (str_starts_with($key, FlowConst::FORM_DATA_PREFIX)) {
                    $formData->set($key, $args->get($key));
                }
            }
            if (!$formData->isEmpty()) {
                $this->addVariable($formData);
            }
        }
    }

    public function abandonTask(string $taskId, string $operator): void
    {
        $task = $this->findDoingTask($taskId);
        $task->abandon($operator);
        $this->state = ProcessInstanceState::ABANDON;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function finish(): void
    {
        $this->state = ProcessInstanceState::FINISHED;
        $this->updateTime = date('Y-m-d H:i:s');
    }

    public function reject(): void
    {
        $this->state = ProcessInstanceState::REJECTED;
        $this->updateTime = date('Y-m-d H:i:s');
    }

    public function interrupt(string $operator): void
    {
        foreach ($this->tasks as $task) {
            $task->interrupt($operator);
        }
        $this->state = ProcessInstanceState::INTERRUPT;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    /**
     * 撤回整单（issues/113/114）：实例与**全部进行中任务**置 WITHDRAW(30)，
     * 已完成(20)/已终止(40)/已废弃(99) 任务行不被改写；实例与被撤任务的
     * update_user 都回写为真实撤回人（鉴权在门面，见 JeeflowFacade::withdraw）。
     *
     * issues/134 案 A（owner 2026-09-28 拍板 A，八栈同判据）：撤回只允许作用于**进行中(10)** 的实例。
     * 实例不是 10（已完成 20 / 已撤回 30 / 强行终止 40 / 已拒绝 45 / 挂起 50 / 已废弃 99）⇒ 抛内部码
     * 20010009，**一行都不改、不落库**——守卫排在下面的任务行循环**之前**，否则已办结实例会被静默
     * 改写成 30（已办列表 / 按状态聚合的统计凭空改历史，且用户看不到任何报错，本案病灶）。
     * 任务行层面那句「已完成(20)/已终止(40) 行不改写」的既有保护保持原样，实例级守卫排在它之前。
     * 出口按 issues/121 口径：门面 flow() 吞内部码 ⇒ code=99999999 ＋ msg 逐字固定文案，码值不进 msg。
     *
     * @throws JeeflowException 20010009 实例非进行中——本栈沿用 20010007/20010008 的形状
     *                          （码进注释、msg 只出固定中文文案，见 {@see self::rejectTask()}）；
     *                          对齐 Java WfErrEnum.WITHDRAW_INSTANCE_NOT_DOING
     */
    public function withdraw(string $operator): void
    {
        if ($this->state !== ProcessInstanceState::DOING) {
            throw new JeeflowException('流程实例非进行中，无法撤回');
        }
        foreach ($this->tasks as $task) {
            if ($task->isDoing()) {
                $task->withdraw($operator);
            }
        }
        $this->state = ProcessInstanceState::WITHDRAW;
        $this->updateTime = date('Y-m-d H:i:s');
        $this->updateUser = $operator;
    }

    public function addVariable(FlowData $args): void
    {
        $this->variables->setAll($args->toArray());
        $this->updateTime = date('Y-m-d H:i:s');
    }

    /**
     * issues/126 案 A（基准＝boot2 内置版 `ProcessTaskServiceImpl` 的三处写：:213 普通建单 /
     * :386 回退新建 / :524 会签建单）：任务行的到期时间在**建单那一刻**按节点表达式真算，
     * 求值语义逐字取 {@link FlowUtil::processTime()}。
     *
     * 本栈原形状只在 {@code JeeflowEngine::startProcessInstanceById} 把**定义级** expireTime 原串
     * 搬到实例列（注释自承"简化：不处理变量替换"），任务行那一列从来没人工过 ⇒ 配了到期表达式的
     * 节点建单即"无到期"，逾期类统计（overdueTaskCount / onTimeRate）在常规流上恒失真。
     *
     * 赋值规则（owner 2026-09-28 明确：**节点没配就保持 NULL，不造默认值**）：
     * expr 为 null 或去空白后为空 ⇒ 不动这一列（不写 now()、不写 ''、不写 0）；否则按表达式算。
     *
     * @param string|null   $expr 节点上配的到期表达式（本栈模型未配＝''，与 Java 的 null 同档）
     * @param FlowData|null $args 变量源：建单三处＝实例变量（boot2 的 execution.getArgs()）；
     *                            回退新建＝随行拷贝那份变量（boot2 的 hisVariable）
     */
    private static function applyExpireTime(?ProcessTask $task, ?string $expr, ?FlowData $args): void
    {
        if ($task === null || $expr === null || trim($expr) === '') {
            return;
        }
        $task->setExpireTime(FlowUtil::processTime($expr, $args ?? FlowData::create()));
    }

    /**
     * 供处理器在"绕过 {@see self::createTask()} 直建任务行"的路径上补同一把尺子——issues/126 §1.8
     * 点名的**第五处写点**：串行会签推进出的下一位成员（`CountersignHandler::createNextCountersignTask`）。
     * 基准侧 boot2 的串行推进是回调 `createCountersignTask`（`ProcessTaskServiceImpl:485`，
     * 内含 :524 那处到期写）⇒ 基准形状里"推进新建的那一位"同样带到期时间；不补就是"首成员有、后续没有"。
     * 变量源＝实例变量（与本栈建单三处同档，对齐 Java `applyNodeExpireTime`）。
     */
    public function applyNodeExpireTime(ProcessTask $task, TaskModel $taskModel): void
    {
        self::applyExpireTime($task, $taskModel->getExpireTime(), $this->variables);
    }

    /**
     * 创建普通任务
     * @param string[] $actorIds
     * @param string|null $expireExpr 节点到期表达式（issues/126 案 A · 第一处写点）；
     *                                未配/不关心时留空 ⇒ 这一列保持 NULL
     */
    public function createTask(string $taskName, string $displayName, ?int $taskType,
                                ?int $performType, ?string $formKey, array $actorIds, string $operator,
                                ?string $parentTaskId = null, bool $isFirstTaskNode = false,
                                ?string $expireExpr = null): ProcessTask
    {
        $task = ProcessTask::create(
            $this->instanceId, $taskName, $displayName,
            $taskType, $performType, $formKey, $actorIds, $operator,
            $parentTaskId, $isFirstTaskNode
        );
        // issues/126 案 A 第一处写点：普通建单，变量源＝实例变量
        self::applyExpireTime($task, $expireExpr, $this->variables);
        $this->tasks[] = $task;
        return $task;
    }

    /**
     * 创建会签任务（每个 actor 一个独立 task）
     *
     * 串行会签（issues/93）：仅创建第一位成员任务，并把会签计数状态写入该任务变量
     * （operatorList_{node} 全量办理人 / loopCounter_{node} 当前序号 / nrOfInstances_{node} 总数），
     * 由 CountersignHandler 在每位成员完成时推进创建下一位——任意时刻仅 1 个 DOING 会签任务，
     * 对齐 mldong 内置引擎 createCountersignTask 与 Java/Go/Python/Node 的串行逐个创建。
     * PARALLEL / 未配置类型保持全员预创建。
     *
     * @param string[] $actorIds
     * @param string|null $expireExpr 节点到期表达式（issues/126 案 A · 第二/三处写点：
     *                                串行首位与并行全员各自都要算，变量源＝实例变量）
     * @return ProcessTask[]
     */
    public function createCountersignTasks(string $taskName, string $displayName, ?int $taskType,
                                            ?int $performType, ?string $formKey, array $actorIds,
                                            string $operator, ?int $countersignType = null,
                                            ?string $parentTaskId = null, bool $isFirstTaskNode = false,
                                            ?string $expireExpr = null): array
    {
        // 串行会签逐个创建（issues/93）：仅建首位 + 记录任务变量
        if ($countersignType === CountersignType::SERIAL) {
            $first = ProcessTask::create(
                $this->instanceId, $taskName, $displayName,
                $taskType, $performType, $formKey, [$actorIds[0]], $operator,
                $parentTaskId, $isFirstTaskNode
            );
            $first->getVariables()->set(FlowConst::COUNTERSIGN_OPERATOR_LIST . '_' . $taskName, $actorIds);
            $first->getVariables()->set(FlowConst::LOOP_COUNTER . '_' . $taskName, 0);
            $first->getVariables()->set(FlowConst::NR_OF_INSTANCES . '_' . $taskName, count($actorIds));
            // issues/126 案 A 第二处写点：串行会签首位成员
            self::applyExpireTime($first, $expireExpr, $this->variables);
            $this->tasks[] = $first;
            return [$first];
        }
        $list = [];
        foreach ($actorIds as $actorId) {
            $task = ProcessTask::create(
                $this->instanceId, $taskName, $displayName,
                $taskType, $performType, $formKey, [$actorId], $operator,
                $parentTaskId, $isFirstTaskNode
            );
            // issues/126 案 A 第三处写点：并行会签全员（每人一行，各自按同一表达式算）
            self::applyExpireTime($task, $expireExpr, $this->variables);
            $this->tasks[] = $task;
            $list[] = $task;
        }
        return $list;
    }

    /**
     * 驳回任务（退回上一步）——血缘版（规范 04 · 退回上一步）：上一步来源＝当前行的
     * task_parent_id，复活调用方取出的那条历史行；不按模型入边拓扑推。
     *
     * @param ProcessTask|null $history 血缘前驱行（由引擎按 parentTaskId 从仓储取；取不到传 null）
     * @throws JeeflowException 无血缘 / 守卫不过各一条固定文案（引擎内部码 20010007、20010008 不进 msg）
     */
    public function rejectTask(ProcessModel $model, ProcessTask $currentTask, ?ProcessTask $history): ProcessTask
    {
        if ($history === null) {
            throw new JeeflowException('上一步任务ID为空，无法驳回至上一步处理');
        }
        $current = $model->getNode($currentTask->getTaskName());
        $parent = $model->getNode($history->getTaskName());
        if ($current === null || $parent === null || !NodeModel::canRejected($current, $parent)) {
            throw new JeeflowException('无法驳回至上一步处理，请确认上一步骤并非fork、join、suprocess以及会签任务');
        }

        $hisVars = $history->getVariables()->toArray();
        // 首任务节点那条由发起人提交 ⇒ 参与者取该行 u_userId；其余取该行办结人。
        // 老行没这个键 ⇒ 按 false 处理（宁可派给该行 actorId，也不用带"仅进行中"判定的现算值）。
        $isFirstRow = !empty($hisVars[FlowConst::IS_FIRST_TASK_NODE]);
        if ($isFirstRow) {
            $operator = isset($hisVars[FlowConst::USER_USER_ID]) ? (string) $hisVars[FlowConst::USER_USER_ID] : '';
            if ($operator === '') {
                $operator = (string) $this->getOperator();
            }
        } else {
            $operator = (string) ($history->getActorId() ?? '');
        }
        if ($operator === '') {
            throw new JeeflowException('上一步任务ID为空，无法驳回至上一步处理');
        }
        $newTask = $this->createTask(
            $history->getTaskName(),
            $history->getDisplayName(),
            $history->getTaskType(),
            $history->getPerformType(),
            $history->getFormKey(),
            [$operator],
            $history->getCreateUser() ?? '',
            // parent 随行拷贝＝"上一步的上一步"，与 mldong-boot2 一致
            $history->getParentTaskId(),
            $isFirstRow
            // 注意：**不**在这里传 expireExpr —— 回退新建的变量源是随行拷贝那份（boot2 的
            // hisVariable），与建单三处的实例变量是两档；混用会让"表达式是个变量名"这一档跨栈得到
            // 不同答案。到期时间在下面 setVariables 之后按 $vars 单独算（issues/126 第四处写点）。
        );
        $vars = self::lineageVars($hisVars, $isFirstRow);
        $newTask->setVariables($vars);
        // issues/126 案 A 第四处写点：到期时间按"被回退掉的那个"节点（＝当前节点）的表达式**重算**，
        // 同 mldong-boot2:386；不是继承被回退行的 expire_time
        if ($current instanceof TaskModel) {
            self::applyExpireTime($newTask, $current->getExpireTime(), $vars);
        }
        return $newTask;
    }

    /**
     * 复活行的变量：只带数据类键。剔掉控制类残留是刻意为之——非必填字段第一次填了、第二次不填时，
     * 整包克隆会把上次提交值带进新待办（用户视角是"我没提交这个怎么显示了"）；会签计数簿记
     * （loopCounter / nrOfInstances / operatorList）同理，留着会让复活的会签节点从错位的序号继续推进。
     * 保留 f_*、u_*、autoGenTitle、isFirstTaskNode。
     */
    private static function lineageVars(array $src, bool $isFirstRow): FlowData
    {
        $out = FlowData::create();
        foreach ($src as $k => $v) {
            $k = (string) $k;
            if ($k === FlowConst::SUBMIT_TYPE || $k === 'taskName'
                || str_starts_with($k, FlowConst::TASK_FORM_DATA_PREFIX)
                || str_starts_with($k, FlowConst::COUNTERSIGN_VARIABLE_PREFIX)
                || str_starts_with($k, FlowConst::LOOP_COUNTER)
                || str_starts_with($k, FlowConst::NR_OF_INSTANCES)
                || str_starts_with($k, FlowConst::COUNTERSIGN_OPERATOR_LIST)) {
                continue;
            }
            $out->set($k, $v);
        }
        $out->set(FlowConst::IS_FIRST_TASK_NODE, $isFirstRow);
        return $out;
    }

    // ═══ 查询方法 ═══

    /** @return ProcessTask[] */
    public function getDoingTasks(): array
    {
        return array_values(array_filter($this->tasks, fn(ProcessTask $t) => $t->isDoing()));
    }

    /** @return ProcessTask[] */
    public function getFinishedTasks(): array
    {
        return array_values(array_filter($this->tasks, fn(ProcessTask $t) => $t->isFinished()));
    }

    public function isAllTasksFinished(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->isDoing()) return false;
        }
        return true;
    }

    public function isDoing(): bool
    {
        return $this->state === ProcessInstanceState::DOING;
    }

    public function isFinished(): bool
    {
        return $this->state === ProcessInstanceState::FINISHED;
    }

    private function findDoingTask(string $taskId): ProcessTask
    {
        foreach ($this->tasks as $task) {
            if ($task->getTaskId() === $taskId) {
                if (!$task->isDoing()) {
                    throw new \RuntimeException("任务[{$taskId}]不是进行中状态");
                }
                return $task;
            }
        }
        throw new \RuntimeException("未找到任务[{$taskId}]或不在聚合根中");
    }

    // ═══ Getters/Setters ═══

    public function getInstanceId(): ?string { return $this->instanceId; }
    public function setInstanceId(?string $v): void { $this->instanceId = $v; }
    public function getParentId(): ?string { return $this->parentId; }
    public function setParentId(?string $v): void { $this->parentId = $v; }
    public function getDefineId(): ?string { return $this->defineId; }
    public function setDefineId(?string $v): void { $this->defineId = $v; }
    public function getState(): int { return $this->state; }
    public function setState(int $v): void { $this->state = $v; }
    public function getParentNodeName(): ?string { return $this->parentNodeName; }
    public function setParentNodeName(?string $v): void { $this->parentNodeName = $v; }
    public function getBusinessNo(): ?string { return $this->businessNo; }
    public function setBusinessNo(?string $v): void { $this->businessNo = $v; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $v): void { $this->operator = $v; }
    public function getExpireTime(): ?string { return $this->expireTime; }
    public function setExpireTime(?string $v): void { $this->expireTime = $v; }
    public function getVariables(): FlowData { return $this->variables; }
    public function setVariables(FlowData $v): void { $this->variables = $v; }
    /** @return ProcessTask[] */
    public function getTasks(): array { return $this->tasks; }
    /** @param ProcessTask[] $v */
    public function setTasks(array $v): void { $this->tasks = $v; }
    public function getCreateTime(): ?string { return $this->createTime; }
    public function setCreateTime(?string $v): void { $this->createTime = $v; }
    public function getCreateUser(): ?string { return $this->createUser; }
    public function setCreateUser(?string $v): void { $this->createUser = $v; }
    public function getUpdateTime(): ?string { return $this->updateTime; }
    public function setUpdateTime(?string $v): void { $this->updateTime = $v; }
    public function getUpdateUser(): ?string { return $this->updateUser; }
    public function setUpdateUser(?string $v): void { $this->updateUser = $v; }
}
