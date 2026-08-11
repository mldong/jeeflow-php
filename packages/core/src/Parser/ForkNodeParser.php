<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\ForkModel;

class ForkNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new ForkModel(); }
    public function parseNode(LfNode $lfNode): void { /* fork 无额外属性 */ }
}
