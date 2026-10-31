<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Exception\JeeflowException;
use Jeeflow\Core\Model\ForkModel;
use Jeeflow\Core\Model\JoinModel;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;

/**
 * 「退回修改、重提直返」——分支归属与直返上下文（PHP 栈）。
 *
 * 状态落点与 Java/Go/Python/Node 参考实现一致：零 DDL，全部寄存在任务变量保留键里
 * （spec 04-engine-ops「退回修改、重提直返」）。
 */
final class ReapplySupport
{
    public const PROP_REAPPLY_MODE = 'reapplyMode';
    public const VAR_BRANCH_CTX = '_branchCtx';
    public const VAR_REAPPLY_CTX = '_reapplyContext';
    public const NORMAL = 'NORMAL';
    public const RETURN_TO_SOURCE = 'RETURN_TO_SOURCE';

    /** @return string NORMAL|RETURN_TO_SOURCE；非法值报配置错误 */
    public static function parseMode(string $raw, string $nodeName): string
    {
        $v = trim($raw);
        if ($v === '' || $v === self::NORMAL) return self::NORMAL;
        if ($v === self::RETURN_TO_SOURCE) return self::RETURN_TO_SOURCE;
        throw new JeeflowException("节点[{$nodeName}]的 reapplyMode 配置非法: {$v}");
    }

    public static function stripReserved(?FlowData $args): FlowData
    {
        $args ??= FlowData::create();
        $args->remove(self::VAR_BRANCH_CTX);
        $args->remove(self::VAR_REAPPLY_CTX);
        return $args;
    }

    /**
     * 任务行 ext 的出口投影（issues/82-3 / 121 P1 判据体）：任务变量只剩引擎建单必写的控制键
     * ⇒ 回退实例变量。控制键集合除行级首节点标记外，还含 spec 04 的两枚直返保留键
     * （`_branchCtx`／`_reapplyContext`，建单时无条件写入、空分支也写 []）——不认它们，
     * 每条任务行都从此不再回退、且内部键直接从公开出口透出（本轮八栈齐漏，只有本栈的
     * 内容级用例报红；内存仓与 PDO 仓两处出口共用这一枚判据，严禁第二份判据）。
     *
     * `_reapplyContext` 是前端「重新提交后返回：X」的唯一取数口（jeeflow-ui helpers.ts 读
     * ext._reapplyContext）⇒ 回退后原样合回。合回落在**副本**上：instanceExt 是同一响应里
     * 的另一个出口，不能被任务侧的键污染。
     *
     * @param array<string,mixed> $taskVars    任务变量
     * @param array<string,mixed> $instanceExt 实例变量
     * @return array<string,mixed>
     */
    public static function projectTaskExt(array $taskVars, array $instanceExt): array
    {
        $control = ['isFirstTaskNode' => 1, self::VAR_BRANCH_CTX => 1, self::VAR_REAPPLY_CTX => 1];
        if (array_diff_key($taskVars, $control) !== []) return $taskVars;
        if (array_key_exists(self::VAR_REAPPLY_CTX, $taskVars)) {
            $instanceExt[self::VAR_REAPPLY_CTX] = $taskVars[self::VAR_REAPPLY_CTX];
        }
        return $instanceExt;
    }

    // ─── 模型静态分析 ────────────────────────────────────────────────────────

    /** @return NodeModel[] 从节点沿出边可达的节点 */
    private static function outgoingTargets(NodeModel $node): array
    {
        $out = [];
        foreach ($node->getOutputs() as $tm) {
            if ($tm->getTarget() !== null) $out[] = $tm->getTarget();
        }
        return $out;
    }

    /**
     * 从节点沿入边回溯到指定分叉之间的任务节点名；可穿过嵌套的内层 fork，遇到 stopFork 即止。
     * @return string[]
     */
    private static function backwardTaskNames(NodeModel $node, string $stopFork): array
    {
        $out = [];
        $stack = [];
        foreach ($node->getInputs() as $tm) {
            if ($tm->getSource() !== null) $stack[] = $tm->getSource();
        }
        $visited = [];
        while ($stack) {
            /** @var NodeModel $cur */
            $cur = array_pop($stack);
            if ($cur === null || isset($visited[$cur->getName()])) continue;
            $visited[$cur->getName()] = true;
            if ($cur instanceof ForkModel && $cur->getName() === $stopFork) continue;
            if ($cur instanceof TaskModel) $out[] = $cur->getName();
            foreach ($cur->getInputs() as $tm) {
                if ($tm->getSource() !== null) $stack[] = $tm->getSource();
            }
        }
        return $out;
    }

