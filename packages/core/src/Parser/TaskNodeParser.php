<?php

declare(strict_types=1);

namespace Jeeflow\Core\Parser;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\CountersignType;
use Jeeflow\Core\Enum\PerformType;
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
        $model->setPerformType(isset($p[self::PERFORM_TYPE_KEY]) ? PerformType::from($p[self::PERFORM_TYPE_KEY]) : null);
        $model->setReminderTime((string) ($p[self::REMINDER_TIME_KEY] ?? ''));
        $model->setReminderRepeat((string) ($p[self::REMINDER_REPEAT_KEY] ?? ''));
        $model->setExpireTime((string) ($p[self::EXPIRE_TIME_KEY] ?? ''));
        $model->setAutoExecute((string) ($p[self::AUTO_EXECUTE_KEY] ?? ''));
        $model->setCallback((string) ($p[self::CALLBACK_KEY] ?? ''));
        // 退回发起人后的重提方式：缺省 NORMAL，非法值报配置错误
        $model->setReapplyMode(\Jeeflow\Core\Support\ReapplySupport::parseMode(
            (string) ($p[\Jeeflow\Core\Support\ReapplySupport::PROP_REAPPLY_MODE] ?? ''), $model->getName()));
        // 后加签两开关（spec 02 §4.3）：缺省关／0＝不限，非法值解析期报配置错误，不得静默当缺省。
        // 判据单点在 AddSignSupport（本类不写第二份）；声明成 TaskModel 字段后，
        // 下面"直接属性镜像进 ext"那条腿不收它们（只收固定白名单），双源就此断掉。
        $model->setAllowAddSign(\Jeeflow\Core\Support\AddSignSupport::parseAllow(
            $p[\Jeeflow\Core\Support\AddSignSupport::PROP_ALLOW_ADD_SIGN] ?? null, $model->getName()));
        $model->setAddSignMaxRounds(\Jeeflow\Core\Support\AddSignSupport::parseMaxRounds(
            $p[\Jeeflow\Core\Support\AddSignSupport::PROP_ADD_SIGN_MAX_ROUNDS] ?? null, $model->getName()));
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
            $model->setCountersignType(CountersignType::from($csType));
        }
        // countersignCompletionCondition：顶层优先、field 子对象兜底（issues/177 R11，形状对齐 issues/163）
        $csCondition = $p[self::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY]
            ?? (is_array($field) ? ($field[self::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY] ?? null) : null);
        if ($csCondition !== null) {
            $model->setCountersignCompletionCondition((string) $csCondition);
        }
        $model->setExt($ext);
    }
}
