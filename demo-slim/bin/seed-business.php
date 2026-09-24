<?php

declare(strict_types=1);

/**
 * T003：业务数据种子 driver——引擎真实启动（startAndExecute + execute），不直插 repo。
 *
 * 矩阵 = 八语言共用 canonical（day-shift 已在 Rust demo 实测全绿，照 rust seed_business.rs 移植）：
 * 16 进行中(state=10) + 9 已完成(advance 推到 state=20) + 8 委托。
 * 8 用户 × 5 菜单（待办/已办/发起/抄送/委托）全覆盖。
 * 注：PHP demo 无 UserProvider/OrgUserProvider——矩阵只用 applicant/字面量/变量注入，不触发 org 查询。
 */

use Jeeflow\WebContract\JeeflowFacade;

/**
 * canonical 矩阵 defineId（文件名排序 1..15）→ 流程名。
 * PHP demo 的 define id 由部署自动生成（非 1..15），driver 用名字解析真实 id。
 */
function seed_define_name(int $defineId): string
{
    return match ($defineId) {
        1 => 'simple',
        2 => 'multi-task',
        3 => 'decision-expr',
        4 => 'fork-join',
        5 => 'countersign-parallel',
        6 => 'countersign-sequential',
        7 => 'countersign-ratio',
        8 => 'cs-seq-approve',
        10 => 'with-reject',
        12 => 'assignee-vars',
        14 => 'candidate-flow',
        15 => 'countersign-one-vote-veto',
        default => 'simple',
    };
}

/** 按流程名解析 define id（processDefine/page 全量扫一遍）。 */
function seed_define_id_map(JeeflowFacade $facade): array
{
    $map = [];
    $resp = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 200]);
    foreach (($resp['data']['rows'] ?? []) as $row) {
        $map[(string) ($row['name'] ?? '')] = $row['id'] ?? null;
    }

    return $map;
}

/**
 * canonical 种子「申请信息」（f_* = 实例变量）：八语言 demo 共用，同表同值同序——勿改措辞、勿重排。
 *
 * 硬规则：①日期一律写死字面量，不按当前时钟算（八栈机器时区/系统时间各异，算出来会漂）；
 * ②字段名严禁 amount / finalAmount——它们是 03-decision-expr、10-mixed-mode 条件表达式的判定变量，
 * 撞上会改流程走向。
 *
 * 键是**逻辑 defineId**（矩阵序 1..15，见 seed_define_name），不是 seed_define_id_map 解析出的
 * 真实库 id（PHP demo 库 id 由部署生成，只有逻辑 id 与 canonical 表对得上）。13 无 apply 节点故不列。
 *
 * @return array<int, array<string, mixed>>
 */
function seed_form_by_define(): array
{
    return [
        1 => ['f_reason' => '家中有事需请假', 'f_days' => 3, 'f_leaveType' => 'annual', 'f_startDate' => '2026-09-01', 'f_endDate' => '2026-09-03'],
        2 => ['f_reason' => '项目上线后调休', 'f_days' => 2, 'f_leaveType' => 'annual', 'f_startDate' => '2026-09-07', 'f_endDate' => '2026-09-08'],
        3 => ['f_reason' => '出差报销申请', 'f_days' => 1, 'f_leaveType' => 'personal', 'f_startDate' => '2026-09-10', 'f_endDate' => '2026-09-10'],
        4 => ['f_reason' => '培训进修请假', 'f_days' => 5, 'f_leaveType' => 'sick', 'f_startDate' => '2026-09-14', 'f_endDate' => '2026-09-18'],
        5 => ['f_reason' => '年假出行', 'f_days' => 4, 'f_leaveType' => 'annual', 'f_startDate' => '2026-09-21', 'f_endDate' => '2026-09-24'],
        6 => ['f_reason' => '婚假申请', 'f_days' => 10, 'f_leaveType' => 'personal', 'f_startDate' => '2026-09-28', 'f_endDate' => '2026-10-07'],
        7 => ['f_reason' => '病假休养', 'f_days' => 6, 'f_leaveType' => 'sick', 'f_startDate' => '2026-10-12', 'f_endDate' => '2026-10-17'],
        8 => ['f_reason' => '产检假', 'f_days' => 3, 'f_leaveType' => 'sick', 'f_startDate' => '2026-10-19', 'f_endDate' => '2026-10-21'],
        9 => ['f_reason' => '陪产假', 'f_days' => 5, 'f_leaveType' => 'personal', 'f_startDate' => '2026-10-26', 'f_endDate' => '2026-10-30'],
        10 => ['f_reason' => '事假处理家务', 'f_days' => 2, 'f_leaveType' => 'personal', 'f_startDate' => '2026-11-02', 'f_endDate' => '2026-11-03'],
        11 => ['f_bizType' => 'purchase', 'f_budget' => 12000, 'f_urgency' => 'normal', 'f_desc' => '采购一批开发板与传感器'],
        12 => ['f_reason' => '部门例行调休', 'f_days' => 1, 'f_leaveType' => 'annual', 'f_startDate' => '2026-11-09', 'f_endDate' => '2026-11-09'],
        14 => ['f_reason' => '外派学习请假', 'f_days' => 7, 'f_leaveType' => 'annual', 'f_startDate' => '2026-11-16', 'f_endDate' => '2026-11-22'],
        15 => ['f_reason' => '丧假', 'f_days' => 3, 'f_leaveType' => 'personal', 'f_startDate' => '2026-11-23', 'f_endDate' => '2026-11-25'],
    ];
}

