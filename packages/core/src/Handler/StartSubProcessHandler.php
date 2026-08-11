<?php

declare(strict_types=1);

namespace Jeeflow\Core\Handler;

use Jeeflow\Core\Execution;
use Jeeflow\Core\Model\SubProcessModel;

/**
 * 启动子流程处理器
 *
 * 对齐 Java StartSubProcessHandler。
 * E1 阶段简化实现：直接跳过子流程执行输出边。
 */
class StartSubProcessHandler implements HandlerInterface
{
    private SubProcessModel $subProcessModel;

    public function __construct(SubProcessModel $subProcessModel)
    {
        $this->subProcessModel = $subProcessModel;
    }

    public function handle(Execution $execution): void
    {
        // E1 简化：子流程需要引擎级支持（创建子实例），暂直接穿透
        // E2 阶段补充完整的子流程启动逻辑
    }
}
