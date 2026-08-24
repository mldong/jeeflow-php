# jeeflow-php

> jeeflow 工作流引擎 · PHP 实现

jeeflow 是一个零框架依赖的轻量工作流引擎，PHP 版与 Java / Go / Python / Node / Rust 保持行为同构。

## 模块结构

```
packages/
├── core/              引擎聚合根、状态机、流程执行、SPI
├── repository-pdo/    MySQL/PDO 持久化
├── persist/           ARCHIVE/SYNC 写侧（PersistPostInterceptor）
├── web-contract/      统一门面 Facade、DTO、错误码与契约测试
└── web-psr/           可选 PSR HTTP adapter
demo-slim/             PHP 引擎参考 demo（Slim 4）
tests/                 测试套件
docs/                  文档
```

## 环境要求

- PHP 8.1+
- Composer 2.x
- MySQL 5.7+（PDO 模块）

## 快速开始

```bash
composer install
composer test
```

## 对齐事实源

- 引擎语义：`jeeflow-java`（Java 是参考实现）
- HTTP 契约：`jeeflow-doc/docs/spec/06-facade.md`
- 共享流程定义：`jeeflow-java/jeeflow-core/src/test/resources/flows/`

## 已实现能力

- **引擎核心**：ProcessInstance 聚合根 + 状态机 + SPI + Handler
- **流程类型**：simple / multi-task / decision / fork-join / countersign / reject / withdraw
- **持久化**：PdoProcessRepository（MySQL）+ InMemoryProcessRepository（测试）
- **统一门面**：JeeflowFacade（与 Java 对齐，含 `bizData` / `candidatePage`）
- **查询解析**：JeeflowQueryParser（m_ 前缀过滤）
- **扩展仓储**：IProcessExtRepository
- **PSR adapter**：JeeflowRequestHandler（PSR-15）
- **persist 写侧**：ARCHIVE/SYNC（PersistPostInterceptor）
- **参考 demo**：demo-slim（Slim 4）

## 文档

统一规范：[jeeflow-doc](https://jeeflow-doc.mldong.com)

## License

Copyright © 2025-2026 mldong

Licensed under the Apache License, Version 2.0.
See [LICENSE](./LICENSE) and [NOTICE](./NOTICE).
