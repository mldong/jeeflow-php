<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 已办撤回（issues/246，规范 04 · 已办撤回）的判据与账本：全部只读/纯函数，
 * 由引擎在聚合变更**之前**调用——"判据前置、报错即零写入"与后加签写入口同一规矩。
 *
 * 契约出处：spec 02 §4.4（开关与两条解析期硬校验）＋ spec 04「已办撤回」＋
 * spec 06 §processTask/recall（判据位序与五条逐字失败文案）＋ spec 11 码 10 TASK_RECALL。
 * 行为基准＝java `core/RecallSupport.java`（90469a2），第二工作样本＝go `engine/recall.go`、
 * python `jeeflow/recall.py`。
 *
 * 语义一句话：办理人收回**自己刚办结的那一手**，同实例内复活重办。窗口取宽口径
 * （owner 2026-11-03 拍）：从该行沿 `task_parent_id` 血缘产出的全部下游行中
 * **存在任何 FINISHED ⇒ 拦**；否则仍 DOING 的下游行全部废弃（后加签/征询成员行、
 * 并行分支多支自然覆盖），同节点新建 DOING 行，原 FINISHED 行**一律不改写**、
 * 只盖 `tf_recallHistory` 追加账本。
 *
 * 与 {@see AddSignSupport} 同款纪律：**判据只在这里有一份**，解析期两条硬校验也收在本类
 * （{@see self::assertModelLegal()}），{@see \Jeeflow\Core\Parser\ModelParser} 只有一个调用点。
 */
final class RecallSupport
{
    /** 节点属性键：已办撤回开关（properties 平铺层，缺省 false） */
    public const PROP_ALLOW_RECALL = 'allowRecall';

    /** 已办撤回节点开关快照键（issues/251）：办理时由引擎从 TaskModel 盖到本行变量，值＝
     *  办理时刻的 allowRecall 定义值；tf_ 前缀不进实例变量（不传播）。查询出口搬运成
     *  行顶层 allowRecall 供前端预判置灰。撤回校验仍以现读定义为权威，快照仅供预判。 */
    public const KEY_ALLOW_RECALL = 'tf_allowRecall';

    /** 逐字判据文案（跨栈统一；规范 06 §processTask/recall 失败文案表） */
    public const MSG_WINDOW = '任务[%s]的后续节点已办理，不能撤回';
    public const MSG_SWITCH_OFF = '节点[%s]未开启 allowRecall，不能撤回';
    public const MSG_NOT_FINISHED = '任务非已完成状态，不可撤回';
    public const MSG_NO_PERMISSION = '无权限撤回该任务';
    public const MSG_INSTANCE_NOT_DOING = '流程实例非进行中状态，不可撤回';

    /** 两条解析期禁配文案（规范 02 §4.4，issues/246；逐字对齐 java ModelParser） */
    public const MSG_COUNTERSIGN_FORBID = '会签节点[%s]不允许配置 allowRecall：撤"谁的一票"语义不清';
    public const MSG_FIRST_TASK_FORBID = '首任务节点[%s]不允许配置 allowRecall：'
        . '申请节点走实例撤回＋重新发起，不需要同实例复活';

    /** 全生态时间格式（规范 §2.4），与 tf_addSignHistory / tf_transferHistory 同尺 */
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct() {}

    /**
     * 窗口判据（owner 2026-11-03 拍宽口径）：从 target 沿 `task_parent_id` 链产出的全部下游行中
     * **存在任何 FINISHED** ⇒ 报错；否则返回其中仍 DOING 的行（供废弃——后加签/征询成员行、
     * 并行分支多支都自然覆盖；已废弃(99)/已撤回(30) 行既不拦也不重复废弃）。
     *
     * ⚠️ 本方法是**纯读**：报错时不碰任何行，调用方必须把它排在任何状态变更之前。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[] 下游仍 DOING 的行
     */
    public static function validateWindow(array $tasks, ProcessTask $target): array
    {
        $doing = [];
        foreach (self::collectDescendants($tasks, $target->getTaskId()) as $t) {
            if ($t->isFinished()) {
                throw new JeeflowException(sprintf(self::MSG_WINDOW, $target->getTaskName()));
            }
            if ($t->isDoing()) {
                $doing[] = $t;
            }
        }
        return $doing;
    }

