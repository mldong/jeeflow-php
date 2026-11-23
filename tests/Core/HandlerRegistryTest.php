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
        $this->assertCount(9, $handlers);
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
        // 两条新项是**顶级类**（不带 OrgUserAssignmentHandlers$ 前缀），注册名与其余七语言逐字一致
        $this->assertContains(
            'com.mldong.jeeflow.interceptor.impl.FormUserDeptLeaderAssignmentHandler',
            $classNames
        );
        $this->assertContains(
            'com.mldong.jeeflow.interceptor.impl.FormUserDeptMainLeaderAssignmentHandler',
            $classNames
        );
    }

    public function testFormUserMetasOrderAndDisplayNames(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('AssignmentHandler');
        $tail = array_slice($handlers, -2);
        $this->assertSame('com.mldong.jeeflow.interceptor.impl.FormUserDeptLeaderAssignmentHandler', $tail[0]->className);
        $this->assertSame('表单指定用户所属部门经理', $tail[0]->displayName);
        $this->assertSame(70, $tail[0]->order);
        $this->assertSame('com.mldong.jeeflow.interceptor.impl.FormUserDeptMainLeaderAssignmentHandler', $tail[1]->className);
        $this->assertSame('表单指定用户所属部门分管领导', $tail[1]->displayName);
        $this->assertSame(80, $tail[1]->order);
    }

    public function testListHandlersNoFilter(): void
    {
        $registry = new HandlerRegistry();
        $all = $registry->listHandlers();
        // 9 内置 AssignmentHandler + 1 内置 ActionPermissionProvider 默认件（issues/29／hub issues/248）
        $this->assertCount(10, $all);
    }

    public function testBuiltInActionPermissionMeta(): void
    {
        $registry = new HandlerRegistry();
        $handlers = $registry->listHandlers('ActionPermissionProvider');
        $this->assertCount(1, $handlers);
        $this->assertSame('com.mldong.jeeflow.spi.DefaultActionPermissionProvider', $handlers[0]->className);
        $this->assertSame('action 权限码默认映射（boot3 注解语义归纳）', $handlers[0]->displayName);
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
        $this->assertCount(10, $handlers); // 9 内置 + 1 自定义
    }

    public function testListHandlerTypes(): void
    {
        $registry = new HandlerRegistry();
        $this->assertSame(['AssignmentHandler', 'ActionPermissionProvider'], $registry->listHandlerTypes(),
            '内置 9 条 AssignmentHandler + 1 条 ActionPermissionProvider（issues/248）⇒ 两类型（首次注册序）');

        $registry->register(new HandlerMeta('CandidateHandler', 'com.example.CandidateProvider', '候选人提供者', 1));
        $this->assertSame(
            ['AssignmentHandler', 'ActionPermissionProvider', 'CandidateHandler'],
            $registry->listHandlerTypes(),
            '显式注册类型入场（对齐 Java 参考口径）'
        );

        $registry->register(new HandlerMeta('AssignmentHandler', 'com.custom.MyHandler', '自定义处理器', 100));
        $this->assertSame(
            ['AssignmentHandler', 'ActionPermissionProvider', 'CandidateHandler'],
            $registry->listHandlerTypes(),
            '同类型多枚 ⇒ 去重不重复'
        );
        $this->assertCount(
            1,
            $registry->listHandlers('CandidateHandler'),
            '与 listHandlers 同口径：类型在场 ⟺ 该类型下有清单项'
        );
    }

    public function testListHandlersSortedByOrder(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(new HandlerMeta('AssignmentHandler', 'com.custom.EarlyBird', '插队处理器', 5));
        $handlers = $registry->listHandlers('AssignmentHandler');
        $this->assertSame(
            [-9999, 5, 10, 20, 30, 40, 50, 60, 70, 80],
            array_map(fn(HandlerMeta $m) => $m->order, $handlers),
            '注册序 ≠ order 序时仍按 order 升序返回（spec 08 场景 22）'
        );
        $this->assertSame('com.custom.EarlyBird', $handlers[1]->className);
    }

    public function testListHandlersGroupSortedByOrder(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(new HandlerMeta('FlowInterceptor', 'com.custom.PreB', 'pre-B', 20, 'pre'));
        $registry->register(new HandlerMeta('FlowInterceptor', 'com.custom.PreA', 'pre-A', 10, 'pre'));
        $pre = $registry->listHandlers('FlowInterceptor', 'pre');
        $this->assertSame(
            ['com.custom.PreA', 'com.custom.PreB'],
            array_map(fn(HandlerMeta $m) => $m->className, $pre),
            '按类型 + 分组列出同样 order 升序（spec 08 场景 22）'
        );
    }
}