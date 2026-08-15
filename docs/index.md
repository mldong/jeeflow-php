# jeeflow-php 文档

> jeeflow 引擎的 **PHP 实现**（多语言联邦第五语言，独立版本线）——行为对齐 Java 参考实现，API 对齐统一规范。本文档面向 PHP 开发者，内容聚合到[文档站语言指南](../../)。

## SDK 集成

| 文档 | 内容 |
|------|------|
| [快速开始（SDK 集成）](./getting-started.md) | Composer 安装、最小示例（内存模式 / PDO-MySQL） |
| [流程定义 JSON 格式](./flow-definition.md) | LogicFlow 节点 / 属性 / 条件边，表达式求值器注册（PHP 特有） |
| [引擎 API](./engine-api.md) | `JeeflowEngine` 核心方法、变量注入、状态码 |
| [SPI 实现指南](./spi-guide.md) | 扩展点：仓储 / ID / 表达式 / 事务 / 用户 / JSON |
| [PSR 框架集成](./psr-integration.md) | 任意 PSR-15 兼容框架接入（Slim / Laminas / Mezzio） |

## 参考 demo

| 文档 | 内容 |
|------|------|
| [演示站（Demo）](./demo.md) | demo-slim 启动、存储模式切换（`JEEFLOW_DEMO_STORE`）、HTTP 端点、冒烟测试 |
| [demo-slim 源码](https://github.com/mldong/jeeflow-php/tree/main/demo-slim) | Slim 4 应用：预部署示例流程、SQLite/MySQL 切换、Docker 镜像 |

## 能力对照（vs 四语言 v1.8.x）

| 能力 | PHP 1.1.x | 说明 |
|------|-----------|------|
| 引擎核心（状态机 / 聚合根 / Handler） | ✅ | 对齐 Java 参考实现 |
| 统一门面 action | ✅ 40 个，与 Java 对齐（issues/61 已闭环） | `JeeflowFacade`，code=0/msg 契约 |
| PDO 持久化（MySQL 八表 / SQLite） | ✅ | `packages/repository-pdo` |
| PSR-15 接入 | ✅ | `JeeflowRequestHandler`（web-psr） |
| 业务数据入库（persist / persist-meta） | ✅ 1.1.2 待发 | `packages/persist`：拦截器调度 + ARCHIVE/SYNC 写侧（issues/69）；读侧仍由集成方挂 `metaTableReader` |
| `snaker:custom` 自定义节点 | ⏳ 规划中 | 无 CustomModel，含该节点的流程 JSON 无法解析 |

## 相关

- 引擎规范（唯一事实来源）：[规范总览](../../spec/)
- 发布通道：Packagist（`mldong/jeeflow-php`），独立版本线（当前 1.1.x），版本号与四语言不对齐，对齐的是 API 契约
- 设计原理 / 通用指南：[jeeflow-doc](../../)
