<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 流程事件类型 —— 规范名 ＋ 码值 ＋ 载荷的唯一权威契约＝**规范 11 · 事件契约**
 * （`jeeflow-doc/docs/spec/11-events.md` §11.3），issues/127＋132 立章。
 *
 * 本枚举是 A 套整型码（java 血缘 1..4 连续扩展 5..9）；八语言按**规范名**对齐，
 * **集成层跨语言判据一律用规范名，不得拿数字码当判据**（§11.6：Go/Node 原 0..5 套、
 * Python 原字符串套都重排到本套）。一个号一旦发出去不许改语义、不许复用
 * （4 号位让位给 `CC_CREATE` 的历史见该项注释）。
 *
 * v1.3.x 的 case 名 `INSTANCE_START` / `INSTANCE_END` / `TASK_START` 已按 §11.3 规范名
 * 改名为 `PROCESS_INSTANCE_START` / `PROCESS_INSTANCE_END` / `PROCESS_TASK_START`
 * （**码值 1/2/3 不动**，仅名字对齐；issues/132 §4.5 给 PHP 栈派的代码腿「名字对齐」）。
 * 集成层消费方需同步改名（laravel 壳 `WfMessageProcessEventListener` 的 match 分支）。
 */
enum ProcessEventTypeEnum: int
{
    /** 流程实例发起成功（§11.3 码 1）；sourceId＝instanceId，载荷 instanceId */
    case PROCESS_INSTANCE_START = 1;
    /** 实例进入终态（办结/拒绝共用，靠载荷 state 分，§11.3 码 2）；sourceId＝instanceId，载荷 instanceId, state */
    case PROCESS_INSTANCE_END = 2;
    /** 新待办生成（含会签逐人、回退复活行、子流程任务，§11.3 码 3）；sourceId＝taskId，载荷 instanceId, taskId, actors */
    case PROCESS_TASK_START = 3;
    /**
     * 新增一条抄送记录（§11.3 码 4，issues/102 首发活码，其余七栈向本栈码值对齐）。
     *
     * 三条路径同判（§11.2 原则 1「码值表达发生了什么事实，不表达谁触发的」）：
     * 发起 `f_ccActors`／办理 `tf_ccActors`／门面手动 `processInstance/createCCInstance`，
     * cc 行落库后**逐抄送人** fire 一次，`ccActorId` 直传事件体。
     */
    case CC_CREATE = 4;
    /**
     * 任务被办掉（同意/跳转/会签办理，§11.3 码 5，issues/132 新增）。
     *
     * 触发时机：任务行 `state` 更新为已完成**并落库之后**（§11.2 原则 3）；
     * sourceId＝taskId，载荷 instanceId, taskId, operator, submitType。
     * 与 {@see self::TASK_REJECT} **互斥**：同一个办理动作走退回就不再 fire 本支。
     * 本支不是被废弃的 `PROCESS_TASK_END`（§11.4 第 4 条明确它不复活）。
     */
    case TASK_COMPLETE = 5;
    /**
     * 任务被退回/拒绝（含退发起人、软拒绝、跳转回退，§11.3 码 6，issues/132 新增）。
     *
     * 码粗载荷细（§11.2 原则 2）：拒绝 2 / 退回上一步 3 / 退回发起人 6 / 会签拒绝 20
     * **共用本支**，靠载荷 `submitType` 区分，不各开一号。
     * 触发时机：退回动作使任务/实例落库之后；与 {@see self::TASK_COMPLETE} 互斥；
     * 实例进入终态另发 {@see self::PROCESS_INSTANCE_END}(2)，两支不互相替代。
     */
    case TASK_REJECT = 6;
    /**
     * 转办发生（§11.3 码 7，issues/132 新增，八栈此前零事件）。
     *
     * 触发时机：任务参与者在 `processTask/transfer`（issues/115）里被替换**并落库之后**；
     * sourceId＝taskId，载荷 instanceId, taskId, fromActor, toActor, operator。
     * 转办不新建任务行，故本支不伴随 {@see self::PROCESS_TASK_START}(3)。
     */
    case TASK_TRANSFER = 7;
    /**
     * 撤回发生，实例进入 30（§11.3 码 8，issues/132 新增，八栈此前零事件）。
     *
     * 触发时机：撤回把实例 `state` 写 30 **落库之后**、被撤任务行更新完成，
     * **每轮撤回只 fire 一次**（不逐任务——一次撤回动的是一个事实）；
     * sourceId＝instanceId，载荷 instanceId, operator。
     * 撤回的实例状态守卫（非 10 一律拒，内部码 20010009，issues/134）被拒的那次不发本支。
     */
    case TASK_WITHDRAW = 8;
    /**
     * 实例被终止，40 强行终止（§11.3 码 9，issues/132 新增，八栈此前零事件）。
     *
     * 触发时机：实例 `state` 写 40 **落库之后**；sourceId＝instanceId，
     * 载荷 instanceId, operator, reason。
     *
     * ⚠️ **PHP 栈现状：本支没有可达 fire 点**——门面没有「终止实例」的 action，
     * 聚合根 {@code ProcessInstance::interrupt()} 在生产路径零调用者（仅
     * `tests/WebContract/JeeflowFacadeTransferTest` 直接调它造 40 档），与 java 同形
     * （java 侧注释亦记「壳侧同样造不出这一档」）。号位按 §11.3 占住（号一旦发出不许改语义、
     * 不许复用）；终止 action 落地时必须在其落库之后挂本支 fire。
     */
    case INSTANCE_TERMINATED = 9;

    // ── 旧 case 名一代兼容别名（issues/132 第二轮 R2-4，spec §11.6「改名栈须保留旧成员名为一代别名」）──
    // PHP backed enum 不允许两个 case 同值，故用 enum 常量承载：常量值就是 case 对象本身，
    // `cases()` / `from()` / `->name` 一律不受影响（别名不进枚举本体，规范名仍是唯一权威）。
    // 旧名来自 v1.3.x：INSTANCE_START / INSTANCE_END / TASK_START（码值 1/2/3 从未变过）。
    public const INSTANCE_START = self::PROCESS_INSTANCE_START;
    public const INSTANCE_END = self::PROCESS_INSTANCE_END;
    public const TASK_START = self::PROCESS_TASK_START;
    // 10+ 预留：超时催办 / 超时自动通过 ……（§11.4 第 1 条「本轮不发」——八栈都没有时钟
    // 扫描器，发了没有触发源；占号只为防下一个新栈再发明一套，见 §11.3 表末行）

    public function label(): string
    {
        return match ($this) {
            self::PROCESS_INSTANCE_START => '流程实例开始',
            self::PROCESS_INSTANCE_END   => '流程实例结束',
            self::PROCESS_TASK_START     => '流程任务开始',
            self::CC_CREATE              => '抄送知会',
            self::TASK_COMPLETE          => '任务办结',
            self::TASK_REJECT            => '任务退回/拒绝',
            self::TASK_TRANSFER          => '任务转办',
            self::TASK_WITHDRAW          => '流程撤回',
            self::INSTANCE_TERMINATED    => '流程实例终止',
        };
    }
}
