<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Metadata\EnumDictRegistry;
use PHPUnit\Framework\TestCase;

/**
 * EnumDictRegistry 单元测试（issues/74）
 */
class EnumDictRegistryTest extends TestCase
{
    public function testKeysReturns7Keys(): void
    {
        $keys = EnumDictRegistry::keys();
        $this->assertCount(7, $keys);
        $this->assertContains('wf_process_define_state', $keys);
        $this->assertContains('wf_process_instance_state', $keys);
        $this->assertContains('wf_process_submit_type', $keys);
        $this->assertContains('wf_process_task_state', $keys);
        $this->assertContains('wf_process_task_type', $keys);
        $this->assertContains('wf_process_task_perform_type', $keys);
        $this->assertContains('wf_countersign_type', $keys);
    }

    public function testGetInstanceState(): void
    {
        $items = EnumDictRegistry::get('wf_process_instance_state');
        $this->assertCount(7, $items);
        foreach ($items as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
        }
        $this->assertSame('10', $items[0]['value']);
        $this->assertSame('进行中', $items[0]['label']);
        $this->assertSame('20', $items[1]['value']);
        $this->assertSame('已完成', $items[1]['label']);
    }

    public function testGetSubmitType(): void
    {
        $items = EnumDictRegistry::get('wf_process_submit_type');
        $this->assertCount(8, $items);
        $this->assertSame('0', $items[0]['value']);
        $this->assertSame('发起申请', $items[0]['label']);
    }

    public function testGetTaskType(): void
    {
        $items = EnumDictRegistry::get('wf_process_task_type');
        $this->assertCount(3, $items);
        $this->assertSame('2', $items[2]['value']);
        $this->assertSame('记录', $items[2]['label']);
    }

    public function testGetPerformType(): void
    {
        $items = EnumDictRegistry::get('wf_process_task_perform_type');
        $this->assertCount(2, $items);
    }

    public function testGetCountersignType(): void
    {
        $items = EnumDictRegistry::get('wf_countersign_type');
        $this->assertCount(2, $items);
        $this->assertSame('0', $items[0]['value']);
        $this->assertSame('并行会签', $items[0]['label']);
        $this->assertSame('1', $items[1]['value']);
        $this->assertSame('串行会签', $items[1]['label']);
    }

    public function testGetDefineState(): void
    {
        $items = EnumDictRegistry::get('wf_process_define_state');
        $this->assertCount(2, $items);
        $this->assertSame('0', $items[0]['value']);
        $this->assertSame('禁用', $items[0]['label']);
    }

    public function testGetTaskState(): void
    {
        $items = EnumDictRegistry::get('wf_process_task_state');
        $this->assertCount(6, $items);
    }

    public function testGetUnknownKeyReturnsEmpty(): void
    {
        $items = EnumDictRegistry::get('unknown_key');
        $this->assertSame([], $items);
    }
}