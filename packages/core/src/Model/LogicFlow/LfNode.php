<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model\LogicFlow;

/**
 * LogicFlow 节点
 */
class LfNode
{
    public string $id = '';
    public string $type = '';
    public int $x = 0;
    public int $y = 0;
    public ?array $properties = null;
    public ?array $text = null;

    public static function fromArray(array $data): self
    {
        $n = new self();
        $n->id = (string) ($data['id'] ?? '');
        $n->type = (string) ($data['type'] ?? '');
        $n->x = (int) ($data['x'] ?? 0);
        $n->y = (int) ($data['y'] ?? 0);
        $n->properties = $data['properties'] ?? null;
        $n->text = $data['text'] ?? null;
        return $n;
    }
}
