#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * jeeflow PHP demo 初始化脚本
 *
 * 功能：
 * 1. 创建 SQLite 数据库和 schema（幂等）
 * 2. 部署 3 个示例流程（幂等，已存在则跳过）
 *
 * 用法：
 *   php bin/demo-init.php              # 默认 SQLite
 *   JEEFLOW_DEMO_STORE=mysql php bin/demo-init.php  # MySQL
 */

require __DIR__ . '/../vendor/autoload.php';
require dirname(__DIR__, 2) . '/jeeflow-flows-dir.php'; // 本仓 flows/ 解析（维护者机器上镜像 Java 源）

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Demo\RepositoryFactory;
use Jeeflow\WebContract\JeeflowFacade;

echo "jeeflow PHP demo init\n";
echo "=====================\n\n";

$mode = RepositoryFactory::getMode();
echo "Storage mode: $mode\n";

// ── 1. 初始化数据库 ──

if ($mode === RepositoryFactory::MODE_SQLITE) {
    $dbPath = RepositoryFactory::getSqlitePath();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    echo "SQLite path: $dbPath\n";
} elseif ($mode === RepositoryFactory::MODE_MYSQL) {
    $dsn = getenv('JEEFLOW_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=jeeflow_demo;charset=utf8mb4';
    echo "MySQL DSN: $dsn\n";
}

$pdo = RepositoryFactory::getPdo();
echo "Database connected.\n";

// 初始化 schema
if ($mode === RepositoryFactory::MODE_SQLITE) {
    RepositoryFactory::initSqliteSchema($pdo);
} elseif ($mode === RepositoryFactory::MODE_MYSQL) {
    RepositoryFactory::initMysqlSchema($pdo);
}
echo "Schema initialized.\n\n";

// ── 2. 初始化引擎 ──

$repo = RepositoryFactory::createProcessRepository();
$extRepo = RepositoryFactory::createProcessExtRepository();
$engine = new JeeflowEngine($repo);
$facade = new JeeflowFacade($engine, $repo, $extRepo);

// 注入事务模板
if ($mode === RepositoryFactory::MODE_MEMORY) {
    ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
        public function required(callable $action): mixed { return $action(); }
    });
} else {
    ServiceContext::put(TransactionTemplateInterface::class, new class($pdo) implements TransactionTemplateInterface {
        private \PDO $pdo;
        public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
        public function required(callable $action): mixed {
            $this->pdo->beginTransaction();
            try { $result = $action(); $this->pdo->commit(); return $result; }
            catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        }
    });
}

// ── 3. 部署示例流程（幂等，对齐 Python/Node：加载全部共享 flow JSON） ──

$flowsDir = jeeflow_flows_dir();

echo "Deploying demo flows...\n";

if (!is_dir($flowsDir)) {
    echo "  [WARN] flows dir not found: $flowsDir\n";
} else {
    // 扫描全部 .json 文件并排序（与 Python os.listdir + sorted / Node readdirSync + sort 对齐）
    $files = glob($flowsDir . '/*.json');
    sort($files);

    foreach ($files as $path) {
        $fname = basename($path);
        $json = file_get_contents($path);
        $raw = json_decode($json, true);

        // 从 JSON 内容读取 name/displayName（与 Python/Node 一致）
        $name = $raw['name'] ?? pathinfo($fname, PATHINFO_FILENAME);
        $displayName = $raw['displayName'] ?? $name;
        $type = $raw['type'] ?? 'approval';

        // 幂等检查：如果已存在则跳过
        $existing = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 100]);
        $rows = $existing['data']['rows'] ?? [];
        $alreadyDeployed = false;
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $name) {
                $alreadyDeployed = true;
                break;
            }
        }

        if ($alreadyDeployed) {
            echo "  [OK] $name (already deployed)\n";
            continue;
        }

        $result = $facade->flow('processDefine/deploy', [
            'name' => $name,
            'displayName' => $displayName,
            'type' => $type,
            'content' => $json,
        ]);

        if ($result['code'] === 0) {
            echo "  [OK] $name ($displayName) deployed\n";
        } else {
            echo "  [FAIL] $name: {$result['msg']}\n";
        }
    }
}

echo "\nDone.\n";
echo "Now run: composer start\n";