    /**
     * 汇合点对应的分叉：取「最近后支配汇合点 === 本 join」的 fork，多候选取最外层。
     * 不能取"回溯遇到的第一个 fork"——嵌套并行时外层汇合点沿内层分支回溯会先撞到内层 fork。
     */
    private static function forkForJoin(ProcessModel $model, JoinModel $join): ?string
    {
        $best = null;
        $bestDepth = -1;
        foreach ($model->getNodes() as $node) {
            if (!$node instanceof ForkModel) continue;
            if (self::nearestPostDominatingJoin($model, $node) !== $join->getName()) continue;
            $d = self::maxDistanceToJoin($node, $join->getName());
            if ($d > $bestDepth) {
                $bestDepth = $d;
                $best = $node->getName();
            }
        }
        return $best;
    }

    private static function nearestPostDominatingJoin(ProcessModel $model, NodeModel $fork): ?string
    {
        $best = null;
        $bestMax = null;
        foreach ($model->getNodes() as $node) {
            if (!$node instanceof JoinModel) continue;
            if (!self::postDominates($fork, $node->getName())) continue;
            $d = self::maxDistanceToJoin($fork, $node->getName());
            if ($d >= 0 && ($bestMax === null || $d < $bestMax)) {
                $bestMax = $d;
                $best = $node->getName();
            }
        }
        return $best;
    }

    /** 屏蔽该汇合点后，fork 的任一分支仍能走到末端即返回 false */
    private static function postDominates(NodeModel $fork, string $joinName): bool
    {
        $stack = self::outgoingTargets($fork);
        $visited = [];
        while ($stack) {
            /** @var NodeModel $cur */
            $cur = array_pop($stack);
            if ($cur === null || $cur->getName() === $joinName || isset($visited[$cur->getName()])) continue;
            $visited[$cur->getName()] = true;
            $outs = self::outgoingTargets($cur);
            if (!$outs) return false;
            foreach ($outs as $o) $stack[] = $o;
        }
        return true;
    }

    /** 分支走到汇合点的最大跳数（外层程度）；不可达返回 -1 */
    private static function maxDistanceToJoin(NodeModel $fork, string $joinName): int
    {
        $frontier = [];
        $visited = [];
        foreach (self::outgoingTargets($fork) as $t) {
            if (!isset($visited[$t->getName()])) {
                $visited[$t->getName()] = true;
                $frontier[] = $t;
            }
        }
        $d = 1;
        $found = -1;
        while ($frontier) {
            $next = [];
            foreach ($frontier as $cur) {
                if ($cur->getName() === $joinName) $found = $d;
                foreach (self::outgoingTargets($cur) as $t) {
                    if (!isset($visited[$t->getName()])) {
                        $visited[$t->getName()] = true;
                        $next[] = $t;
                    }
                }
            }
            $frontier = $next;
            $d++;
        }
        return $found;
    }

    /** @return array<int, array{fork:string,join:string,round:string,root:string}> 外层→内层 */
    public static function staticScopes(ProcessModel $model, string $nodeName): array
    {
        $hits = [];
        foreach ($model->getNodes() as $node) {
            if (!$node instanceof JoinModel) continue;
            $fork = self::forkForJoin($model, $node);
            if ($fork === null) continue;
            $inside = self::backwardTaskNames($node, $fork);
            if (!in_array($nodeName, $inside, true)) continue;
            $hits[] = ['size' => count($inside), 'fork' => $fork, 'join' => $node->getName()];
        }
        usort($hits, static fn($a, $b) => $b['size'] <=> $a['size']);
        return array_map(static fn($h) => ['fork' => $h['fork'], 'join' => $h['join'], 'round' => '0', 'root' => '0'], $hits);
    }

    public static function buildScopes(ProcessModel $model, string $nodeName, ?ProcessTask $parent,
                                       string $selfId): array
    {
        $statics = self::staticScopes($model, $nodeName);
        if (!$statics) return [];
        $parentScopes = $parent !== null ? self::scopesOf($parent->getVariables()) : [];
        $parentId = $parent !== null ? (string) $parent->getTaskId() : '0';
        $out = [];
        foreach ($statics as $s) {
            $inherited = null;
            foreach ($parentScopes as $p) {
                if ($p['fork'] === $s['fork'] && $p['join'] === $s['join']) { $inherited = $p; break; }
            }
            $out[] = $inherited !== null
                ? ['fork' => $s['fork'], 'join' => $s['join'], 'round' => $inherited['round'], 'root' => $inherited['root']]
                : ['fork' => $s['fork'], 'join' => $s['join'], 'round' => $parentId, 'root' => $selfId];
        }
        return $out;
    }

