<?php

declare(strict_types=1);

namespace Jeeflow\Core\Support;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngineInterface;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\SubProcessModel;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;

/**
 * 顶层 startAndExecute 与子流程启动共用的自办腿（issues/257 裁决 D）。
 *
 * 对齐 Java StartAndExecuteSupport；门面与子流程各调用一次，避免两条路径
 * 各自复制参与者注入、APPLY 参数与下一办理人映射。
 */
final class StartAndExecuteSupport
{
    private const NOT_FOUND_PROCESS_DEFINE = 20010002;

    private function __construct() {}

    /**
     * 对实例当前全部 DOING 快照逐任务追加真实发起人并以 flow.auto/APPLY 自办。
     *
     * 快照必须先取完：无 apply 的首任务自办可能推进并新建后继任务，不能把后继
     * 也误当成初始任务继续自动办结。返回嵌套调用产生的新任务，由调用方标记为已落库。
     *
     * @return ProcessTask[]
     */
    public static function applyInitialTasks(JeeflowEngineInterface $engine, string $instanceId,
                                             string $applicant, ?FlowData $args): array
    {
        $repository = $engine->getRepository();
        $doing = $repository->findDoingTasks($instanceId);
        $result = [];
        foreach ($doing as $task) {
            $repository->addTaskActor($task->getTaskId(), [$applicant]);
            $applyArgs = $args?->copy() ?? FlowData::create();
            $applyArgs->set(FlowConst::SUBMIT_TYPE, SubmitType::APPLY);
            $next = $applyArgs->get(FlowConst::PROCESS_START_NEXT_NODE_OPERATOR);
            if ($next !== null && (string) $next !== '') {
                $applyArgs->set(FlowConst::NEXT_NODE_OPERATOR, $next);
            }
            foreach ($engine->executeProcessTask($task->getTaskId(), FlowConst::AUTO_ID, $applyArgs) as $created) {
                $result[(string) $created->getTaskId()] = $created;
            }
        }
        return array_values($result);
    }

    /** 子实例参数复制父业务参数，并剥掉父办理控制键/预指派人/分支与重提上下文。 */
    public static function childArgs(?FlowData $parentArgs): FlowData
    {
        $args = $parentArgs?->copy() ?? FlowData::create();
        $args->remove(FlowConst::SUBMIT_TYPE);
        $args->remove(FlowConst::PROCESS_START_NEXT_NODE_OPERATOR);
        $args->remove(FlowConst::NEXT_NODE_OPERATOR);
        $args->remove(ReapplySupport::VAR_BRANCH_CTX);
        $args->remove(ReapplySupport::VAR_REAPPLY_CTX);
        foreach ($args->keys() as $key) {
            if (str_starts_with((string) $key, FlowConst::TASK_FORM_DATA_PREFIX)) {
                $args->remove((string) $key);
            }
        }
        return $args;
    }

    /** flow.auto/flow.admin 触发的子流程回退父实例真实 operator，其余显式 operator 原样使用。 */
    public static function realOperator(string $operator, ?ProcessInstance $parent): string
    {
        if ($parent === null) {
            return $operator;
        }
        if (strcasecmp($operator, FlowConst::AUTO_ID) === 0
            || strcasecmp($operator, FlowConst::ADMIN_ID) === 0) {
            return $parent->getOperator();
        }
        return $operator;
    }

    /**
     * 当前节点直接出边的子流程目标只读预检（对齐 Java 同名方法）。
     *
     * 必须排在 completeTask 之前：子流程启动处理器还会再做一次运行时校验，
     * 但那一层已来不及阻止内存仓储的父任务写入。
     */
    public static function validateSubProcessTarget(?ProcessModel $model, ?NodeModel $current,
                                                     ?ProcessRepositoryInterface $repository): void
    {
        if ($model === null || $current === null || $repository === null) {
            return;
        }
        foreach ($current->getOutputs() as $transition) {
            $target = $transition->getTarget();
            if ($target instanceof SubProcessModel) {
                self::requireDefine($repository, $target);
            }
        }
    }

    /** 子流程统一定位定义；form 不参与查找，缺失时报内部码 20010002 与固定文案。 */
    public static function requireDefine(ProcessRepositoryInterface $repository, SubProcessModel $model): ?array
    {
        $name = $model->getProcessName();
        $version = $model->getVersion();
        if (trim($name) === '') {
            $define = null;
        } elseif (method_exists($repository, 'findDefineByNameAndVersion')) {
            $define = $repository->findDefineByNameAndVersion($name, $version);
        } else {
            $define = self::findDefineByPage($repository, $name, $version);
        }
        if ($define === null) {
            throw new JeeflowException(
                '子流程' . $model->getProcessName() . '定义不存在',
                self::NOT_FOUND_PROCESS_DEFINE,
            );
        }
        return $define;
    }

    /** 未实现可选扩展方法的第三方仓储，用统一分页判据补同样的选版语义。 */
    private static function findDefineByPage(ProcessRepositoryInterface $repository, string $name, ?int $version): ?array
    {
        $latest = null;
        $pageSize = 100;
        for ($pageNum = 1; ; $pageNum++) {
            $query = new PageQuery($pageNum, $pageSize);
            $query->add('t.name', 'EQ', $name);
            if ($version !== null) {
                $query->add('t.version', 'EQ', $version);
            }
            $page = $repository->pageDefines($query);
            foreach ($page->getRows() as $row) {
                if (($row['name'] ?? '') !== $name) continue;
                if ($version !== null && (int) ($row['version'] ?? 0) !== $version) continue;
                $versionCmp = (int) ($row['version'] ?? 0) <=> (int) ($latest['version'] ?? 0);
                if ($versionCmp > 0 || ($versionCmp === 0
                        && self::compareDefineIds((string) ($row['id'] ?? ''), (string) ($latest['id'] ?? '')) > 0)) {
                    $latest = $row;
                }
            }
            if ($pageNum * $pageSize >= $page->getRecordCount()) break;
        }
        return $latest;
    }

    private static function compareDefineIds(string $a, string $b): int
    {
        $numericA = ltrim($a, '0');
        $numericB = ltrim($b, '0');
        $numericA = $numericA === '' ? '0' : $numericA;
        $numericB = $numericB === '' ? '0' : $numericB;
        if (strlen($numericA) !== strlen($numericB)) return strlen($numericA) <=> strlen($numericB);
        $numericCmp = strcmp($numericA, $numericB);
        return $numericCmp !== 0 ? $numericCmp : strcmp($b, $a);
    }
}
