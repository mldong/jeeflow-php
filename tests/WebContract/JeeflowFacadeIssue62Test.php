<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/62：taskDetail 的 taskModel 缺 ext（字段权限）补齐测试
 *
 * 对齐 Java JeeflowFacadeTest 的 testTaskDetailTaskModelExt 场景：
 * - taskModel 补 form（节点 properties.form）与 ext（节点 properties.field，
 *   含 PERMISSION_* 字段权限，前端 initPermission 依据，与 boot2 setTaskModel 一致）
 */
class JeeflowFacadeIssue62Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo);
        // 注册简单事务模板（直接执行）
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    private function deploySimpleFlow(): string
    {
        $json = file_get_contents(__DIR__ . '/../../../jeeflow-java/jeeflow-core/src/test/resources/flows/01-simple.json');
        $result = $this->facade->flow('processDefine/deploy', [
            'content' => $json,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $result['code'], '部署失败: ' . $result['msg']);
        return $result['data']['processDefineId'];
    }

    public function testTaskDetailTaskModelExt(): void
    {
        $defineId = $this->deploySimpleFlow();
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId,
            'operator' => 'user1',
        ]);
        $this->assertEquals(0, $start['code'], $start['msg']);

        // task1（leader）在待办，detail 应带 taskModel.form/ext
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'leader']);
        $taskId = $todo['data']['rows'][0]['id'];

        $detail = $this->facade->flow('processTask/detail', ['id' => $taskId, 'operator' => 'leader']);
        $this->assertEquals(0, $detail['code'], $detail['msg']);
        $this->assertTrue($detail['data']['executable']);

        $tm = $detail['data']['taskModel'];
        $this->assertEquals('task1', $tm['name']);
        $this->assertEquals('leave-form', $tm['form']);
        // 字段权限：带 f_ 前缀优先 + 去前缀兼容
        $this->assertEquals(1, $tm['ext']['PERMISSION_f_leaveType']);
        $this->assertEquals(2, $tm['ext']['PERMISSION_days']);
    }
}
