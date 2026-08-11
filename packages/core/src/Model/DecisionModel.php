<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;

/**
 * 决策节点模型
 *
 * 对齐 Java DecisionModel。
 */
class DecisionModel extends NodeModel
{
    private string $expr = '';
    private string $handleClass = '';

    protected function exec(Execution $execution): void
    {
        $found = false;
        $nextNodeName = null;

        if ($this->expr !== '') {
            $evaluator = ServiceContext::find(ExpressionEvaluatorInterface::class);
            if ($evaluator === null) {
                throw new JeeflowException('未注册表达式求值器 SPI');
            }
            $result = $evaluator->eval($this->expr, $execution->getArgs());
            if ($result !== null) {
                $nextNodeName = (string) $result;
            }
        }

        foreach ($this->getOutputs() as $tm) {
            if ($tm->getExpr() !== '') {
                $evaluator = ServiceContext::find(ExpressionEvaluatorInterface::class);
                if ($evaluator !== null && $evaluator->eval($tm->getExpr(), $execution->getArgs()) === true) {
                    $found = true;
                    $tm->setEnabled(true);
                    $tm->execute($execution);
                }
            } elseif ($nextNodeName !== null && strcasecmp($tm->getTo(), $nextNodeName) === 0) {
                $found = true;
                $tm->setEnabled(true);
                $tm->execute($execution);
            }
        }

        if (!$found) {
            throw new JeeflowException('无法确定下一节点');
        }
    }

    public function getExpr(): string { return $this->expr; }
    public function setExpr(string $v): void { $this->expr = $v; }
    public function getHandleClass(): string { return $this->handleClass; }
    public function setHandleClass(string $v): void { $this->handleClass = $v; }
}
