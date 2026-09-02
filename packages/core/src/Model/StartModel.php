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
        // INSTANCE_START（InstanceStart 语义，对齐 Java StartModel.exec）：
        // 实例已在 saveInstance 落库、processInstanceId 已分配。本批次监听器
        // 对它不发站内信（spec §4.4），但引擎 fire 以保持事件清单完整。
        ProcessPublisher::notify(ProcessEvent::of(
            ProcessEventTypeEnum::INSTANCE_START,
            $execution->getProcessInstanceId(),
        ));
        $this->runOutTransition($execution);
    }
}
