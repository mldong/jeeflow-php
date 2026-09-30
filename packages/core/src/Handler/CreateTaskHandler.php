<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Util\CcActorUtil;

/**
 * 创建任务处理器
 *
 * 对齐 Java CreateTaskHandler。
 */
class CreateTaskHandler implements HandlerInterface
{
    private TaskModel $taskModel;

    public function __construct(TaskModel $taskModel)
    {
        $this->taskModel = $taskModel;
    }

    public function handle(Execution $execution): void
    {
        $instance = $execution->getProcessInstance();
        $model = $execution->getProcessModel();
        $operator = $execution->getOperator();

        $execution->setNodeModel($this->taskModel);
        $actors = $this->resolveActors($execution);
        $isFirstTaskNode = \Jeeflow\Core\Util\FlowUtil::isFirstTaskName(
            $model, $this->taskModel->getName());

        if ($this->taskModel->getPerformType() === PerformType::COUNTERSIGN) {
            $tasks = $instance->createCountersignTasks(
                $this->taskModel->getName(),
                $this->taskModel->getDisplayName(),
                $this->taskModel->getTaskType(),
                $this->taskModel->getPerformType(),
                $this->taskModel->getForm() ?: null,
                $actors,
                $operator,
                $this->taskModel->getCountersignType(),
                // 建单不变量：parent＝本 execution 刚办结的任务；发起时为 null ⇒ 工厂落 '0'
                $execution->getProcessTaskId(), $isFirstTaskNode,
                // issues/126 案 A：节点到期表达式随建单一起交给聚合根（串行首位 / 并行全员两档）
                $this->taskModel->getExpireTime()
            );
        } else {
            $task = $instance->createTask(
                $this->taskModel->getName(),
                $this->taskModel->getDisplayName(),
                $this->taskModel->getTaskType(),
                $this->taskModel->getPerformType(),
                $this->taskModel->getForm() ?: null,
                $actors,
                $operator,
                $execution->getProcessTaskId(), $isFirstTaskNode,
                // issues/126 案 A：普通建单按节点表达式算任务行 expire_time
                $this->taskModel->getExpireTime()
            );
            $tasks = [$task];
        }

        $execution->addTasks($tasks);
    }

    /**
     * @return string[]
     */
    private function resolveActors(Execution $execution): array
    {
        $actors = [];
        $args = $execution->getArgs();

        // 1. 动态指定下一节点处理人优先（issues/142 B 批 · spec 06 §2.11 表第三行）：
        //    逗号串与数组**两形同判据**，且必须复用 §2.10 那一枚单点。旧形状是本仓的第三、第四把
        //    手写尺子（数组臂与串臂各写一份 trim／丢空／去重），与门面腿、两仓写侧迟早分叉；
        //    外层那个 (string) 强转在入参是数组时还会撞 PHP 的 Array to string conversion 警告，
        //    靠【转出来是非空串】侥幸走进数组臂——那是运气不是设计。
        //    单点已含四件：逐元素 trim → 空串/纯空白/null 丢弃 → 同次调用折叠（严格比较，
        //    '0' 与 '00' 是两个不同的人）→ 两形同一入口。
        $byVar = CcActorUtil::normalizeActors($args->get(FlowConst::NEXT_NODE_OPERATOR));
        if ($byVar !== []) {
            return $byVar;
        }

        // 2. 固定指派 assignee
        $assignee = $this->taskModel->getAssignee();
        if ($assignee !== '') {
            foreach (explode(',', $assignee) as $raw) {
                $token = trim($raw);
                if ($token === '') continue;
                // mldong 契约特殊值：applicant → 流程发起人
                if (str_contains($token, 'applicant')) {
                    $token = str_replace('applicant', $execution->getProcessInstance()->getOperator(), $token);
                }
                $v = $args->get($token);
                if ($v !== null) {
                    if (is_array($v) || $v instanceof \Traversable) {
                        foreach ((array) $v as $o) {
                            $t = trim((string) $o);
                            if ($t !== '' && !in_array($t, $actors, true)) $actors[] = $t;
                        }
                    } else {
                        $t = trim((string) $v);
                        if ($t !== '' && !in_array($t, $actors, true)) $actors[] = $t;
                    }
                } elseif (!in_array($token, $actors, true)) {
                    $actors[] = $token;
                }
            }
        }

        // 3. 动态指派处理器 assignmentHandler（actors 为空时才生效，对齐 Java L120-140）
        if ($actors === []) {
            $handlerName = $this->taskModel->getAssignmentHandler();
            if ($handlerName !== '') {
                $registry = ServiceContext::find(AssignmentHandlerRegistry::class);
                if ($registry !== null) {
                    $handler = $registry->resolve($handlerName);
                    if ($handler !== null) {
                        $result = $handler->assign($execution);
                        if ($result !== null && $result !== '') {
                            foreach (explode(',', $result) as $a) {
                                $t = trim($a);
                                if ($t !== '' && !in_array($t, $actors, true)) {
                                    $actors[] = $t;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $actors;
    }
}
