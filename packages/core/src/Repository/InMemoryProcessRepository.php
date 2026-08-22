<?php

declare(strict_types=1);

namespace Jeeflow\Core\Repository;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;

/**
 * 内存仓储实现 —— 用于单元测试
 *
 * 对齐 Java MemoryProcessRepository。所有数据保存在内存数组中。
 */
class InMemoryProcessRepository implements ProcessRepositoryInterface
{
    /** @var array<string, array> 流程定义 */
    private array $defines = [];

    /** @var array<string, ProcessInstance> 流程实例 */
    private array $instances = [];

    /** @var array<string, ProcessTask> 流程任务 */
    private array $tasks = [];

    /** @var array<int, array> 抄送记录 */
    private array $ccInstances = [];

    private IdGeneratorInterface $idGenerator;

    public function __construct(?IdGeneratorInterface $idGenerator = null)
    {
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    public function getIdGenerator(): IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    // ── 定义 ──

    public function addDefine(array $define): void
    {
        $id = (string) ($define['id'] ?? $this->idGenerator->nextId());
        $define['id'] = $id;
        $this->defines[$id] = $define;
    }

    public function findDefineById(int|string $id): ?array
    {
        return $this->defines[(string) $id] ?? null;
    }

    public function updateDefine(array $define): void
    {
        $id = (string) $define['id'];
        if (isset($this->defines[$id])) {
            $this->defines[$id] = array_merge($this->defines[$id], $define);
        }
    }

    public function removeDefine(int|string $id): void
    {
        unset($this->defines[(string) $id]);
    }

    public function updateDefineState(int|string $id, int $state): void
    {
        $id = (string) $id;
        if (isset($this->defines[$id])) {
            $this->defines[$id]['state'] = $state;
        }
    }

    public function findLatestDefineByName(string $name): ?array
    {
        $found = null;
        foreach ($this->defines as $d) {
            if (($d['name'] ?? '') === $name) {
                if ($found === null || ($d['version'] ?? 0) > ($found['version'] ?? 0)) {
                    $found = $d;
                }
            }
        }
        return $found;
    }

    public function pageDefines(PageQuery $query): PageResult
    {
        $rows = array_values($this->defines);
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getDefineField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 实例 ──

    public function saveInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        if ($instance->getInstanceId() === null) {
            $instance->setInstanceId((string) $this->idGenerator->nextId());
        }
        $this->instances[$instance->getInstanceId()] = $instance;
    }

    public function updateInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        $this->instances[$instance->getInstanceId()] = $instance;
    }

    public function findInstanceById(int|string $id): ?object
    {
        return $this->instances[(string) $id] ?? null;
    }

