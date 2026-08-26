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
    $srcNames = [];
    foreach (scandir($src) as $f) {
        if (!str_ends_with($f, '.json')) {
            continue;
        }
        $srcNames[$f] = true;
        copy($src . '/' . $f, $dst . '/' . $f);
    }
    // 孤儿清理：本仓有、Java 源已无的 .json（防 id 错位）
    foreach (scandir($dst) as $f) {
        if (str_ends_with($f, '.json') && !isset($srcNames[$f])) {
            unlink($dst . '/' . $f);
        }
    }
}
