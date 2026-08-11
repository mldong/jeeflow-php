<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\StartModel;

class StartNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new StartModel(); }
    public function parseNode(LfNode $lfNode): void { /* start 无额外属性 */ }
}
