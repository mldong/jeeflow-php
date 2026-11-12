<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\PerformType;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Util\FlowUtil;

/**
 * 动态会签（征询模式，issues/247）的判据、账本与投影：全部只读/纯函数，
 * 由门面在聚合变更**之前**调用——"判据前置、报错即零写入"与后加签写入口同一规矩。
 *
 * 契约出处：spec 02 §4.4（开关与三条解析期硬校验）＋ spec 04「动态会签（征询模式）」＋
 * spec 06 §2.7 投影与 §processTask/revokeDynamicCountersign（逐字失败文案表）。
 * 行为基准＝java `core/CollectSupport.java`（ffe2749），第二工作样本＝go `engine/collect.go`、
 * python `jeeflow/collect.py`。
 *
 * 语义一句话：主办在审批节点提交时动态拉若干人**收意见**，主办行**不办结**、
 * 闸门挡到收齐（挡**全部** submitType，含驳回），唯一出口是撤回动态会签。
 * 不新增 submitType 档、不加 DDL、不新增节点类型——簿记全写在任务行的 variable JSON 里，
 * 与后加签同键名（`tf_addSignOperators` / `tf_addSignHistory` / `tf_addSignReason` /
 * `addSignRound_{node}`）。
 *
 * ⚠️ 三条承重判据（java 测试照出来的，逐条都有理由，不可"顺手简化"）：
 *  1. **成员行识别＝parent 且同节点同名**（{@see self::memberRows()}）：只按 `task_parent_id`
 *     认成员会把主办推进后的下游行误当成员（第二次撤回会把下游行废弃）；
 *  2. **闸门收齐＝无 DOING 成员行**（{@see self::pendingMembers()}）：计数与投影一律跳过
 *     99（ABANDON）行——撤回清场留下的 99 行不进分母；
 *  3. 撤回的参与人判断**按名册**、不能用 `isAllowed`（那条在门面与
 *     {@see \Jeeflow\Core\Domain\ProcessTask::isAllowed()}，`isAllowed` 只认 DOING 行，
 *     主办行办结后会把合法撤回判成无权）。
 */
final class CollectSupport
{
    /** 节点属性键：动态会签（征询模式）开关（properties 平铺层，缺省 false） */
    public const PROP_DYNAMIC_COUNTERSIGN = 'dynamicCountersign';

    /** 逐字判据文案（跨栈统一；规范 06 失败文案表） */
    public const MSG_PENDING = '节点[%s]动态会签意见未收齐（已提交 %d/%d），不能办理';
    public const MSG_DUPLICATE = '操作人[%s]已在节点[%s]的动态会签中';
    public const MSG_MEMBER_PULL = '动态会签成员任务不能发起加签';
    public const MSG_NO_PENDING = '节点[%s]没有进行中的动态会签';
    public const MSG_NO_PERMISSION_REVOKE = '无权限撤回该动态会签';

    /** 三条解析期禁配文案（规范 02 §4.4，issues/247；逐字对齐 java ModelParser） */
    public const MSG_MUTEX_FORBID = '节点[%s]不允许同时配置 allowAddSign 与 dynamicCountersign：'
        . '两档共用 tf_addSignOperators，行为靠节点属性分流，双开＝行为歧义';
    public const MSG_COUNTERSIGN_FORBID = '会签节点[%s]不允许配置 dynamicCountersign：'
        . '会签池推进语义与征询挂起语义打架';
    public const MSG_FIRST_TASK_FORBID = '申请节点[%s]不允许配置 dynamicCountersign：'
        . '申请节点那手走实例撤回＋重新发起，不需要征询挂起';

    /** 全生态时间格式（规范 §2.4），与 tf_addSignHistory / tf_transferHistory 同尺 */
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct() {}

