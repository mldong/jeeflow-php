<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\DecisionModel;

class DecisionNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new DecisionModel(); }

    public function parseNode(LfNode $lfNode): void
    {
        /** @var DecisionModel $model */
        $model = $this->nodeModel;
        $p = $lfNode->properties ?? [];
        $model->setExpr((string) ($p[self::EXPR_KEY] ?? ''));
        $model->setHandleClass((string) ($p[self::HANDLE_CLASS_KEY] ?? ''));
    }
}
