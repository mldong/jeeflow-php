<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model\LogicFlow;

/**
 * LogicFlow 模型 —— 前端流程设计器的 JSON 数据模型
 *
 * 对齐 Java LfModel。
 */
class LfModel
{
    public string $name = '';
    public string $displayName = '';
    public string $type = '';
    public string $instanceUrl = '';
    public string $instanceNoClass = '';
    public string $preInterceptors = '';
    public string $postInterceptors = '';
    public string $relTableName = '';
    public string $persistMode = '';
    public string $expireTime = '';
    /** @var LfNode[] */
    public array $nodes = [];
    /** @var LfEdge[] */
    public array $edges = [];

    public static function fromArray(array $data): self
    {
        $m = new self();
        $m->name = (string) ($data['name'] ?? '');
        $m->displayName = (string) ($data['displayName'] ?? '');
        $m->type = (string) ($data['type'] ?? '');
        $m->instanceUrl = (string) ($data['instanceUrl'] ?? '');
        $m->instanceNoClass = (string) ($data['instanceNoClass'] ?? '');
        $m->preInterceptors = (string) ($data['preInterceptors'] ?? '');
        $m->postInterceptors = (string) ($data['postInterceptors'] ?? '');
        $m->relTableName = (string) ($data['relTableName'] ?? '');
        $m->persistMode = (string) ($data['persistMode'] ?? '');
        $m->expireTime = (string) ($data['expireTime'] ?? '');
        foreach (($data['nodes'] ?? []) as $n) {
            $m->nodes[] = LfNode::fromArray($n);
        }
        foreach (($data['edges'] ?? []) as $e) {
            $m->edges[] = LfEdge::fromArray($e);
        }
        return $m;
    }
}
