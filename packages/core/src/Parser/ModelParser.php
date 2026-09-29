<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfModel;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ProcessModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Model\TransitionModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\JsonProviderInterface;

/**
 * 模型解析器 —— 将 JSON 流程定义解析为 ProcessModel
 *
 * 对齐 Java ModelParser。
 */
final class ModelParser
{
    /** @var array<string, NodeParserInterface> 节点类型 → 解析器映射 */
    private static array $parsers = [];
    private static bool $initialized = false;

    private function __construct() {}

    /**
     * 注册内置节点解析器（首次调用时自动初始化）
     */
    private static function ensureInitialized(): void
    {
        if (self::$initialized) return;
        self::registerParser('start', new StartNodeParser());
        self::registerParser('end', new EndNodeParser());
        self::registerParser('task', new TaskNodeParser());
        self::registerParser('decision', new DecisionNodeParser());
        self::registerParser('fork', new ForkNodeParser());
        self::registerParser('join', new JoinNodeParser());
        self::registerParser('subprocess', new SubProcessNodeParser());
        self::$initialized = true;
    }

    /**
     * 注册自定义节点解析器
     */
    public static function registerParser(string $type, NodeParserInterface $parser): void
    {
        self::$parsers[$type] = $parser;
    }

    /**
     * 将 JSON 字符串解析为流程模型
     */
    public static function parse(string $jsonStr): ProcessModel
    {
        self::ensureInitialized();

        $json = ServiceContext::find(JsonProviderInterface::class);
        $data = $json !== null ? $json->decode($jsonStr) : json_decode($jsonStr, true);
        if (!is_array($data)) {
            // issues/139：文案与 java 逐字一致（`ModelParser.java:51` 「读取流程定义 JSON 失败」）。
            // 本栈此前写作「流程定义 JSON 解析失败」——同一失败在两栈给前端两套对外 msg，
            // 而 issues/139 立的是"固定文案、且不把底层异常文本拼进对外 msg"（本栈 PHP 的
            // json_decode 没有 cause 可拼，故只对齐固定文案那半）。
            throw new \RuntimeException('读取流程定义 JSON 失败');
        }

        $lfModel = LfModel::fromArray($data);
        $processModel = new ProcessModel();

        // 流程定义基本信息
        $processModel->setName($lfModel->name);
        $processModel->setDisplayName($lfModel->displayName);
        $processModel->setType($lfModel->type);
        $processModel->setInstanceUrl($lfModel->instanceUrl);
        $processModel->setInstanceNoClass($lfModel->instanceNoClass);
        $processModel->setPreInterceptors($lfModel->preInterceptors);
        $processModel->setPostInterceptors($lfModel->postInterceptors);
        $processModel->setRelTableName($lfModel->relTableName);
        $processModel->setPersistMode($lfModel->persistMode);
        $processModel->setExpireTime($lfModel->expireTime);

        $nodes = $lfModel->nodes;
        $edges = $lfModel->edges;

        if (empty($nodes) || empty($edges)) {
            return $processModel;
        }

        // 解析各节点
        foreach ($nodes as $lfNode) {
            $type = str_replace(NodeParserInterface::NODE_NAME_PREFIX, '', $lfNode->type);
            $parser = self::$parsers[$type] ?? null;
            if ($parser === null) {
                // issues/141 G4 义务 2（spec 02-flow-definition.md「类型键的三条义务」第 2 条）：
                // 类型表查不到解析器时，跳过本身是允许的，但**必须先留一条可诊断记录**
                // （节点 id ＋ 实得类型串含 snaker: 前缀原样），不允许"无声丢节点＋连带丢它的出边"。
                // spec 02 点名的现读反面样本就是本栈：类型表七档无 'custom'（:34-40）、也无
                // CustomNodeParser.php，而自家共享夹具 flows/08-custom-node.json:38 写着
                // "type": "snaker:custom" ⇒ 该节点连同出边被无声吞掉。本轮 owner 明确**只补日志**，
                // 不新增 custom 档、也不做大小写归一（义务 1 留到下一轮，java 同口径未做）。
                // 日志通道沿用本仓 core 既有姿势（ProcessPublisher / SurrogateInterceptor 的 error_log，
                // 不引新依赖），级别语义靠 "WARNING" 前缀表达（php 无日志级别对象）。
                error_log('[jeeflow-php] WARNING 流程定义里的节点类型没有对应解析器，该节点及其出边将被跳过: '
                    . 'nodeId=' . $lfNode->id . ', type=' . $lfNode->type . ', lookupKey=' . $type);
                continue;
            }
            $parser->parse($lfNode, $edges);
            $nodeModel = $parser->getModel();
            $processModel->addNode($nodeModel);
            if ($nodeModel instanceof TaskModel) {
                $processModel->addTask($nodeModel);
            }
        }

        // 构造输入边的 source/target 引用
        foreach ($processModel->getNodes() as $node) {
            foreach ($node->getOutputs() as $transition) {
                $to = $transition->getTo();
                foreach ($processModel->getNodes() as $node2) {
                    if (strcasecmp($to, $node2->getName()) === 0) {
                        $node2->addInput($transition);
                        $transition->setTarget($node2);
                    }
                }
            }
        }

        return $processModel;
    }

    /**
     * 重置解析器状态（用于测试）
     */
    public static function reset(): void
    {
        self::$parsers = [];
        self::$initialized = false;
    }
}
