<?php

declare(strict_types=1);

namespace Jeeflow\Core\Interceptor;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Execution;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;

/**
 * 委托代理拦截器（issues/116 批次 D）——**引擎内置、默认开启**
 *
 * 契约依据：`docs/spec/06-facade.md` §4.5「运行期语义」+ `docs/spec/05-spi.md`
 * 「SurrogateInterceptor（委托生效，内置实现）」+ `docs/spec/08-compliance.md` 用例 26/27。
 * 对齐 Java `SurrogateInterceptor`（`03474fe`）与 Python `jeeflow/surrogate.py`（`ccb4667`）。
 *
 * 任务参与者解析完成后、**落库前**，对每个 actor 查一次生效委托
 * （`ProcessExtRepositoryInterface::getSurrogate`），命中则把被委托人**并入参与者集合本身**，
 * 随后由 `saveTask` 随任务一起全量写入 `wf_process_task_actor`；授权人保留，任一可办
 * （委托不是转办，不摘原人）。
 *
 * **为什么不走"事后 `addTaskActor` 补写"**（Java 首版的做法，issues/116 的第二层病根）：
 * 挂点在建单期，此刻 `saveTask` 尚未执行、`taskId` 还是 null（由仓储 `saveTask` 里才分配），
 * 那次补写打在空 id 上是**静默无效**的——能力看起来实现了，实际一单都没代理出去。
 * 故本类只改集合，绝不补写（06 §4.5 条款 2 的 ⚠️）。
 *
 * 四条要点：
 * - **覆盖全部建任务路径**：引擎的新任务落库唯一收口 `JeeflowEngine::saveNewTask()`
 *   在 `saveTask` 之前调用本方法——发起 / 办理推进 / **串行会签每一步推进** / 跳转 全部走它，
 *   只挂在发起一处会漏掉流转中产生的新单（条款 1）；
 * - **不级联**：只遍历建单那一刻的**原始参与者快照**（PHP `foreach` 遍历数组值副本，天然快照），
 *   代理人自身的委托不展开（条款 1.2，环状委托会死循环）；串行会签时代理人只进**当一步任务**，
 *   不扩 `operatorList` 投票名册（条款 1.3）；
 * - **静默降级**：未配置扩展仓储时直接跳过；仓储自身报错（如表未建）也只记日志，**不打断建单**
 *   （条款 4，委托是增强能力，缺仓储属正常部署形态）；
 * - **幂等**：代理人已在集合里则不重复追加，与集成方自行注册的实例叠加也不会重复落库。
 *
 * 显式关闭（回到"仅台账"行为）见 `JeeflowEngine::__construct()` 的 `$surrogateAutoApply`
 * 与 `NullSurrogateInterceptor`。
 *
 * 本类同时是 `FlowInterceptor` 的普通实现，集成方可自行注册（或配到节点/流程的
 * `preInterceptors`）叠加自定义逻辑。委托在 todoList 的合并展示属集成方视图层职责。
 */
class SurrogateInterceptor implements FlowInterceptor
{
    /** 引擎解析 applier 的 ServiceContext 键（集成方自行注册/覆盖时用同一键） */
    public const CONTEXT_KEY = self::class;

    /** 可为 null：运行时从 ServiceContext 解析（未配置则静默跳过） */
    private ?ProcessExtRepositoryInterface $extRepository;

    public function __construct(?ProcessExtRepositoryInterface $extRepository = null)
    {
        $this->extRepository = $extRepository;
    }

    /**
     * 拦截器入口：对 execution 本次产生的**全部**新任务应用生效委托。
     *
     * `processName` 走 {@link resolveProcessName}——**流程模型 name 优先，缺失才回落
     * `wf_process_define.name`**（06 §4.5 条款 1.1）。
     */
    public function intercept(Execution $execution): void
    {
        $tasks = $execution->getProcessTaskList();
        if ($tasks === []) return;
        $processName = self::resolveProcessName($execution);
        $at = date('Y-m-d H:i:s');
        foreach ($tasks as $task) {
            $this->apply($task, $processName, $at);
        }
    }

