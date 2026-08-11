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
            throw new \RuntimeException('流程定义 JSON 解析失败');
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
            if ($parser !== null) {
                $parser->parse($lfNode, $edges);
                $nodeModel = $parser->getModel();
                $processModel->addNode($nodeModel);
                if ($nodeModel instanceof TaskModel) {
                    $processModel->addTask($nodeModel);
                }
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
