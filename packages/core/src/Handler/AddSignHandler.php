<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Support\AddSignSupport;
use Jeeflow\Core\Support\ReapplySupport;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 后加签（可重复）处理器——决定本节点的推进闸（merged），并在点了人时同节点续建任务。
 *
 * 判据顺序不可反（spec 04 · 后加签；行为基准＝java `handler/impl/AddSignHandler`）：
 *  1. 本次给了加签人 → 同节点建单，`merged=false`，不流转；
 *  2. 没给但本节点还有未完成任务（多选时同圈的兄弟待办）→ `merged=false`，不流转；
 *  3. 两者皆否 → `merged=true`，由 {@see \Jeeflow\Core\Model\TaskModel::exec()} 放行出边。
 *
 * 形状基准＝串行会签的续建腿（{@see CountersignHandler::createNextCountersignTask()}），
 * 但走 {@see \Jeeflow\Core\Domain\ProcessInstance::createTask()} 而不是裸 `ProcessTask::create`：
 * 那条腿内部已按节点 expireTime 表达式同尺写 expire_time（issues/126 第五处写点的教训），
 * 加签腿因此**不新增**建单写点，spec 04 的 five-writes 计数与标记不动。
 *
 * ⚠️ 报错类判据**不在此**（互斥／开关未开／加给自己／超上限由
 * {@see AddSignSupport::validateSubmission()} 在改动任何状态之前拦下）——
 * 进到这里的提交都已判定合法；本处理器只负责建单与裁决。
 *
 * ⚠️ 加签人只认 {@see Execution::getAddSignOperators()}（引擎从**本次请求**参数带入），
 * 不读 `$execution->getArgs()`：那里合并了实例变量，历史形状下会把上一轮的
 * tf_addSignOperators 复活、凭空多建一条本节点待办（根因已在
 * {@see \Jeeflow\Core\Domain\ProcessInstance::completeTask()} 修掉，本字段是第二道，不替代根因）。
 */
class AddSignHandler implements HandlerInterface
{
    private TaskModel $taskModel;

    public function __construct(TaskModel $taskModel)
    {
        $this->taskModel = $taskModel;
    }

    public function handle(Execution $execution): void
    {
        $instance = $execution->getProcessInstance();
        $completed = $execution->getProcessTask();
        $node = $this->taskModel->getName();
        $operators = $execution->getAddSignOperators();

        if ($operators === []) {
            // 停止条件即本判据本身：没点人且本节点再无未完成待办 ⇒ 放行出边
            $execution->setMerged(!AddSignSupport::hasDoingOnNode($instance, $node));
            return;
        }

        $model = $execution->getProcessModel();
        $round = AddSignSupport::roundOf($completed, $node) + 1;
        $firstTaskNode = $model !== null && FlowUtil::isFirstTaskName($model, $node);
        $created = [];
        foreach ($operators as $actor) {
            // 参与者＝本次点名的一位；多选则同一圈建多行（每行一位，与普通/会签建单同形）
            $next = $instance->createTask(
                $this->taskModel->getName(),
                $this->taskModel->getDisplayName(),
                $this->taskModel->getTaskType(),
                // performType 取节点配置：能走到这里说明本节点不是会签（解析期已拦），
                // 加签任务因而是普通任务
                $this->taskModel->getPerformType(),
                $this->taskModel->getForm() ?: null,
                [$actor],
                $execution->getOperator(),
                // 建单不变量：血缘 parent＝刚办结的那一条，加签链就此串成链
                $execution->getProcessTaskId(),
                $firstTaskNode,
                // 到期时间同尺（走聚合根那条腿，不另造写点）
                $this->taskModel->getExpireTime()
            );
            // 轮次记在任务变量的新键上：不复用会签簿记 loopCounter——后者退回复活时被剔
            $next->getVariables()->set(FlowConst::ADD_SIGN_ROUND . '_' . $node, $round);
            $created[] = $next;
        }
        // 分支归属与每一次普通建单同一条腿（并行范围内的加签任务必须继承父任务归属，
        // 否则汇合判据读不到这一支）：复用 CreateTaskHandler 那枚 attachScopes，不抄第二份。
        if ($model !== null) {
            ReapplySupport::attachScopes($model, $instance, $created, $node,
                (string) ($execution->getProcessTaskId() ?? ''));
        }
        foreach ($created as $task) {
            $execution->addTask($task);
        }
        AddSignSupport::appendHistory($completed, $execution->getOperator(), $operators, $round);
        $execution->setMerged(false);
    }
}
