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
     * 事务包裹（audit8 R9 145-7）——引擎五入口与已办撤回内部都过此方法；门面/宿主可复用它做
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

    /**
     * 已办撤回（issues/246，规范 04 · 已办撤回）：办理人撤回**自己刚办结的那一手**，
     * 同实例内复活重办。判据全部排在任何状态变更之前（报错即零写入——内存仓储无回滚）。
     *
     * ⚠️ 参与人判断按**名册**（`actorIds`）走，不用 {@see ProcessTask::isAllowed()}——
     * 后者只认 DOING 行，而撤回目标行正是 FINISHED，用它会把合法撤回判成无权
     * （三条承重判据之一，判据本体见 {@see \Jeeflow\Core\Support\RecallSupport}）。
     *
     * @return ProcessTask 复活的新行（DOING，本实例本节点唯一活待办）
     */
    public function recallTask(string $taskId, string $operator): ProcessTask;

    /**
     * 动态协办（issues/253，规范 04 · 动态协办）：主办把"这活"交给别人干——按人派生
     * task_type=1 的协办子行（parent＝主办行、同节点同名、performType=NORMAL），
     * **主办行原样保留 DOING**；协办行办结不驱动流程，节点推进仍由主办行办结触发。
     *
     * 判据全排前（报错即零写入）：任务存在 → 禁递归 → 主办行 DOING → 名册鉴权
     * （flow.auto/flow.admin 例外）→ 逐人去重。建单走 saveNewTask 既有腿（委托并入＋落库＋fire 码 3），
     * 不新增事件码。
     *
     * @param string[] $actorIds     协办人（每人一行）
     * @param bool     $waitCompletion true＝等待闸（主办办结前判无 DOING 协办行）；false（缺省）＝不等
     * @return ProcessTask[] 本次派生的协办行（DOING）
     */
    public function aidantTask(string $taskId, array $actorIds, ?string $reason,
                               bool $waitCompletion, string $operator): array;
}
