<?php

declare(strict_types=1);

namespace Jeeflow\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;

/**
 * 工作流引擎接口
 *
 * 对齐 Java JeeflowEngine。
 */
interface JeeflowEngineInterface
{
    public function getRepository(): ProcessRepositoryInterface;

    /**
     * 事务包裹（audit8 R9 145-7）——引擎五入口内部都过此方法；门面/宿主可复用它做
     * 整次动作级包裹。嵌套调用并入最外层（模板实现 `inTransaction()` 探测时）。
     */
    public function inTx(callable $action): mixed;

    /** 启动流程实例 */
    public function startProcessInstanceById(string $defineId, string $operator, ?FlowData $args = null,
                                              ?string $parentId = null, ?string $parentNodeName = null): ProcessInstance;

    /** 执行任务（正常审批） */
    /** @return ProcessTask[] */
    public function executeProcessTask(string $taskId, string $operator, ?FlowData $args = null): array;

    /** 执行任务并跳转到指定节点 */
    /** @return ProcessTask[] */
    public function executeAndJumpTask(string $taskId, string $operator, ?FlowData $args = null, ?string $nodeName = null): array;

    /** 执行任务并跳转到结束（驳回） */
    /** @return ProcessTask[] */
    public function executeAndJumpToEnd(string $taskId, string $operator, ?FlowData $args = null): array;

    /** 执行任务并跳转到首个任务节点 */
    /** @return ProcessTask[] */
    public function executeAndJumpToFirstTaskNode(string $taskId, string $operator, ?FlowData $args = null): array;
}
