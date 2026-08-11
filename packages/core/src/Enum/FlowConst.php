<?php

declare(strict_types=1);

namespace Jeeflow\Core\Enum;

/**
 * 流程常量定义 —— 对齐 Java FlowConst
 */
final class FlowConst
{
    public const BUSINESS_NO = 'BUSINESS_NO';
    public const ADMIN_ID = 'flow.admin';
    public const AUTO_ID = 'flow.auto';

    public const PROCESS_NAME_KEY = 'name';
    public const PROCESS_DISPLAY_NAME_KEY = 'displayName';
    public const PROCESS_TYPE = 'type';

    public const PROCESS_DEFINE_ID_KEY = 'processDefineId';
    public const PROCESS_DESIGN_ID_KEY = 'processDesignId';
    public const PROCESS_TASK_ID_KEY = 'processTaskId';
    public const PROCESS_INSTANCE_ID_KEY = 'processInstanceId';

    public const FORM_DATA_PREFIX = 'f_';
    public const TASK_FORM_DATA_PREFIX = 'tf_';

    public const APPROVAL_COMMENT = 'tf_approvalComment';
    public const APPROVAL_ATTACHMENT = 'tf_approvalAttachment';
    public const NEXT_NODE_OPERATOR = 'tf_nextNodeOperator';
    public const PROCESS_START_NEXT_NODE_OPERATOR = 'f_nextNodeOperator';
    public const CC_ACTORS = 'tf_ccActors';
    public const CC_ACTORS_START = 'f_ccActors';

    public const USER_USER_ID = 'u_userId';
    public const USER_REAL_NAME = 'u_realName';
    public const USER_DEPT_ID = 'u_deptId';
    public const USER_DEPT_NAME = 'u_deptName';
    public const USER_POST_ID = 'u_postId';
    public const USER_POST_NAME = 'u_postName';

    public const SUBMIT_TYPE = 'submitType';
    public const AUTO_GEN_TITLE = 'autoGenTitle';
    public const TASK_NAME = 'taskName';
    public const IS_FIRST_TASK_NODE = 'isFirstTaskNode';

    public const COUNTERSIGN_VARIABLE_PREFIX = 'csv_';
    public const NR_OF_ACTIVATE_INSTANCES = 'nrOfActivateInstances';
    public const LOOP_COUNTER = 'loopCounter';
    public const NR_OF_INSTANCES = 'nrOfInstances';
    public const NR_OF_COMPLETED_INSTANCES = 'nrOfCompletedInstances';
    public const COUNTERSIGN_OPERATOR_LIST = 'operatorList';
    public const COUNTERSIGN_TYPE = 'countersignType';
    public const COUNTERSIGN_DISAGREE_FLAG = 'countersignDisagreeFlag';

    public const ACTOR_IDS_KEY = 'actorIds';
    public const CUSTOM_RETURN_VAL = 'custom_return_val';
}
