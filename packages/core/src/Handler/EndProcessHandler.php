<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Enum\TaskType;
use Jeeflow\Core\Event\PendingInstanceEnd;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\SubProcessModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Support\StartAndExecuteSupport;

/**
 * 结束流程实例处理器
 *
 * 对齐 Java EndProcessHandler：本处理器**只登记实例终态、不就地 fire**，
 * 真正的 fire 收口在 `JeeflowEngine::flushInstanceEndEvents`（spec §11.2 原则 3）。
 */
class EndProcessHandler implements HandlerInterface
{
    private EndModel $endModel;

    public function __construct(EndModel $endModel)
    {
        $this->endModel = $endModel;
    }

    public function handle(Execution $execution): void
    {
        $engine = $execution->getEngine();
        $instance = $execution->getProcessInstance();
        if ($engine === null || $instance === null) {
            return;
        }

        // issues/257 对齐 SnakerFlow EndProcessHandler：有未完成主办任务时先拒绝结束，
        // 且该守卫发生在任何协办自动办结/实例终态变更之前。协办由 flow.auto 同意办结。
        // 当前聚合根优先，避免把本次命令刚废弃/办结但尚未落库的兄弟任务误判成进行中。
        $active = $instance->getDoingTasks();
        $knownTaskIds = [];
        foreach ($instance->getTasks() as $task) {
            $knownTaskIds[(string) $task->getTaskId()] = true;
        }
        foreach ($engine->getRepository()->findDoingTasks($instance->getInstanceId()) as $task) {
            if (!isset($knownTaskIds[(string) $task->getTaskId()])) $active[] = $task;
        }
        foreach ($active as $task) {
            if ($task->getTaskType() !== TaskType::ASSIST) {
                throw new \Jeeflow\Core\JeeflowException('存在未完成的主办任务,请确认.');
            }
        }
        $activeTaskIds = [];
        foreach ($active as $task) $activeTaskIds[(string) $task->getTaskId()] = true;
        foreach ($active as $task) {
            $args = $execution->getArgs()->copy();
            $args->set(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);
            $engine->executeProcessTask($task->getTaskId(), FlowConst::AUTO_ID, $args);
        }
        if ($active !== []) {
            $refreshed = $engine->getRepository()->findInstanceById($instance->getInstanceId());
            if ($refreshed !== null) {
                $tasks = $instance->getTasks();
                foreach ($refreshed->getTasks() as $task) {
                    $index = null;
                    foreach ($tasks as $i => $current) {
                        if ((string) $current->getTaskId() === (string) $task->getTaskId()) { $index = $i; break; }
                    }
                    $taskId = (string) $task->getTaskId();
                    if (isset($activeTaskIds[$taskId]) || $index === null) {
                        if ($index === null) $tasks[] = $task;
                        else $tasks[$index] = $task;
                    }
                }
                $instance->setTasks($tasks);
                $instance->getVariables()->setAll($refreshed->getVariables()->toArray());
            }
        }

        $submitType = $execution->getArgs()->getInt(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);

        if ($submitType === SubmitType::REJECT) {
            $instance->reject();
        } else {
            $instance->finish();
        }

        // 实例终态事件（spec §11.3 码 2 PROCESS_INSTANCE_END）：办结与拒绝共用这一支，
        // 规范名不拆，靠载荷 state 分（§11.6 收口口径）。直传载荷键 instanceId + state。
        // 只登记、不就地 fire；真正 fire 由 JeeflowEngine 在落库后统一 flush。
        $execution->addPendingEnd(new PendingInstanceEnd(
            $execution->getProcessInstanceId(), $instance->getState(), $instance,
        ));

        // 子流程处理：终态落定后按 parentId/parentNodeName 精确回到父 SubProcessModel。
        if ($instance->getParentId() !== null) {
            $parentInstance = $engine->getRepository()->findInstanceById($instance->getParentId());
            if ($parentInstance === null) return;
            $parentDefine = $engine->getRepository()->findDefineById($parentInstance->getDefineId());
            if ($parentDefine === null) return;
            $pm = ModelParser::parse((string) $parentDefine['content']);
            $spm = $pm->getNode($instance->getParentNodeName() ?? '');
            if (!$spm instanceof SubProcessModel) return;

            $newExec = new Execution();
            $newExec->setEngine($engine);
            $newExec->setProcessModel($pm);
            $newExec->setProcessInstance($parentInstance);
            $newExec->setProcessInstanceId($parentInstance->getInstanceId());
            $newExec->setArgs($execution->getArgs());
            $newExec->setOperator(StartAndExecuteSupport::realOperator($execution->getOperator(), $instance));
            $spm->execute($newExec);

            // 父级联任务与终态随独立 continuation 上收，由根 execution 递归落库后统一播。
            $execution->addContinuation($newExec);
        }
    }
}
