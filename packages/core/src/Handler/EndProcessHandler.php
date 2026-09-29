<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\PendingInstanceEnd;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Model\EndModel;

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
        $submitType = $execution->getArgs()->getInt(FlowConst::SUBMIT_TYPE, SubmitType::AGREE);

        if ($submitType === SubmitType::REJECT) {
            $execution->getProcessInstance()->reject();
        } else {
            $execution->getProcessInstance()->finish();
        }

        // 实例终态事件（spec §11.3 码 2 PROCESS_INSTANCE_END）：办结与拒绝共用这一支，
        // 规范名不拆，靠载荷 state 分（§11.6 收口口径）。直传载荷键 instanceId + state。
        // 一处覆盖三条路径：execute 走到 end / jumpToEnd / start 直达 end。
        //
        // **只登记、不就地 fire**（§11.2 原则 3／08-compliance 场景 32「state 落库之后」）：
        // 上面 finish()/reject() 只改了内存聚合根，实例那一行要等调用方——
        // JeeflowEngine::persistTasks（或发起路径）——的 updateInstance 才落库。
        // 在这里 fire 就是"先播后写"，监听器（站内信反查、待办角标、persist 回写）当下
        // 反查实例读到的是旧 state，issues/121／126 两轮"回写序"教训的同一族。
        // 真正的 fire 收口在 JeeflowEngine::flushInstanceEndEvents。
        $instance = $execution->getProcessInstance();
        $execution->addPendingEnd(new PendingInstanceEnd(
            $execution->getProcessInstanceId(), $instance->getState(), $instance,
        ));

        // 子流程处理：如果有父流程，继续执行父流程
        if ($instance->getParentId() !== null && $execution->getEngine() !== null) {
            $parentInstance = $execution->getEngine()->getRepository()->findInstanceById($instance->getParentId());
            if ($parentInstance !== null) {
                $parentDefine = $execution->getEngine()->getRepository()->findDefineById($parentInstance->getDefineId());
                if ($parentDefine !== null) {
                    $pm = \Jeeflow\Core\Parser\ModelParser::parse((string) $parentDefine['content']);
                    if ($pm !== null) {
                        $spm = $pm->getNode($instance->getParentNodeName() ?? '');
                        if ($spm !== null) {
                            $newExec = new Execution();
                            $newExec->setEngine($execution->getEngine());
                            $newExec->setProcessModel($pm);
                            $newExec->setProcessInstance($parentInstance);
                            $newExec->setProcessInstanceId($parentInstance->getInstanceId());
                            $newExec->setArgs($execution->getArgs());
                            $spm->execute($newExec);
                            $execution->addTasks($newExec->getProcessTaskList());
                            // 父实例若被这一支流转带到终态，**它的**子流程节点会再进一次本处理器，
                            // 登记挂在 newExec 上；newExec 是这里的局部对象，随即丢弃 ⇒ 待播事件
                            // 必须与任务一起上收到外层 execution（同 addTasks 那条腿），漏一行
                            // 就是"父实例终态事件整支丢掉"。
                            $execution->addPendingEnds($newExec->getPendingEnds());
                        }
                    }
                }
            }
        }
    }
}
