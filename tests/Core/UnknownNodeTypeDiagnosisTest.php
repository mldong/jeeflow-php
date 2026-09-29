<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 未知节点档不再静默丢（issues/141 G4 义务 2 · spec 02-flow-definition.md「类型键的三条义务」· PHP 栈）
 *
 * 条文（逐字）：「**未知档不得静默丢节点**：类型不在表里时，必须**记一条可诊断日志
 * （带节点 id 与实得类型串）**再决定跳过，不允许"静默丢节点＋连带丢它的出边"。
 * 现读反面样本：php 类型表根本没有 `custom` 档（`ModelParser.php:34-40` 七档无 `'custom'`，
 * 也无 `CustomNodeParser.php`），而它自家共享夹具 `jeeflow-php/flows/08-custom-node.json:38`
 * 就写着 `"type": "snaker:custom"` ⇒ 这个节点在 php 栈被无声吞掉。」
 *
 * 本轮 owner 拍的落地范围＝**只补可诊断日志**（与 java 同口径：查不到解析器时落一条 WARNING，
 * 带 nodeId ＋ 实得类型串）：
 *  - **不**新增 `custom` 解析器（那是缺档补齐，另轮决定）；
 *  - **不**做大小写归一化（义务 1，java 也没做，牵动整张档位表需一次全量回归）。
 * 所以这里同时钉住"跳过仍然发生"（义务 2 要的是可诊断，不是保留节点）。
 *
 * 日志通道沿用本仓 core 既有姿势：`error_log()`（`ProcessPublisher` 的监听器隔离、
 * `SurrogateInterceptor` 的委托降级都走它，零新依赖）。测试用 `ini_set('error_log', 临时文件)`
 * 收流——CLI 默认落 stderr，不可断言。
 */
final class UnknownNodeTypeDiagnosisTest extends TestCase
{
    private ?string $logFile = null;
    private string|false $savedErrorLog = false;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ModelParser::reset();

        $this->logFile = sys_get_temp_dir() . '/jeeflow-unknown-type-' . getmypid()
            . '-' . spl_object_id($this) . '.log';
        @unlink($this->logFile);
        $this->savedErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->savedErrorLog !== false && $this->savedErrorLog !== '') {
            ini_set('error_log', (string) $this->savedErrorLog);
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        ModelParser::reset();
        ServiceContext::clear();
    }

    /** 收流：本轮解析写下的 error_log 全文（无则空串）。 */
    private function logTail(): string
    {
        return is_file((string) $this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /** start → odd(给定类型串) → end 的三节点两边形定义；类型串由用例给定。 */
    private static function flowWith(string $nodeType, string $nodeId = 'odd'): string
    {
        return json_encode([
            'name' => 'unknown-141',
            'displayName' => '未知档诊断',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 100, 'y' => 200,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => $nodeId, 'type' => $nodeType, 'x' => 300, 'y' => 200,
                 'properties' => ['clazz' => 'SomeHandler'], 'text' => ['value' => '通知外部系统']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 500, 'y' => 200,
                 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e1', 'sourceNodeId' => 'start', 'targetNodeId' => $nodeId, 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => $nodeId, 'targetNodeId' => 'end', 'properties' => []],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 类型表查不到解析器时，跳过本身允许，但**必须留下一条可诊断记录**：
     * 带节点 id ＋ 实得类型串（含 snaker: 前缀原样）。改前是 `if ($parser !== null)` 无声吞掉，
     * 一条日志都没有 ⇒ 这一格改前必红。
     */
    public function testUnknownNodeTypeIsLoggedNotSilentlyDropped(): void
    {
        $model = ModelParser::parse($this->flowWith('snaker:notRegisteredAtAll', 'odd'));

        $this->assertNull($model->getNode('odd'),
            '未建档的类型仍不进模型（义务 2 要的是可诊断，不是保留节点）');
        $log = $this->logTail();
        $this->assertNotSame('', $log, '未知档必须留一条可诊断记录，改前这里是空（无声丢节点）');
        $this->assertStringContainsString('WARNING', $log, '诊断记录须落在 WARNING 级语义（java 用 JUL WARNING）');
        $this->assertStringContainsString('nodeId=odd', $log, '诊断记录应带节点 id，实际文案=' . $log);
        $this->assertStringContainsString('snaker:notRegisteredAtAll', $log,
            '诊断记录应带实得类型串（含 snaker: 前缀原样），实际文案=' . $log);
        $this->assertStringContainsString('notRegisteredAtAll', $log,
            '诊断记录也应带去前缀后的查表键，方便对照档位表，实际文案=' . $log);
    }

    // testCustomNodeInSharedFixtureIsDiagnosed 已删除（2026-09-30，issues/142 §5 第 2 条改判）：
    // 它钉的是「自家夹具 flows/08-custom-node.json 里的 snaker:custom 节点不进模型、只出未知档诊断」，
    // 而本栈现在补上了 custom 档（CustomNodeParser ＋ CustomModel 执行腿 ＋ DONE 历史行），
    // 那句 assertNull($model->getNode('custom1')) 与新契约正面互斥——实测病灶还原时它反过来变绿，
    // 说明两件事不可能同时成立，属"跟着裁定改判"而非"改期望值蒙红"。
    // custom 现在的形状由 tests/Core/CustomNodeRecordLegTest.php 的
    // testCustomNodeIsParsedWithItsOutgoingEdge（进模型＋出边还在＋不再冒未知档诊断）反向钉住；
    // 「未知档不得静默丢、要记可诊断日志」这条义务仍由本文件
    // testUnknownNodeTypeIsLoggedNotSilentlyDropped / testBlankTypeIsAlsoDiagnosed 两格守着。

    /**
     * 已知档不得被日志改动带偏：正常定义解析时不应冒出"未知类型"诊断。
     * （01-simple 四档 start/task/task/end 全命中。）
     */
    public function testKnownTypesProduceNoUnknownTypeDiagnosis(): void
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json);

        $model = ModelParser::parse($json);

        $this->assertCount(4, $model->getNodes(), '01-simple 应解析出 4 个节点');
        $log = $this->logTail();
        $this->assertStringNotContainsString('没有对应解析器', $log,
            '已知档不得产生未知类型诊断，实际文案=' . $log);
    }

    /** 空类型串（设计器脏数据）同样要可诊断，而不是无声消失。 */
    public function testBlankTypeIsAlsoDiagnosed(): void
    {
        $model = ModelParser::parse($this->flowWith('', 'weird'));

        $this->assertNull($model->getNode('weird'));
        $this->assertStringContainsString('nodeId=weird', $this->logTail(),
            '空类型串也得留下带节点 id 的诊断，实际文案=' . $this->logTail());
    }
}
