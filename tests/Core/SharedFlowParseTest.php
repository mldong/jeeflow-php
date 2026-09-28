<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 共享流程定义解析测试 —— 验证 14 个 Java 共享 JSON 全部可解析
 */
class SharedFlowParseTest extends TestCase
{
    /** 必须存在的共享流程（新增夹具时往这里点名，不维护总数） */
    public static array $requiredFlows = [
        '06-countersign-sequential.json',
        '13-countersign-one-vote-veto.json',
        '06-countersign-sequential-expire.json',
    ];

    private static string $flowsDir;

    public static function setUpBeforeClass(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        self::$flowsDir = jeeflow_flows_dir();
    }

    protected function tearDown(): void
    {
        ModelParser::reset();
    }

    /**
     * @dataProvider flowFileProvider
     */
    public function testParseFlow(string $filename): void
    {
        $path = self::$flowsDir . '/' . $filename;
        $this->assertFileExists($path, "共享流程文件必须存在: {$filename}");

        $json = file_get_contents($path);
        $this->assertNotFalse($json);

        $model = ModelParser::parse($json);
        $this->assertNotEmpty($model->getName(), "{$filename}: name 不应为空");
        $this->assertNotNull($model->getStart(), "{$filename}: 必须有开始节点");
        $this->assertNotEmpty($model->getNodes(), "{$filename}: 节点列表不应为空");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flowFileProvider(): array
    {
        $dir = jeeflow_flows_dir();
        if (!is_dir($dir)) {
            return [['01-simple.json']]; // fallback if dir missing
        }
        $files = glob($dir . '/*.json');
        $cases = [];
        foreach ($files as $f) {
            $name = basename($f);
            $cases[$name] = [$name];
        }
        return $cases;
    }

    /**
     * 必备夹具点名核对——**故意不数总份数**：总数会随每次新增共享流程漂移，改忘一次就是一条
     * 与契约无关的红（owner 2026-09-28：测试用例别写具体数字）。点名要什么，就只判什么在不在。
     */
    public function testRequiredSharedFlowsArePresent(): void
    {
        $present = array_map('basename', glob(self::$flowsDir . '/*.json') ?: []);
        foreach (self::$requiredFlows as $need) {
            $this->assertContains($need, $present, "共享流程夹具缺失：{$need}（flows 副本落后于 java 编辑源）");
        }
    }
}
