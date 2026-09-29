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

    /** 为任务追加参与人（去重追加，不清空既有参与人） */
    public function addTaskActor(int|string $taskId, array $actorIds): void;

    /**
     * 按人摘除任务参与人（issues/115，SPI 必选方法，对齐 Java removeTaskActor /
     * Go RemoveTaskActor / Python remove_task_actor / Node removeTaskActor）
     *
     * 语义：只删 {@code actorIds} 里这些人**在该任务下**的参与者行，其余参与人一行不动
     * （会签节点转办摘的是"自己那一票"）。全量重置参与者请用 removeTaskActor + addTaskActor 组合，
     * addTaskActor 本身永远是追加语义。
     *
     * @param string[] $actorIds
     */
    public function removeTaskActor(int|string $taskId, array $actorIds): void;

    /** 待办任务分页 */
    public function pageTodoTasks(PageQuery $query): PageResult;

    /** 已办任务分页 */
    public function pageDoneTasks(PageQuery $query): PageResult;

    // ── 抄送 ──

    /**
     * 建 cc 行。
     *
     * issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4）：本方法**自带判重**——
     * 同一 `(instanceId, actorId)` 已有 cc 行时跳过，不新增行、不重置未读、不更新原行时间。
     * 需要"到底新建了哪些人"（拿去 fire CC_CREATE）的调用方请走
     * {@see self::createCcInstanceIfAbsent()}，不要直接用本方法后按原始请求全量 fire。
     *
     * @param string[] $actorIds
     */
    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void;

    /**
     * issues/141 G2 写侧判重的**读侧**：某实例已存在 cc 行的 actor id 集合。
     *
     * 形状照 java 参考实现（`IProcessRepository#findCcActorIds`，default 方法返回空集）。
     * ⚠️ PHP 接口**不能有方法体**，故这里只能声明为**必选方法**（本仓 SPI 演进的既有姿势，
     * 同 issues/115 的 `removeTaskActor`）——自实现仓储的集成方必须补这两个方法，
     * 最小实现即 java 的 default 语义：本方法返回 `[]` ⇒ 不判重，
     * {@see self::createCcInstanceIfAbsent()} 原样转调 {@see self::createCcInstance()}
     * 并把**全量入参**当"新建子集"返回＝旧行为。
     *
     * jeeflow 自带的两仓（{@see \Jeeflow\Core\Repository\InMemoryProcessRepository} 与
     * PDO 仓 `Jeeflow\RepositoryPDO\PdoProcessRepository`）**必须**给出真实实现：否则
     * issues/141 G1 那条「同一栈 SQL 仓与内存仓两个答案」的分叉会在写侧重演一遍。
     *
     * @return string[]
     */
    public function findCcActorIds(int|string $instanceId): array;

    /**
     * issues/141 G2：写侧幂等建 cc 行，返回**实际新建**的 actor 子集（顺序与入参一致，
     * 同一次调用内的重复也折叠）。
     *
     * 同一 `(instanceId, actorId)` 已有 cc 行时**跳过**：①不新增行、②不重置未读状态
     * （`state`）、③不更新原行时间（`create_time`/`update_time` 逐字不变）——重复抄送同一个人
     * 在数据面上是 no-op（owner 2026-09-29 明确「不需要重置」，不产生"再提醒一次"语义）。
     *
     * 为什么要返回子集而不是 void：spec 11.2 原则 1「码值表达发生了什么事实」⇒
     * 没发生"创建"就不得 fire `CC_CREATE`（码 4）。三条入口（发起 `f_ccActors`／办理 `tf_ccActors`／
     * 门面手动 `createCCInstance`）逐人 fire 的入参一律换成这个子集，子集为空则整支不 fire
     * （见 {@see \Jeeflow\Core\Event\ProcessPublisher::notifyCcCreate()} 的两个调用点）。
     *
     * 仓储不覆写 {@see self::findCcActorIds()}（返回空集）时，本方法退化成"全量建行＋全量返回"
     * ＝旧行为，与 java 侧 default 方法同一档（见上方兼容性说明）。
     *
     * @param string[] $actorIds
     * @return string[] 实际新建的 actor 子集
     */
    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array;

    /**
     * 标记抄送已读
     */
    public function updateCcStatus(int|string $instanceId, string $operator): void;

    /**
     * 抄送列表分页（"抄送我"）。
     *
     * **归属条件必填**（issues/141 G1 · spec 06-facade.md §2.5「抄送分页同一条尺子」）：
     * 查询必须带 `cc.actor_id` 的**有效**归属条件；条件缺失或为空值（值为 null / 字符串全空白 /
     * 集合为空）时**返回空页**（`recordCount=0`、`rows=[]`），严禁退化成"这条不加"而返回全部实例。
     * 非归属列的空值放行不受影响（`m_` 前缀那类可选过滤照旧按"没填即不过滤"）。
     *
     * 同一栈的 SQL 仓与内存仓在同一条判据上**必须给同一个答案**（issues/117 场景 27 那把尺子
     * 扩到 ccList）：只修一边不算修完。
     */
    public function pageCcInstances(PageQuery $query): PageResult;

    // ── 统计（issues/103） ──

    /** 获取全部流程实例（统计聚合用） */
    public function getAllInstances(): array;

    /** 获取全部流程任务（统计聚合用） */
    public function getAllTasks(): array;
}
