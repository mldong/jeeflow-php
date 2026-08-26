<?php

// phpunit 启动引导：
// 1. composer autoloader（原 bootstrap=vendor/autoload.php）
// 2. 全局 jeeflow_flows_dir()（本仓 flows/ 解析 + 维护者机器上镜像 Java 源）
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/jeeflow-flows-dir.php';