/**
 * canonical 种子「办理表单」（tf_* = 任务变量）：同样八栈同表同值——勿改勿重排。
 * 硬规则③：键 = 审批节点的 formKey，表里没有的 formKey 只落通用审批意见，不臆造字段。
 *
 * @return array<string, array<string, mixed>>
 */
function seed_tf_by_form(): array
{
    return [
        'leave-form' => ['tf_approvedDays' => 3, 'tf_needExtra' => 'no', 'tf_remark' => '按项目排期核准，注意工作交接'],
        'review-form' => ['tf_riskLevel' => 'low', 'tf_needLegalDoc' => 'no', 'tf_reviewOpinion' => '条款与预算均无风险'],
        'boss-form' => ['tf_finalDecision' => 'agree', 'tf_finalAmount' => 8000, 'tf_bossNote' => '同意，走年度预算'],
        'check-form' => ['tf_invoiceOk' => 'yes', 'tf_amountChecked' => 8000, 'tf_checkNote' => '票据齐全，计入差旅科目'],
        'countersign-form' => ['tf_signVote' => 'support', 'tf_signAmount' => 5000, 'tf_signOpinion' => '本条线无异议'],
        'seq-form' => ['tf_seqStage' => 'first', 'tf_seqVote' => 'pass', 'tf_seqOpinion' => '初审通过，转下一人'],
        'approve-form' => ['tf_approveResult' => 'ok', 'tf_approveAmount' => 8000, 'tf_approveNote' => '审批通过'],
        'ratio-form' => ['tf_ratioVote' => 'agree', 'tf_ratioOpinion' => '达到比例即可通过'],
        'veto-form' => ['tf_vetoResult' => 'pass', 'tf_vetoReason' => '无异议'],
        'form-a' => ['tf_branchA' => 'a1', 'tf_branchANote' => 'A 分支选方案 A1'],
        'form-b' => ['tf_branchB' => 'b1', 'tf_branchBNote' => 'B 分支选方案 B1'],
        'field-form' => ['tf_ownerName' => '张三', 'tf_field' => 'tech', 'tf_fieldNote' => '技术域评估通过'],
        'operator-form' => ['tf_selfCheck' => 'done', 'tf_operatorNote' => '发起人自查无误'],
        'dept-form' => ['tf_deptAgree' => 'yes', 'tf_deptQuota' => 8000, 'tf_deptNote' => '同意占用本部门额度'],
        'role-form' => ['tf_roleResult' => 'pass', 'tf_roleNote' => '角色审批通过'],
    ];
}

/**
 * 办理表单落库：先给通用审批意见，再按该节点的 formKey 覆盖专属字段。
 * 抽成 helper 是因为每个 processTask/execute 调用点（seed_advance 循环 / I14 特例）必须同口径，
 * 否则八栈横评里同一节点会填出不一样的数据。
 * formKey 可能缺键或非字符串（无表单节点、存量行）→ 退化为只落通用意见。
 *
 * @param array<string,mixed> $ex
 * @return array<string,mixed>
 */
