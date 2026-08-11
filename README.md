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

实施中（E0 阶段）。
