<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\ServiceContext;
use PHPUnit\Framework\TestCase;

/**
 * ServiceContext 单元测试
 */
class ServiceContextTest extends TestCase
{
    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    public function testPutAndFind(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        ServiceContext::put('key1', $obj);
        $found = ServiceContext::find('key1');
        $this->assertSame($obj, $found);
        $this->assertSame('test', $found->name);
    }

    public function testFindNotFound(): void
    {
        $this->assertNull(ServiceContext::find('nonexistent'));
    }

    public function testClear(): void
    {
        ServiceContext::put('key1', new \stdClass());
        ServiceContext::clear();
        $this->assertNull(ServiceContext::find('key1'));
    }

    public function testOverwrite(): void
    {
        $obj1 = new \stdClass();
        $obj1->v = 1;
        $obj2 = new \stdClass();
        $obj2->v = 2;
        ServiceContext::put('key1', $obj1);
        ServiceContext::put('key1', $obj2);
        $this->assertSame(2, ServiceContext::find('key1')->v);
    }

    public function testMultipleKeys(): void
    {
        $a = new \stdClass(); $a->v = 'a';
        $b = new \stdClass(); $b->v = 'b';
        $c = new \stdClass(); $c->v = 'c';
        ServiceContext::put('key_a', $a);
        ServiceContext::put('key_b', $b);
        ServiceContext::put('key_c', $c);
        $this->assertSame('a', ServiceContext::find('key_a')->v);
        $this->assertSame('b', ServiceContext::find('key_b')->v);
        $this->assertSame('c', ServiceContext::find('key_c')->v);
    }

    public function testClassBasedKey(): void
    {
        $obj = new \stdClass();
        ServiceContext::put(\stdClass::class, $obj);
        $this->assertSame($obj, ServiceContext::find(\stdClass::class));
    }

    public function testRemove(): void
    {
        ServiceContext::put('key1', new \stdClass());
        $this->assertNotNull(ServiceContext::find('key1'));
        ServiceContext::remove('key1');
        $this->assertNull(ServiceContext::find('key1'));
    }

    public function testAll(): void
    {
        ServiceContext::put('a', new \stdClass());
        ServiceContext::put('b', new \stdClass());
        $all = ServiceContext::all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }

    public function testIsolation(): void
    {
        $before = new \stdClass();
        $before->v = 'before';
        ServiceContext::put('shared', $before);
        $this->assertSame('before', ServiceContext::find('shared')->v);

        ServiceContext::clear();
        $after = new \stdClass();
        $after->v = 'after';
        ServiceContext::put('shared', $after);
        $this->assertSame('after', ServiceContext::find('shared')->v);
    }
}
