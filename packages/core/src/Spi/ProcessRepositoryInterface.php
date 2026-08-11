<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\Domain\FlowData;

/**
 * 流程仓储 SPI —— 对齐 Java IProcessRepository
 *
 * 核心五表的读写抽象。引擎通过此接口与持久层交互，
 * 实现可以是内存（测试）、PDO/MySQL、或其他。
 */
interface ProcessRepositoryInterface
{
    // ── 流程定义 ──

    /**
     * 按 ID 查找流程定义
     * @return array{id:int|string,name:string,displayName:string,type:?string,state:int,content:string,version:int}|null
     */
    public function findDefineById(int|string $id): ?array;

    // ── 流程实例 ──

    public function saveInstance(object $instance): void;
    public function updateInstance(object $instance): void;
    public function findInstanceById(int|string $id): ?object;

    // ── 流程任务 ──

    public function saveTask(object $task): void;
    public function updateTask(object $task): void;
    public function findTaskById(int|string $id): ?object;

    // ── 抄送 ──

    /**
     * @param string[] $actorIds
     */
    public function createCcInstance(int|string $instanceId, string $operator, array $actorIds): void;
}
