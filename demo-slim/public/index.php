<?php

declare(strict_types=1);

/**
 * jeeflow PHP 引擎参考 demo —— Slim 4
 *
 * 启动前请先初始化：
 *   php bin/demo-init.php
 *
 * 启动：composer start  或  php -S localhost:8090 -t public
 * 访问：POST http://localhost:8090/wf/{action}
 *
 * 存储模式（环境变量 JEEFLOW_DEMO_STORE）：
 *   - sqlite : SQLite 文件（默认）
 *   - mysql  : MySQL PDO
 *
 * 对齐其他语言 demo 的 8 个具名用户和统一接口规范。
 */

require __DIR__ . '/../vendor/autoload.php';

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Demo\RepositoryFactory;
use Jeeflow\WebContract\JeeflowFacade;
use Jeeflow\WebPsr\JeeflowRequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// ── 初始化引擎 ──

$mode = RepositoryFactory::getMode();
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
    $pdo = RepositoryFactory::getPdo();
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

// ── Slim App ──

$app = AppFactory::create();

// CORS（对齐其他 demo）
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// OPTIONS 预检
$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

// ── jeeflow 统一路由 ──
// POST /wf/{action} → JeeflowFacade
// 使用 JeeflowRequestHandler (PSR-15) 作为中间件

$jeeflowHandler = new JeeflowRequestHandler($facade);

$app->post('/wf/{action:.+}', function (Request $request, Response $response) use ($jeeflowHandler): Response {
    return $jeeflowHandler->handle($request);
});

// ── 健康检查 ──

$app->get('/health', function (Request $request, Response $response) use ($mode): Response {
    $body = json_encode([
        'status' => 'ok',
        'engine' => 'jeeflow-php',
        'store' => $mode,
    ]);
    $response->getBody()->write($body);
    return $response->withHeader('Content-Type', 'application/json');
});

// ── 已部署流程列表（demo 专用） ──

$app->get('/demo/flows', function (Request $request, Response $response) use ($facade): Response {
    $result = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 100]);
    $data = json_encode($result['data']['rows'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response->getBody()->write($data);
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
