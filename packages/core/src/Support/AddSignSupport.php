<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Util\CcActorUtil;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 「后加签（可重复）」——配置解析、写入口校验、轮次与账本读写（PHP 栈**唯一**判据点）。
 *
 * 契约出处：spec 04-engine-ops「后加签（可重复）」＋ spec 06-facade §2.7；
 * 行为基准＝java `core/AddSignSupport`＋`parser/ModelParser`＋`handler/impl/AddSignHandler`。
 *
 * **为什么判据只在这里有一份**（本栈历史病：抄送那族曾因判据散在各调用点而分叉，
 * issues/142 B 批把归一抬成单点）：节点两开关的非法值判据、四条写入口判据、
 * 「本节点是否还有未完成待办」、「废弃本节点未完成加签」、轮次与账本的读写，
 * 全部只由本类兑现一次；解析期两条硬校验也收在本类（{@see self::assertModelLegal()}），
 * {@see \Jeeflow\Core\Parser\ModelParser} 只有一个调用点——不在多处各写一份。
 * 加签人的归一**不另造尺子**，直接复用 {@see CcActorUtil::normalizeActors()}
 * （spec 06 §2.10/§2.11 那枚单点：逗号串与数组两形同判据、逐元素 trim、空/null 丢弃）。
 *
 * ⚠️ **校验一律排在改动状态之前**：内存仓储没有事务回滚，一旦先 `completeTask` 再判错，
 * 那条任务行就在聚合里变成已完成而无人能改回来——"报错且零写入"是这条链的硬要求，
 * 所以 {@see self::validateSubmission()} 由引擎在 `prepareExecution` 里、
 * `$instance->completeTask()` 之前调用，而不是放进 {@see \Jeeflow\Core\Handler\AddSignHandler}。
 */
final class AddSignSupport
{
    /** 节点属性键：后加签开关（properties 平铺层，缺省 false） */
    public const PROP_ALLOW_ADD_SIGN = 'allowAddSign';
    /** 节点属性键：加签轮次上限（0＝不限，缺省 0） */
    public const PROP_ADD_SIGN_MAX_ROUNDS = 'addSignMaxRounds';

    /** 逐字判据文案（跨栈统一；spec 06 文末失败 msg 表与 java 同一条） */
    public const MSG_MUTEX = 'tf_addSignOperators 与 tf_nextNodeOperator 互斥，不能同时提交';
    public const MSG_SWITCH_OFF = '节点[%s]未开启 allowAddSign，不接受 tf_addSignOperators';
    public const MSG_SELF = '不能把任务加签给自己';
    public const MSG_MAX_ROUNDS = '节点[%s]的加签轮次已达上限 %d，本次提交被拒绝';

    /** 全生态时间格式（spec §2.4），与 tf_transferHistory 同尺 */
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    /** addSignMaxRounds 两档尾注（与 java 的三档文案逐字对齐：小数档另有「（必须是整数）」） */
    private const TAIL_NOT_INTEGER = '（必须是整数，0 表示不限）';
    private const TAIL_NEGATIVE = '（不得为负，0 表示不限）';

    private function __construct() {}