    public function pageInstances(PageQuery $query): PageResult
    {
        $rows = [];
        foreach ($this->instances as $inst) {
            $row = $this->instanceToRow($inst);
            // Enrich with define info (align Java instanceRowToMap L1257-1262)
            $def = $this->findDefineById($inst->getDefineId());
            if ($def !== null) {
                // 兼容 PDO snake_case / 内存 camelCase 定义键（issues/82-6）
                $row['processDefineName'] = $def['name'] ?? null;
                $row['processDefineDisplayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
                $row['processDefineVersion'] = isset($def['version']) ? (int) $def['version'] : null;
                $row['displayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
                $row['version'] = isset($def['version']) ? (int) $def['version'] : null;
            } else {
                $row['processDefineName'] = null;
                $row['processDefineDisplayName'] = null;
                $row['processDefineVersion'] = null;
                $row['displayName'] = null;
                $row['version'] = null;
            }
            $rows[] = $row;
        }
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getInstanceField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 任务 ──

    public function saveTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        if ($task->getTaskId() === null) {
            $task->setTaskId((string) $this->idGenerator->nextId());
        }
        $this->tasks[$task->getTaskId()] = $task;
    }

    public function updateTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        $this->tasks[$task->getTaskId()] = $task;
    }

    public function findTaskById(int|string $id): ?object
    {
        return $this->tasks[(string) $id] ?? null;
    }

    public function findDoingTasks(int|string $instanceId, ?array $actorIds = null): array
    {
        $result = [];
        foreach ($this->tasks as $task) {
            if ($task->getProcessInstanceId() !== (string) $instanceId) continue;
            if ($task->getTaskState() !== ProcessTaskState::DOING) continue;
            if ($actorIds !== null && !empty($actorIds)) {
                $taskActors = $task->getActorIds();
                if (empty(array_intersect($actorIds, $taskActors))) continue;
            }
            $result[] = $task;
        }
        return $result;
    }

    public function findHistoryTasks(int|string $instanceId): array
    {
        // issues/82-10：历史任务=实例全部任务（含进行中），对齐 Java 内存/Go(state=-1)/Node(null)/Python。
        // 此前排除 DOING 导致 highLight nodeProgress 拿不到会签进行中任务 → 成员/active 全丢。
        $result = [];
        foreach ($this->tasks as $task) {
            if ($task->getProcessInstanceId() !== (string) $instanceId) continue;
            $result[] = $task;
        }
        return $result;
    }

    public function addTaskActor(int|string $taskId, array $actorIds): void
    {
        $task = $this->tasks[(string) $taskId] ?? null;
        if ($task === null) return;
        $existing = $task->getActorIds();
        foreach ($actorIds as $aid) {
            if (!in_array($aid, $existing, true)) {
                $existing[] = $aid;
            }
        }
        $task->setActorIds($existing);
    }

    public function pageTodoTasks(PageQuery $query): PageResult
    {
        // Extract actor filter from conditions
        $actorFilter = null;
        $remainingConditions = [];
        foreach ($query->getConditions() as $cond) {
            $clean = preg_replace('/^[a-z]+\./', '', $cond['column']);
            if ($clean === 'actor_id' && $cond['op'] === 'EQ') {
                $actorFilter = (string) $cond['value'];
            } else {
                $remainingConditions[] = $cond;
            }
        }
        $rows = [];
        foreach ($this->tasks as $task) {
            if ($task->getTaskState() !== ProcessTaskState::DOING) continue;
            if ($actorFilter !== null && !in_array($actorFilter, $task->getActorIds(), true)) continue;
            $rows[] = $this->taskToRow($task);
        }
        // Apply remaining filters
        foreach ($remainingConditions as $cond) {
            $rows = array_filter($rows, fn($row) => $this->matchCondition($row, $cond['column'], $cond['op'], $cond['value']));
        }
        $rows = array_values($rows);
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    public function pageDoneTasks(PageQuery $query): PageResult
    {
        $rows = [];
        foreach ($this->tasks as $task) {
            if ($task->getTaskState() === ProcessTaskState::DOING) continue;
            $rows[] = $this->taskToRow($task);
        }
        $rows = $this->applyFilters($rows, $query, fn($row, $col) => $this->getTaskField($row, $col));
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    // ── 抄送 ──

    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void
    {
        foreach ($actorIds as $actorId) {
            $this->ccInstances[] = [
                'processInstanceId' => (string) $instanceId,
                'actorId' => $actorId,
                'state' => 0,
                'createUser' => $operator,
                'createTime' => date('Y-m-d H:i:s'),
            ];
        }
    }

    public function updateCcStatus(int|string $instanceId, string $operator): void
    {
        foreach ($this->ccInstances as &$cc) {
            if ($cc['processInstanceId'] === (string) $instanceId && $cc['actorId'] === $operator) {
                $cc['state'] = 1;
            }
        }
    }

    public function pageCcInstances(PageQuery $query): PageResult
    {
        $rows = $this->ccInstances;
        // Handle filters
        foreach ($query->getConditions() as $cond) {
            $clean = preg_replace('/^[a-z]+\./', '', $cond['column']);
            $rows = array_filter($rows, function ($row) use ($clean, $cond) {
                // Map snake_case to camelCase
                $field = $clean === 'actor_id' ? 'actorId' : ($clean === 'process_instance_id' ? 'processInstanceId' : $clean);
                $actual = $row[$field] ?? null;
                return match ($cond['op']) {
                    'EQ' => (string) $actual === (string) $cond['value'],
                    'LIKE' => str_contains((string) $actual, (string) $cond['value']),
                    default => true,
                };
            });
        }
        $rows = array_values($rows);
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    /** @return array<int, array> */
    public function getCcInstances(): array
    {
        return $this->ccInstances;
    }

    /** @return array<string, ProcessInstance> */
    public function getAllInstances(): array
    {
        return $this->instances;
    }

    /** @return array<string, ProcessTask> */
    public function getAllTasks(): array
    {
        return $this->tasks;
    }

    // ── 内部辅助 ──

    private function instanceToRow(ProcessInstance $inst): array
    {
        return [
            'id' => $inst->getInstanceId(),
            'parentId' => $inst->getParentId(),
            'processDefineId' => $inst->getDefineId(),
            'state' => $inst->getState(),
            'parentNodeName' => $inst->getParentNodeName(),
            'businessNo' => $inst->getBusinessNo(),
            'operator' => $inst->getOperator(),
            'variable' => $inst->getVariables()->toArray() ?: (object)[],
            'ext' => $inst->getVariables()->toArray() ?: (object)[],
            'createTime' => $inst->getCreateTime(),
            'createUser' => $inst->getCreateUser(),
            'updateTime' => $inst->getUpdateTime(),
            'updateUser' => $inst->getUpdateUser(),
            'expireTime' => $inst->getExpireTime(),
        ];
    }

    private function taskToRow(ProcessTask $task): array
    {
        // issues/82-3：instanceExt 容器（实例变量对象）+ ext 空回退实例变量
        // —— 对齐 Java taskRowToMap / PDO pagedTaskQuery（门面 pass-through，仓储必须出契约）
        $inst = $this->instances[(string) ($task->getProcessInstanceId() ?? '')] ?? null;
        $instanceExt = $inst !== null ? ($inst->getVariables()->toArray() ?: []) : [];
        $ext = $task->getVariables()->toArray();
        if (empty($ext)) $ext = $instanceExt;
        $row = [
            'id' => $task->getTaskId(),
            'processInstanceId' => $task->getProcessInstanceId(),
            'taskName' => $task->getTaskName(),
            'displayName' => $task->getDisplayName(),
            'taskType' => $task->getTaskType(),
            'performType' => $task->getPerformType(),
            'taskState' => $task->getTaskState(),
            'operator' => $task->getActorId(),
            'formKey' => $task->getFormKey(),
            'taskParentId' => $task->getParentTaskId(),
            'taskActorIdList' => $task->getActorIds(),
            'variable' => $task->getVariables()->toArray() ?: (object)[],
            'ext' => $ext ?: (object)[],
            'instanceExt' => $instanceExt ?: (object)[],
            'taskFormData' => (object)[],
            'finishTime' => $task->getFinishTime(),
            'expireTime' => $task->getExpireTime(),
            'createTime' => $task->getCreateTime(),
            'createUser' => $task->getCreateUser(),
            'updateTime' => $task->getUpdateTime(),
            'updateUser' => $task->getUpdateUser(),
        ];
        if ($inst !== null) {
            $row['instanceCreateTime'] = $inst->getCreateTime();
            $def = $this->findDefineById($inst->getDefineId());
            $row['processDefineName'] = $def['name'] ?? null;
            $row['processDefineDisplayName'] = $def['display_name'] ?? $def['displayName'] ?? null;
            $row['processDefineVersion'] = $def['version'] ?? null;
            $row['version'] = isset($def['version']) ? (int) $def['version'] : null;
        }
        return $row;
    }

    private function getDefineField(array $row, string $col): mixed
    {
        $map = ['id' => 'id', 'name' => 'name', 'display_name' => 'displayName', 'displayName' => 'displayName',
                'type' => 'type', 'state' => 'state', 'version' => 'version'];
        $key = $map[$col] ?? $col;
        return $row[$key] ?? null;
    }

    private function getInstanceField(array $row, string $col): mixed
    {
        $clean = preg_replace('/^[a-z]+\./', '', $col);
        return $row[$clean] ?? null;
    }

    private function getTaskField(array $row, string $col): mixed
    {
        $clean = preg_replace('/^[a-z]+\./', '', $col);
        return $row[$clean] ?? null;
    }

    private function applyFilters(array $rows, PageQuery $query, callable $fieldGetter): array
    {
        foreach ($query->getConditions() as $cond) {
            $rows = array_filter($rows, function ($row) use ($cond, $fieldGetter) {
                $val = $fieldGetter($row, $cond['column']);
                return $this->matchCondition($row, $cond['column'], $cond['op'], $cond['value']);
            });
        }
        return array_values($rows);
    }

    /**
     * m_ 查询列 → 行键值解析（issues/82-6：对齐 Java in-memory 的 pd./t. 白名单映射）
     *
     * 旧实现只剥表别名后按裸键取（pd.name→name / t.display_name→display_name），
     * 而内存行键是 camelCase（processDefineName / displayName），导致 pd. 前缀/snake_case 列
     * 静默失配。现：剥别名 + snake→camel，pd. 前缀映射到 processDefine 行键。
     */
    private function resolveColumnValue(array $row, string $col): mixed
    {
        $alias = '';
        if (str_contains($col, '.')) {
            [$alias, $col] = explode('.', $col, 2);
        }
        $field = $this->snakeToCamel($col);
        if ($alias === 'pd') {
            return $row['processDefine' . ucfirst($field)] ?? null;
        }
        return $row[$field] ?? null;
    }

    private function snakeToCamel(string $s): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $s))));
    }

    private function matchCondition(array $row, string $col, string $op, mixed $value): bool
    {
        $actual = $this->resolveColumnValue($row, $col);
        return match ($op) {
            'EQ' => (string) $actual === (string) $value,
            'LIKE' => str_contains((string) $actual, (string) $value),
            'GT' => $actual > $value,
            'LT' => $actual < $value,
            'GE' => $actual >= $value,
            'LE' => $actual <= $value,
            'IN' => is_array($value) && in_array($actual, $value),
            default => true,
        };
    }
}
