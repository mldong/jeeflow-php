<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model\LogicFlow;

/**
 * LogicFlow 边
 */
class LfEdge
{
    public string $id = '';
    public string $type = '';
    public string $sourceNodeId = '';
    public string $targetNodeId = '';
    public ?array $properties = null;
    public ?array $text = null;
    public ?array $startPoint = null;
    public ?array $endPoint = null;
    /** @var array{ x: int, y: int }[]|null */
    public ?array $pointsList = null;

    public static function fromArray(array $data): self
    {
        $e = new self();
        $e->id = (string) ($data['id'] ?? '');
        $e->type = (string) ($data['type'] ?? '');
        $e->sourceNodeId = (string) ($data['sourceNodeId'] ?? '');
        $e->targetNodeId = (string) ($data['targetNodeId'] ?? '');
        $e->properties = $data['properties'] ?? null;
        $e->text = $data['text'] ?? null;
        $e->startPoint = $data['startPoint'] ?? null;
        $e->endPoint = $data['endPoint'] ?? null;
        $e->pointsList = $data['pointsList'] ?? null;
        return $e;
    }
}
