<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Model\LogicFlow\LfEdge;
use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;

/**
 * 节点解析接口
 *
 * 对齐 Java NodeParser。
 */
interface NodeParserInterface
{
    public const NODE_NAME_PREFIX = 'snaker:';
    public const TEXT_VALUE_KEY = 'value';
    public const WIDTH_KEY = 'width';
    public const HEIGHT_KEY = 'height';
    public const PRE_INTERCEPTORS_KEY = 'preInterceptors';
    public const POST_INTERCEPTORS_KEY = 'postInterceptors';
    public const EXPR_KEY = 'expr';
    public const HANDLE_CLASS_KEY = 'handleClass';
    public const FORM_KEY = 'form';
    public const ASSIGNEE_KEY = 'assignee';
    public const ASSIGNMENT_HANDLE_KEY = 'assignmentHandler';
    public const TASK_TYPE_KEY = 'taskType';
    public const PERFORM_TYPE_KEY = 'performType';
    public const REMINDER_TIME_KEY = 'reminderTime';
    public const REMINDER_REPEAT_KEY = 'reminderRepeat';
    public const EXPIRE_TIME_KEY = 'expireTime';
    public const AUTO_EXECUTE_KEY = 'autoExecute';
    public const CALLBACK_KEY = 'callback';
    public const EXT_FIELD_KEY = 'field';
    public const EXT_FIELD_CANDIDATE_USERS_KEY = 'candidateUsers';
    public const EXT_FIELD_CANDIDATE_GROUPS_KEY = 'candidateGroups';
    public const EXT_FIELD_CANDIDATE_HANDLER_KEY = 'candidateHandler';
    public const EXT_FIELD_COUNTERSIGN_TYPE_KEY = 'countersignType';
    public const EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY = 'countersignCompletionCondition';
    // 记录类（自定义）节点 properties 四键（spec 02-flow-definition.md §6；键名逐字对齐
    // java NodeParser.CLASS_KEY / METHOD_NAME_KEY / ARGS_KEY / RETURN_VAL_KEY）
    public const CLASS_KEY = 'clazz';
    public const METHOD_NAME_KEY = 'methodName';
    public const ARGS_KEY = 'args';
    public const RETURN_VAL_KEY = 'val';

    /** @param LfEdge[] $edges */
    public function parse(LfNode $lfNode, array $edges): void;

    public function getModel(): NodeModel;
}
