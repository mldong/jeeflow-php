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

    /**
     * 为任务追加参与人（去重追加，不清空既有参与人）。
     *
     * **空值义务（issues/142 B 批 · spec 06-facade.md §2.11「归属值写侧归一」）**：
     * 这条义务**每个实现方都要自带**，不能只写在 {@see self::createCcInstance()} 上——
     * §2.11 末段"SPI 注释义务"点名的正是这个形状：注释只挂 cc 一支，第三方照注释实现必然漏任务侧。
     *
     *  1. **写侧兜底（两层里的第二层，要求①）**：入参里的**空串、纯空白、`null`** 一律丢弃，
     *     不得落进 `wf_process_task_actor.actor_id`。门面的加签腿自己也会归一，但**绕过门面
     *     直连仓储**的调用方（集成层、第三方仓储消费者）照样能从这一层灌进去，那空归属值正是
     *     issues/129 那族「空 operator 读全库」的进水口。
     *  2. **落库与判重都取 trim 后的值（要求②）**：`" 123 "` 与 `"123"` 是同一个人，
     *     不 trim 就会与自己下面的判重错开，同一人落两行。
     *  3. **判重必须严格比较（要求④）**：PHP 松散比较把数字串按数值比（`'0' == '00'`、`'1' == '01'`）
     *     ⇒ 第二个人被静默丢掉（issues/141 G2 在本栈的实测踩点）；`'0'` 这类"看起来像空"的正常 id
     *     **不得**被当空值丢弃，判空一律 `trim(...) === ''`，严禁无回调 `array_filter`／`empty()`。
     *  4. **归一后为空 ⇒ 空操作**（不发 INSERT、不改既有参与人）。
     *  5. 同一栈的 SQL 仓与内存仓在同一条判据上**必须给同一个答案**（issues/117 场景 27），
     *     只修一边不算修完。
     *
     * 本仓两处的落点：{@see \Jeeflow\Core\Util\CcActorUtil::normalizeList()}（§2.10 已落地的那一枚
     * 单点，§2.11 要求"复用、不要再抄第二份"）——内存仓与 PDO 仓各调一次，两仓同判据。
     *
     * ⚠️ `taskId` 是**主键**，与上面"归属值为空 ⇒ 丢弃"是两件事（§2.11 末段主键档）：
     * 空串/纯空白的 task id 属调用方写错，必须在门面/引擎那一层响亮报错
     * （本仓落点 `JeeflowFacade::taskSurrogate()` 的 `processTaskId 缺失或非法`），
     * 严禁拿 `''`/`0` 当 id 往下落库；实现方**不得**用"查不到就静默跳过"替代上层的报错。
     *
     * @param string[] $actorIds 参与人集合（空值元素由实现方丢弃，不落行）
     */
    public function addTaskActor(int|string $taskId, array $actorIds): void;

    /**
     * 按人摘除任务参与人（issues/115，SPI 必选方法，对齐 Java removeTaskActor /
     * Go RemoveTaskActor / Python remove_task_actor / Node removeTaskActor）
     *
     * 语义：只删 {@code actorIds} 里这些人**在该任务下**的参与者行，其余参与人一行不动
     * （会签节点转办摘的是"自己那一票"）。全量重置参与者请用 removeTaskActor + addTaskActor 组合，
     * addTaskActor 本身永远是追加语义。
     *
     * **归属值删除腿义务（issues/137 §3-6 · spec 06-facade.md §processTask/removeTaskActor 语义 6，
     * owner 2026-10-02 拍「两形并集」）——这条义务每个实现方都要自带，且与上面
     * {@see self::addTaskActor()} 的写侧义务不同，别照抄**（写侧是"落库取 trim 后的值"，
     * 删除腿是"两形并集"；把写侧那一支搬过来正是本栈 1.8.36 之前的形状，见下第 2 条）：
     *
     *  1. **空值一律丢弃、不喂 `DELETE`**：`null`／`''`／纯空白都不得进匹配集合，否则历史
     *     `actor_id=''` 脏行会被批量误删（那是替脏数据做掉唯一痕迹）。
     *  2. **非空值同时以「原值」与「trim 值」两形匹配**（按字面去重、保序）。只取一头各有一种**假成功**：
     *     只取 **trim 形** ⇒ 门面按语义 6 交出的是**行上的原值**，历史脏行 `" 9101 "` 被削成 `9101`，
     *     真库（MySQL 8.0 NO PAD 排序规则）下 `actor_id = '9101'` 打不中 `' 9101 '` 那一行，
     *     删不掉而门面报成功——被摘的人待办还在；只取 **原值** ⇒ 第三方绕过门面直连仓储传
     *     `" 8601 "` 时删不掉写侧归一后落库的规范行 `8601`（issues/142 §9.2 那一路）。
     *     两形并集同时满足两侧，且按 §2.11 归一口径 `" 9101 "` 与 `9101` 本就是**同一个人**，
     *     两行都删才是"摘掉这个人"的正确结果，不构成误删。
     *  3. **展开后为空 ⇒ 早退，一条 `DELETE` 都不发**——空列表不得退化成"清空该任务全部参与者"
     *     （那是语义 5「至少需保留一名参与人」的仓储侧对偶）。
     *
     * 判据本体只有一枚＝{@see \Jeeflow\Core\Util\CcActorUtil::deleteForms()}（各语言栈有同名对应件：
     * java `StringUtils.actorDeleteForms`／go `spi.ActorDeleteForms`／node `spi.actorDeleteForms`／
     * py `spi.actor_delete_forms`／rs `model::actor_delete_forms`／moon `@model.actor_delete_forms`／
     * c# `PageQuery.ActorDeleteForms`）；trim 与判空的规则仍复用 `CcActorUtil` 那一枚，
     * **不要在仓储里抄第二份**（spec §2.11 尾注明令）。判空一律 `trim((string) $x) === ''`——
     * `'0'` 这类"看起来像空"的正常 id **不得**被丢掉，且 `'0'` 与 `'00'` 是两个不同的人；
     * 严禁无回调 `array_filter`／`empty()` 的假值判据，去重与比较一律**严格**
     * （`in_array(..., true)`；松散比较把 `'0' == '00'` 判同人，issues/141 G2 本栈实测踩点）。
     *
     * ⚠️ 库值那一侧**按字面精确比、不要再 trim**：并集已在入参侧覆盖两形，库值再 trim 会让
     * "删未 trim 历史脏行"这件事照不出来（脏行被 trim 形命中 ⇒ 假绿），也会让内存仓与 SQL 仓分叉。
     * 同一栈的 SQL 仓与内存仓在这条判据上**必须给同一个答案**（issues/117 场景 27），只修一边不算修完。
     *
     * `taskId` 仍是**主键**不是归属值，同 {@see self::addTaskActor()} 末段那一档。
     *
     * @param string[] $actorIds 待摘除的参与人（空值元素由实现方丢弃，一行都不删）
     */
    public function removeTaskActor(int|string $taskId, array $actorIds): void;

    /** 待办任务分页 */
    public function pageTodoTasks(PageQuery $query): PageResult;

    /** 已办任务分页 */
    public function pageDoneTasks(PageQuery $query): PageResult;

    // ── 抄送 ──

    /**
     * 建 cc 行（最底层写入口）。
     *
     * issues/141 G2 写侧判重＝幂等空操作（spec 06-facade.md §4）：本方法**自带判重**——
     * 同一 `(instanceId, actorId)` 已有 cc 行时跳过，不新增行、不重置未读、不更新原行时间。
     * 需要"到底新建了哪些人"（拿去 fire CC_CREATE）的调用方请走
     * {@see self::createCcInstanceIfAbsent()}，不要直接用本方法后按原始请求全量 fire。
     *
     * issues/141 G10「空不创建行」（spec 06-facade.md §2.10）：入参里的**空串、纯空白、`null`
     * 一律丢弃**，落库值取 trim 后的串（`" 123 "` 与 `"123"` 是同一个人，与上面的 G2 判重同一条尺子）。
     * 这条义务必须落在**这一层**而不只落在引擎漏斗里——绕过 {@code JeeflowEngine::handleCcActors}
     * 与门面、直连仓储的调用方（集成层、第三方仓储消费者）同样不得把空归属值灌进 `actor_id`，
     * 那正是 issues/129 那族「空 operator 读全库」的病根。
     * 自带两仓的落点：{@see \Jeeflow\Core\Util\CcActorUtil::normalizeList()}（单点复用，两仓同一条判据）。
     *
     * ⚠️ 本轮**没有**往本接口加任何方法：PHP 接口不能有默认方法体，G2 那两个必选成员已经是
     * "第三方自实现仓储升版致命"的账（见 changelog），判据一律加在 concrete 仓与共用工具里。
     *
     * @param string[] $actorIds 抄送人集合（空值元素由实现方丢弃，不建行）
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
     * issues/141 G10「空不创建行」（spec 06-facade.md §2.10）：返回的子集**不得含空串/纯空白**，
     * 元素一律是 trim 后的串——这个子集会被三条入口直接拿去 fire 码 4，带空值等于往事件里灌
     * 空归属人；入参丢完为空 ⇒ 子集为空 ⇒ 不建行、整支不 fire（与"空集合"同档）。
     *
     * @param string[] $actorIds
     * @return string[] 实际新建的 actor 子集
     */
    public function createCcInstanceIfAbsent(int|string $instanceId, string $operator, array $actorIds): array;

    /**
     * 标记抄送已读。
     *
     * **归属值义务（issues/142 B 批 · spec 06-facade.md §2.11 表第五行）**：`operator` 在这里是
     * **归属谓词的值**（`WHERE cc.actor_id = ?`），不是普通可选过滤——它必须**归一后再比**：
     *  1. 比较两侧都取 trim 后的串（`" 123 "` 与 `"123"` 是同一个人）；
     *  2. **空串/纯空白不得命中任何行**：否则一条空 operator 会把 `state=1` 批量打到历史
     *     `actor_id=''` 的脏行上（正是 issues/129 那族「空归属值读全库」的读侧变体）。
     * 空值档（丢弃/空页）的收口姿势与 {@see self::pageCcInstances()} 的"归属条件为空 ⇒ 返回空页"
     * 同一条尺子。
     *
     * ⚠️ 本栈**当前形状如实留痕**（issues/142 B 批派单只含本条的注释义务，未含实现改动）：
     * `JeeflowFacade::updateCCStatus()` 目前把 `operator` 原样透传（未 trim、空值不拦），
     * 两仓按传入值直接比较 ⇒ 上面两条是**待收口的实现要求**，不是已验绿的现状。
     * 实现方若先补上归一（本方法语义不变，只把脏档挡住），须与门面腿共用
     * {@see \Jeeflow\Core\Util\CcActorUtil} 那一枚单点，**不要抄第二份判据**。
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
