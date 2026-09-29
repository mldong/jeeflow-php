<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Fixture;

use Jeeflow\Core\Event\ProcessEvent;
use Jeeflow\Core\Event\ProcessEventListener;

/**
 * 事件记录器（测试用）：按 fire 顺序收全量 {@see ProcessEvent}。
 *
 * issues/142 A 批用它钉"落库与码 3 解耦"——记录类节点的历史行**严禁**收编进
 * `saveNewTask → notifyTaskStart` 那条"新待办产生"的腿
 * （spec 02-flow-definition.md §6.1 硬结论 2／§11.3 码 3 语义）。
 */
final class RecordingCustomListener implements ProcessEventListener
{
    /** @var ProcessEvent[] */
    public array $events = [];

    public function onEvent(ProcessEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * 按枚举规范名筛（如 `PROCESS_TASK_START`）。
     *
     * @return ProcessEvent[]
     */
    public function ofType(string $typeName): array
    {
        return array_values(array_filter(
            $this->events,
            fn(ProcessEvent $e) => $e->getType()->name === $typeName
        ));
    }
}
