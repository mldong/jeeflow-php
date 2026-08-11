<?php

declare(strict_types=1);

namespace Jeeflow\WebPsr;

use Jeeflow\WebContract\JeeflowFacade;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 请求处理器 —— 将 HTTP 请求转发到 JeeflowFacade
 *
 * 对齐其他语言的 HTTP adapter 层。可插入任何 PSR-15 兼容框架
 * （Slim、Laminas、Mezzio 等）。
 *
 * 用法：
 *   $handler = new JeeflowRequestHandler($facade);
 *   $response = $handler->handle($request);
 *
 * 约定：
 *   - POST /wf/{action} → facade->flow('{action}', body)
 *   - 路径从 URI 中提取 action（/wf/ 前缀后的部分）
 *   - Body 为 JSON，解析后作为 args 传入
 *   - 响应统一为 {code, msg, data} JSON
 */
class JeeflowRequestHandler implements RequestHandlerInterface
{
    private JeeflowFacade $facade;
    private string $prefix;

    public function __construct(JeeflowFacade $facade, string $prefix = '/wf')
    {
        $this->facade = $facade;
        $this->prefix = rtrim($prefix, '/');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1. 提取 action
        $path = $request->getUri()->getPath();
        $action = $this->extractAction($path);

        if ($action === null) {
            return $this->jsonResponse($request, [
                'code' => 99999999,
                'msg' => '无效的路径，期望 POST ' . $this->prefix . '/{action}',
                'data' => null,
            ], 404);
        }

        // 2. 解析 body
        $body = $request->getParsedBody();
        if ($body === null) {
            $raw = (string) $request->getBody();
            $body = json_decode($raw, true) ?? [];
        }
        if (!is_array($body)) {
            $body = [];
        }

        // 3. 注入 query 参数（兼容 GET 参数）
        $queryParams = $request->getQueryParams();
        $args = array_merge($body, $queryParams);

        // 4. 调用 Facade
        $result = $this->facade->flow($action, $args);

        // 5. 返回 JSON 响应
        return $this->jsonResponse($request, $result);
    }

    /**
     * 从路径中提取 action
     * 例：/wf/processDefine/page → processDefine/page
     */
    private function extractAction(string $path): ?string
    {
        $path = rtrim($path, '/');
        if (!str_starts_with($path, $this->prefix . '/')) {
            return null;
        }
        $action = substr($path, strlen($this->prefix) + 1);
        return $action !== '' ? $action : null;
    }

    /**
     * 生成 JSON 响应
     * 注意：PSR-7 ResponseInterface 需要具体的实现类来写 body。
     * 这里使用一个简单的工厂方法。
     */
    private function jsonResponse(ServerRequestInterface $request, array $data, int $status = 200): ResponseInterface
    {
        $responseFactory = ResponseFactory::getInstance();
        $response = $responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }
}
