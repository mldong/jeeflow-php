<?php

declare(strict_types=1);

namespace Jeeflow\Core\Metadata;

/**
 * 内置枚举字典注册表 —— 对齐 Python metadata._DICTS / Java EnumDictRegistry
 *
 * 7 个 key 对齐 boot3 字典 key，value/label 与 Java enums 完全一致。
 * 集成方通过 get(key) 查询，无需重复定义。
 */
final class EnumDictRegistry
{
    /** @var array<string, list<array{value:string, label:string}>> */
    private static array $dicts = [
        'wf_process_define_state' => [
            ['value' => '0', 'label' => '禁用'],
            ['value' => '1', 'label' => '启用'],
        ],
        'wf_process_instance_state' => [
            ['value' => '10', 'label' => '进行中'],
            ['value' => '20', 'label' => '已完成'],
            ['value' => '30', 'label' => '已撤回'],
            ['value' => '40', 'label' => '强行终止'],
            ['value' => '45', 'label' => '已拒绝'],
            ['value' => '50', 'label' => '挂起'],
            ['value' => '99', 'label' => '已废弃'],
        ],
        'wf_process_submit_type' => [
            ['value' => '0', 'label' => '发起申请'],
            ['value' => '1', 'label' => '同意申请'],
            ['value' => '2', 'label' => '拒绝申请'],
            ['value' => '3', 'label' => '退回上一步'],
            ['value' => '4', 'label' => '跳转'],
            ['value' => '5', 'label' => '重新提交'],
            ['value' => '6', 'label' => '退回发起人'],
            ['value' => '20', 'label' => '拒绝申请'],
        ],
        'wf_process_task_state' => [
            ['value' => '10', 'label' => '进行中'],
            ['value' => '20', 'label' => '已完成'],
            ['value' => '30', 'label' => '已撤回'],
            ['value' => '40', 'label' => '强行终止'],
            ['value' => '50', 'label' => '挂起'],
            ['value' => '99', 'label' => '已废弃'],
        ],
        'wf_process_task_type' => [
            ['value' => '0', 'label' => '主办'],
            ['value' => '1', 'label' => '协办'],
            ['value' => '2', 'label' => '记录'],
        ],
        'wf_process_task_perform_type' => [
            ['value' => '0', 'label' => '普通参与'],
            ['value' => '1', 'label' => '会签参与'],
        ],
        'wf_countersign_type' => [
            ['value' => '0', 'label' => '并行会签'],
            ['value' => '1', 'label' => '串行会签'],
        ],
    ];

    /** @return list<string> 内置字典 key 清单 */
    public static function keys(): array
    {
        return array_keys(self::$dicts);
    }

    /**
     * 按 key 取字典项
     *
     * @return list<array{value:string, label:string}>
     */
    public static function get(string $key): array
    {
        return self::$dicts[$key] ?? [];
    }
}
