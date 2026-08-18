<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Metadata\HandlerMeta;
use Jeeflow\Core\Metadata\HandlerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * HandlerRegistry 内置注册 单元测试（issues/74）
 */
class HandlerRegistryTest extends TestCase
{
    public function testBuiltInAssignmentHandlers(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('AssignmentHandler');
        $this->assertCount(7, $handlers);
    }

    public function testFirstHandlerIsOperator(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('AssignmentHandler');
        $this->assertSame('流程发起人', $handlers[0]->displayName);
        $this->assertSame(-9999, $handlers[0]->order);
    }

    public function testHandlerClassNames(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('AssignmentHandler');
        $classNames = array_map(fn(HandlerMeta $m) => $m->className, $handlers);

        $this->assertContains(
            'com.mldong.jeeflow.interceptor.impl.OperatorAssignmentHandler',
            $classNames
        );
        $this->assertContains(
            'com.mldong.jeeflow.interceptor.impl.FormFieldAssigneeHandler',
            $classNames
        );
    }

    public function testListHandlersNoFilter(): void
    {
        $registry = new HandlerRegistry();
        $all = $registry->listHandlers();
        $this->assertCount(7, $all);
    }

    public function testListHandlersUnknownType(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('UnknownType');
        $this->assertSame([], $handlers);
    }

    public function testRegisterCustomHandler(): void
    {
        $registry = new HandlerRegistry();
        $custom = new HandlerMeta('AssignmentHandler', 'com.custom.MyHandler', '自定义处理器', 100);
        $registry->register($custom);

        $handlers = $registry->listHandlers('AssignmentHandler');
        $this->assertCount(8, $handlers); // 7 内置 + 1 自定义
    }
}