    /**
     * 解析布尔开关：缺省 false；非布尔报配置错误，不得静默落回缺省
     * （判据照 reapplyMode 既有先例，逐字对齐 java 的档位）。
     *
     * issues/246/247 起 $keyName 成为第三参：同一把尺子服务三枚开关
     * （allowAddSign / allowRecall / dynamicCountersign），非法值文案里的键名取**实际那枚**，
     * 不写死 allowAddSign——逐字对齐 java `AddSignSupport.parseAllow(value, nodeName, keyName)`。
     */
    public static function parseAllow(mixed $value, string $nodeName, string $keyName = self::PROP_ALLOW_ADD_SIGN): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            throw new JeeflowException(self::illegalProp($keyName, '数组', $nodeName));
        }
        $v = trim(self::stringify($value));
        if ($v === '') {
            return false;
        }
        if (strcasecmp($v, 'true') === 0) {
            return true;
        }
        if (strcasecmp($v, 'false') === 0) {
            return false;
        }
        throw new JeeflowException(self::illegalProp($keyName, $v, $nodeName));
    }

    /**
     * 解析 addSignMaxRounds：缺省 0＝不限；非整数或负数报配置错误，不得静默当缺省。
     *
     * 三档与 java 逐字同尺：整数（含 1.0 这类"值上是整的浮点"）⇒ 取值；
     * 带小数的数 ⇒ "（必须是整数）"；非整数串 ⇒ "（必须是整数，0 表示不限）"；
     * 负数 ⇒ "（不得为负，0 表示不限）"。
     */
    public static function parseMaxRounds(mixed $value, string $nodeName): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            // java 侧 String.valueOf(Boolean) 得 "true"/"false"，走的是"非整数串"那一档
            throw new JeeflowException(self::illegalRounds($value ? 'true' : 'false', $nodeName,
                self::TAIL_NOT_INTEGER));
        }
        if (is_int($value)) {
            return self::guardNonNegative($value, (string) $value, $nodeName);
        }
        if (is_array($value)) {
            // 数组不是合法轮次值，且 PHP 强转数组会撞 Array to string conversion 警告
            //（本仓 phpunit failOnWarning ⇒ 必须先挡在转串之前）
            throw new JeeflowException(self::illegalRounds('数组', $nodeName, self::TAIL_NOT_INTEGER));
        }
        if (is_float($value)) {
            if ($value !== floor($value) || is_infinite($value)) {
                // 数值档的小数：java 那句尾注是"（必须是整数）"，与串档差半句，逐字保留
                throw new JeeflowException(self::illegalRounds(self::stringify($value), $nodeName,
                    '（必须是整数）'));
            }
            return self::guardNonNegative((int) $value, self::stringify($value), $nodeName);
        }
        $v = trim(self::stringify($value));
        if ($v === '') {
            return 0;
        }
        if (!preg_match('/^[+-]?\d+$/', $v)) {
            throw new JeeflowException(self::illegalRounds($v, $nodeName, self::TAIL_NOT_INTEGER));
        }
        return self::guardNonNegative((int) $v, $v, $nodeName);
    }

    /** 本次提交指定的加签人（逗号串与数组两形共用 §2.11 那枚归一单点；没点人返回空表）。 */
    /** @return string[] */
    public static function operators(?FlowData $args): array
    {
        if ($args === null) {
            return [];
        }
        return CcActorUtil::normalizeActors($args->get(FlowConst::ADD_SIGN_OPERATORS));
    }

    /**
     * 写入口校验（互斥／开关未开／加给自己／超上限）。**必须排在任何状态变更之前**。
     *
     * 互斥这条的理由不是语义矛盾（一个管本节点再来一轮、一个管流出后交给谁，逻辑可共存），
     * 而是**参数生命周期**：`tf_*` 键只在当次请求的执行参数与已完成任务行变量里可读，
     * 加签使令牌停留本节点、流出发生在后续请求，届时先传的 tf_nextNodeOperator 已不可读
     * ——同传时它是死参数。静默择一＝用户选的"下一节点处理人"无声消失，报错是把它变响话。
     */
    public static function validateSubmission(?ProcessModel $model, ?ProcessTask $task,
                                              ?FlowData $args, string $operator): void
    {
        if ($args === null || $task === null) {
            return;
        }
        $ops = self::operators($args);
        if ($ops !== [] && self::hasNextNodeOperator($args)) {
            throw new JeeflowException(self::MSG_MUTEX);
        }
        if ($ops === []) {
            // 没点人＝正常流转档，其余判据不涉及（也不要求节点开开关）
            return;
        }
        $node = $model?->getNode($task->getTaskName());
        if (!$node instanceof TaskModel || !$node->isAllowAddSign()) {
            throw new JeeflowException(sprintf(self::MSG_SWITCH_OFF, $task->getTaskName()));
        }
        $me = CcActorUtil::normalizeActor($operator);
        foreach ($ops as $actor) {
            if ($me !== '' && $me === $actor) {
                throw new JeeflowException(self::MSG_SELF);
            }
        }
        $max = $node->getAddSignMaxRounds();
        if ($max > 0 && self::roundOf($task, $node->getName()) + 1 > $max) {
            throw new JeeflowException(sprintf(self::MSG_MAX_ROUNDS, $node->getName(), $max));
        }
    }

    /**
     * 解析期两条硬校验（spec 02 §4.3）——配了就直接报错，不做运行时降级：
     * 静默跑下去会让配置者以为开关生效，实际两档推进闸各裁各的。
     *
     * ⚠️ 调用点唯一（{@see \Jeeflow\Core\Parser\ModelParser::parse()} 的引用构造**之后**），
     * 因为"是不是首任务节点"要沿 start 的出边看（{@see FlowUtil::isFirstTaskName()}
     * 读的是 output transition 的目标）。
     */
    public static function assertModelLegal(ProcessModel $model): void
    {
        foreach ($model->getTasks() as $task) {
            if (!$task->isAllowAddSign()) {
                continue;
            }
            if ($task->getPerformType() === PerformType::COUNTERSIGN) {
                throw new JeeflowException('会签节点[' . $task->getName()
                    . ']不允许配置 allowAddSign：会签池内加人与会签之后加人是两种语义，推进闸会打架');
            }
            if (FlowUtil::isFirstTaskName($model, $task->getName())) {
                throw new JeeflowException('首任务节点[' . $task->getName()
                    . ']不允许配置 allowAddSign：申请节点的办理是发起/重提语义，与加签路由打架');
            }
        }
    }

    /** 本节点是否还有未完成任务（刚办结那条此刻已是 FINISHED，不计入）。
     *  issues/253：协办行（task_type=1）不算"兄弟待办"——它不驱动流程、不是接力链的一环，
     *  算进来会让 waitCompletion=false 的主办在加签/征询节点上被协办行挡住推进（规范 04
     *  「缺省 false 主办可随时推进」）。协办行的等待由 {@see AidantSupport::gateWait()} 单独裁决。 */
    public static function hasDoingOnNode(?ProcessInstance $instance, string $nodeName): bool
    {
        if ($instance === null || $nodeName === '') {
            return false;
        }
        foreach ($instance->getTasks() as $t) {
            if ($nodeName === $t->getTaskName() && $t->isDoing() && !AidantSupport::isAidantRow($t)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 废弃本节点未完成的加签任务（拒绝／退回发起人时调用，不留孤儿待办）。
     * 只对开了 {@see TaskModel::isAllowAddSign() allowAddSign}
     * 或 {@see TaskModel::isDynamicCountersign() dynamicCountersign} 的节点动手（issues/247：
     * 征询成员行同属本节点 DOING，实例撤回/退回路径一并清场）。
     *
     * 落库不另开腿：废弃只改聚合里的行，{@see \Jeeflow\Core\JeeflowEngine::persistTasks()}
     * 那次 `updateInstance` 的级联会 UPDATE 到既有行（与会签的 abandon 腿同一条）。
     *
     * @return int 实际废弃的行数
     */
    public static function abandonDoingOnNode(?ProcessModel $model, ?ProcessInstance $instance,
                                              string $nodeName, string $operator): int
    {
        if ($model === null || $instance === null || $nodeName === '') {
            return 0;
        }
        $node = $model->getNode($nodeName);
        if (!$node instanceof TaskModel
            || (!$node->isAllowAddSign() && !$node->isDynamicCountersign())) {
            return 0;
        }
        $abandoned = 0;
        foreach ($instance->getTasks() as $t) {
            if ($nodeName === $t->getTaskName() && $t->isDoing()) {
                $t->abandon($operator);
                $abandoned++;
            }
        }
        return $abandoned;
    }

    /** 读轮次号：首圈（普通建单产生的那条）没有该键 ⇒ 0。 */
    public static function roundOf(?ProcessTask $task, string $nodeName): int
    {
        if ($task === null) {
            return 0;
        }
        return (int) $task->getVariables()->getInt(FlowConst::ADD_SIGN_ROUND . '_' . $nodeName, 0);
    }

    /**
     * 账本追加（只追加不覆盖），落已完成任务行变量；`tf_` 前缀使退回复活腿天然剔掉它。
     *
     * @param string[] $toActors
     */
    public static function appendHistory(?ProcessTask $completed, string $operator,
                                          array $toActors, int $round): void
    {
        if ($completed === null) {
            return;
        }
        $vars = $completed->getVariables();
        $history = self::asHistory($vars->get(FlowConst::ADD_SIGN_HISTORY));
        $history[] = [
            'round' => $round,
            'fromActor' => $operator,
            'toActors' => array_values($toActors),
            'time' => date(self::TIME_FORMAT),
            'operator' => $operator,
        ];
        $vars->set(FlowConst::ADD_SIGN_HISTORY, $history);
    }

    /** 读账本（测试与投影用）。@return array<int, array<string, mixed>> */
    public static function historyOf(?ProcessTask $task): array
    {
        if ($task === null) {
            return [];
        }
        return self::asHistory($task->getVariables()->get(FlowConst::ADD_SIGN_HISTORY));
    }

    /**
     * 账本取值兼容：JSON 往返后仍是 list&lt;map&gt;；不是列表（脏值／伪造）就当没有，
     * 但**绝不覆盖**已有形状——判据只在这一处。
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
     * tf_nextNodeOperator 是否"传了"。标量档逐字照 java（非空 trim 串）；
     * 数组档走归一单点——PHP 里 `(string)[]` 会撞 Array to string conversion 警告，
     * 而归一后为空的那个参数本就什么都指定不了，不该算"同传"。
     */
    private static function hasNextNodeOperator(FlowData $args): bool
    {
        $raw = $args->get(FlowConst::NEXT_NODE_OPERATOR);
        if ($raw === null) {
            return false;
        }
        if (is_array($raw)) {
            return CcActorUtil::normalizeActors($raw) !== [];
        }
        return trim(self::stringify($raw)) !== '';
    }

    private static function guardNonNegative(int $n, string $shown, string $nodeName): int
    {
        if ($n < 0) {
            throw new JeeflowException(self::illegalRounds($shown, $nodeName, self::TAIL_NEGATIVE));
        }
        return $n;
    }

    private static function illegalProp(string $prop, string $got, string $nodeName): string
    {
        return "节点[{$nodeName}]的 {$prop} 配置非法: {$got}";
    }

    private static function illegalRounds(string $got, string $nodeName, string $tail): string
    {
        return "节点[{$nodeName}]的 " . self::PROP_ADD_SIGN_MAX_ROUNDS . " 配置非法: {$got}" . $tail;
    }

    /** 标量转串（对象没有 __toString 时不硬转，避免 Error；数组在上面已分流）。 */
    private static function stringify(mixed $value): string
    {
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }
        return (string) $value;
    }
}
