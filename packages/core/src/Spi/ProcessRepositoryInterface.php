<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\Domain\FlowData;

/**
 * 流程仓储 SPI —— 对齐 Java IProcessRepository
 *
 * 核心五表的读写抽象。引擎通过此接口与持久层交互，
 * 实现可以是内存（测试）、PDO/MySQL、或其他。
 */
interface ProcessRepositoryInterface
{
    /** 获取 ID 生成器 */
    public function getIdGenerator(): IdGeneratorInterface;

    // ── 流程定义 ──

    /**
     * 按 ID 查找流程定义
     * @return array{id:int|string,name:string,displayName:string,type:?string,state:int,content:string,version:int}|null
     */
    public function findDefineById(int|string $id): ?array;

    /** 新增流程定义 */
    public function addDefine(array $define): void;

    /** 更新流程定义内容 */
    public function updateDefine(array $define): void;

    /** 删除流程定义 */
    public function removeDefine(int|string $id): void;

    /** 更新定义状态（1 启用 / 0 停用） */
    public function updateDefineState(int|string $id, int $state): void;

    /** 按名称查找最新定义 */
    public function findLatestDefineByName(string $name): ?array;

    /** 流程定义分页 */
    public function pageDefines(PageQuery $query): PageResult;

    // ── 流程实例 ──

    public function saveInstance(object $instance): void;
    public function updateInstance(object $instance): void;
    public function findInstanceById(int|string $id): ?object;

    /** 流程实例分页 */
    public function pageInstances(PageQuery $query): PageResult;

    // ── 流程任务 ──

    public function saveTask(object $task): void;
    public function updateTask(object $task): void;
    public function findTaskById(int|string $id): ?object;

    /** 查找进行中的任务 */
    public function findDoingTasks(int|string $instanceId, ?array $actorIds = null): array;

    /** 查找已完成的任务 */
    public function findHistoryTasks(int|string $instanceId): array;

    /** 为任务追加参与人 */
    public function addTaskActor(int|string $taskId, array $actorIds): void;

    /** 待办任务分页 */
    public function pageTodoTasks(PageQuery $query): PageResult;

    /** 已办任务分页 */
    public function pageDoneTasks(PageQuery $query): PageResult;

    // ── 抄送 ──

    /**
     * @param string[] $actorIds
     */
    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void;

    /** 标记抄送已读 */
    public function updateCcStatus(int|string $instanceId, string $operator): void;

    /** 抄送列表分页 */
    public function pageCcInstances(PageQuery $query): PageResult;
}