function seed_with_task_form(array $ex, mixed $formKey): array
{
    $ex['tf_approvalComment'] = '同意，情况已核实';
    $tf = is_string($formKey) ? (seed_tf_by_form()[$formKey] ?? []) : [];

    return array_merge($ex, $tf);
}

/**
 * @return array<int, array{0:int,1:string,2:array<string,mixed>,3:list<string>}>
 */
function seed_in_progress_rows(): array
{
    return [
        [1, 'user1', [], ['userA', 'userB']],
        [2, 'user1', [], []],
        [3, 'userA', ['amount' => 500], []],
        [4, 'manager', [], ['userC', 'leader']],
        [5, 'userB', [], []],
        [6, 'director', [], ['manager', 'boss']],
        [7, 'userC', [], ['user1']],
        [1, 'boss', [], []],
        [12, 'user1', ['deptLeader' => 'manager'], []],
        [12, 'userC', ['deptLeader' => 'director'], []],
        [12, 'userB', ['deptLeader' => 'user1'], []],
        [15, 'userA', [], ['boss']],
        [14, 'leader', [], ['director', 'userC']],
        [2, 'userA', [], []],
        [10, 'userB', [], []],
        [8, 'user1', [], []],
    ];
}

/**
 * @return array<int, array{0:int,1:string,2:array<string,mixed>,3:list<string>}>
 */
function seed_finished_rows(): array
{
    return [
        [1, 'userA', [], ['user1', 'director']],
        [8, 'userB', [], ['boss', 'manager']],
        [2, 'manager', [], ['boss']],
        [10, 'director', [], []],
        [12, 'userC', ['deptLeader' => 'leader'], []],
        [1, 'director', [], []],
        [5, 'manager', [], []],
        [12, 'userA', ['deptLeader' => 'director'], []],
        [12, 'userB', ['deptLeader' => 'user1'], []],
    ];
}

/**
 * 委托 8 条：processSurrogate/page 无 operator 过滤 → 8 用户委托菜单全非空。
 * @return array<int, array{0:string,1:string}>
 */
function seed_surrogate_rows(): array
{
    return [
        ['user1', 'userA'], ['userA', 'userB'], ['userB', 'userC'], ['userC', 'leader'],
        ['leader', 'manager'], ['manager', 'director'], ['director', 'boss'], ['boss', 'user1'],
    ];
}

/**
 * 发起实例。$defineId 是解析后的真实库 id（只用于起单），$logicalDefineId 是 canonical 矩阵的逻辑
 * defineId（只用于查 seed_form_by_define）——两者不同源，故分开传。
 */
function seed_start_instance(JeeflowFacade $facade, int|string $defineId, string $op, array $extra, ?int $logicalDefineId = null): mixed
{
    // f_* 先铺、$extra 后铺：已有的流程变量（amount / deptLeader）优先，不被表单值盖掉
    $form = $logicalDefineId === null ? [] : (seed_form_by_define()[$logicalDefineId] ?? []);
    $args = array_merge(['processDefineId' => $defineId, 'operator' => $op], $form, $extra);
    $resp = $facade->flow('processDefine/startAndExecute', $args);
    if (($resp['code'] ?? -1) !== 0) {
        echo "  [seed] startAndExecute define=$defineId op=$op 失败\n";
        return null;
    }

    return $resp['data']['processInstanceId'] ?? null;
}

function seed_add_cc(JeeflowFacade $facade, mixed $iid, string $op, array $actors): void
{
    $facade->flow('processInstance/createCCInstance', [
        'processInstanceId' => $iid,
        'operator' => $op,
        'actorIds' => $actors,
    ]);
}

/**
 * advance 原语：循环读 detail，对每个 doing 任务以其自身 actor execute(submitType=1)。
 * doing 任务 operator 为 null，actor 取 taskActorIdList[0]。
 * detail 任务行带 formKey → execute 参数经 seed_with_task_form 铺办理表单（与 I14 同口径）。
 */
