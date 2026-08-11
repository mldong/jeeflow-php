<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;

/**
 * PDO 仓储实现 —— 支持 MySQL / SQLite
 *
 * 对齐 Java JdbcProcessRepository。核心五表读写。
 */
class PdoProcessRepository implements ProcessRepositoryInterface
{
    private \PDO $pdo;
    private IdGeneratorInterface $idGenerator;

    public function __construct(\PDO $pdo, ?IdGeneratorInterface $idGenerator = null)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function getIdGenerator(): IdGeneratorInterface
    {
        return $this->idGenerator;
    }

    /** 执行建表 SQL（用于测试或初始化） */
    public function initSchema(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    // ── 流程定义 ──

    public function addDefine(array $define): void
    {
        $id = (string) ($define['id'] ?? $this->idGenerator->nextId());
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_define (id, name, display_name, type, state, content, version, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $define['name'] ?? '',
            $define['displayName'] ?? '',
            $define['type'] ?? null,
            $define['state'] ?? 1,
            $define['content'] ?? null,
            $define['version'] ?? 1,
            $define['createTime'] ?? $now,
            $define['createUser'] ?? null,
            $define['updateTime'] ?? $now,
            $define['updateUser'] ?? null,
        ]);
    }

    public function findDefineById(int|string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_define WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->defineRow($row);
    }

