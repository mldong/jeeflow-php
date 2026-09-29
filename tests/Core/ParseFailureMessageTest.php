<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 流程定义 JSON 读取失败文案逐字对齐 java（issues/139 · 八栈同批的 PHP 腿）
 *
 * 基准原文：`../jeeflow-java/jeeflow-core/src/main/java/com/mldong/jeeflow/parser/ModelParser.java:51`
 * —— `throw new RuntimeException("读取流程定义 JSON 失败", e);`
 * 本栈此前写作「流程定义 JSON 解析失败」（`ModelParser.php:62`），同一失败在两栈给前端两套对外 msg，
 * 跨栈比对/日志检索时对不上。本轮改成**逐字一致**。
 *
 * issues/139 的另一半是"不把底层异常文本拼进对外 msg"：本栈 `json_decode` 没有 cause 可拼，
 * 天然满足；测试同时钉住"文案里没有语法错误细节"（例如 `json_get_last_error` 那类底层文本），
 * 免得哪天有人顺手把 `json_last_error_msg()` 拼进去。
 */
final class ParseFailureMessageTest extends TestCase
{
    /** java 逐字原文——改这个字面量等于改跨栈契约，要走 issues/139 而不是顺手改 */
    private const JAVA_VERBATIM = '读取流程定义 JSON 失败';

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ModelParser::reset();
    }

    protected function tearDown(): void
    {
        ModelParser::reset();
        ServiceContext::clear();
    }

    /** 坏 JSON ⇒ 抛出文案与 java 逐字一致（改前是「流程定义 JSON 解析失败」，这一格必红）。 */
    public function testMalformedJsonThrowsJavaVerbatimMessage(): void
    {
        foreach ([
            '空串' => '',
            '裸文本' => 'this-is-not-json',
            '截断的对象' => '{"name":"half',
            '顶层是数字' => '123',
            '顶层是 null' => 'null',
            '顶层是字符串' => '"just a string"',
        ] as $label => $json) {
            try {
                ModelParser::parse($json);
                $this->fail("{$label} 应抛出读取失败");
            } catch (\RuntimeException $e) {
                $this->assertSame(self::JAVA_VERBATIM, $e->getMessage(),
                    "{$label}：对外文案须与 java 逐字一致（issues/139）");
            }
        }
    }

    /** 旧文案不得残留：跨栈检索按「读取流程定义 JSON 失败」这一把尺子，别留第二套。 */
    public function testOldWordingIsGone(): void
    {
        try {
            ModelParser::parse('{"name":');
            $this->fail('应抛出读取失败');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('解析失败', $e->getMessage(),
                '旧文案「流程定义 JSON 解析失败」不得残留');
        }
    }

    /**
     * 不拼底层异常/语法细节文本（issues/139 的另一半）：msg 就是那句固定文案本身，
     * 逐字相等即"没有任何底层文本被拼进来"——多一个字符这一格就红。
     */
    public function testMessageCarriesNoUnderlyingErrorDetail(): void
    {
        try {
            ModelParser::parse("{'unquoted': keys, trailing,}");
            $this->fail('应抛出读取失败');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::JAVA_VERBATIM, $e->getMessage());
            $this->assertStringNotContainsString('Syntax error', $e->getMessage());
            $this->assertStringNotContainsString('{', $e->getMessage(), '不得把入参原文回显进 msg');
            $this->assertStringNotContainsString('json_last_error', strtolower($e->getMessage()),
                '不得把底层 JSON 错误文本拼进对外 msg（issues/139 立的正是这一条）');
        }
    }

    /** 正常定义不受影响（防止把判据改成"逢解析就抛"）。 */
    public function testValidFlowStillParses(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json);
        $model = ModelParser::parse($json);
        $this->assertSame('simple', $model->getName());
    }
}
