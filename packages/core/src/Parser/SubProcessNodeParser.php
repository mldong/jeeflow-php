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
        $model->setForm((string) ($p[self::FORM_KEY] ?? ''));
        if (isset($p['version'])) {
            $model->setVersion((int) $p['version']);
        }
    }
}
