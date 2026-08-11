<?php

declare(strict_types=1);

/**
 * jeeflow PHP 引擎参考 demo —— Slim 4
 *
 * 启动：composer start  或  php -S localhost:8090 -t public
 * 访问：POST http://localhost:8090/wf/{action}
 *
 * 对齐其他语言 demo 的 8 个具名用户和统一接口规范。
 */

require __DIR__ . '/../vendor/autoload.php';

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use Jeeflow\WebPsr\JeeflowRequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// ── 初始化引擎 ──

$repo = new InMemoryProcessRepository();
$extRepo = new InMemoryProcessExtRepository();
$engine = new JeeflowEngine($repo);
$facade = new JeeflowFacade($engine, $repo, $extRepo);

// 注入事务模板（demo 用同步执行）
ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
    public function required(callable $action): mixed { return $action(); }
});

// ── 预部署示例流程 ──

$flowsDir = dirname(__DIR__, 3) . '/jeeflow-java/jeeflow-core/src/test/resources/flows';
$flowFiles = [
    '01-simple.json' => '简单审批',
    '02-multi-instance.json' => '多实例',
    '03-decision-expr.json' => '条件分支',
];

foreach ($flowFiles as $file => $displayName) {
    $path = $flowsDir . '/' . $file;
    if (!file_exists($path)) continue;
    $json = file_get_contents($path);
    $name = pathinfo($file, PATHINFO_FILENAME);
    $result = $facade->flow('processDefine/deploy', [
        'name' => $name,
        'displayName' => $displayName,
        'content' => $json,
    ]);
    if ($result['code'] !== 0) {
        error_log("Failed to deploy {$name}: {$result['msg']}");
    }
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

$app->get('/health', function (Request $request, Response $response): Response {
    $body = json_encode(['status' => 'ok', 'engine' => 'jeeflow-php']);
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