    // ─── 变量读写 ────────────────────────────────────────────────────────────

    public static function scopesOf(?FlowData $vars): array
    {
        if ($vars === null) return [];
        $raw = $vars->get(self::VAR_BRANCH_CTX);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $out[] = ['fork' => (string) ($item['fork'] ?? ''), 'join' => (string) ($item['join'] ?? ''),
                      'round' => (string) ($item['round'] ?? '0'), 'root' => (string) ($item['root'] ?? '0')];
        }
        return $out;
    }

    public static function reapplyOf(?FlowData $vars): ?array
    {
        if ($vars === null) return null;
        $raw = $vars->get(self::VAR_REAPPLY_CTX);
        return is_array($raw) && $raw !== [] ? $raw : null;
    }

    public static function branchOf(?array $rc): array
    {
        if ($rc === null) return [];
        return self::scopesOf(FlowData::of([self::VAR_BRANCH_CTX => $rc['branch'] ?? []]));
    }

    public static function findScope(array $scopes, string $joinName): ?array
    {
        foreach ($scopes as $s) if ($s['join'] === $joinName) return $s;
        return null;
    }

    public static function newContext(string $sourceTaskId, string $sourceNode, array $branch): array
    {
        return ['mode' => self::RETURN_TO_SOURCE, 'sourceTaskId' => $sourceTaskId,
                'sourceNode' => $sourceNode, 'branch' => $branch];
    }

    // ─── 引擎侧适配 ──────────────────────────────────────────────────────────

    /** 建单钩子：给本次新任务补分支归属（父任务在聚合内按 ID 查）。 */
    public static function attachScopes(ProcessModel $model, ProcessInstance $instance, array $tasks,
                                        string $nodeName, string $parentId): void
    {
        if ($tasks === []) return;
        $parent = null;
        foreach ($instance->getTasks() as $t) {
            if ((string) $t->getTaskId() === $parentId) { $parent = $t; break; }
        }
        foreach ($tasks as $nt) {
            $nt->getVariables()->set(self::VAR_BRANCH_CTX,
                self::buildScopes($model, $nodeName, $parent, (string) $nt->getTaskId()));
        }
    }

    /** 汇合是否可放行：有分支归属按本轮各根判定，无归属回落旧判据。 */
    public static function mergeReady(ProcessInstance $instance, JoinModel $joinModel, string $arrivingId): bool
    {
        $arriving = null;
        foreach ($instance->getTasks() as $t) {
            if ((string) $t->getTaskId() === $arrivingId) { $arriving = $t; break; }
        }
        $scope = $arriving !== null ? self::findScope(self::scopesOf($arriving->getVariables()), $joinModel->getName()) : null;
        if ($scope === null) return self::legacyMerged($instance, $joinModel);

        $roots = [];
        foreach ($instance->getTasks() as $t) {
            $s = self::findScope(self::scopesOf($t->getVariables()), $joinModel->getName());
            $inRound = $s !== null && $s['fork'] === $scope['fork'] && $s['round'] === $scope['round'];
            if ($inRound && !array_key_exists($s['root'], $roots)) $roots[$s['root']] = false;
            if ($t->getTaskState() !== ProcessTaskState::DOING) continue;
            if ($inRound) $roots[$s['root']] = true;
            foreach (self::branchOf(self::reapplyOf($t->getVariables())) as $b) {
                if ($b['join'] === $joinModel->getName() && $b['fork'] === $scope['fork']
                        && $b['round'] === $scope['round']) {
                    $roots[$b['root']] = true;
                }
            }
        }
        if ($roots === []) return self::legacyMerged($instance, $joinModel);
        return !in_array(true, $roots, true);
    }

    /** 存量/无归属任务的旧判据（与改造前逐字同形） */
    private static function legacyMerged(ProcessInstance $instance, JoinModel $joinModel): bool
    {
        $doing = $instance->getDoingTasks();
        if ($doing === []) return true;
        foreach ($joinModel->getInputs() as $input) {
            $source = $input->getSource();
            if ($source === null) continue;
            foreach ($doing as $task) {
                if ($source->getName() === $task->getTaskName()) return false;
            }
        }
        return true;
    }
}
