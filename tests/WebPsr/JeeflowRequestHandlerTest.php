<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebPsr;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use Jeeflow\WebPsr\JeeflowRequestHandler;
use Jeeflow\WebPsr\ResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * PSR-15 adapter 测试
 */
class JeeflowRequestHandlerTest extends TestCase
{
    private JeeflowRequestHandler $handler;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $repo = new InMemoryProcessRepository();
        $engine = new JeeflowEngine($repo);
        $this->facade = new JeeflowFacade($engine, $repo);
        $this->handler = new JeeflowRequestHandler($this->facade);

        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ── 基本路由 ──

    public function testValidAction(): void
    {
        $request = new ServerRequest('POST', '/wf/processDefine/page');
        $request = $request->withParsedBody(['pageNum' => 1, 'pageSize' => 10]);

        $response = $this->handler->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(0, $body['code']);
        $this->assertArrayHasKey('rows', $body['data']);
    }

    public function testInvalidPath(): void
    {
        $request = new ServerRequest('POST', '/other/something');
        $response = $this->handler->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(99999999, $body['code']);
    }

    public function testEmptyAction(): void
    {
        $request = new ServerRequest('POST', '/wf/');
        $response = $this->handler->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
    }

    // ── JSON body 解析 ──

    public function testJsonBodyParsing(): void
    {
        $json = json_encode(['pageNum' => 1, 'pageSize' => 5]);
        $request = new ServerRequest('POST', '/wf/processDefine/page');
        $request->getBody()->write($json);
        $request->getBody()->rewind();

        $response = $this->handler->handle($request);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(0, $body['code']);
    }

    // ── Query 参数合并 ──

    public function testQueryParamsMerge(): void
    {
        $request = new ServerRequest('POST', '/wf/processDefine/page?pageNum=1&pageSize=3');
        $response = $this->handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(0, $body['code']);
    }

    // ── 自定义前缀 ──

    public function testCustomPrefix(): void
    {
        $handler = new JeeflowRequestHandler($this->facade, '/api/flow');
        $request = new ServerRequest('POST', '/api/flow/processDefine/page');
        $request = $request->withParsedBody(['pageNum' => 1, 'pageSize' => 10]);

        $response = $handler->handle($request);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(0, $body['code']);
    }

    // ── ResponseFactory 单例 ──

    public function testResponseFactorySingleton(): void
    {
        $f1 = ResponseFactory::getInstance();
        $f2 = ResponseFactory::getInstance();
        $this->assertSame($f1, $f2);
    }

    public function testResponseFactoryCreateResponse(): void
    {
        $response = ResponseFactory::getInstance()->createResponse(201);
        $this->assertEquals(201, $response->getStatusCode());
    }

    // ── 完整流程：start → task 查询 ──

    public function testFullFlowViaHandler(): void
    {
        // 部署流程定义
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $deployResult = $this->facade->flow('processDefine/deploy', [
            'name' => 'simple',
            'displayName' => '简单审批',
            'content' => $json,
        ]);
        $this->assertEquals(0, $deployResult['code']);
        $defineId = $deployResult['data']['processDefineId'];

        // 通过 handler 发起流程
        $request = new ServerRequest('POST', '/wf/processInstance/startAndExecute');
        $request = $request->withParsedBody([
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);

        $response = $this->handler->handle($request);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(0, $body['code']);
        $this->assertArrayHasKey('processInstanceId', $body['data']);
    }
}