    /**
     * 解析当前流程名（06 §4.5 条款 1.1 的取值口径，跨栈对拍的唯一维护点）。
     *
     * **模型 name 优先**（`ProcessModel::getName()`，即流程 JSON 顶层 `name`）——迁移基线是内置版
     * mldong-wf 的 `SurrogateInterceptor`，它用的正是 `execution.getProcessModel().getName()`，
     * 用户在内置版配的委托迁到 jeeflow 后必须命中同一条。
     * **模型未带 name 时才回落 `wf_process_define.name`**：deploy 的 `def.setName(model.getName())`
     * 让两者正常情况下恒等，所以回落只在"模型缺 name"这种异常形态下才触发。
     *
     * ⚠️ 回落不是可选项：直接给空串等于把 `processName` 当"未指定"，按判据① 只能命中
     * 全流程兜底行，**该流程自己配的委托一条都查不到**（用户视角=委托静默失效）。
     */
    public static function resolveProcessName(Execution $exec): string
    {
        $name = trim((string) $exec->getProcessModel()?->getName());
        if ($name !== '') return $name;

        $defineId = $exec->getProcessInstance()?->getDefineId();
        $engine = $exec->getEngine();
        if ($defineId === null || $defineId === '' || $engine === null) return '';
        try {
            $define = $engine->getRepository()->findDefineById($defineId);
        } catch (\Throwable $e) {
            // 回落查询本身报错不该把建单打断（条款 4 同口径）：退回空串=只命中兜底行
            error_log('[jeeflow] surrogate processName fallback failed, define=' . $defineId
                . ': ' . $e->getMessage());
            return '';
        }
        return trim((string) ($define['name'] ?? ''));
    }

    /**
     * 对单个任务应用生效委托：逐个 actor 查一次，命中的被委托人**并入参与者集合**。
     *
     * @param ProcessTask $task        任务（参与者集合就地更新，供随后的 saveTask 全量落库）
     * @param string      $processName 当前流程名（{@link resolveProcessName}：模型 name 优先、
     *                                 缺失才回落 wf_process_define.name；仍为空时只命中全流程兜底）
     * @param string|null $at          判定时间（`Y-m-d H:i:s`；null 取当前时间）
     */
    public function apply(ProcessTask $task, string $processName, ?string $at = null): void
    {
        // 只对待办生效：已完成/废弃/撤回的历史记录不追加代理人
        if (!$task->isDoing()) return;

        $repo = $this->resolveRepository();
        if ($repo === null) return;                      // 条款 4：未配置扩展仓储 → 静默跳过

        $actors = $task->getActorIds();
        if ($actors === []) return;
        $at ??= date('Y-m-d H:i:s');

        $known = array_map(strval(...), $actors);
        $additions = [];
        // 条款 1.2：遍历原始参与者快照（foreach 取的是数组副本），本轮追加的代理人不再触发查询
        foreach ($actors as $rawActor) {
            $actor = trim((string) $rawActor);
            if ($actor === '') continue;
            try {
                $hit = $repo->getSurrogate($actor, $processName, $at);
            } catch (\Throwable $e) {
                // 条款 4：委托是增强能力，仓储自身报错（表未建等）不得打断建单
                error_log('[jeeflow] getSurrogate(' . $actor . ', ' . $processName
                    . ') failed, surrogate skipped: ' . $e->getMessage());
                continue;
            }
            // 行键两仓同名（内存仓 camelCase、PDO 行 snake_case，该列都是 surrogate）
            $agent = is_array($hit) ? trim((string) ($hit['surrogate'] ?? '')) : '';
            if ($agent === '' || $agent === $actor) continue;   // 判据③兜底（仓储未过滤时也不自委托）
            if (in_array($agent, $known, true) || in_array($agent, $additions, true)) continue;
            $additions[] = $agent;
            $known[] = $agent;
        }
        if ($additions === []) return;

        // 条款 2：落在参与者集合本身（原元素顺序与内容不动），随任务一起 saveTask 全量落库
        $task->setActorIds([...$actors, ...$additions]);
    }

    private function resolveRepository(): ?ProcessExtRepositoryInterface
    {
        if ($this->extRepository !== null) return $this->extRepository;
        return ServiceContext::find(ProcessExtRepositoryInterface::class);
    }
}
