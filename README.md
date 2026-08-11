# jeeflow-php

> jeeflow 工作流引擎 · PHP 实现（第五语言）

jeeflow 是一个零框架依赖的轻量工作流引擎，PHP 版与 Java / Go / Python / Node 保持行为同构。

## 模块结构

```
packages/
├── core/              引擎聚合根、状态机、流程执行、SPI
├── repository-pdo/    MySQL/PDO 持久化
├── web-contract/      40 action 的 Facade、DTO、错误码与契约测试
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
- 共享流程定义：`jeeflow-java/jeeflow-core/src/test/resources/flows/`（14 个 LogicFlow JSON）

## 状态

**E3 完成** · 177 tests · 679 assertions · 全绿

| 阶段 | 内容 | 状态 |
|------|------|------|
| E1 | 引擎内核（聚合根/状态机/SPI/InMemoryRepository）+ 14 共享流程 JSON | ✅ |
| E2 | PDO/MySQL 持久化（五表读写/事务/并发保护）+ 集成测试 | ✅ |
| E3 | 40 action Facade + IProcessExtRepository + 测试 | ✅ |

### 已实现能力

- **引擎核心**：ProcessInstance 聚合根 + 状态机 + 6 SPI + 7 Handler
- **流程类型**：simple/multi-task/decision-expr/fork-join/countersign-parallel/sequential/reject/withdraw
- **持久化**：PdoProcessRepository（MySQL 五表）+ InMemoryProcessRepository（测试）
- **统一门面**：JeeflowFacade 40 action（processDefine 7 + processInstance 5 + processTask 9 + processDesign 9 + processSurrogate 3 + 视图端点 7）
- **查询解析**：JeeflowQueryParser（m_ 前缀过滤）
- **扩展仓储**：IProcessExtRepository + InMemoryProcessExtRepository

### 待完成

- `web-psr/` PSR HTTP adapter（可选，集成方按需选择 Slim/Laminas 等）
- `demo-slim/` Slim 4 参考 demo
- `processTask/candidatePage` 需 IUserSearchProvider SPI
- `processInstance/bizData` 需 MetaTableReader SPI
