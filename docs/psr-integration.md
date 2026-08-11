# PSR 集成指南

> 将 jeeflow-php 接入任何 PSR-15 兼容的 PHP 框架。

## 内置 PSR-15 Handler

`web-psr` 包提供 `JeeflowRequestHandler`，实现 `Psr\Http\Server\RequestHandlerInterface`：

```php
use Jeeflow\WebPsr\JeeflowRequestHandler;

$handler = new JeeflowRequestHandler($facade);  // 默认前缀 /wf
$response = $handler->handle($request);          // PSR-7 ServerRequestInterface
```

约定：
- `POST /wf/{action}` → `facade->flow('{action}', body)`
- Body 为 JSON，解析后作为 args 传入
- 响应统一为 `{code, msg, data}` JSON

## Slim 4 集成

```php
use Slim\Factory\AppFactory;
use Jeeflow\WebPsr\JeeflowRequestHandler;

$app = AppFactory::create();
$handler = new JeeflowRequestHandler($facade);

$app->post('/wf/{action:.+}', function ($request, $response) use ($handler) {
    return $handler->handle($request);
});

$app->run();
```

完整示例见 [demo-slim](https://github.com/mldong/jeeflow-php/tree/main/demo-slim)。

## Laminas / Mezzio 集成

```php
use Laminas\Diactoros\Response;
use Jeeflow\WebPsr\JeeflowRequestHandler;

// 在路由配置中
$app->post('/wf/{action:.+}', $handler);
```

## 自定义 ResponseFactory

默认使用 `nyholm/psr7`，可替换为任何 PSR-17 实现：

```php
use Jeeflow\WebPsr\ResponseFactory;
use MyCustom\Psr17Factory;

ResponseFactory::setFactory(new Psr17Factory());
```

## 不依赖 PSR

如果不用 HTTP 框架，直接调用 Facade：

```php
$result = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 10]);
// $result 是纯数组，自行 json_encode
```
