<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Model\EndModel;

/**
 * 结束流程实例处理器
 *
 * 对齐 Java EndProcessHandler。
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

        // 子流程处理：如果有父流程，继续执行父流程
        $instance = $execution->getProcessInstance();
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
                        }
                    }
                }
            }
        }
    }
}
