<?php

// 解析本仓 flows/ 流程定义目录（维护者与用户统一入口）。
//
// 唯一编辑源是 jeeflow-java 仓的 test/resources/flows/。本仓 flows/ 是其副本，
// 入库 commit（单语言用户下载即用，不依赖隔壁 Java 仓）。
//
// jeeflow_flows_dir() 的语义（返回不带尾斜杠的本仓 flows/ 绝对路径）：
//   1. 环境变量 JEEFLOW_FLOWS_DIR 显式覆盖（容器/特殊部署）
//   2. 否则以本文件所在目录（仓根）为基准
//   3. 若仓根的兄弟目录里有 Java 源（维护者机器）→ 精确镜像进本仓 flows/
//      （拷贝所有 .json + 删除本仓多出的孤儿 .json，防 id 按文件名排序错位）
//   4. 始终返回本仓 flows/ 路径 —— 所有读取点只读这里，Java 仓不再被直接读取
//
// 全局函数：测试经 phpunit bootstrap（tests/bootstrap.php）引入，demo 脚本 require_once 引入。
// 放仓根（composer autoload 只映射 packages/*，本文件不进发布包）。

function jeeflow_flows_dir(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = getenv('JEEFLOW_FLOWS_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }
    $root = __DIR__; // 本文件在仓根
    jeeflow_flows_mirror($root);
    $cached = $root . '/flows';
    return $cached;
}

// 若 Java 源存在则精确镜像到本仓 flows/（拷所有 + 删孤儿），不存在则原样返回。
function jeeflow_flows_mirror(string $root): void
{
    $src = $root . '/../jeeflow-java/jeeflow-core/src/test/resources/flows';
    $dst = $root . '/flows';
    if (!is_dir($src)) {
        return; // 用户单仓 / 容器：无 Java 源，跳过镜像
    }
    if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
        throw new RuntimeException("flows_resolver: 建不出镜像目录 {$dst}");
    }
    $srcNames = [];
    foreach (scandir($src) as $f) {
        if (!str_ends_with($f, '.json')) {
            continue;
        }
        $srcNames[$f] = true;
        jeeflow_flows_write_atomic($dst . '/' . $f, jeeflow_flows_read($src . '/' . $f));
    }
    // 孤儿清理：本仓有、Java 源已无的 .json（防 id 错位）。临时件名不以 .json 结尾，不在这条腿上。
    foreach (scandir($dst) as $f) {
        if (str_ends_with($f, '.json') && !isset($srcNames[$f])) {
            unlink($dst . '/' . $f);
        }
    }
}

// audit8 R81（issues/187 的 php 同形档）：镜像写腿此前是逐份 `copy()`——就地 truncate→write
// 非原子、且每次都写，读者（隔壁进程）可能撞半写。三件事与 node/python 同判据面：
// 稳态零写、`.tmp.<pid>` + rename、失败响亮抛出且不丢临时件。
// 预算按 php 自己的运行时表现定（`rename` 覆盖被占用的目标会失败），不是照抄别栈的数。
const JEEFLOW_FLOWS_RETRY = 300;
const JEEFLOW_FLOWS_RETRY_US = 10000; // 10ms

function jeeflow_flows_write_atomic(string $target, string $data): void
{
    // 稳态零写：内容逐字节相同就不动盘 ⇒ 绝大多数测试轮里这条腿根本不写，读者无从撞窗口。
    if (is_file($target) && @file_get_contents($target) === $data) {
        return;
    }
    $tmp = $target . '.tmp.' . getmypid(); // 名带 pid ⇒ 并发进程互不覆写同一条临时件
    for ($attempt = 0; ; $attempt++) {
        if (@file_put_contents($tmp, $data) === false) {
            @unlink($tmp);
            throw new RuntimeException("flows_resolver: 临时件写入失败 {$tmp}");
        }
        if (@rename($tmp, $target)) {
            return; // 同目录 rename 对读者是原子的：只会看到旧件或新件，看不到半截
        }
        if ($attempt >= JEEFLOW_FLOWS_RETRY) {
            @unlink($tmp);
            throw new RuntimeException("flows_resolver: 镜像 {$target} 失败（重试 " . JEEFLOW_FLOWS_RETRY . ' 次）');
        }
        usleep(JEEFLOW_FLOWS_RETRY_US);
    }
}

// 读源件那一腿同样可能有瞬时共享冲突（别的进程正在换这份文件），撞上就等一等而不是把
// "读不到夹具"变成整轮测试的红；预算用尽仍读不到 ⇒ 响亮抛出。
function jeeflow_flows_read(string $path): string
{
    for ($attempt = 0; ; $attempt++) {
        $data = @file_get_contents($path);
        if ($data !== false) {
            return $data;
        }
        if ($attempt >= 100) {
            throw new RuntimeException("flows_resolver: 读不到源件 {$path}");
        }
        usleep(JEEFLOW_FLOWS_RETRY_US);
    }
}
