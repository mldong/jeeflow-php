<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessPublisher;
use Jeeflow\Core\Execution;

/**
 * 开始节点模型
 */
class StartModel extends NodeModel
{
    protected function exec(Execution $execution): void
    {
        // PROCESS_INSTANCE_START（spec §11.3 码 1，对齐 Java StartModel.exec）：
        // 实例已在 saveInstance 落库、processInstanceId 已分配——§11.2 原则 3 的「实例行 insert
        // 之后」即此。本栈监听器对它不发站内信（§11.4/集成层职责），但引擎 fire 以保持清单完整。
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::PROCESS_INSTANCE_START,
            $execution->getProcessInstanceId(),
            null,
            [ProcessPublisher::KEY_INSTANCE_ID => $execution->getProcessInstanceId()],
        ));
        $this->runOutTransition($execution);
    }
}
