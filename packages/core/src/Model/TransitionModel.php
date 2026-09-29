<?php

declare(strict_types=1);

namespace Jeeflow\Core\Model;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\CreateTaskHandler;
use Jeeflow\Core\Handler\StartSubProcessHandler;

/**
 * 边/转移模型 —— 连接两个节点的有向边
 *
 * 对齐 Java TransitionModel。
 */
class TransitionModel extends BaseModel
{
    private ?NodeModel $source = null;
    private ?NodeModel $target = null;
    private string $to = '';
    private string $expr = '';
    private string $g = '';
    private bool $enabled = false;

    public function execute(Execution $execution): void
    {
        if (!$this->enabled) return;
        // issues/143：出边的目标节点不在模型里 ⇒ 这条边**落穿**（记一条可诊断日志后不推进），
        // 严禁裸调 target->execute()。
        //
        // 什么时候会为 null：解析期类型表查不到解析器（G4 义务 2 的未知档）时，节点被 continue
        // 跳过、不进模型，而**指向它的边**仍挂在上游节点的 outputs 上（ModelParser.php:124-135
        // 只在目标节点存在时才 setTarget）。令牌走到上游节点并办结 ⇒ 原形状是
        // Error: Call to a member function execute() on null，一次配置写错（大小写、拼错、
        // 设计器脏数据）把整次办理打崩，正面违反 spec 02 §6.2 第 2 条「严禁抛错打断建单」
        // 与 spec 04「节点属性配错不该把流程炸掉」。
        //
        // 为什么选「停住」而不是「越过它继续」：未知档没被解析成任何模型，越过它等于用一条臆造
        // 通路把跑不通的定义跑成功，用户面更难发现；停住＋一条带 from/to 的 WARNING 才可诊断
        // （与 rust/moon/go 的可观测结果一致：实例留 DOING、库里不产生越过它的行）。
        if ($this->target === null) {
            error_log("[jeeflow-php] WARNING 转移出边的目标节点不在模型里（该节点类型未建档，解析期已跳过），"
                . "本次流转停在这条边: from="
                . ($this->source !== null ? $this->source->getName() : 'null')
                . ", to=" . $this->to);
            return;
        }
        if ($this->target instanceof TaskModel) {
            $this->fire(new CreateTaskHandler($this->target), $execution);
        } elseif ($this->target instanceof SubProcessModel) {
            $this->fire(new StartSubProcessHandler($this->target), $execution);
        } else {
            $this->target->execute($execution);
        }
    }

    // ── Getters/Setters ──

    public function getSource(): ?NodeModel { return $this->source; }
    public function setSource(?NodeModel $v): void { $this->source = $v; }
    public function getTarget(): ?NodeModel { return $this->target; }
    public function setTarget(?NodeModel $v): void { $this->target = $v; }
    public function getTo(): string { return $this->to; }
    public function setTo(string $v): void { $this->to = $v; }
    public function getExpr(): string { return $this->expr; }
    public function setExpr(string $v): void { $this->expr = $v; }
    public function getG(): string { return $this->g; }
    public function setG(string $v): void { $this->g = $v; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $v): void { $this->enabled = $v; }
}