    public function updateDefine(array $define): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'display_name', 'type', 'content', 'version'] as $f) {
            $camel = $this->toCamel($f);
            if (isset($define[$camel]) || isset($define[$f])) {
                $val = $define[$camel] ?? $define[$f];
                $fields[] = "$f = ?";
                $params[] = $val;
            }
        }
        if (isset($define['updateUser'])) { $fields[] = 'update_user = ?'; $params[] = $define['updateUser']; }
        $fields[] = 'update_time = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = (string) $define['id'];
        $stmt = $this->pdo->prepare('UPDATE wf_process_define SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function removeDefine(int|string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM wf_process_define WHERE id = ?');
        $stmt->execute([(string) $id]);
    }

    public function updateDefineState(int|string $id, int $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE wf_process_define SET state = ?, update_time = ? WHERE id = ?');
        $stmt->execute([$state, date('Y-m-d H:i:s'), (string) $id]);
    }

    public function findLatestDefineByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_define WHERE name = ? ORDER BY version DESC LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->defineRow($row);
    }

    public function pageDefines(PageQuery $query): PageResult
    {
        return $this->pagedQuery('wf_process_define', 't', $query, function ($row) {
            return $this->defineRow($row);
        });
    }

    // ── 流程实例 ──

    public function saveInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        if ($instance->getInstanceId() === null) {
            $instance->setInstanceId((string) $this->idGenerator->nextId());
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_instance
             (id, parent_id, process_define_id, state, parent_node_name, business_no, operator, expire_time, variable, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $instance->getInstanceId(),
            $instance->getParentId(),
            $instance->getDefineId(),
            $instance->getState(),
            $instance->getParentNodeName(),
            $instance->getBusinessNo(),
            $instance->getOperator(),
            $instance->getExpireTime(),
            json_encode($instance->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $instance->getCreateTime(),
            $instance->getCreateUser(),
            $instance->getUpdateTime(),
            $instance->getUpdateUser(),
        ]);
    }

    public function updateInstance(object $instance): void
    {
        assert($instance instanceof ProcessInstance);
        $stmt = $this->pdo->prepare(
            'UPDATE wf_process_instance SET
             state = ?, variable = ?, expire_time = ?, update_time = ?, update_user = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $instance->getState(),
            json_encode($instance->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $instance->getExpireTime(),
            $instance->getUpdateTime(),
            $instance->getUpdateUser(),
            $instance->getInstanceId(),
        ]);

        // 同步子任务
        foreach ($instance->getTasks() as $task) {
            $existing = $this->findTaskById($task->getTaskId());
            if ($existing !== null) {
                $this->updateTask($task);
            }
        }
    }

    public function findInstanceById(int|string $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_instance WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $instance = $this->hydrateInstance($row);

        // 加载关联的任务
        $taskStmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE process_instance_id = ? ORDER BY create_time');
        $taskStmt->execute([(string) $id]);
        $tasks = [];
        while ($taskRow = $taskStmt->fetch(\PDO::FETCH_ASSOC)) {
            $task = $this->hydrateTask($taskRow);
            $tasks[] = $task;
        }
        $instance->setTasks($tasks);

        return $instance;
    }

    public function pageInstances(PageQuery $query): PageResult
    {
        return $this->pagedQuery('wf_process_instance', 't', $query, function ($row) {
            return [
                'id' => $row['id'],
                'parentId' => $row['parent_id'],
                'processDefineId' => $row['process_define_id'],
                'state' => (int) $row['state'],
                'parentNodeName' => $row['parent_node_name'],
                'businessNo' => $row['business_no'],
                'operator' => $row['operator'] ?? '',
                'variable' => json_decode((string)($row['variable'] ?? '{}'), true) ?: [],
                'ext' => json_decode((string)($row['variable'] ?? '{}'), true) ?: [],
                'createTime' => $row['create_time'],
                'createUser' => $row['create_user'],
                'updateTime' => $row['update_time'],
                'updateUser' => $row['update_user'],
                'expireTime' => $row['expire_time'],
            ];
        });
    }

    // ── 流程任务 ──

    public function saveTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        if ($task->getTaskId() === null) {
            $task->setTaskId((string) $this->idGenerator->nextId());
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_task
             (id, process_instance_id, task_name, display_name, task_type, perform_type, task_state, operator, finish_time, expire_time, form_key, task_parent_id, variable, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $task->getTaskId(),
            $task->getProcessInstanceId(),
            $task->getTaskName(),
            $task->getDisplayName(),
            $task->getTaskType(),
            $task->getPerformType(),
            $task->getTaskState(),
            $task->getActorId(),
            $task->getFinishTime(),
            $task->getExpireTime(),
            $task->getFormKey(),
            $task->getParentTaskId(),
            json_encode($task->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $task->getCreateTime(),
            $task->getCreateUser(),
            $task->getUpdateTime(),
            $task->getUpdateUser(),
        ]);

        // 保存 actor 关系
        foreach ($task->getActorIds() as $actorId) {
            $actorStmt = $this->pdo->prepare(
                'INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time, create_user)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $actorStmt->execute([
                (string) $this->idGenerator->nextId(),
                $task->getTaskId(),
                $actorId,
                $task->getCreateTime(),
                $task->getCreateUser(),
            ]);
        }
    }

    public function updateTask(object $task): void
    {
        assert($task instanceof ProcessTask);
        $stmt = $this->pdo->prepare(
            'UPDATE wf_process_task SET
             task_state = ?, operator = ?, finish_time = ?, variable = ?, update_time = ?, update_user = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $task->getTaskState(),
            $task->getActorId(),
            $task->getFinishTime(),
            json_encode($task->getVariables()->toArray(), JSON_UNESCAPED_UNICODE),
            $task->getUpdateTime(),
            $task->getUpdateUser(),
            $task->getTaskId(),
        ]);
    }

    public function findTaskById(int|string $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->hydrateTask($row);
    }

    public function findDoingTasks(int|string $instanceId, ?array $actorIds = null): array
    {
        $sql = 'SELECT t.* FROM wf_process_task t WHERE t.process_instance_id = ? AND t.task_state = ?';
        $params = [(string) $instanceId, ProcessTaskState::DOING];
        if ($actorIds !== null && !empty($actorIds)) {
            $sql .= ' AND t.id IN (SELECT process_task_id FROM wf_process_task_actor WHERE actor_id IN (' .
                    implode(',', array_fill(0, count($actorIds), '?')) . '))';
            $params = array_merge($params, $actorIds);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateTask($row);
        }
        return $result;
    }

    public function findHistoryTasks(int|string $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_task WHERE process_instance_id = ? AND task_state != ? ORDER BY create_time');
        $stmt->execute([(string) $instanceId, ProcessTaskState::DOING]);
        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateTask($row);
        }
        return $result;
    }

    public function addTaskActor(int|string $taskId, array $actorIds): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($actorIds as $actorId) {
            // Check if already exists
            $check = $this->pdo->prepare('SELECT id FROM wf_process_task_actor WHERE process_task_id = ? AND actor_id = ?');
            $check->execute([(string) $taskId, $actorId]);
            if ($check->fetch() === false) {
                $stmt = $this->pdo->prepare('INSERT INTO wf_process_task_actor (id, process_task_id, actor_id, create_time, create_user) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([(string) $this->idGenerator->nextId(), (string) $taskId, $actorId, $now, null]);
            }
        }
    }

    public function pageTodoTasks(PageQuery $query): PageResult
    {
        $baseSql = ' FROM wf_process_task t '
            . 'LEFT JOIN wf_process_instance pi ON t.process_instance_id = pi.id '
            . 'LEFT JOIN wf_process_define pd ON pi.process_define_id = pd.id '
            . 'LEFT JOIN wf_process_task_actor pta ON t.id = pta.process_task_id '
            . 'WHERE t.task_state = ?';
        $baseParams = [ProcessTaskState::DOING];
        return $this->pagedTaskQuery($baseSql, $baseParams, $query);
    }

    public function pageDoneTasks(PageQuery $query): PageResult
    {
        $baseSql = ' FROM wf_process_task t '
            . 'LEFT JOIN wf_process_instance pi ON t.process_instance_id = pi.id '
            . 'LEFT JOIN wf_process_define pd ON pi.process_define_id = pd.id '
            . 'WHERE t.task_state != ?';
        $baseParams = [ProcessTaskState::DOING];
        return $this->pagedTaskQuery($baseSql, $baseParams, $query);
    }

    // ── 抄送 ──

    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($actorIds as $actorId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO wf_process_cc_instance (id, process_instance_id, actor_id, state, create_time, create_user, update_time, update_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (string) $this->idGenerator->nextId(),
                (string) $instanceId,
                $actorId,
                0,
                $now,
                $operator,
                $now,
                $operator,
            ]);
        }
    }

    public function updateCcStatus(int|string $instanceId, string $operator): void
    {
        $stmt = $this->pdo->prepare('UPDATE wf_process_cc_instance SET state = 1, update_time = ? WHERE process_instance_id = ? AND actor_id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), (string) $instanceId, $operator]);
    }

    public function pageCcInstances(PageQuery $query): PageResult
    {
        $baseSql = ' FROM wf_process_cc_instance t WHERE 1=1';
        $params = [];
        [$whereSql, $whereParams] = $this->buildConditions($query);
        $baseSql .= $whereSql;
        $params = array_merge($params, $whereParams);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM wf_process_cc_instance t WHERE 1=1" . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $order = $query->getOrderBy() ?: 't.create_time DESC';
        $sql = "SELECT t.* FROM wf_process_cc_instance t WHERE 1=1" . $whereSql . " ORDER BY $order LIMIT ? OFFSET ?";
        $params[] = $query->getPageSize();
        $params[] = $query->getOffset();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = [
                'processInstanceId' => $row['process_instance_id'],
                'actorId' => $row['actor_id'],
                'state' => (int) $row['state'],
                'createTime' => $row['create_time'],
                'createUser' => $row['create_user'],
            ];
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    // ── 内部方法 ──

    private function defineRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'displayName' => $row['display_name'],
            'type' => $row['type'],
            'state' => (int) $row['state'],
            'content' => $row['content'],
            'version' => (int) $row['version'],
            'createTime' => $row['create_time'] ?? null,
            'createUser' => $row['create_user'] ?? null,
            'updateTime' => $row['update_time'] ?? null,
            'updateUser' => $row['update_user'] ?? null,
        ];
    }

    private function hydrateInstance(array $row): ProcessInstance
    {
        $instance = new ProcessInstance();
        $instance->setInstanceId($row['id']);
        $instance->setParentId($row['parent_id']);
        $instance->setDefineId($row['process_define_id']);
        $instance->setState((int) $row['state']);
        $instance->setParentNodeName($row['parent_node_name']);
        $instance->setBusinessNo($row['business_no']);
        $instance->setOperator($row['operator'] ?? '');
        $instance->setExpireTime($row['expire_time']);
        $vars = json_decode((string) ($row['variable'] ?? '{}'), true) ?: [];
        $instance->setVariables(FlowData::of($vars));
        $instance->setCreateTime($row['create_time']);
        $instance->setCreateUser($row['create_user']);
        $instance->setUpdateTime($row['update_time']);
        $instance->setUpdateUser($row['update_user']);
        return $instance;
    }

    /**
     * 从数据库行还原 ProcessTask（含 actorIds）
     */
    private function hydrateTask(array $row): ProcessTask
    {
        $task = new ProcessTask();
        $task->setTaskId($row['id']);
        $task->setProcessInstanceId($row['process_instance_id']);
        $task->setTaskName($row['task_name']);
        $task->setDisplayName($row['display_name']);
        $task->setTaskType($row['task_type'] !== null ? (int) $row['task_type'] : null);
        $task->setPerformType($row['perform_type'] !== null ? (int) $row['perform_type'] : null);
        $task->setTaskState((int) ($row['task_state'] ?? ProcessTaskState::DOING));
        $task->setActorId($row['operator']);
        $task->setFinishTime($row['finish_time']);
        $task->setExpireTime($row['expire_time']);
        $task->setFormKey($row['form_key']);
        $task->setParentTaskId($row['task_parent_id']);
        $vars = json_decode((string) ($row['variable'] ?? '{}'), true) ?: [];
        $task->setVariables(FlowData::of($vars));
        $task->setCreateTime($row['create_time']);
        $task->setCreateUser($row['create_user']);
        $task->setUpdateTime($row['update_time']);
        $task->setUpdateUser($row['update_user']);

        // 加载 actorIds
        $actorStmt = $this->pdo->prepare('SELECT actor_id FROM wf_process_task_actor WHERE process_task_id = ?');
        $actorStmt->execute([$row['id']]);
        $actorIds = [];
        while ($actorRow = $actorStmt->fetch(\PDO::FETCH_ASSOC)) {
            $actorIds[] = $actorRow['actor_id'];
        }
        $task->setActorIds($actorIds);

        return $task;
    }

    private function toCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    /**
     * 通用分页查询
     */
    private function pagedQuery(string $table, string $alias, PageQuery $query, callable $rowMapper): PageResult
    {
        [$whereSql, $whereParams] = $this->buildConditions($query);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table $alias WHERE 1=1" . $whereSql);
        $countStmt->execute($whereParams);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $order = $query->getOrderBy() ?: "$alias.create_time DESC";
        $sql = "SELECT $alias.* FROM $table $alias WHERE 1=1" . $whereSql . " ORDER BY $order LIMIT ? OFFSET ?";
        $params = array_merge($whereParams, [$query->getPageSize(), $query->getOffset()]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $rowMapper($row);
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    private function pagedTaskQuery(string $baseSql, array $baseParams, PageQuery $query): PageResult
    {
        [$whereSql, $whereParams] = $this->buildConditions($query);
        $allParams = array_merge($baseParams, $whereParams);

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(DISTINCT t.id)" . $baseSql . $whereSql);
        $countStmt->execute($allParams);
        $total = (int) $countStmt->fetchColumn();

        // Fetch - include process define info
        $order = $query->getOrderBy() ?: 't.create_time DESC';
        $sql = "SELECT DISTINCT t.*, pd.name AS process_define_name, pd.display_name AS process_define_display_name, "
            . "pd.version AS process_define_version, pi.variable AS instance_variable, pi.create_time AS instance_create_time "
            . $baseSql . $whereSql . " ORDER BY $order LIMIT ? OFFSET ?";
        $params = array_merge($allParams, [$query->getPageSize(), $query->getOffset()]);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $task = $this->hydrateTask($row);
            // Parse instance variable JSON for ext/instanceExt (align with Java/Node)
            $instanceVarJson = $row['instance_variable'] ?? null;
            $instanceExt = null;
            if ($instanceVarJson !== null && is_string($instanceVarJson)) {
                $decoded = json_decode($instanceVarJson, true);
                $instanceExt = is_array($decoded) ? $decoded : null;
            }
            $rows[] = [
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
                'variable' => $task->getVariables()->toArray(),
                'ext' => $instanceExt ?? (object)[],
                'instanceExt' => $instanceExt ?? (object)[],
                'taskFormData' => (object)[],
                'finishTime' => $task->getFinishTime(),
                'expireTime' => $task->getExpireTime(),
                'createTime' => $task->getCreateTime(),
                'createUser' => $task->getCreateUser(),
                'updateTime' => $task->getUpdateTime(),
                'updateUser' => $task->getUpdateUser(),
                // Process define info (from JOIN)
                'processDefineName' => $row['process_define_name'] ?? null,
                'processDefineDisplayName' => $row['process_define_display_name'] ?? null,
                'version' => $row['process_define_version'] ?? null,
                'instanceVariable' => $instanceVarJson,
                'instanceCreateTime' => $row['instance_create_time'] ?? null,
            ];
        }
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    /**
     * 构建 WHERE 条件（从 PageQuery 的 conditions 生成 SQL）
     * @return array{0: string, 1: array}
     */
    private function buildConditions(PageQuery $query): array
    {
        $sql = '';
        $params = [];
        foreach ($query->getConditions() as $cond) {
            $col = $cond['column'];
            // Convert camelCase column to snake_case for DB
            $dbCol = $this->camelToSnake($col);
            $op = $cond['op'];
            $val = $cond['value'];
            $sql .= match ($op) {
                'EQ' => " AND $col = ?",
                'LIKE' => " AND $col LIKE ?",
                'GT' => " AND $col > ?",
                'LT' => " AND $col < ?",
                'GE' => " AND $col >= ?",
                'LE' => " AND $col <= ?",
                'IN' => " AND $col IN (" . implode(',', array_fill(0, is_array($val) ? count($val) : 1, '?')) . ")",
                default => '',
            };
            if ($op === 'LIKE') {
                $params[] = '%' . $val . '%';
            } elseif ($op === 'IN' && is_array($val)) {
                $params = array_merge($params, $val);
            } else {
                $params[] = $val;
            }
        }
        return [$sql, $params];
    }

    private function camelToSnake(string $input): string
    {
        // Strip alias prefix (e.g., "t.columnName" -> "columnName")
        $col = preg_replace('/^[a-z]+\./', '', $input);
        return strtolower(preg_replace('/[A-Z]/', '_$0', $col));
    }
}
