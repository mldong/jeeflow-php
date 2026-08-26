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

    public function testAllFlowsCountIs15(): void
    {
        $files = glob(self::$flowsDir . '/*.json');
        $this->assertNotFalse($files);
        // issues/91：新增 13-countersign-one-vote-veto.json → 共享流程 15 个
        $this->assertCount(15, $files, '应有 15 个共享流程定义');
    }
}
