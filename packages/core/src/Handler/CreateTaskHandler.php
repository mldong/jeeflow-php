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

        if ($this->taskModel->getPerformType() === PerformType::COUNTERSIGN) {
            $tasks = $instance->createCountersignTasks(
                $this->taskModel->getName(),
                $this->taskModel->getDisplayName(),
                $this->taskModel->getTaskType(),
                $this->taskModel->getPerformType(),
                $this->taskModel->getForm() ?: null,
                $actors,
                $operator,
                $this->taskModel->getCountersignType()
            );
        } else {
            $task = $instance->createTask(
                $this->taskModel->getName(),
                $this->taskModel->getDisplayName(),
                $this->taskModel->getTaskType(),
                $this->taskModel->getPerformType(),
                $this->taskModel->getForm() ?: null,
                $actors,
                $operator
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

        // 1. 动态指定下一节点处理人优先
        $nextNodeOperator = $args->get(FlowConst::NEXT_NODE_OPERATOR);
        if ($nextNodeOperator !== null && (string) $nextNodeOperator !== '') {
            if (is_array($nextNodeOperator) || $nextNodeOperator instanceof \Traversable) {
                foreach ((array) $nextNodeOperator as $o) {
                    $t = trim((string) $o);
                    if ($t !== '' && !in_array($t, $actors, true)) $actors[] = $t;
                }
            } else {
                foreach (explode(',', (string) $nextNodeOperator) as $a) {
                    $t = trim($a);
                    if ($t !== '' && !in_array($t, $actors, true)) $actors[] = $t;
                }
            }
            return $actors;
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
