<?php

declare(strict_types=1);

namespace Jeeflow\Persist;

/**
 * PDO 默认实现（SQLite PRAGMA / MySQL information_schema）
 *
 * 不自行 commit：外层引擎事务（Laravel WfTransactionTemplate）负责提交。
 */
class PdoDynamicTableWriter implements DynamicTableWriter
{
    /** @var array<string, list<array{name: string, pk: bool, auto: bool}>> */
    private array $cache = [];

    public mixed $defaultUserValue = 'system';
    public bool $strictColumnMatch = false;
    /** @var (callable(string): mixed)|null */
    public mixed $primaryKeyGenerator = null;

    public function __construct(
        private \PDO $pdo,
        private string $dialect = 'sqlite',
        private ?string $createTimeColumn = 'create_time',
        private ?string $createUserColumn = 'create_user',
        private ?string $updateTimeColumn = 'update_time',
        private ?string $updateUserColumn = 'update_user',
        private ?string $isDeletedColumn = 'is_deleted',
    ) {
    }

    public function filterColumns(string $tableName, array $columns): array
    {
        $this->checkTableName($tableName);
        $cols = $this->tableColumns($tableName);
        $kept = [];
        foreach ($columns as $c) {
            if ($this->findTableColumn($cols, $c) !== null) {
                $kept[] = $c;
            }
        }
        return $kept;
    }

    public function insert(string $tableName, array $data): mixed
    {
        $this->checkTableName($tableName);
        $cols = $this->tableColumns($tableName);
        $names = [];
        $values = [];
        foreach ($cols as $col) {
            $key = $this->findDataKey($data, $col['name']);
            if ($key !== null) {
                $names[] = $col['name'];
                $values[] = $data[$key];
                continue;
            }
            if ($col['pk'] && !$col['auto']) {
                if ($this->primaryKeyGenerator === null) {
                    throw new \RuntimeException(
                        "persist: table {$tableName} primary key {$col['name']} is not auto-increment "
                        . 'and no primary key generator configured'
                    );
                }
                $names[] = $col['name'];
                $values[] = ($this->primaryKeyGenerator)($tableName);
            }
        }
        if ($names === []) {
            throw new \RuntimeException("persist: no matching columns for {$tableName}");
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $quoted = implode(',', array_map(fn($n) => $this->quoteIdent($n), $names));
        $sql = "INSERT INTO {$tableName} ({$quoted}) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $this->pdo->lastInsertId();
    }

    public function exists(string $tableName, string $bizKey, mixed $bizKeyValue): bool
    {
        $this->checkTableName($tableName);
        $this->tableColumns($tableName);
        $sql = "SELECT COUNT(1) FROM {$tableName} WHERE {$this->quoteIdent($bizKey)} = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$bizKeyValue]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function update(string $tableName, array $data, string $whereColumn, mixed $whereValue): int
    {
        $this->checkTableName($tableName);
        if ($whereColumn === '') {
            throw new \RuntimeException("persist: update {$tableName} requires where column");
        }
        $cols = $this->tableColumns($tableName);
        $sets = [];
        $values = [];
        foreach ($cols as $col) {
            if ($this->normalize($col['name']) === $this->normalize($whereColumn)) {
                continue;
            }
            $key = $this->findDataKey($data, $col['name']);
            if ($key !== null) {
                $sets[] = $this->quoteIdent($col['name']) . ' = ?';
                $values[] = $data[$key];
            }
        }
        if ($sets === []) {
            return 0;
        }
        $sql = 'UPDATE ' . $tableName . ' SET ' . implode(',', $sets)
            . ' WHERE ' . $this->quoteIdent($whereColumn) . ' = ?';
        $values[] = $whereValue;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    public function fillSystemFields(array &$data, bool $insert): void
    {
        $now = date('Y-m-d H:i:s');
        if ($insert) {
            if ($this->createTimeColumn) {
                $data[$this->createTimeColumn] ??= $now;
            }
            if ($this->createUserColumn) {
                $data[$this->createUserColumn] ??= $this->resolveDefaultUser($data);
            }
            if ($this->updateTimeColumn) {
                $data[$this->updateTimeColumn] ??= $now;
            }
            if ($this->updateUserColumn) {
                $data[$this->updateUserColumn] ??= $this->resolveDefaultUser($data);
            }
            if ($this->isDeletedColumn) {
                $data[$this->isDeletedColumn] ??= 0;
            }
        } else {
            if ($this->updateTimeColumn) {
                $data[$this->updateTimeColumn] = $now;
            }
            if ($this->updateUserColumn) {
                $data[$this->updateUserColumn] ??= $this->resolveDefaultUser($data);
            }
        }
    }

    private function resolveDefaultUser(array $data): mixed
    {
        return $data['apply_user_id'] ?? $this->defaultUserValue;
    }

    /**
     * @return list<array{name: string, pk: bool, auto: bool}>
     */
    private function tableColumns(string $tableName): array
    {
        if (isset($this->cache[$tableName])) {
            return $this->cache[$tableName];
        }
        if ($this->dialect === 'sqlite') {
            $rows = $this->pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $cols = [];
            foreach ($rows as $r) {
                $type = strtoupper(trim((string) ($r['type'] ?? '')));
                $pk = (int) ($r['pk'] ?? 0) === 1;
                $cols[] = [
                    'name' => (string) $r['name'],
                    'pk' => $pk,
                    'auto' => $pk && $type === 'INTEGER',
                ];
            }
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT column_name, extra, column_key FROM information_schema.columns '
                . 'WHERE UPPER(table_name) = UPPER(?) AND table_schema = DATABASE() '
                . 'ORDER BY ordinal_position'
            );
            $stmt->execute([$tableName]);
            $cols = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $extra = strtolower((string) ($r['extra'] ?? ''));
                $cols[] = [
                    'name' => (string) $r['column_name'],
                    'pk' => strtoupper((string) ($r['column_key'] ?? '')) === 'PRI',
                    'auto' => str_contains($extra, 'auto_increment'),
                ];
            }
        }
        if ($cols === []) {
            throw new \RuntimeException("persist: table {$tableName} not found");
        }
        $this->cache[$tableName] = $cols;
        return $cols;
    }

    /** @param list<array{name: string, pk: bool, auto: bool}> $cols */
    private function findTableColumn(array $cols, string $key): ?string
    {
        foreach ($cols as $c) {
            if ($this->strictColumnMatch) {
                if (strcasecmp($c['name'], $key) === 0) {
                    return $c['name'];
                }
            } elseif ($this->normalize($c['name']) === $this->normalize($key)) {
                return $c['name'];
            }
        }
        return null;
    }

    private function findDataKey(array $data, string $col): ?string
    {
        foreach ($data as $k => $_) {
            if ($this->strictColumnMatch) {
                if (strcasecmp($col, (string) $k) === 0) {
                    return (string) $k;
                }
            } elseif ($this->normalize($col) === $this->normalize((string) $k)) {
                return (string) $k;
            }
        }
        return null;
    }

    private function normalize(string $name): string
    {
        return str_replace('_', '', strtolower($name));
    }

    private function checkTableName(string $tableName): void
    {
        if ($tableName === '') {
            throw new \RuntimeException('persist: table name is empty');
        }
        if (str_starts_with($tableName, 'sys_')) {
            throw new \RuntimeException("persist: table {$tableName} with sys_ prefix is not allowed");
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            throw new \RuntimeException("persist: table {$tableName} contains illegal characters");
        }
    }

    private function quoteIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException("persist: illegal identifier {$name}");
        }
        return $this->dialect === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }
}
