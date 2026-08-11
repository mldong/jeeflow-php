<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use PHPUnit\Framework\TestCase;

/**
 * FlowData 单元测试
 */
class FlowDataTest extends TestCase
{
    public function testCreate(): void
    {
        $fd = FlowData::create();
        $this->assertTrue($fd->isEmpty());
        $this->assertSame(0, $fd->count());
    }

    public function testOf(): void
    {
        $fd = FlowData::of(['a' => 1, 'b' => 'hello']);
        $this->assertSame(1, $fd->get('a'));
        $this->assertSame('hello', $fd->get('b'));
        $this->assertSame(2, $fd->count());
    }

    public function testSetAndGet(): void
    {
        $fd = FlowData::create();
        $fd->set('key', 'value');
        $this->assertSame('value', $fd->get('key'));
        $this->assertTrue($fd->has('key'));
        $this->assertFalse($fd->has('nonexistent'));
    }

    public function testGetDefault(): void
    {
        $fd = FlowData::create();
        $this->assertNull($fd->get('missing'));
        $this->assertSame('default', $fd->get('missing', 'default'));
    }

    public function testRemove(): void
    {
        $fd = FlowData::of(['a' => 1, 'b' => 2]);
        $fd->remove('a');
        $this->assertFalse($fd->has('a'));
        $this->assertTrue($fd->has('b'));
    }

    public function testSetAll(): void
    {
        $fd = FlowData::of(['a' => 1]);
        $fd->setAll(['b' => 2, 'c' => 3]);
        $this->assertSame(1, $fd->get('a'));
        $this->assertSame(2, $fd->get('b'));
        $this->assertSame(3, $fd->get('c'));
    }

    public function testCopy(): void
    {
        $fd = FlowData::of(['a' => 1]);
        $copy = $fd->copy();
        $copy->set('b', 2);
        $this->assertFalse($fd->has('b'), '原对象不应受 copy 影响');
        $this->assertTrue($copy->has('b'));
    }

    public function testToArray(): void
    {
        $data = ['x' => 1, 'y' => 'hello', 'z' => true];
        $fd = FlowData::of($data);
        $this->assertSame($data, $fd->toArray());
    }

    public function testKeys(): void
    {
        $fd = FlowData::of(['a' => 1, 'b' => 2, 'c' => 3]);
        $keys = $fd->keys();
        sort($keys);
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    // ── 类型安全取值 ──

    public function testGetStr(): void
    {
        $fd = FlowData::of(['name' => 'test', 'num' => 42]);
        $this->assertSame('test', $fd->getStr('name'));
        $this->assertSame('42', $fd->getStr('num'));
        $this->assertNull($fd->getStr('missing'));
        $this->assertSame('default', $fd->getStr('missing', 'default'));
    }

    public function testGetInt(): void
    {
        $fd = FlowData::of(['num' => 42, 'str' => '100', 'bad' => 'abc']);
        $this->assertSame(42, $fd->getInt('num'));
        $this->assertSame(100, $fd->getInt('str'));
        $this->assertSame(100, $fd->getInt('str')); // numeric string
        $this->assertNull($fd->getInt('bad'));
        $this->assertSame(0, $fd->getInt('missing', 0));
    }

    public function testGetLong(): void
    {
        $fd = FlowData::of(['big' => 9999999999, 'str' => '123']);
        $this->assertSame(9999999999, $fd->getLong('big'));
        $this->assertSame(123, $fd->getLong('str'));
        $this->assertNull($fd->getLong('missing'));
    }

    public function testGetBool(): void
    {
        $fd = FlowData::of([
            't1' => true,
            't2' => 'true',
            't3' => '1',
            't4' => 'yes',
            'f1' => false,
            'f2' => 'false',
            'f3' => '0',
        ]);
        $this->assertTrue($fd->getBool('t1'));
        $this->assertTrue($fd->getBool('t2'));
        $this->assertTrue($fd->getBool('t3'));
        $this->assertTrue($fd->getBool('t4'));
        $this->assertFalse($fd->getBool('f1'));
        $this->assertFalse($fd->getBool('f2'));
        $this->assertFalse($fd->getBool('f3'));
        $this->assertNull($fd->getBool('missing'));
    }

    public function testGetArray(): void
    {
        $fd = FlowData::of([
            'arr' => [1, 2, 3],
            'single' => 'value',
        ]);
        $this->assertSame([1, 2, 3], $fd->getArray('arr'));
        $this->assertSame(['value'], $fd->getArray('single'));
        $this->assertSame([], $fd->getArray('missing'));
    }

    // ── ArrayAccess ──

    public function testArrayAccess(): void
    {
        $fd = FlowData::create();
        $fd['key'] = 'value';
        $this->assertTrue(isset($fd['key']));
        $this->assertSame('value', $fd['key']);
        unset($fd['key']);
        $this->assertFalse(isset($fd['key']));
    }

    // ── IteratorAggregate ──

    public function testIterable(): void
    {
        $fd = FlowData::of(['a' => 1, 'b' => 2]);
        $result = [];
        foreach ($fd as $k => $v) {
            $result[$k] = $v;
        }
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }
}
