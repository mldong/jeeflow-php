<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Model\CustomModel;
use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;

/**
 * 记录类（自定义）节点解析器 —— 对齐 Java CustomParser
 *
 * spec 02-flow-definition.md §6「自定义节点 properties（snaker:custom）」四键：
 * `clazz`（处理器注册名，必填）／`methodName`／`args`（逗号分隔的变量 key）／
 * `val`（返回值写入的变量 key，缺省 `FlowConst::CUSTOM_RETURN_VAL` = `custom_return_val`）。
 *
 * 本解析器是 issues/142 A 批给 php 补的那一档：此前 `ModelParser` 类型表只有七档
 * （start/end/task/decision/fork/join/subprocess），`snaker:custom` 查不到解析器 ⇒ 节点
 * **连同它的出边**在解析期被整个跳过（自带夹具 `flows/08-custom-node.json` 同样被吞）。
 *
 * ⚠️ 类型表**不做大小写归一、也不加别名**（issues/141 G4 义务 1/3 走 C 方案＝先立法后补实现，
 * 另轮处理）：查表方式与既有七档完全一致，只把 `custom` 这一档补进去。
 */
class CustomNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new CustomModel(); }

    public function parseNode(LfNode $lfNode): void
    {
        /** @var CustomModel $model */
        $model = $this->nodeModel;
        $p = $lfNode->properties ?? [];
        $model->setClazz((string) ($p[self::CLASS_KEY] ?? ''));
        $model->setMethodName((string) ($p[self::METHOD_NAME_KEY] ?? ''));
        $model->setArgs((string) ($p[self::ARGS_KEY] ?? ''));
        // val 命中用之，否则回落 custom_return_val（java 在解析期就默认，c# 同形）
        $val = trim((string) ($p[self::RETURN_VAL_KEY] ?? ''));
        $model->setVar($val !== '' ? $val : FlowConst::CUSTOM_RETURN_VAL);
    }
}
