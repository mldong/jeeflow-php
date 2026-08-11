<?php

declare(strict_types=1);

namespace Jeeflow\Demo;

use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\RepositoryPDO\PdoProcessExtRepository;
use Jeeflow\RepositoryPDO\PdoProcessRepository;

/**
 * 仓储工厂 —— 根据环境变量选择存储后端
 *
 * JEEFLOW_DEMO_STORE 取值：
 *   - memory : 内存仓储（仅 smoke_test 使用，禁止 HTTP demo 使用）
 *   - sqlite : SQLite 文件（默认，适合本机 demo / jeeflow-ui）
 *   - mysql  : MySQL PDO（适合联调 / 生产）
 */
class RepositoryFactory
{
    public const MODE_MEMORY = 'memory';
    public const MODE_SQLITE = 'sqlite';
    public const MODE_MYSQL = 'mysql';

    private static ?\PDO $sharedPdo = null;

    /**
     * 获取当前存储模式
     */
    public static function getMode(): string
    {
        return getenv('JEEFLOW_DEMO_STORE') ?: self::MODE_SQLITE;
    }

    /**
     * 创建核心五表仓储
     */
    public static function createProcessRepository(): ProcessRepositoryInterface
    {
        $mode = self::getMode();

        return match ($mode) {
            self::MODE_MEMORY => new InMemoryProcessRepository(),
            self::MODE_SQLITE, self::MODE_MYSQL => new PdoProcessRepository(self::getPdo()),
            default => throw new \RuntimeException("Unknown JEEFLOW_DEMO_STORE mode: $mode"),
        };
    }

    /**
     * 创建扩展三表仓储
     */
    public static function createProcessExtRepository(): ProcessExtRepositoryInterface
    {
        $mode = self::getMode();

        return match ($mode) {
            self::MODE_MEMORY => new InMemoryProcessExtRepository(),
            self::MODE_SQLITE, self::MODE_MYSQL => new PdoProcessExtRepository(self::getPdo()),
            default => throw new \RuntimeException("Unknown JEEFLOW_DEMO_STORE mode: $mode"),
        };
    }

    /**
     * 获取 PDO 连接（复用同一个实例）
     */
    public static function getPdo(): \PDO
    {
        if (self::$sharedPdo !== null) {
            return self::$sharedPdo;
        }

        $mode = self::getMode();

        if ($mode === self::MODE_SQLITE) {
            $dbPath = self::getSqlitePath();
            $isNew = !file_exists($dbPath);
            self::$sharedPdo = new \PDO('sqlite:' . $dbPath);
            self::$sharedPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if ($isNew) {
                self::initSqliteSchema(self::$sharedPdo);
            }
        } elseif ($mode === self::MODE_MYSQL) {
            $dsn = getenv('JEEFLOW_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=jeeflow_demo;charset=utf8mb4';
            $user = getenv('JEEFLOW_MYSQL_USER') ?: 'root';
            $pass = getenv('JEEFLOW_MYSQL_PASS') ?: '';
            self::$sharedPdo = new \PDO($dsn, $user, $pass);
            self::$sharedPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } else {
            throw new \RuntimeException("Cannot get PDO for mode: $mode");
        }

        return self::$sharedPdo;
    }

    /**
     * 获取 SQLite 数据库文件路径
     */
    public static function getSqlitePath(): string
    {
        return getenv('JEEFLOW_SQLITE_PATH') ?: dirname(__DIR__) . '/data/jeeflow-demo.sqlite';
    }

    /**
     * 初始化 SQLite schema
     */
    public static function initSqliteSchema(\PDO $pdo): void
    {
        $schemaFile = dirname(__DIR__, 2) . '/packages/repository-pdo/sql/schema-sqlite.sql';
        if (file_exists($schemaFile)) {
            $pdo->exec(file_get_contents($schemaFile));
        }
    }

    /**
     * 初始化 MySQL schema
     */
    public static function initMysqlSchema(\PDO $pdo): void
    {
        $schemaFile = dirname(__DIR__, 2) . '/packages/repository-pdo/sql/schema-mysql.sql';
        if (file_exists($schemaFile)) {
            $pdo->exec(file_get_contents($schemaFile));
        }
    }

    /**
     * 重置共享 PDO（仅用于测试）
     */
    public static function resetPdo(): void
    {
        self::$sharedPdo = null;
    }
}
