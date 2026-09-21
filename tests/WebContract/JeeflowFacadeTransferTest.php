<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessInstanceState;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 转办 processTask/transfer（issues/115，规范 06 §processTask/transfer / 合规用例 25）
 *
 * 七条语义逐条落地，断言一律落在**持久值/读回值**上（仓储 findTaskById / todoList /
 * doneList / approvalRecord），不断"内存对象顺手改了什么"：
 * 摘原人只删那一行 · 加新人 · 任务不新建 · 留痕三件（submitType=7 槽位 +
 * tf_transferHistory 追加式账本 + tf_approvalComment 末跳文案）· 变量合并序 ·
 * 去重与明确报错 · 前置态。
 *
 * 另钉两条红线：
 * - 转办**不得**覆写任务 actor_id/operator（DOING 任务该列恒无值）；
 * - 转办后再撤回/终止该单，被摘走的人的 doneList 里**不得**凭空出现这条他没办过的单
 *   （pageDoneTasks 按 state<>10 AND operator=? 过滤，Node 实测踩过）。
 */
class JeeflowFacadeTransferTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowFacade $facade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new InMemoryProcessRepository();
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    private function deploy(string $file): string
    {
        $r = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/' . $file),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['processDefineId'];
    }

    /** 起一单 simple，返回 [instanceId, task1Id]（task1 参与者 leader） */
    private function startSimple(string $file = '01-simple.json'): array
    {
        $defineId = $this->deploy($file);
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];
        $doing = $this->repo->findDoingTasks($instanceId);
        $this->assertNotEmpty($doing, '前置：应有进行中任务');
        return [$instanceId, $doing[0]->getTaskId()];
    }

    private function task(string $taskId): ProcessTask
    {
        $t = $this->repo->findTaskById($taskId);
        $this->assertNotNull($t, "任务 {$taskId} 应读得到");
        return $t;
    }

    /** 账本读出（内存仓存的就是写入的数组形状） */
    private function ledger(string $taskId): array
    {
        $value = $this->task($taskId)->getVariables()->get(FlowConst::TRANSFER_HISTORY);
        return is_array($value) ? $value : [];
    }

    private function todoIdsOf(string $actor): array
    {
        $r = $this->facade->flow('processTask/todoList', ['operator' => $actor]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return array_column($r['data']['rows'], 'id');
    }

    private function doneRowsOf(string $actor): array
    {
        $r = $this->facade->flow('processTask/doneList', ['operator' => $actor]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['data']['rows'];
    }

    // ═══ 正向：转办挪待办 + 三件留痕 ═══

    public function testTransferMovesTodoAndWritesThreeArtifacts(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertContains($taskId, $this->todoIdsOf('leader'), '前置：leader 有待办');
        $this->assertNotContains($taskId, $this->todoIdsOf('lisi'), '前置：lisi 无该待办');

        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId,
            'fromActor' => 'leader',
            'toActor' => 'lisi',
            'reason' => '出差一周',
            'operator' => 'leader',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertNull($r['data'], '契约 data → null');

        // 语义 1+2+3：待办从 A 挪到 B，沿用同一 taskId（任务不新建）
        $this->assertNotContains($taskId, $this->todoIdsOf('leader'), 'A 的待办应消失');
        $this->assertContains($taskId, $this->todoIdsOf('lisi'), 'B 的待办应出现（同一 taskId）');
        $this->assertSame(['lisi'], $this->task($taskId)->getActorIds(), '参与者表读回应只剩 lisi');
        $this->assertSame(ProcessTaskState::DOING, $this->task($taskId)->getTaskState(), '任务仍进行中（不新建不置态）');
        $this->assertCount(1, $this->repo->findDoingTasks($instanceId), '实例进行中任务数不变');

        // 留痕①：任务变量 submitType=7 槽位
        $vars = $this->task($taskId)->getVariables();
        $this->assertSame(SubmitType::TRANSFER, $vars->get(FlowConst::SUBMIT_TYPE), '留痕① submitType 应=7');

        // 留痕②：tf_transferHistory 一条、六键固定 camelCase、reason/time/operator 逐字段
        $ledger = $this->ledger($taskId);
        $this->assertCount(1, $ledger, '留痕② 账本应有 1 条');
        $this->assertSame(
            ['submitType', 'fromActor', 'toActor', 'reason', 'time', 'operator'],
            array_keys($ledger[0]),
            '账本六键必须固定 camelCase 且与契约同序'
        );
        $this->assertSame(7, $ledger[0]['submitType']);
        $this->assertSame('leader', $ledger[0]['fromActor']);
        $this->assertSame('lisi', $ledger[0]['toActor']);
        $this->assertSame('出差一周', $ledger[0]['reason']);
        $this->assertSame('leader', $ledger[0]['operator']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $ledger[0]['time'],
            "账本 time 须 yyyy-MM-dd HH:mm:ss 字符串（§2.4），不得用本地 ISO 方言: {$ledger[0]['time']}"
        );
        // 单跳便捷键
        $this->assertSame('lisi', $vars->get(FlowConst::TRANSFER_TO));
        $this->assertSame('出差一周', $vars->get(FlowConst::TRANSFER_REASON));

        // 留痕③：末跳可读文案写 tf_approvalComment（前端审批意见既有读取位）
        $this->assertSame('leader 转办给 lisi（出差一周）', $vars->get(FlowConst::APPROVAL_COMMENT));

        // 审批记录读得到（approvalRecord 取实例全部任务行，variable/ext 即任务变量）
        $rec = $this->facade->flow('processInstance/approvalRecord', ['id' => $instanceId]);
        $this->assertSame(0, $rec['code'], json_encode($rec, JSON_UNESCAPED_UNICODE));
        $row = null;
        foreach ($rec['data'] as $line) {
            if ((string) ($line['id'] ?? '') === $taskId || ($line['taskName'] ?? '') === 'task1') $row = $line;
        }
        $this->assertNotNull($row, 'approvalRecord 应含被转办的任务行');
        $this->assertSame(7, $row['ext']['submitType'] ?? null, '审批记录槽位读作 submitType=7');
        $this->assertCount(1, $row['ext']['tf_transferHistory'] ?? [], '审批记录 ext 应透出账本');
        $this->assertSame('leader 转办给 lisi（出差一周）', $row['ext']['tf_approvalComment'] ?? null);

        // 办理人记谁：update_user = 转办操作人（不占 actor_id）
        $this->assertSame('leader', $this->task($taskId)->getUpdateUser());
    }

    /** reason 缺省时写空串，不写 null；文案不带括号 */
    public function testTransferWithoutReasonWritesEmptyString(): void
    {
        [, $taskId] = $this->startSimple();
        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi', 'operator' => 'leader',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $hop = $this->ledger($taskId)[0];
        $this->assertSame('', $hop['reason'], 'reason 无值须写 "" 不写 null');
        $this->assertArrayHasKey('reason', $hop);
        $this->assertSame('', $this->task($taskId)->getVariables()->get(FlowConst::TRANSFER_REASON));
        $this->assertSame('leader 转办给 lisi', $this->task($taskId)->getVariables()->get(FlowConst::APPROVAL_COMMENT));
    }

    /** 多跳：B 再转给 C，A→B 那条仍在（只追加不覆盖），末跳键只留末跳 */
    public function testMultiHopAppendsWithoutLosingEarlierHops(): void
    {
        [, $taskId] = $this->startSimple();
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => '出差一周', 'operator' => 'leader',
        ])['code']);
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'lisi', 'toActor' => 'wangwu',
            'reason' => '休假', 'operator' => 'lisi',
        ])['code']);

        $ledger = $this->ledger($taskId);
        $this->assertCount(2, $ledger, 'B 再转给 C 时 A→B 那条必须仍在（追加式账本）');
        $this->assertSame(['leader', 'lisi'], array_column($ledger, 'fromActor'));
        $this->assertSame(['lisi', 'wangwu'], array_column($ledger, 'toActor'));
        // 既往各跳逐字段原样存活（覆盖式写法会把首跳的 reason/operator 一起抹掉）
        $this->assertSame('出差一周', $ledger[0]['reason'], '首跳 reason 须原样存活');
        $this->assertSame('leader', $ledger[0]['operator'], '首跳 operator 须原样存活');
        $this->assertSame('休假', $ledger[1]['reason']);
        $this->assertSame(7, $ledger[0]['submitType']);
        $this->assertSame(7, $ledger[1]['submitType']);
        $this->assertSame(['leader', 'lisi'], array_column($ledger, 'operator'), '每跳 operator 记该跳操作人');
        $this->assertSame('wangwu', $this->task($taskId)->getActorIds()[0] ?? null, '参与者读回应是末跳接手人');
        // 末跳便捷键与末跳文案只留末跳，全量以账本为准
        $this->assertSame('wangwu', $this->task($taskId)->getVariables()->get(FlowConst::TRANSFER_TO));
        $this->assertSame('lisi 转办给 wangwu（休假）',
            $this->task($taskId)->getVariables()->get(FlowConst::APPROVAL_COMMENT));
    }

    /**
     * 变量合并序（契约条款 5，八栈必须同构）：任务既有变量为底 ← 本次提交参数最高。
     * B 办结后：账本仍在（未被整体替换），submitType 槽位被 B 的 1 覆盖（未被转办的 7 反噬）。
     */
    public function testCompletionMergesOntoLedgerAndArgsWinOverSubmitType(): void
    {
        [, $taskId] = $this->startSimple();
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => '出差一周', 'operator' => 'leader',
        ])['code']);

        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'lisi',
            'submitType' => SubmitType::AGREE, 'tf_approvalComment' => '同意',
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));

        $done = $this->task($taskId);
        $this->assertSame(ProcessTaskState::FINISHED, $done->getTaskState());
        $this->assertSame('lisi', $done->getActorId(), '办结后办理人列由 finish 正常写入 lisi');
        $vars = $done->getVariables();
        $this->assertCount(1, $this->ledger($taskId), 'B 办结后 tf_transferHistory 不得被整体替换掉');
        $this->assertSame('出差一周', $this->ledger($taskId)[0]['reason'], '账本内容逐字段存活');
        $this->assertSame(SubmitType::AGREE, $vars->get(FlowConst::SUBMIT_TYPE),
            '本次提交参数最高：转办的 7 不得反噬 B 提交的 1');
        $this->assertSame('同意', $vars->get(FlowConst::APPROVAL_COMMENT), 'B 的意见覆盖转办末跳文案，属预期');
        $this->assertSame('lisi', $vars->get(FlowConst::TRANSFER_TO), '便捷键跨办结存活');
    }

    /** 会签/多参与人：只摘 fromActor 一行，其余参与人不受影响 */
    public function testTransferOnlyRemovesFromActorRow(): void
    {
        [, $taskId] = $this->startSimple();
        $add = $this->facade->flow('processTask/surrogate', [
            'processTaskId' => $taskId, 'actorIds' => ['user2', 'user3'],
        ]);
        $this->assertSame(0, $add['code'], json_encode($add, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['leader', 'user2', 'user3'], $this->task($taskId)->getActorIds());

        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi', 'operator' => 'leader',
        ])['code']);

        $this->assertSame(['user2', 'user3', 'lisi'], $this->task($taskId)->getActorIds(),
            '只摘 leader 一行，加签进来的 user2/user3 原样保留');
        $this->assertContains($taskId, $this->todoIdsOf('user2'), '其余参与人待办不受影响');
        $this->assertContains($taskId, $this->todoIdsOf('user3'), '其余参与人待办不受影响');
    }

    /** 会签节点：转的是"自己那一票"，其余成员各自任务不变，流程仍能办结 */
    public function testCountersignTransferKeepsOtherMembersBookkeeping(): void
    {
        $defineId = $this->deploy('05-countersign-parallel.json');
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => 'user1',
        ]);
        $instanceId = $start['data']['processInstanceId'];
        $taskA = $this->repo->findDoingTasks($instanceId, ['userA'])[0] ?? null;
        $this->assertNotNull($taskA, '前置：userA 有会签任务');

        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskA->getTaskId(), 'fromActor' => 'userA', 'toActor' => 'lisi',
            'operator' => 'userA',
        ])['code']);
        $this->assertSame(['lisi'], $this->task($taskA->getTaskId())->getActorIds());
        // 其余成员任务一行未动
        $this->assertNotEmpty($this->repo->findDoingTasks($instanceId, ['userB']), 'userB 会签任务不受影响');
        $this->assertNotEmpty($this->repo->findDoingTasks($instanceId, ['userC']), 'userC 会签任务不受影响');

        // 转办后接手人照常可办，会签计数不受打断
        foreach ([['lisi', $taskA->getTaskId()], ['userB', null], ['userC', null]] as [$actor, $tid]) {
            $doing = $tid !== null ? [$tid] : array_map(
                fn(ProcessTask $t) => $t->getTaskId(), $this->repo->findDoingTasks($instanceId, [$actor])
            );
            $this->assertNotEmpty($doing, "{$actor} 应有可办任务");
            $r = $this->facade->flow('processTask/execute', [
                'processTaskId' => $doing[0], 'operator' => $actor, 'submitType' => SubmitType::AGREE,
            ]);
            $this->assertSame(0, $r['code'], "{$actor} 办结: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(ProcessInstanceState::FINISHED,
            $this->repo->findInstanceById($instanceId)->getState(), '会签全员办结后整单应结束');
    }

    // ═══ 负向：八种 msg 逐一（失败码一律 99999999，msg 逐字） ═══

    public function testTransferNegativeMsgs(): void
    {
        [, $taskId] = $this->startSimple();

        $cases = [
            'operator 必填' => [['processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi'],
                'operator 必填'],
            'fromActor 必填' => [['processTaskId' => $taskId, 'operator' => 'leader', 'toActor' => 'lisi'],
                'fromActor 必填'],
            'toActor 必填' => [['processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => 'leader'],
                'toActor 必填'],
            '无权限转办该任务' => [['processTaskId' => $taskId, 'operator' => 'someoneelse',
                'fromActor' => 'leader', 'toActor' => 'lisi'], '无权限转办该任务'],
            '原办理人不是该任务参与人' => [['processTaskId' => $taskId, 'operator' => 'notanactor',
                'fromActor' => 'notanactor', 'toActor' => 'lisi'], '原办理人不是该任务参与人'],
            '目标人已是该任务参与人' => [['processTaskId' => $taskId, 'operator' => 'leader',
                'fromActor' => 'leader', 'toActor' => 'leader'], '目标人已是该任务参与人'],
        ];
        foreach ($cases as $case => [$args, $expectMsg]) {
            $r = $this->facade->flow('processTask/transfer', $args);
            $this->assertSame(99999999, $r['code'], "{$case} 应失败: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertSame($expectMsg, $r['msg'], "{$case} 的 msg 须逐字对齐跨栈统一文案");
            $this->assertNull($r['data'], "{$case} 失败不得返回 data");
        }

        // 空白串等同缺失（必填判据 trim 后判空）
        $blank = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => '  ', 'toActor' => 'lisi',
        ]);
        $this->assertSame(99999999, $blank['code']);
        $this->assertSame('fromActor 必填', $blank['msg']);

        // 负向不得留下任何副作用：参与者、账本原样
        $this->assertSame(['leader'], $this->task($taskId)->getActorIds());
        $this->assertSame([], $this->ledger($taskId));

        // 任务非进行中，不可转办（先办结再转）
        $exec = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $exec['code'], json_encode($exec, JSON_UNESCAPED_UNICODE));
        $late = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => 'leader', 'toActor' => 'lisi',
        ]);
        $this->assertSame(99999999, $late['code']);
        $this->assertSame('任务非进行中，不可转办', $late['msg']);

        // 八条文案齐：撤回那条由 JeeflowFacadeWithdrawAuthTest 覆盖，此处补齐「无权限撤回该流程实例」
        $inst = $this->facade->flow('processInstance/withdraw', ['id' => 'no-such-instance', 'operator' => 'x']);
        $this->assertSame('流程实例不存在', $inst['msg']);
    }

    /** 管理员代转：operator=flow.admin 可转别人的待办，账本 operator 记管理员 */
    public function testAdminCanTransferOthersTask(): void
    {
        [, $taskId] = $this->startSimple();
        $r = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'flow.admin',
            'fromActor' => 'leader', 'toActor' => 'lisi',
        ]);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['lisi'], $this->task($taskId)->getActorIds());
        $hop = $this->ledger($taskId)[0];
        $this->assertSame('flow.admin', $hop['operator'], '账本记真实操作人（管理员代转）');
        $this->assertSame('leader', $hop['fromActor']);
    }

    // ═══ 回归红线 ═══

    /** DOING 任务的 actor_id/operator 列恒无值：转办不得写进去 */
    public function testTransferNeverWritesActorId(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertNull($this->task($taskId)->getActorId(), '前置：进行中任务办理人列恒无值');

        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi',
            'reason' => '出差一周', 'operator' => 'leader',
        ])['code']);

        $after = $this->task($taskId);
        $this->assertNull($after->getActorId(), '转办严禁覆写任务 actor_id/operator 列');
        $detail = $this->facade->flow('processTask/detail', ['id' => $taskId, 'operator' => 'lisi']);
        $this->assertSame(0, $detail['code'], json_encode($detail, JSON_UNESCAPED_UNICODE));
        $this->assertNull($detail['data']['operator'], 'taskVo operator 出口对进行中任务须为 null');
        $todo = $this->facade->flow('processTask/todoList', ['operator' => 'lisi']);
        $this->assertNull($todo['data']['rows'][0]['operator'], '待办行 operator 出口须为 null');
        // 办理人记谁由 update_user + 账本 operator 承载
        $this->assertSame('leader', $after->getUpdateUser());
        $this->assertSame('leader', $this->ledger($taskId)[0]['operator']);

        // 撤回/终止该单后离开 DOING 但保留该列值 → 被摘走的人不得凭空出现在我已办
        $this->assertSame(0, $this->facade->flow('processInstance/withdraw',
            ['id' => $instanceId, 'operator' => 'user1'])['code']);
        $this->assertSame(ProcessTaskState::WITHDRAW, $this->task($taskId)->getTaskState());
        foreach ($this->doneRowsOf('leader') as $row) {
            $this->assertNotSame($instanceId, (string) ($row['processInstanceId'] ?? ''),
                '被摘走的 leader 不得在「我已办」凭空看到这条他没办过的单');
        }
        $this->assertSame([], array_values(array_filter(
            $this->doneRowsOf('leader'), fn($row) => (string) ($row['id'] ?? '') === $taskId
        )), 'leader 的 doneList 不得含被转办的任务');
    }

    /** 转办后终止（INTERRUPT 40）同样不得让 leader 的 doneList 出现该单 */
    public function testTransferThenInterruptKeepsLeaderDoneListClean(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi', 'operator' => 'leader',
        ])['code']);

        $inst = $this->repo->findInstanceById($instanceId);
        $inst->interrupt('flow.admin');
        $this->repo->updateInstance($inst);
        $this->assertSame(ProcessTaskState::INTERRUPT, $this->task($taskId)->getTaskState());

        foreach ($this->doneRowsOf('leader') as $row) {
            $this->assertNotSame($instanceId, (string) ($row['processInstanceId'] ?? ''),
                '终止后 leader 的 doneList 也不得凭空出现该单');
        }
    }

    /** 回归：加签（surrogate / addCandidate）仍是只追加，原人保留可办，不写转办账本 */
    public function testSurrogateStillAppendsOnly(): void
    {
        [, $taskId] = $this->startSimple();
        foreach (['processTask/surrogate', 'processTask/addCandidate'] as $action) {
            $r = $this->facade->flow($action, ['processTaskId' => $taskId, 'actorIds' => ['user9']]);
            $this->assertSame(0, $r['code'], $action . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertContains('leader', $this->task($taskId)->getActorIds(),
                $action . ' 加签后原办理人必须保留可办');
        }
        $this->assertSame(['leader', 'user9'], $this->task($taskId)->getActorIds(), '加签去重追加');
        $this->assertSame([], $this->ledger($taskId), '加签不得写转办账本');
        $this->assertNull($this->task($taskId)->getVariables()->get(FlowConst::SUBMIT_TYPE),
            '加签不得写 submitType 槽位');
        $this->assertContains($taskId, $this->todoIdsOf('leader'), '加签后 leader 待办仍在');
    }

    /** 回归：撤回主流程仍按 30 落态，转办过不影响撤回 */
    public function testTransferThenWithdrawStillWrites30(): void
    {
        [$instanceId, $taskId] = $this->startSimple();
        $this->assertSame(0, $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'fromActor' => 'leader', 'toActor' => 'lisi', 'operator' => 'leader',
        ])['code']);
        // 接手人也是参与者 → 判据 2 放行
        $r = $this->facade->flow('processInstance/withdraw', ['id' => $instanceId, 'operator' => 'lisi']);
        $this->assertSame(0, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(ProcessTaskState::WITHDRAW, $this->task($taskId)->getTaskState());
        $this->assertSame(ProcessInstanceState::WITHDRAW, $this->repo->findInstanceById($instanceId)->getState());
        $this->assertCount(1, $this->ledger($taskId), '撤回不得抹掉转办账本');
    }
}