    /**
     * 成员行＝parent 指向主办行**且同节点同名**的行（成员不能再拉人 ⇒ 无更深层级）。
     *
     * ⚠️ 承重判据 1：**同名过滤不可省**——主办办结推进后，下一节点行的 parent 同样是主办行 id
     * （建单不变量），只认 parent 会把下游行误当成员——撤回腿会误废弃它们。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[]
     */
    public static function memberRows(array $tasks, ProcessTask $host): array
    {
        $out = [];
        $hostId = $host->getTaskId();
        if ($hostId === null) {
            return $out;
        }
        foreach ($tasks as $t) {
            if ($t->getParentTaskId() === $hostId && $t->getTaskName() === $host->getTaskName()
                // issues/253：协办行（task_type=1）也满足 parent＋同节点同名，但它属另一族
                // ——征询收齐闸只数征询成员行（task_type=0），两闸各数各的（规范 04「与征询并存」）。
                && !AidantSupport::isAidantRow($t)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 成员行判定：parent 指向**同节点同名**行（规范 04 · 动态会签；成员行禁带拉人参数的判据）。
     *
     * 无父档（null / '' / '0'）⇒ 不是成员行（本栈 id 是串，'0' 即 java 的 `== 0L` 那一档）。
     * issues/253：协办行（task_type=1）不是征询成员行——两族靠 task_type 分辨。
     *
     * @param ProcessTask[] $tasks
     */
    public static function isMemberRow(array $tasks, ProcessTask $row): bool
    {
        $parentId = $row->getParentTaskId();
        if ($parentId === null || $parentId === '' || $parentId === '0') {
            return false;
        }
        if (AidantSupport::isAidantRow($row)) {
            return false;
        }
        foreach ($tasks as $t) {
            if ($t->getTaskId() === $parentId && $t->getTaskName() === $row->getTaskName()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 闸门原料：在册成员行总数与已提交（FINISHED）数——**已废弃(99) 行（撤回腿清场的）不算在册**
     * （承重判据 2）；空集＝本行不是征询主办行。
     *
     * @param ProcessTask[] $tasks
     * @return array{0:int,1:int} [总数, 已提交数]
     */
    public static function countMembers(array $tasks, ProcessTask $host): array
    {
        $total = 0;
        $submitted = 0;
        foreach (self::memberRows($tasks, $host) as $t) {
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
     * 未答成员行（DOING），撤回接口的废弃集合。
     *
     * 承重判据 2 的正向表述：**收齐判据＝"还有无 DOING 成员行"**，不是"提交数 == 总数"——
     * 撤回清场的 99 行既不在册也不待答，闸门因此自然恢复，不需要额外的复位动作。
     *
     * @param ProcessTask[] $tasks
     * @return ProcessTask[]
     */
    public static function pendingMembers(array $tasks, ProcessTask $host): array
    {
        $out = [];
        foreach (self::memberRows($tasks, $host) as $t) {
            if ($t->isDoing()) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 投影（规范 06 §2.7）：征询主办行 `ext.dynamicCountersign`
     * ＝ {active, round, total, submitted, actors[]}；**无成员行 ⇒ null**（不输出空壳）。
     *
     * 99 行不进分母、也不进 actors（与 {@see self::countMembers()} 同一条尺子）。
     *
     * @param ProcessTask[] $tasks
     * @return array<string, mixed>|null
     */
    public static function buildProjection(array $tasks, ProcessTask $host): ?array
    {
        $members = self::memberRows($tasks, $host);
        if ($members === []) {
            return null;
        }
        $total = 0;
        $submitted = 0;
        $actors = [];
        foreach ($members as $m) {
            if ($m->getTaskState() === ProcessTaskState::ABANDON) {
                continue; // 撤回清场的行不进投影
            }
            $total++;
            if ($m->isFinished()) {
                $submitted++;
            }
            $ids = $m->getActorIds();
            $actors[] = [
                'actor' => $ids === [] ? null : $ids[0],
                'taskId' => $m->getTaskId(),
                'state' => $m->getTaskState(),
            ];
        }
        return [
            'active' => $submitted < $total,
            'round' => self::lastRound($host),
            'total' => $total,
            'submitted' => $submitted,
            'actors' => $actors,
        ];
    }

    /** 主办行当前轮次＝账本条数里最后一条的 round（每次拉人追加一条＝轮次推进；撤回不消耗）。 */
    public static function lastRound(?ProcessTask $host): int
    {
        $history = AddSignSupport::historyOf($host);
        if ($history === []) {
            return 0;
        }
        $r = end($history)['round'] ?? null;
        return is_numeric($r) ? (int) $r : 0;
    }

    /**
     * 发起会签账本追加（只追加不覆盖），落**主办行**（征询模式下主办行未办结，
     * 任务行变量的合并序保证账本活到办结后）；每条含 reason（选填，空则不写键）。
     *
     * @param string[] $toActors
     */
    public static function appendCollectHistory(?ProcessTask $host, string $operator,
                                                array $toActors, int $round, ?string $reason): void
    {
        $entry = self::baseEntry($operator, $toActors, $round);
        if ($reason !== null && $reason !== '') {
            $entry['reason'] = $reason;
        }
        self::append($host, $entry);
    }

    /**
     * 撤回会签账本追加：`revoked＝true` 标记本条是撤回（未答废弃），`toActors`＝被撤的人。
     *
     * ⚠️ 规范 11 码表**没有**为撤回动态会签发新码——这一条账本就是唯一留痕（逐字对齐 java）。
     *
     * @param string[] $revokedActors
     */
    public static function appendRevokeHistory(?ProcessTask $host, string $operator,
                                               array $revokedActors, int $round, ?string $reason): void
    {
        $entry = self::baseEntry($operator, $revokedActors, $round);
        $entry['revoked'] = true;
        if ($reason !== null && $reason !== '') {
            $entry['reason'] = $reason;
        }
        self::append($host, $entry);
    }

    /**
     * 三条解析期硬校验（规范 02 §4.4）——配了就直接报错，不做运行时降级：
     * 与 allowAddSign 双开＝共用参数 tf_addSignOperators 的行为歧义；
     * 会签节点推进语义与征询挂起语义打架；申请节点不需要征询。
     *
     * ⚠️ 调用点唯一（{@see \Jeeflow\Core\Parser\ModelParser::parse()}，排在已办撤回两判**之后**，
     * 位序照 java），因为"是不是首任务节点"要沿 start 的出边看。
     */
    public static function assertModelLegal(ProcessModel $model): void
    {
        foreach ($model->getTasks() as $task) {
            if (!$task->isDynamicCountersign()) {
                continue;
            }
            if ($task->isAllowAddSign()) {
                throw new JeeflowException(sprintf(self::MSG_MUTEX_FORBID, $task->getName()));
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
     * 账本条目基底。键序逐字照 java 的 LinkedHashMap：round / fromActor / toActors / time / operator。
     *
     * @param string[] $toActors
     * @return array<string, mixed>
     */
    private static function baseEntry(string $operator, array $toActors, int $round): array
    {
        return [
            'round' => $round,
            'fromActor' => $operator,
            'toActors' => array_values($toActors),
            'time' => date(self::TIME_FORMAT),
            'operator' => $operator,
        ];
    }

    /**
     * 追加进主办行的 `tf_addSignHistory` 账本（键即 {@see FlowConst::ADD_SIGN_HISTORY}）。
     *
     * ⚠️ 与 {@see AddSignSupport::appendHistory()} 同一条读法（`asHistory` 兼容 JSON 往返），
     * 但**不复用它**：那一条的条目形状没有 reason/revoked 两枚键，写入口也不带这两个参数。
     */
    private static function append(?ProcessTask $host, array $entry): void
    {
        if ($host === null) {
            return;
        }
        $vars = $host->getVariables();
        $history = AddSignSupport::historyOf($host);
        $history[] = $entry;
        $vars->set(FlowConst::ADD_SIGN_HISTORY, $history);
    }
}
