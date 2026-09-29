<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Enum\ProcessEventTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * 旧 case 名一代兼容别名（issues/132 第二轮 R2-4，spec §11.6）。
 *
 * 判据三格：① 别名常量与规范名是**同一个 case 对象**（不是同值的另一支）；
 * ② 别名不进 `cases()`、不改 `from()` 的返回与 `->name` 的规范名（否则 L2-30 事件流水
 *    sink 写出的 `<code>|<规范名>` 会被别名污染）；③ 码值 1/2/3 在改名前后不动。
 */
final class EventTypeAliasTest extends TestCase
{
    /**
     * 数据集顺序＝测试方法形参顺序：[旧名, 规范名, 码值]（键只当数据集名字用，不进参数）。
     *
     * @return array<string,array{string,string,int}>
     */
    public static function aliasProvider(): array
    {
        return [
            'INSTANCE_START' => ['INSTANCE_START', 'PROCESS_INSTANCE_START', 1],
            'INSTANCE_END' => ['INSTANCE_END', 'PROCESS_INSTANCE_END', 2],
            'TASK_START' => ['TASK_START', 'PROCESS_TASK_START', 3],
        ];
    }

    /**
     * @dataProvider aliasProvider
     */
    public function testAliasPointsToTheSameCaseObject(string $old, string $canonical, int $code): void
    {
        $alias = constant(ProcessEventTypeEnum::class . '::' . $old);
        $same = ProcessEventTypeEnum::from($code);
        self::assertSame($same, $alias, "别名 {$old} 必须是 case 对象本身");
        self::assertSame($canonical, $alias->name, "别名取到的 ->name 应是规范名 {$canonical}");
    }

    public function testAliasesAreNotEnumCases(): void
    {
        $names = array_map(static fn ($c) => $c->name, ProcessEventTypeEnum::cases());
        foreach (['INSTANCE_START', 'INSTANCE_END', 'TASK_START'] as $old) {
            self::assertNotContains($old, $names, "别名 {$old} 不应混进 cases()");
        }
        self::assertCount(9, $names, 'A 套码表 1..9 共九支，不多不少');
    }
}
