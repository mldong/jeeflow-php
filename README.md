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

**E3 完成** · 186 tests · 694 assertions · 全绿

| 阶段 | 内容 | 状态 |
|------|------|------|
| E1 | 引擎内核（聚合根/状态机/SPI/InMemoryRepository）+ 14 共享流程 JSON | ✅ |
| E2 | PDO/MySQL 持久化（八表读写/事务/并发保护）+ 集成测试 | ✅ |
| E3 | 40 action Facade + PSR-15 adapter + Slim demo + 八表 schema | ✅ |

### 已实现能力

- **引擎核心**：ProcessInstance 聚合根 + 状态机 + 6 SPI + 7 Handler
- **流程类型**：simple/multi-task/decision-expr/fork-join/countersign-parallel/sequential/reject/withdraw
- **持久化**：PdoProcessRepository（MySQL 八表）+ InMemoryProcessRepository（测试）
- **统一门面**：JeeflowFacade 40 action（与 Java 对齐；issues/61 补齐 `bizData`/`candidatePage`）
- **查询解析**：JeeflowQueryParser（m_ 前缀过滤）
- **扩展仓储**：IProcessExtRepository + InMemoryProcessExtRepository
- **PSR adapter**：JeeflowRequestHandler（PSR-15）+ ResponseFactory（nyholm/psr7）
- **参考 demo**：demo-slim（Slim 4，预部署 3 个示例流程）

### issues/61 闭环（v1.1.0）

- `processTask/candidatePage` —— 模型候选（`candidateUsers`/`candidateGroups` 双源）命中则用户映射，
  未命中走 `UserSearchProviderInterface.page` 分页搜索（新增 SPI：`UserSearchProviderInterface` / `OrgUserProviderInterface`）
- `processInstance/bizData` —— 定义顶层 `relTableName`（回落 `name`）→ `ServiceContext::put("metaTableReader", ...)`
  注册的读取器回显（需引入 persist 模块，未注册明确报错）
