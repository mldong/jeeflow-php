<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Model\LogicFlow\LfNode;
use Jeeflow\Core\Model\NodeModel;
use Jeeflow\Core\Model\TaskModel;

class TaskNodeParser extends AbstractNodeParser
{
    public function newModel(): NodeModel { return new TaskModel(); }

    public function parseNode(LfNode $lfNode): void
    {
        /** @var TaskModel $model */
        $model = $this->nodeModel;
        $p = $lfNode->properties ?? [];
        $model->setForm((string) ($p[self::FORM_KEY] ?? ''));
        $model->setAssignee((string) ($p[self::ASSIGNEE_KEY] ?? ''));
        $model->setAssignmentHandler((string) ($p[self::ASSIGNMENT_HANDLE_KEY] ?? ''));
        $model->setTaskType(isset($p[self::TASK_TYPE_KEY]) ? (int) $p[self::TASK_TYPE_KEY] : null);
        $model->setPerformType(isset($p[self::PERFORM_TYPE_KEY]) ? (int) $p[self::PERFORM_TYPE_KEY] : null);
        $model->setReminderTime((string) ($p[self::REMINDER_TIME_KEY] ?? ''));
        $model->setReminderRepeat((string) ($p[self::REMINDER_REPEAT_KEY] ?? ''));
        $model->setExpireTime((string) ($p[self::EXPIRE_TIME_KEY] ?? ''));
        $model->setAutoExecute((string) ($p[self::AUTO_EXECUTE_KEY] ?? ''));
        $model->setCallback((string) ($p[self::CALLBACK_KEY] ?? ''));
        // ext 字段
        $ext = FlowData::create();
        $field = $p[self::EXT_FIELD_KEY] ?? null;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $ext->set($k, $v);
            }
        }
        // 直接属性也放入 ext（candidateUsers/candidateGroups 等）
        foreach ([self::EXT_FIELD_CANDIDATE_USERS_KEY, self::EXT_FIELD_CANDIDATE_GROUPS_KEY,
                     self::EXT_FIELD_CANDIDATE_HANDLER_KEY, self::EXT_FIELD_COUNTERSIGN_TYPE_KEY,
                     self::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY] as $key) {
            if (isset($p[$key])) {
                $ext->set($key, $p[$key]);
            }
        }
        // countersignType 同时设到模型字段
        if (isset($p[self::EXT_FIELD_COUNTERSIGN_TYPE_KEY])) {
            $csType = $p[self::EXT_FIELD_COUNTERSIGN_TYPE_KEY];
            // Java 端 'ALL' → 1 (COUNTERSIGN), 'ANY' → 2 等; 这里直接存原始值
            $model->setCountersignType(is_numeric($csType) ? (int) $csType : null);
        }
        if (isset($p[self::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY])) {
            $model->setCountersignCompletionCondition((string) $p[self::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY]);
        }
        $model->setExt($ext);
    }
}