function seed_advance(JeeflowFacade $facade, mixed $iid): int
{
    for ($i = 0; $i < 30; $i++) {
        $resp = $facade->flow('processInstance/detail', ['id' => $iid]);
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $state = (int) ($data['state'] ?? 0);
        if ($state !== 10) {
            return $state;
        }
        $progress = false;
        foreach (($data['tasks'] ?? []) as $t) {
            if ((int) ($t['taskState'] ?? 0) !== 10) {
                continue;
            }
            $actor = $t['operator'] ?? null;
            if (!is_string($actor) || $actor === '') {
                $list = is_array($t['taskActorIdList'] ?? null) ? $t['taskActorIdList'] : [];
                $actor = $list[0] ?? null;
            }
            if (!is_string($actor) || $actor === '') {
                continue;
            }
            $r = $facade->flow('processTask/execute', seed_with_task_form([
                'processTaskId' => $t['id'] ?? null,
                'operator' => $actor,
                'submitType' => 1,
            ], $t['formKey'] ?? ''));
            if (($r['code'] ?? -1) === 0) {
                $progress = true;
            }
        }
        if (!$progress) {
            return $state;
        }
    }

    return -1;
}

/** 仅 I14 用：在该实例里找 op 的 doing 任务行。 */
function seed_todo_row(JeeflowFacade $facade, string $op, mixed $iid): ?array
{
    $resp = $facade->flow('processTask/todoList', ['operator' => $op, 'pageNum' => 1, 'pageSize' => 200]);
    $rows = is_array($resp['data']['rows'] ?? null) ? $resp['data']['rows'] : [];
    foreach ($rows as $row) {
        if ((string) ($row['processInstanceId'] ?? '') === (string) $iid
            && (int) ($row['taskState'] ?? 0) === 10) {
            return $row;
        }
    }

    return null;
}

/** 种业务数据；失败逐条打日志不抛异常（demo 构建不被单条卡死）。 */
function seed_business(JeeflowFacade $facade): void
{
    $idMap = seed_define_id_map($facade);
    $resolve = fn (int $defineId) => $idMap[seed_define_name($defineId)] ?? null;
    $okIn = 0;
    $okFin = 0;
    $okSurr = 0;
    foreach (seed_in_progress_rows() as [$defineId, $op, $extra, $cc]) {
        $realId = $resolve($defineId);
        if ($realId === null) {
            echo "  [seed] define 名解析失败: " . seed_define_name($defineId) . "
";
            continue;
        }
        $iid = seed_start_instance($facade, $realId, $op, $extra, $defineId);
        if ($iid === null) {
            continue;
        }
        // I14：发起后再办 leader、manager 两节点 → 停在 boss
        if ($defineId === 2 && $op === 'userA') {
            foreach (['leader', 'manager'] as $actor) {
                $t = seed_todo_row($facade, $actor, $iid);
                if ($t !== null) {
                    // todoList 行同样带 formKey（PdoProcessRepository 行映射），照 advance 同口径填办理表单
                    $facade->flow('processTask/execute', seed_with_task_form([
                        'processTaskId' => $t['id'] ?? null,
                        'operator' => $actor,
                        'submitType' => 1,
                    ], $t['formKey'] ?? ''));
                } else {
                    echo "  [seed] I14 todoRow actor=$actor iid=$iid 未找到\n";
                }
            }
        }
        if ($cc !== []) {
            seed_add_cc($facade, $iid, $op, $cc);
        }
        ++$okIn;
    }
    foreach (seed_finished_rows() as [$defineId, $op, $extra, $cc]) {
        $realId = $resolve($defineId);
        if ($realId === null) {
            echo "  [seed] FIN define 名解析失败: " . seed_define_name($defineId) . "
";
            continue;
        }
        $iid = seed_start_instance($facade, $realId, $op, $extra, $defineId);
        if ($iid === null) {
            continue;
        }
        $state = seed_advance($facade, $iid);
        if ($state !== 20) {
            echo "  [seed] FIN define=$defineId op=$op 终态=$state（期望 20）\n";
        }
        if ($cc !== []) {
            seed_add_cc($facade, $iid, $op, $cc);
        }
        ++$okFin;
    }
    foreach (seed_surrogate_rows() as [$op, $surrogate]) {
        $resp = $facade->flow('processSurrogate/save', [
            'operator' => $op,
            'surrogate' => $surrogate,
            'processName' => '',
            'startTime' => '2026-01-01 00:00:00',
            'endTime' => '2027-12-31 23:59:59',
        ]);
        if (($resp['code'] ?? -1) === 0) {
            ++$okSurr;
        } else {
            echo "  [seed] surrogate $op->$surrogate 失败\n";
        }
    }
    echo "[seedBusiness] done: in-progress $okIn/16, finished $okFin/9, surrogates $okSurr/8\n";
}
