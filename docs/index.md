# jeeflow-php 文档

> jeeflow 引擎的 **PHP 实现**（多语言联邦第五语言，独立版本线）——行为对齐 Java 参考实现，API 对齐统一规范。本文档面向 PHP 开发者，内容聚合到[文档站语言指南](../../)。

## SDK 集成

| 文档 | 内容 |
|------|------|
| [快速开始（SDK 集成）](./getting-started.md) | Composer 安装、最小示例（内存模式 / PDO-MySQL） |
| [引擎 API](./engine-api.md) | `JeeflowEngine` 核心方法、变量注入、状态码 |
| [Facade 契约（40 action）](./facade-api.md) | 统一门面 action 清单、统一响应格式、submitType 枚举 |
| [SPI 实现指南](./spi-guide.md) | 6 个 SPI 扩展点：仓储 / ID / 表达式 / 事务 / 用户体系 |
| [PSR 框架集成](./psr-integration.md) | 任意 PSR-15 兼容框架接入（Slim / Laminas / Mezzio） |

## 参考 demo

| 文档 | 内容 |
|------|------|
| [demo-slim（Slim 4 应用）](https://github.com/mldong/jeeflow-php/tree/main/demo-slim) | 预部署示例流程、SQLite/MySQL 存储切换（`JEEFLOW_DEMO_STORE`）、Docker 镜像 |

## 相关

- 引擎规范（唯一事实来源）：[规范总览](../../spec/)
- 发布通道：Packagist（`mldong/jeeflow-php`），独立版本线（当前 1.0.x），版本号与四语言不对齐，对齐的是 API 契约
- 设计原理 / 通用指南：[jeeflow-doc](../../)
