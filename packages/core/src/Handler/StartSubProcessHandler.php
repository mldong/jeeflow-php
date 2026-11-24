<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Model\SubProcessModel;
use Jeeflow\Core\Support\StartAndExecuteSupport;

/**
 * 启动子流程处理器（issues/257 主链）。
 *
 * 定义定位与启动参数遵循裁决 A/D；子实例 start 与初始任务 execute 各自已落库并
 * fire 事件，因此这里**不**把子任务 addTasks 到父 execution，只把返回值标记为已落库。
 */
class StartSubProcessHandler implements HandlerInterface
{
    private SubProcessModel $subProcessModel;

    public function __construct(SubProcessModel $subProcessModel)
    {
        $this->subProcessModel = $subProcessModel;
    }

    public function handle(Execution $execution): void
    {
        $engine = $execution->getEngine();
        $parent = $execution->getProcessInstance();
        if ($engine === null || $parent === null) {
            return;
        }

        $repository = $engine->getRepository();
        $define = StartAndExecuteSupport::requireDefine($repository, $this->subProcessModel);
        $parentDoing = [];
        foreach ($repository->findDoingTasks($parent->getInstanceId()) as $task) {
            if ($task instanceof ProcessTask && $task->getTaskId() !== null) {
                $parentDoing[(string) $task->getTaskId()] = $task;
            }
        }
        $operator = StartAndExecuteSupport::realOperator($execution->getOperator(), $parent);
        $childArgs = StartAndExecuteSupport::childArgs($execution->getArgs());

        $child = $engine->startProcessInstanceById(
            (string) $define['id'],
            $operator,
            $childArgs,
            $parent->getInstanceId(),
            $this->subProcessModel->getName(),
        );

        // 复用顶层 startAndExecute：初始 DOING 快照逐任务追加 applicant，再以
        // flow.auto + submitType=0 自办。产生的任务已由子 execute 落库/播码 3，
        // 标记后即使未来调用方带回父 execution 也不会重复保存。
        $initialTasks = StartAndExecuteSupport::applyInitialTasks(
            $engine,
            (string) $child->getInstanceId(),
            $operator,
            $childArgs,
        );
        $execution->addPersistedTasks($initialTasks);

        // 子 start/自办若已级联办结并回到父节点，可能产生**父级**新任务。
        // 它们由父级 continuation 独立保存，不能并入本 execution 再保存一次；
        // 这里只把实际新增的父任务 id 标为已持久化，保留调用方可见性。
        foreach ($repository->findDoingTasks($parent->getInstanceId()) as $task) {
            if ($task instanceof ProcessTask && $task->getTaskId() !== null
                && !isset($parentDoing[(string) $task->getTaskId()])) {
                $execution->addPersistedTasks([$task]);
            }
        }
    }
}
