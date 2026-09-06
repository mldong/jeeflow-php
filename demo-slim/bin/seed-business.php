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

function seed_start_instance(JeeflowFacade $facade, int|string $defineId, string $op, array $extra): mixed
{
    $args = array_merge(['processDefineId' => $defineId, 'operator' => $op], $extra);
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
            $r = $facade->flow('processTask/execute', [
                'processTaskId' => $t['id'] ?? null,
                'operator' => $actor,
                'submitType' => 1,
            ]);
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
        $iid = seed_start_instance($facade, $realId, $op, $extra);
        if ($iid === null) {
            continue;
        }
        // I14：发起后再办 leader、manager 两节点 → 停在 boss
        if ($defineId === 2 && $op === 'userA') {
            foreach (['leader', 'manager'] as $actor) {
                $t = seed_todo_row($facade, $actor, $iid);
                if ($t !== null) {
                    $facade->flow('processTask/execute', [
                        'processTaskId' => $t['id'] ?? null,
                        'operator' => $actor,
                        'submitType' => 1,
                    ]);
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
        $iid = seed_start_instance($facade, $realId, $op, $extra);
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
