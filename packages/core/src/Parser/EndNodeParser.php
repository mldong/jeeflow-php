<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\EndModel;

class EndNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new EndModel(); }
    public function parseNode(LfNode $lfNode): void { /* end 无额外属性 */ }
}
