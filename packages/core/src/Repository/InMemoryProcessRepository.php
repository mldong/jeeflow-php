<?php

declare(strict_types=1);

namespace Jeeflow\Core\Repository;

use Jeeflow\Core\Domain\ProcessInstance;
use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
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
}
