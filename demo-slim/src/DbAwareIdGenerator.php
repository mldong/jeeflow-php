<?php

declare(strict_types=1);

namespace Jeeflow\Demo;

use Jeeflow\Core\Spi\IdGeneratorInterface;

/**
 * 基于库内现存最大 id 的自增 ID 生成器（demo 幂等修复）。
 *
 * 引擎默认的 InMemoryIdGenerator 是进程内自增（固定从 1001 起）——PHP 进程每次重启
 * counter 归零，而 SQLite/MySQL 是持久化库：对保留数据的库重新 deploy 新流程时，
 * 新 id 必然撞历史 id（主键冲突）。
 *
 * 本生成器在构造时扫描各业务表的 MAX(id) 取全局最大值作为起点，保证同一库上
 * 跨进程重启后 id 永远单调递增（「保留库升级」幂等）。
 */
final class DbAwareIdGenerator implements IdGeneratorInterface
{
    private const TABLES = [
        'wf_process_define',
        'wf_process_instance',
        'wf_process_task',
        'wf_process_task_actor',
        'wf_process_cc_instance',
        'wf_process_design',
        'wf_process_design_his',
        'wf_process_surrogate',
    ];

    private int $counter;

    public function __construct(\PDO $pdo)
    {
        // 默认起点与 InMemoryIdGenerator 的 1000 对齐（全新库行为不变）
        $max = 1000;
        foreach (self::TABLES as $table) {
            try {
                $v = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
                if ($v > $max) {
                    $max = $v;
                }
            } catch (\PDOException) {
                // 表不存在（全新库 schema 未初始化）→ 跳过，用默认起点
            }
        }
        $this->counter = $max;
    }

    public function nextId(): int|string
    {
        return (string) (++$this->counter);
    }
}
