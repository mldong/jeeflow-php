<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\JoinModel;

class JoinNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new JoinModel(); }
    public function parseNode(LfNode $lfNode): void { /* join 无额外属性 */ }
}