    /**
     * 按 `task_parent_id` 血缘收集 target 的全部后代行（栈式 DFS；遍历顺序不改变集合内容）。
     * 血缘在建单不变量里必写（0＝无父）。
     *
     * ⚠️ 本栈 id 是**串**（java 是 Long）：比较一律走严格相等，不做数值化——
     * 19 位雪花 id 转 float 会丢精度，两个不同 id 可能读成同一个。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[]
     */
    private static function collectDescendants(array $tasks, ?string $ancestorId): array
    {
        $result = [];
        if ($ancestorId === null || $ancestorId === '') {
            return $result;
        }
        $frontier = [$ancestorId];
        while ($frontier !== []) {
            $parent = array_pop($frontier);
            foreach ($tasks as $t) {
                if ($t->getParentTaskId() === $parent) {
                    $result[] = $t;
                    $tid = $t->getTaskId();
                    if ($tid !== null) {
                        $frontier[] = $tid;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 账本追加（只追加不覆盖），落**被撤回的旧行**变量；`recalledTaskId` 指向复活的新行
     * （从历史记录可一步跳到唯一活待办）。`tf_` 前缀使退回复活腿
     * （{@see \Jeeflow\Core\Domain\ProcessInstance::lineageVars()}）天然剔掉它。
     *
     * ⚠️ 调用方随后必须把这一行**再落一次库**：本栈 PDO 仓存的是快照，不补这一刀账本就丢
     * （与 {@see AddSignSupport::appendHistory()} 同一条纪律；内存仓共享对象引用才侥幸看得见）。
     */
    public static function appendRecallHistory(?ProcessTask $target, string $operator, ?string $resurrectedTaskId): void
    {
        if ($target === null) {
            return;
        }
        $vars = $target->getVariables();
        $history = self::asHistory($vars->get(FlowConst::RECALL_HISTORY));
        $history[] = [
            'operator' => $operator,
            'time' => date(self::TIME_FORMAT),
            'recalledTaskId' => $resurrectedTaskId,
        ];
        $vars->set(FlowConst::RECALL_HISTORY, $history);
    }

    /** 读账本（测试与出证用）；无该键／脏值 ⇒ 空表。@return array<int, array<string, mixed>> */
    public static function historyOf(?ProcessTask $task): array
    {
        if ($task === null) {
            return [];
        }
        return self::asHistory($task->getVariables()->get(FlowConst::RECALL_HISTORY));
    }

    /**
     * 解析期两条硬校验（规范 02 §4.4）——配了就直接报错，不做运行时降级：
     * 会签节点撤"谁的一票"语义不清；申请节点走实例撤回＋重新发起（既有通道），
     * 不需要同实例复活。
     *
     * ⚠️ 调用点唯一（{@see \Jeeflow\Core\Parser\ModelParser::parse()} 的引用构造**之后**，
     * 且排在后加签两判**之后**、动态会签三判**之前**——位序照 java `ModelParser.parse`），
     * 因为"是不是首任务节点"要沿 start 的出边看（{@see FlowUtil::isFirstTaskName()}）。
     */
    public static function assertModelLegal(ProcessModel $model): void
    {
        foreach ($model->getTasks() as $task) {
            if (!$task->isAllowRecall()) {
                continue;
            }
            if ($task->getPerformType() === PerformType::COUNTERSIGN) {
                throw new JeeflowException(sprintf(self::MSG_COUNTERSIGN_FORBID, $task->getName()));
            }
            if (FlowUtil::isFirstTaskName($model, $task->getName())) {
                throw new JeeflowException(sprintf(self::MSG_FIRST_TASK_FORBID, $task->getName()));
            }
        }
    }

    /**
     * 账本取值兼容：JSON 往返后仍是 list&lt;map&gt;；不是列表（脏值／伪造）就当没有，
     * 但**绝不覆盖**已有形状——判据只在这一处（与 AddSignSupport::asHistory 同款）。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function asHistory(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * 办理时把当前节点的 allowRecall 开关快照进行变量（issues/251）：已办行置灰的
     * "节点开关"预判从行上读，查询出口只搬运不解析定义。tf_ 前缀键被实例合并排除
     * （completeTask 立法，不传播）；本栈建单只写 isFirstTaskNode（与 jeeflow/go 同形）。
     * 撤回校验仍以现读定义为权威，快照仅供前端预判（漂移为设计内）。
     * 非 TaskModel（start/end/decision 等不办理的节点）与 null args 静默跳过。
     */
    public static function stampAllowRecall(?FlowData $args, ?NodeModel $node): void
    {
        if ($args === null || !$node instanceof TaskModel) {
            return;
        }
        $args->set(self::KEY_ALLOW_RECALL, $node->isAllowRecall());
    }
}
