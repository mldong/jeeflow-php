<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfEdge;
use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\TransitionModel;

/**
 * 通用属性解析抽象类（解析基本属性和边）
 *
 * 对齐 Java AbstractNodeParser。
 */
abstract class AbstractNodeParser implements NodeParserInterface
{
    protected NodeModel $nodeModel;

    /** @param LfEdge[] $edges */
    public function parse(LfNode $lfNode, array $edges): void
    {
        $this->nodeModel = $this->newModel();
        // 解析基本信息
        $this->nodeModel->setName($lfNode->id);
        if ($lfNode->text !== null) {
            $this->nodeModel->setDisplayName((string) ($lfNode->text[self::TEXT_VALUE_KEY] ?? ''));
        }
        $properties = $lfNode->properties ?? [];
        // 解析布局属性
        $x = $lfNode->x;
        $y = $lfNode->y;
        $w = self::toInt($properties[self::WIDTH_KEY] ?? null, 0);
        $h = self::toInt($properties[self::HEIGHT_KEY] ?? null, 0);
        $this->nodeModel->setLayout("{$x},{$y},{$w},{$h}");
        // 解析拦截器
        $this->nodeModel->setPreInterceptors((string) ($properties[self::PRE_INTERCEPTORS_KEY] ?? ''));
        $this->nodeModel->setPostInterceptors((string) ($properties[self::POST_INTERCEPTORS_KEY] ?? ''));
        // 解析输出边
        $nodeEdges = $this->getEdgesBySourceNodeId($lfNode->id, $edges);
        foreach ($nodeEdges as $edge) {
            $tm = new TransitionModel();
            $tm->setName($edge->id);
            $tm->setTo($edge->targetNodeId);
            $tm->setSource($this->nodeModel);
            if ($edge->properties !== null) {
                $tm->setExpr((string) ($edge->properties[self::EXPR_KEY] ?? ''));
            }
            if ($edge->pointsList !== null && count($edge->pointsList) > 0) {
                $parts = [];
                foreach ($edge->pointsList as $p) {
                    $parts[] = ($p['x'] ?? 0) . ',' . ($p['y'] ?? 0);
                }
                $tm->setG(implode(';', $parts));
            } elseif ($edge->startPoint !== null && $edge->endPoint !== null) {
                $tm->setG(
                    ($edge->startPoint['x'] ?? 0) . ',' . ($edge->startPoint['y'] ?? 0) . ';' .
                    ($edge->endPoint['x'] ?? 0) . ',' . ($edge->endPoint['y'] ?? 0)
                );
            }
            $this->nodeModel->addOutput($tm);
        }
        // 调用子类特定解析方法
        $this->parseNode($lfNode);
    }

    /** 子类实现特定解析 */
    abstract public function parseNode(LfNode $lfNode): void;

    /** 子类各自创建节点模型对象 */
    abstract public function newModel(): NodeModel;

    public function getModel(): NodeModel
    {
        return $this->nodeModel;
    }

    /** @param LfEdge[] $edges */
    private function getEdgesBySourceNodeId(string $sourceNodeId, array $edges): array
    {
        return array_values(array_filter($edges, fn(LfEdge $e) => $e->sourceNodeId === $sourceNodeId));
    }

    private static function toInt(mixed $value, int $default): int
    {
        if ($value === null) return $default;
        if (is_numeric($value)) return (int) $value;
        return $default;
    }
}
