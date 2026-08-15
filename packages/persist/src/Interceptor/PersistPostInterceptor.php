<?php

declare(strict_types=1);

namespace Jeeflow\Persist\Interceptor;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Interceptor\FlowInterceptor;
use Jeeflow\Core\Metadata\HandlerMeta;
use Jeeflow\Core\Metadata\HandlerRegistry;
use Jeeflow\Core\Model\EndModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Persist\DynamicTableWriter;

/**
 * 业务数据入库后置拦截器 —— 对齐 Java PersistPostInterceptor
 *
 * ARCHIVE：结束同意 INSERT；SYNC：发起 INSERT → 任务节点按字段权限 UPDATE → 结束定稿。
 */
class PersistPostInterceptor implements FlowInterceptor
{
    public const JAVA_CLASS = 'com.mldong.jeeflow.persist.interceptor.PersistPostInterceptor';
    public const PERSIST_MODE_ARCHIVE = 'ARCHIVE';
    public const PERSIST_MODE_SYNC = 'SYNC';
    public const PERMISSION_PREFIX = 'PERMISSION_';
    public const PERM_EDIT = 2;
    public const META_DISPLAY_NAME = '业务数据自动入库';
    public const META_GROUP = 'post';

    public function intercept(Execution $execution): void
    {
        $writer = ServiceContext::find(DynamicTableWriter::class);
        if (!$writer instanceof DynamicTableWriter) {
            return;
        }
        $model = $execution->getProcessModel();
        $instance = $execution->getProcessInstance();
        if ($model === null || $instance === null) {
            return;
        }
        $table = trim($model->getRelTableName());
        if ($table === '') {
            $table = trim($model->getName());
        }
        if ($table === '') {
            return;
        }
        $mode = strtoupper(trim($model->getPersistMode()));
        if ($mode === self::PERSIST_MODE_SYNC) {
            $this->interceptSync($execution, $writer, $table);
            return;
        }
        $this->interceptArchive($execution, $writer, $table);
    }

    public static function registerMeta(HandlerRegistry $registry): void
    {
        $registry->register(new HandlerMeta(
            'FlowInterceptor',
            self::JAVA_CLASS,
            self::META_DISPLAY_NAME,
            0,
            self::META_GROUP,
        ));
    }

    private function interceptArchive(Execution $execution, DynamicTableWriter $writer, string $table): void
    {
        $node = $execution->getNodeModel();
        $instance = $execution->getProcessInstance();
        if (!$node instanceof EndModel || $instance === null) {
            return;
        }
        if ($instance->getState() !== ProcessInstanceState::FINISHED) {
            return;
        }
        $submitType = $execution->getArgs()->getInt(FlowConst::SUBMIT_TYPE);
        if ($submitType !== SubmitType::AGREE) {
            return;
        }
        if (!$this->markChain($execution)) {
            return;
        }
        if ($writer->exists($table, 'process_instance_id', $instance->getInstanceId())) {
            return;
        }
        $data = $this->extractFields($execution->getArgs(), null, false, true);
        $this->fillContext($data, $execution);
        $writer->fillSystemFields($data, true);
        $writer->insert($table, $data);
    }

    private function interceptSync(Execution $execution, DynamicTableWriter $writer, string $table): void
    {
        $node = $execution->getNodeModel();
        $instance = $execution->getProcessInstance();
        if ($node === null || $instance === null) {
            return;
        }
        if (!$this->markChain($execution)) {
            return;
        }
        $exists = $writer->exists($table, 'process_instance_id', $instance->getInstanceId());
        $isTask = $node instanceof TaskModel;
        $fieldPerm = $isTask ? $node->getExt() : null;
        $data = $this->extractFields($execution->getArgs(), $fieldPerm, !$exists || $isTask, !$exists || $isTask);
        $stateCode = $isTask ? ProcessInstanceState::DOING : $instance->getState();
        $this->putStateField($writer, $table, $data, $node->getName(), $stateCode);
        $this->fillContext($data, $execution);
        if (!$exists) {
            $writer->fillSystemFields($data, true);
            $writer->insert($table, $data);
            return;
        }
        $writer->fillSystemFields($data, false);
        $writer->update($table, $data, 'process_instance_id', $instance->getInstanceId());
    }

    private function markChain(Execution $execution): bool
    {
        $instance = $execution->getProcessInstance();
        $node = $execution->getNodeModel();
        if ($instance === null || $node === null) {
            return false;
        }
        $key = '__persist_executed_' . $instance->getInstanceId() . '_' . $node->getName();
        if ($execution->getArgs()->get($key) === true) {
            return false;
        }
        $execution->getArgs()->set($key, true);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFields(FlowData $args, ?FlowData $fieldPerm, bool $includeTaskFields, bool $includeFormFields): array
    {
        $prefix = FlowConst::FORM_DATA_PREFIX;
        $taskPrefix = FlowConst::TASK_FORM_DATA_PREFIX;
        $data = [];
        foreach ($args->keys() as $k) {
            if ($includeFormFields && str_starts_with($k, $prefix) && strlen($k) > strlen($prefix)) {
                $name = substr($k, strlen($prefix));
                if ($fieldPerm !== null && !$this->isEditable($fieldPerm, $name)) {
                    continue;
                }
                $data[$name] = $args->get($k);
            } elseif ($includeTaskFields && str_starts_with($k, $taskPrefix) && strlen($k) > strlen($taskPrefix)) {
                $data[substr($k, strlen($taskPrefix))] = $args->get($k);
            }
        }
        return $data;
    }

    private function isEditable(FlowData $fieldPerm, string $fieldName): bool
    {
        $perm = $fieldPerm->get(self::PERMISSION_PREFIX . FlowConst::FORM_DATA_PREFIX . $fieldName);
        if ($perm === null) {
            $perm = $fieldPerm->get(self::PERMISSION_PREFIX . $fieldName);
        }
        if ($perm === null) {
            return true;
        }
        return (int) $perm === self::PERM_EDIT;
    }

    /** @param array<string, mixed> $data */
    private function putStateField(DynamicTableWriter $writer, string $table, array &$data, string $nodeId, int $stateCode): void
    {
        if ($nodeId === '') {
            return;
        }
        $kept = $writer->filterColumns($table, [$nodeId . '_' . $stateCode, $nodeId]);
        if ($kept !== []) {
            $data[$kept[0]] = $stateCode;
        }
    }

    /** @param array<string, mixed> $data */
    private function fillContext(array &$data, Execution $execution): void
    {
        $instance = $execution->getProcessInstance();
        if ($instance === null) {
            return;
        }
        $data['process_instance_id'] ??= $instance->getInstanceId();
        $data['apply_user_id'] ??= $instance->getOperator();
        $data['apply_dept_id'] ??= $execution->getArgs()->get(FlowConst::USER_DEPT_ID);
    }
}
