<?php

declare(strict_types=1);

namespace Jeeflow\RepositoryPDO;

use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;

/**
 * PDO 扩展仓储实现 —— 支持 MySQL / SQLite
 *
 * 对齐 Java JdbcProcessExtRepository。管理流程设计稿（草稿）和委托代理。
 */
class PdoProcessExtRepository implements ProcessExtRepositoryInterface
{
    private \PDO $pdo;
    private IdGeneratorInterface $idGenerator;

    public function __construct(\PDO $pdo, ?IdGeneratorInterface $idGenerator = null)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    // ── 流程设计 ──

    public function pageDesigns(PageQuery $query): PageResult
    {
        $offset = $query->getOffset();
        $limit = $query->getPageSize();

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM wf_process_design')->fetchColumn();

        $sql = 'SELECT * FROM wf_process_design ORDER BY create_time DESC'
            . SqlPaging::clause((int) $limit, (int) $offset);
        $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['id'])) {
                $row['id'] = (string) $row['id'];
            }
        }
        unset($row);

        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    public function findDesignById(int|string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_design WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function saveDesign(array $design): string
    {
        $id = (string) ($design['id'] ?? $this->idGenerator->nextId());
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_design (id, name, display_name, type, icon, is_deployed, remark, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $design['name'] ?? '',
            $design['displayName'] ?? '',
            $design['type'] ?? 'approval',
            $design['icon'] ?? null,
            $design['isDeployed'] ?? 0,
            $design['remark'] ?? null,
            $design['createTime'] ?? $now,
            $design['createUser'] ?? null,
            $design['updateTime'] ?? $now,
            $design['updateUser'] ?? null,
        ]);

        return $id;
    }

    public function updateDesign(array $design): void
    {
        $id = (string) $design['id'];
        $now = date('Y-m-d H:i:s');

        $fields = [];
        $params = [];

        foreach (['name', 'displayName', 'type', 'icon', 'isDeployed', 'remark', 'updateUser'] as $key) {
            if (array_key_exists($key, $design)) {
                $col = $this->camelToSnake($key);
                $fields[] = "$col = ?";
                $params[] = $design[$key];
            }
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = 'update_time = ?';
        $params[] = $now;
        $params[] = $id;

        $sql = 'UPDATE wf_process_design SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function saveDesignHis(int|string $designId, string $content, ?string $operator = null): void
    {
        $did = (string) $designId;

        // 去重：如果最新快照内容相同则不重复存
        $latest = $this->findLatestDesignHis($did);
        if ($latest !== null && ($latest['content'] ?? '') === $content) {
            return;
        }

        $id = (string) $this->idGenerator->nextId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_design_his (id, process_design_id, content, create_time, create_user)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $did,
            $content,
            date('Y-m-d H:i:s'),
            $operator,
        ]);
    }

    public function findLatestDesignHis(int|string $designId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM wf_process_design_his WHERE process_design_id = ? ORDER BY create_time DESC LIMIT 1'
        );
        $stmt->execute([(string) $designId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findDesignHisList(int|string $designId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM wf_process_design_his WHERE process_design_id = ? ORDER BY create_time DESC'
        );
        $stmt->execute([(string) $designId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function removeDesign(int|string $id): void
    {
        $did = (string) $id;
        $this->pdo->prepare('DELETE FROM wf_process_design WHERE id = ?')->execute([$did]);
        $this->pdo->prepare('DELETE FROM wf_process_design_his WHERE process_design_id = ?')->execute([$did]);
    }

    public function updateDesignDeployed(int|string $designId, int $isDeployed): void
    {
        $this->pdo->prepare('UPDATE wf_process_design SET is_deployed = ?, update_time = ? WHERE id = ?')
            ->execute([$isDeployed, date('Y-m-d H:i:s'), (string) $designId]);
    }

    public function listDesignsByType(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM wf_process_design ORDER BY type, create_time DESC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $type = $row['type'] ?? 'default';
            $grouped[$type][] = $row;
        }
        return $grouped;
    }

    // ── 委托代理 ──

    public function pageSurrogates(PageQuery $query): PageResult
    {
        $offset = $query->getOffset();
        $limit = $query->getPageSize();

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM wf_process_surrogate')->fetchColumn();

        $sql = 'SELECT * FROM wf_process_surrogate ORDER BY create_time DESC'
            . SqlPaging::clause((int) $limit, (int) $offset);
        $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['id'])) {
                $row['id'] = (string) $row['id'];
            }
        }
        unset($row);

        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $rows);
    }

    public function findSurrogateById(int|string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_process_surrogate WHERE id = ?');
        $stmt->execute([(string) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function saveSurrogate(array $surrogate): string
    {
        $id = (string) ($surrogate['id'] ?? $this->idGenerator->nextId());
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO wf_process_surrogate (id, process_name, operator, surrogate, start_time, end_time, enabled, create_time, create_user, update_time, update_user)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $surrogate['processName'] ?? null,
            $surrogate['operator'] ?? '',
            $surrogate['surrogate'] ?? '',
            $surrogate['startTime'] ?? null,
            $surrogate['endTime'] ?? null,
            $surrogate['enabled'] ?? 1,
            $surrogate['createTime'] ?? $now,
            $surrogate['createUser'] ?? null,
            $surrogate['updateTime'] ?? $now,
            $surrogate['updateUser'] ?? null,
        ]);

        return $id;
    }

    public function updateSurrogate(array $surrogate): void
    {
        $id = (string) $surrogate['id'];
        $now = date('Y-m-d H:i:s');

        $fields = [];
        $params = [];

        foreach (['processName', 'operator', 'surrogate', 'startTime', 'endTime', 'enabled', 'updateUser'] as $key) {
            if (array_key_exists($key, $surrogate)) {
                $col = $this->camelToSnake($key);
                $fields[] = "$col = ?";
                $params[] = $surrogate[$key];
            }
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = 'update_time = ?';
        $params[] = $now;
        $params[] = $id;

        $sql = 'UPDATE wf_process_surrogate SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function removeSurrogate(int|string $id): void
    {
        $this->pdo->prepare('DELETE FROM wf_process_surrogate WHERE id = ?')->execute([(string) $id]);
    }

    // ── 辅助 ──

    private function camelToSnake(string $str): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $str));
    }
}
