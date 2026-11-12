<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\TaskType;
use Jeeflow\Core\JeeflowException;

/**
 * 动态协办（issues/253，规范 04 · 动态协办）的判据、账本与投影：全部只读/纯函数，
 * 由引擎在聚合变更**之前**调用——"判据前置、报错即零写入"与后加签/征询/撤回写入口同一规矩。
 *
 * 契约出处：spec 04「动态协办（aidant）」＋ spec 06 §2.7 协议键与 §processTask/aidant（逐字失败文案表）。
 * 行为基准＝java `core/AidantSupport.java`（issues/253 实施轮），第二工作样本＝本栈
 * {@see CollectSupport}（征询）／{@see RecallSupport}（撤回）同族形状。
 *
 * **与征询（{@see CollectSupport}）的分辨判据＝task_type**：两族子行都是 parent 指向主办行＋
 * 同节点同名，协办行 task_type=1（{@see TaskType::ASSIST}）、征询成员行 task_type=0。
 * 等待闸只数协办行、征询收齐闸只数成员行，各数各的（规范 04「与征询允许同节点并存」）。
 *
 * **无节点开关**：协办档只由运行期 `processTask/aidant` 派生，节点级 taskType=1 解析期禁配
 * （issues/252，见 TaskTypeAssistParseTest）。
 *
 * ⚠️ 本栈 id 是**串**（java 是 Long）：parent/同名比较一律严格相等，不做数值化
 * （19 位雪花 id 转 float 丢精度，与 {@see RecallSupport::collectDescendants()} 同一条纪律）。
 */
final class AidantSupport
{
    /** 逐字判据文案（跨栈统一；规范 06 失败文案表「协办六行」） */
    public const MSG_NO_ACTORS = 'actorIds 必填';
    public const MSG_NO_PERMISSION = '无权限派生协办任务';
    public const MSG_NOT_DOING = '任务非进行中，不可派生协办';
    public const MSG_DUPLICATE = '该人已有未办结的协办任务';
    public const MSG_NO_RECURSION = '协办任务不能再派生协办';
    public const MSG_WAIT_PENDING = '节点[%s]协办未办结（已办结 %d/%d），不能办理';
    public const MSG_TASK_NOT_FOUND = '任务不存在';

    /** 全生态时间格式（规范 §2.4），与 tf_addSignHistory / tf_transferHistory / tf_recallHistory 同尺 */
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct() {}

    /** 协办行判定＝task_type=1（ASSIST）。这是两族子行唯一的分辨判据。 */
    public static function isAidantRow(?ProcessTask $row): bool
    {
        return $row !== null && $row->getTaskType() === TaskType::ASSIST;
    }

