<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/189 · 列表行 taskFormData（内存仓储腿）
 *
 * 现场：todoList/doneList 的行投影在仓储（门面 pass-through），而两枚仓储曾把
 * `taskFormData` 硬编码成 `(object)[]` —— 同一栈同一个键两种真相：单任务 detail 能拿到
 * `tf_*`、两条最密的列表出口永远拿不到（C6-L2 乙-1，第六派）。
 * 修法＝两仓储改走 FormDataUtil::of（与门面同源）；本件钉内存腿，PDO/SQLite 腿在
 * PdoSqliteTaskRowExitTest::testTaskFormDataRealOnListRows 同判据。
 *
 * 判据（spec 06-facade.md:164/:649）：
 * - 正向：任务变量含 tf_ 键 ⇒ 列表行 taskFormData 出「带前缀 + 去前缀副本」两键；
 * - 不回退：任务变量只剩引擎控制键时 taskFormData 恒空对象，即使 ext 按 issues/82-3
 *   回退了实例变量（对齐 Java JeeflowFacade.finishRowToMap 的"任务自身原列"口径）；
 * - 一致性：同一任务的列表行与 detail 行 taskFormData 同值（本案病根判据）。
 */
final class TaskFormData189Test extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private InMemoryProcessExtRepository $extRepo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->extRepo = new InMemoryProcessExtRepository();
        $this->engine = new JeeflowEngine($this->repo);
        $this->facade = new JeeflowFacade($this->engine, $this->repo, $this->extRepo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    /**
     * 正向＋一致性：transfer 写入的任务账本键（tf_transferTo）进列表行 taskFormData；
     * todoList／doneList 与 detail 三个出口同值——修前列表两腿恒空、detail 有值，
     * 本条的第一断言改前必红。
     */
    public function testTransferWrittenTaskVarsReachListAndDetailRows(): void
    {
        [$taskId] = $this->startSimple();
        $tr = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => 'taskFormData 用', 'operator' => 'leader',
        ]);
        $this->assertSame(0, $tr['code'], json_encode($tr, JSON_UNESCAPED_UNICODE));

        $todoRow = $this->rowOf('processTask/todoList', 'lisi', $taskId);
        $fd = $this->asArr($todoRow['taskFormData']);
        // 对照锚：任务变量里本就有 tf_transferTo（否则本件恒真空）
        $this->assertSame('lisi', $this->asArr($todoRow['ext'])['tf_transferTo'] ?? null,
            '前置：transfer 后任务变量应含 tf_transferTo');
        $this->assertSame('lisi', $fd['tf_transferTo'] ?? null,
            '列表行 taskFormData 应含带前缀键: ' . json_encode($todoRow['taskFormData'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('lisi', $fd['transferTo'] ?? null, '列表行 taskFormData 应含去前缀副本');

        // 同一任务：列表行与 detail 行 taskFormData 必须同值（本案病根判据）
        $det = $this->facade->flow('processTask/detail', ['id' => $taskId, 'operator' => 'lisi']);
        $this->assertSame(0, $det['code'], json_encode($det, JSON_UNESCAPED_UNICODE));
        $this->assertSame($fd, $this->asArr($det['data']['taskFormData']),
            '同一任务：todoList 行与 detail 行 taskFormData 必须同值');

        // doneList 腿（办结后同一任务行）
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'lisi', 'submitType' => 1,
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));
        $doneRow = $this->rowOf('processTask/doneList', 'lisi', $taskId);
        $doneFd = $this->asArr($doneRow['taskFormData']);
        $this->assertSame('lisi', $doneFd['tf_transferTo'] ?? null,
            'doneList 行 taskFormData 应含带前缀键: ' . json_encode($doneRow['taskFormData'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('lisi', $doneFd['transferTo'] ?? null, 'doneList 行 taskFormData 应含去前缀副本');
    }

    /**
     * 负向：任务变量只剩引擎控制键（isFirstTaskNode）时 taskFormData 恒空对象——
     * 即使 ext 按 issues/82-3 回退了实例变量，taskFormData 也不回退、不吃 f_ 前缀；
     * 空值出空对象 {}（不是空数组 / null / 缺键）。
     */
    public function testEngineControlKeyOnlyVarsKeepTaskFormDataEmptyObject(): void
    {
        [$taskId] = $this->startSimple();
        $row = $this->rowOf('processTask/todoList', 'leader', $taskId);

        // 对照锚：ext 此刻有内容（回退了实例变量 f_applyReason）——taskFormData 空不是"行没数据"
        $this->assertSame('unit-test', $this->asArr($row['ext'])['f_applyReason'] ?? null,
            '前置：ext 应回退实例变量（issues/82-3）');

        $fd = $row['taskFormData'];
        $this->assertInstanceOf(\stdClass::class, $fd,
            '无 tf_ 任务变量时 taskFormData 应出空对象 {}: ' . json_encode($fd, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], get_object_vars($fd), 'taskFormData 应为空对象（不回退实例变量）');
        $this->assertArrayNotHasKey('f_applyReason', get_object_vars($fd), 'f_ 前缀不进任务表单数据');
        $this->assertArrayNotHasKey('applyReason', get_object_vars($fd), 'f_ 去前缀副本同样不进');
    }

    // ── 辅助 ──

    /** ⇒ [taskId]：01-simple 起单后 leader 的 task1 待办；返回该任务 id。 */
    private function startSimple(): array
    {
        $json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
        $this->assertNotFalse($json, '缺少流程 fixture: 01-simple.json');
        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $json, 'operator' => 'zhangsan']);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'zhangsan',
            'f_applyReason' => 'unit-test',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $taskId = '';
        foreach ($this->repo->findDoingTasks($start['data']['processInstanceId']) as $t) {
            if ($t->getTaskName() === 'task1') $taskId = (string) $t->getTaskId();
        }
        $this->assertNotSame('', $taskId, '前置：startAndExecute 后应有 task1 进行中任务');
        return [$taskId];
    }

    private function rowOf(string $action, string $operator, string $taskId): array
    {
        $r = $this->facade->flow($action, ['operator' => $operator]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ($r['data']['rows'] as $row) {
            if ((string) ($row['id'] ?? '') === $taskId) return $row;
        }
        $this->fail("{$action}(operator={$operator}) 未取到任务 {$taskId}");
    }

    private function asArr(mixed $v): array
    {
        return is_object($v) ? get_object_vars($v) : (array) ($v ?? []);
    }
}
