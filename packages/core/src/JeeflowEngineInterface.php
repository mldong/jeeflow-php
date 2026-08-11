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
