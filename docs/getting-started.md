# 快速开始（SDK 集成）

> 把 jeeflow-php 作为依赖集成到你的项目。参考 demo（Slim 4 应用）见 [demo-slim](https://github.com/mldong/jeeflow-php/tree/main/demo-slim)。

## 安装

```bash
composer require mldong/jeeflow-php
```

引擎核心零框架依赖（纯 PHP 8.1+），只需 `psr/http-message` 接口。

## 最小示例（内存模式，5 行跑起来）

不依赖任何数据库，适合学习、测试：

```php
<?php
require 'vendor/autoload.php';

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;

$repo = new InMemoryProcessRepository();
$engine = new JeeflowEngine($repo);

// 1. 部署流程定义（LogicFlow JSON）
$facade = new Jeeflow\WebContract\JeeflowFacade($engine, $repo);
$result = $facade->flow('processDefine/deploy', [
    'name' => 'simple',
    'displayName' => '简单审批',
    'content' => file_get_contents('flow.json'),
]);
$defineId = $result['data']['processDefineId'];

// 2. 发起流程（startAndExecute 自动完成申请节点）
$result = $facade->flow('processDefine/startAndExecute', [
    'processDefineId' => $defineId,
    'operator' => 'user1',
]);
$instanceId = $result['data']['processInstanceId'];

// 3. 查询待办
$todo = $facade->flow('processTask/todoList', ['operator' => 'leader']);
$taskId = $todo['data']['rows'][0]['id'];

// 4. 审批
$facade->flow('processTask/execute', [
    'processTaskId' => $taskId,
    'operator' => 'leader',
    'submitType' => 'approve',
]);
```

## 生产环境（PDO/MySQL）

```php
use Jeeflow\RepositoryPDO\PdoProcessRepository;

$pdo = new PDO('mysql:host=localhost;dbname=jeeflow', 'root', '');
$repo = new PdoProcessRepository($pdo);
$engine = new JeeflowEngine($repo);
```

建表 SQL 见 `packages/repository-pdo/sql/schema-mysql.sql`（八表）。

## 下一步

- [引擎 API](./engine-api.md) —— `JeeflowEngine` 全部方法
- [Facade 契约](./facade-api.md) —— 40 action 统一门面
- [SPI 实现指南](./spi-guide.md) —— 接入自己的数据库/用户体系
- [PSR 集成](./psr-integration.md) —— 接入 Slim/Laminas/Mezzio 等框架
