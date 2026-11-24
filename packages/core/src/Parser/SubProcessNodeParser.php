<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\SubProcessModel;

class SubProcessNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new SubProcessModel(); }

    public function parseNode(LfNode $lfNode): void
    {
        /** @var SubProcessModel $model */
        $model = $this->nodeModel;
        $p = $lfNode->properties ?? [];

        // issues/257 裁决 A：目标定义名固定按 processName → properties.name → 节点 id 回落。
        // form 只随模型保留，不参与定义查找；subProcessName 不是新契约键。
        $processName = trim((string) ($p['processName'] ?? ''));
        if ($processName === '') {
            $processName = trim((string) ($p['name'] ?? ''));
        }
        if ($processName === '') {
            $processName = $model->getName();
        }
        $model->setProcessName($processName);
        $model->setForm((string) ($p[self::FORM_KEY] ?? ''));
        if (isset($p['version']) && is_numeric($p['version'])) {
            $model->setVersion((int) $p['version']);
        }
    }
}
