<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Support\ReapplySupport;
use PHPUnit\Framework\TestCase;

/**
 * issues/242 · `reapplyMode` 非法值必须抛**引擎契约异常**，不是 `Error: Class not found`。
 *
 * 病根：`ReapplySupport.php:11` 原写 `use Jeeflow\Core\Exception\JeeflowException;`，
 * 而本栈的契约异常实际住在 `Jeeflow\Core\JeeflowException`（没有 `Exception` 子命名空间）。
 * PHP 是运行期解析命名空间，编译期照不出来 ⇒ 只有走到"非法值"那一档才当场炸 `Error`。
 * 后果不是"没报错"，而是**对外文案失逐字**：门面把非契约异常统一转成 `流程处理失败`
 * （判别式见 spec 06 §2.12），配置者拿不到「节点[n1]的 reapplyMode 配置非法: BOGUS」这句
 * 带节点名与键名的话——正是"该响的地方响错了内容"。
 *
 * 为什么断言写成"类型＋逐字 msg"两件事：只断"抛了异常"照不出本缺陷——
 * 修复前抛的 `Error` 也算"抛了"。这一格在修复前必红（`expectException` 接不住 `Error`）。
 */
final class ReapplyModeIllegalValueTest extends TestCase
{
    /** 逐字契约文案：与 spec 06 失败文案表、其余七栈同尺；改字面量要走 issue，不要顺手改 */
    private const VERBATIM = '节点[n1]的 reapplyMode 配置非法: BOGUS';

    public function testIllegalValueThrowsEngineContractException(): void
    {
        $caught = null;
        try {
            ReapplySupport::parseMode('BOGUS', 'n1');
        } catch (\Throwable $t) {
            $caught = $t;
        }

        self::assertNotNull($caught, '非法值必须报错，不得静默落回缺省');
        self::assertInstanceOf(
            JeeflowException::class,
            $caught,
            '必须是引擎契约异常；' . get_class($caught) . ' 说明异常件的命名空间又串了（issues/242 同形）'
        );
        self::assertSame(
            self::VERBATIM,
            $caught->getMessage(),
            '对外 msg 必须逐字带上节点名与键名，且不把内部码拼进来'
        );
        self::assertStringNotContainsString('2001', $caught->getMessage(), '内部码不得外泄');
    }

    /** 回归：三档合法值不经过抛错分支，逐档原样返回。 */
    public function testLegalValuesPassThroughUnchanged(): void
    {
        self::assertSame(ReapplySupport::NORMAL, ReapplySupport::parseMode('', 'n1'));
        self::assertSame(ReapplySupport::NORMAL, ReapplySupport::parseMode('  NORMAL  ', 'n1'));
        self::assertSame(
            ReapplySupport::RETURN_TO_SOURCE,
            ReapplySupport::parseMode(ReapplySupport::RETURN_TO_SOURCE, 'n1')
        );
    }

    /** 普查守卫：`ReapplySupport` 里每一枚 `use` 都必须解析得到（issues/242 的同类形状）。 */
    public function testEveryImportedClassOfReapplySupportResolves(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/core/src/Support/ReapplySupport.php'
        );
        $classes = [];
        foreach (preg_split('/\R/', $src) as $line) {
            if (preg_match('/^use\s+([^;]+);/', trim($line), $m)) {
                $classes[] = trim($m[1]);
            }
        }
        self::assertNotEmpty($classes, '没读到 use 语句＝这格变成死格，先修探针再谈判据');
        foreach ($classes as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class),
                "use {$class} 解析不到＝运行期才会炸（issues/242 同形）"
            );
        }
    }
}