    /**
     * 协办行＝parent 指向主办行**且同节点同名且 task_type=1** 的行。
     *
     * ⚠️ 同名过滤不可省（照 {@see CollectSupport::memberRows()} 的理由）：主办办结推进后，
     * 下一节点行的 parent 同样是主办行 id（建单不变量），只认 parent 会把下游行误当协办行。
     * task_type 过滤同样不可省：征询成员行也满足 parent＋同名，混进来会让等待闸把
     * "意见未收齐"误报成"协办未办结"。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[]
     */
    public static function aidantRows(array $tasks, ?ProcessTask $host): array
    {
        $out = [];
        if ($host === null || $host->getTaskId() === null) {
            return $out;
        }
        $hostId = $host->getTaskId();
        $hostName = $host->getTaskName();
        foreach ($tasks as $t) {
            if (self::isAidantRow($t)
                && $t->getParentTaskId() === $hostId
                && $t->getTaskName() === $hostName) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 等待闸原料：在册协办行总数与已办结（FINISHED）数——已废弃(99) 行（离开节点清场的）
     * 不算在册（规范 04「计数跳过已废弃(99) 行」）。
     *
     * @param ProcessTask[] $tasks
     * @return array{0:int,1:int} [总数, 已办结数]
     */
    public static function countAidant(array $tasks, ?ProcessTask $host): array
    {
        $total = 0;
        $submitted = 0;
        foreach (self::aidantRows($tasks, $host) as $t) {
            if ($t->getTaskState() === ProcessTaskState::ABANDON) {
                continue;
            }
            $total++;
            if ($t->isFinished()) {
                $submitted++;
            }
        }
        return [$total, $submitted];
    }

    /**
     * 未办结协办行（DOING）——等待闸的判据集合，也是离开节点时的清场集合。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[]
     */
    public static function pendingAidant(array $tasks, ?ProcessTask $host): array
    {
        $out = [];
        foreach (self::aidantRows($tasks, $host) as $t) {
            if ($t->isDoing()) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 等待闸开关（waitCompletion）：派生时盖在**主办行**变量上，缺省 false＝不等。
     * 多轮派生时后一次的值生效（投影 ext.aidant.wait 同源）。
     *
     * ⚠️ 本栈 bool 转串是 "1"/""（不是 java 的 "true"/"false"），故 bool 档直接取值，
     * 串档才走 "true" 比较——与 java `Boolean.parseBoolean(String.valueOf(v))` 终态同答案。
     */
    public static function waitOf(?ProcessTask $host): bool
    {
        if ($host === null) {
            return false;
        }
        $v = $host->getVariables()->get(FlowConst::AIDANT_WAIT);
        if ($v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v) || is_object($v)) {
            return false;
        }
        return strcasecmp(trim((string) $v), 'true') === 0;
    }

    /**
     * 等待闸（规范 04「推进闸与等待闸」）：waitCompletion=true 时主办行办结前判
     * "不存在 DOING 协办行"，未齐 ⇒ 逐字文案报错、零写入。协办行自身不受本闸约束
     * （它办结本来就不驱动流程）；waitCompletion=false（缺省）原样放行。
     *
     * ⚠️ 必须由引擎排在 {@see ProcessInstance::completeTask()} 之前——内存仓储无回滚，
     * 判据后置时"报错"那一次调用结束后主办待办已经消失（与后加签/退回守卫同一规矩）。
     *
     * @param ProcessTask[] $tasks
     */
    public static function gateWait(array $tasks, ?ProcessTask $host): void
    {
        if ($host === null || self::isAidantRow($host) || !self::waitOf($host)) {
            return;
        }
        if (self::pendingAidant($tasks, $host) === []) {
            return;
        }
        [$total, $submitted] = self::countAidant($tasks, $host);
        throw new JeeflowException(sprintf(self::MSG_WAIT_PENDING, $host->getTaskName(), $submitted, $total));
    }

    /**
     * 离开节点时清场未办结协办行（规范 04：waitCompletion=false 时主办可随时推进，
     * 未办结协办行随废弃腿清场、账本记废弃，不留孤儿待办）。
     *
     * 只对协办行（task_type=1）动手，且**不要求节点开任何开关**——协办无节点开关，
     * 普通节点上的协办行同样要清。按 parent 分组，废弃事实追加到各自主办行的账本上。
     * 幂等：已废弃(99)/已办结(20) 的行不再命中，重复调用不重复记账。
     *
     * @return int 实际废弃的行数
     */
    public static function abandonPendingOnNode(?ProcessInstance $instance, ?string $nodeName, string $operator): int
    {
        if ($instance === null || $nodeName === null || $nodeName === '') {
            return 0;
        }
        // parent → 该主办行下待清场的协办行（PHP 数组保序＝java LinkedHashMap 的行序）
        $byHost = [];
        foreach ($instance->getTasks() as $t) {
            if ($t->getTaskName() === $nodeName && $t->isDoing() && self::isAidantRow($t)) {
                $parent = $t->getParentTaskId();
                $key = $parent ?? '';
                if (!isset($byHost[$key])) {
                    $byHost[$key] = [];
                }
                $byHost[$key][] = $t;
            }
        }
        $abandoned = 0;
        foreach ($byHost as $parentId => $rows) {
            $actors = [];
            foreach ($rows as $t) {
                $t->abandon($operator);
                $actors[] = self::firstActor($t);
                $abandoned++;
            }
            // ⚠️ PHP 数组键把数字串 id（"1003"）强转成 int——回传 findTask(?string) 前必须铸回串，
            // 否则 TypeError（本栈 id 是串，与 RecallSupport 严格串比较同一条纪律）。
            $host = self::findTask($instance, $parentId === '' ? null : (string) $parentId);
            if ($host !== null) {
                self::appendAbandonHistory($host, $operator, $actors, self::lastRound($host));
            }
        }
        return $abandoned;
    }

    /**
     * 投影（规范 06 §2.7）：主办行 ext.aidant＝{active, round, total, submitted, actors, wait}；
     * 无协办行 ⇒ null（不输出空壳，与征询投影同口径）。
     *
     * @param ProcessTask[] $tasks
     * @return array<string, mixed>|null
     */
    public static function buildProjection(array $tasks, ?ProcessTask $host): ?array
    {
        $rows = self::aidantRows($tasks, $host);
        if ($rows === []) {
            return null;
        }
        $total = 0;
        $submitted = 0;
        $actors = [];
        foreach ($rows as $t) {
            if ($t->getTaskState() === ProcessTaskState::ABANDON) {
                continue; // 清场的行不进投影（与征询投影同口径）
            }
            $total++;
            if ($t->isFinished()) {
                $submitted++;
            }
            $actors[] = [
                'actor' => self::firstActor($t),
                'taskId' => $t->getTaskId(),
                'state' => $t->getTaskState(),
            ];
        }
        return [
            'active' => $submitted < $total,
            'round' => self::lastRound($host),
            'total' => $total,
            'submitted' => $submitted,
            'actors' => $actors,
            'wait' => self::waitOf($host),
        ];
    }

    /** 主办行当前轮次＝账本条数里最后一条的 round（每次派生追加一条＝轮次推进；清场记账不消耗轮次）。 */
    public static function lastRound(?ProcessTask $host): int
    {
        $history = self::historyOf($host);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $r = $history[$i]['round'] ?? null;
            if (is_numeric($r)) {
                return (int) $r;
            }
        }
        return 0;
    }

    /**
     * 派生账本追加（只追加不覆盖），落**主办行**变量；键序锁定
     * {round, fromActor, toActors, reason, time, operator}（规范 06 §2.7），
     * reason 空串兜底、time 一律 §2.4 字符串。
     *
     * 主办行在派生时不办结，{@see ProcessTask::finish()} 的 variables 合并（setAll）
     * 是合并而非整体替换，故账本能活到主办办结之后（与转办/征询账本同一条存活理由）。
     *
     * @param string[] $toActors
     */
    public static function appendAidantHistory(?ProcessTask $host, string $operator,
                                               array $toActors, int $round, ?string $reason): void
    {
        self::append($host, self::baseEntry($operator, $toActors, $round, $reason));
    }

    /**
     * 清场账本追加：abandoned=true 标记本条是"离开节点废弃"（形状照征询撤回条目的
     * revoked=true），六键原序不动，toActors＝被废弃的人。
     *
     * @param string[] $abandonedActors
     */
    public static function appendAbandonHistory(?ProcessTask $host, string $operator,
                                                array $abandonedActors, int $round): void
    {
        $entry = self::baseEntry($operator, $abandonedActors, $round, '');
        $entry['abandoned'] = true;
        self::append($host, $entry);
    }

    /** 读账本（测试与投影用）；无该键／脏值 ⇒ 空表。@return array<int, array<string, mixed>> */
    public static function historyOf(?ProcessTask $task): array
    {
        if ($task === null) {
            return [];
        }
        return self::asHistory($task->getVariables()->get(FlowConst::AIDANT_HISTORY));
    }

    /**
     * 账本条目基底。键序逐字照 java 的 LinkedHashMap：round / fromActor / toActors / reason / time / operator。
     *
     * @param string[] $toActors
     * @return array<string, mixed>
     */
    private static function baseEntry(string $operator, array $toActors, int $round, ?string $reason): array
    {
        return [
            'round' => $round,
            'fromActor' => $operator,
            'toActors' => array_values($toActors),
            'reason' => $reason ?? '',
            'time' => date(self::TIME_FORMAT),
            'operator' => $operator,
        ];
    }

    /**
     * 追加进主办行的 tf_aidantHistory 账本。
     *
     * ⚠️ 与 {@see CollectSupport::append()} 同一条读法（asHistory 兼容 JSON 往返），但落的是
     * 协办自己的键（AIDANT_HISTORY），不与征询账本（ADD_SIGN_HISTORY）混用——两族各记各的。
     *
     * @param array<string, mixed> $entry
     */
    private static function append(?ProcessTask $host, array $entry): void
    {
        if ($host === null) {
            return;
        }
        $vars = $host->getVariables();
        $history = self::historyOf($host);
        $history[] = $entry;
        $vars->set(FlowConst::AIDANT_HISTORY, $history);
    }

    /**
     * 账本取值兼容：JSON 往返后仍是 list&lt;map&gt;；不是列表（脏值／伪造）就当没有，
     * 但**绝不覆盖**已有形状（与 {@see AddSignSupport::asHistory()} 同款）。
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

    private static function findTask(ProcessInstance $instance, ?string $taskId): ?ProcessTask
    {
        if ($taskId === null || $taskId === '') {
            return null;
        }
        foreach ($instance->getTasks() as $t) {
            if ($t->getTaskId() === $taskId) {
                return $t;
            }
        }
        return null;
    }

    private static function firstActor(ProcessTask $t): ?string
    {
        $ids = $t->getActorIds();
        return $ids === [] ? null : $ids[0];
    }
}