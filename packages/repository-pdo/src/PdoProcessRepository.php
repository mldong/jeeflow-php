<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
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
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'displayName' => $row['display_name'],
            'type' => $row['type'],
            'state' => (int) $row['state'],
            'content' => $row['content'],
            'version' => (int) $row['version'],
        ];
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

    // ── 内部方法 ──

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
}
