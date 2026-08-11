# jeeflow PHP 引擎参考 demo（Slim 4）

基于 Slim 4 框架的 jeeflow PHP 工作流引擎参考实现。

## 快速启动

```bash
# 1. 安装依赖
composer install

# 2. 初始化数据库和示例流程（默认 SQLite）
composer init
# 或 php bin/demo-init.php

# 3. 启动服务
composer start
# 或 php -S localhost:8090 -t public
```

服务启动后：

- 健康检查：`GET http://localhost:8090/health`
- 已部署流程：`GET http://localhost:8090/demo/flows`
- 工作流接口：`POST http://localhost:8090/wf/{action}`

## 存储模式

通过环境变量 `JEEFLOW_DEMO_STORE` 选择存储后端：

| 模式 | 说明 | 适用场景 |
|------|------|----------|
| `sqlite` | SQLite 文件（**默认**） | 本机 demo / jeeflow-ui |
| `mysql` | MySQL PDO | 联调 / 生产 |
| `memory` | 内存仓储 | 仅 `smoke_test.php` 使用 |

```bash
# SQLite（默认）
php bin/demo-init.php

# MySQL
JEEFLOW_DEMO_STORE=mysql \
JEEFLOW_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=jeeflow_demo;charset=utf8mb4" \
JEEFLOW_MYSQL_USER=root \
JEEFLOW_MYSQL_PASS=secret \
php bin/demo-init.php
```

## 预部署流程

`bin/demo-init.php` 幂等部署以下示例流程（来自 `jeeflow-java` 共享流程定义）：

| 流程名 | 文件 | 说明 |
|--------|------|------|
| `01-simple` | 01-simple.json | 简单审批（发起人→组长） |
| `03-decision-expr` | 03-decision-expr.json | 条件分支 |

## 接口示例

### 发起流程

```bash
curl -X POST http://localhost:8090/wf/processDefine/startAndExecute \
  -H "Content-Type: application/json" \
  -d '{
    "processDefineId": "<defineId>",
    "operator": "user1",
    "f_reason": "请假申请"
  }'
```

### 查询待办

```bash
curl -X POST http://localhost:8090/wf/processTask/todoList \
  -H "Content-Type: application/json" \
  -d '{"operator": "leader"}'
```

### 审批任务

```bash
curl -X POST http://localhost:8090/wf/processTask/execute \
  -H "Content-Type: application/json" \
  -d '{
    "processTaskId": "<taskId>",
    "operator": "leader",
    "submitType": "approve"
  }'
```

## 架构说明

```
demo-slim/
├── bin/
│   └── demo-init.php       # 初始化脚本（建表 + 部署 flows）
├── data/                   # SQLite 数据库（gitignore）
├── public/
│   └── index.php           # 入口：RepositoryFactory + Slim 路由
├── src/
│   └── RepositoryFactory.php  # 仓储工厂（env 选择存储后端）
├── smoke_test.php          # 单进程端到端冒烟测试（memory 模式）
└── composer.json
```

- **RepositoryFactory**：根据 `JEEFLOW_DEMO_STORE` 环境变量创建仓储
- **JeeflowRequestHandler**（PSR-15）：将 `/wf/{action}` 请求转发到 `JeeflowFacade.flow()`
- **8 个具名用户**：user1/userA/userB/userC/leader/manager/director/boss（与其他语言 demo 统一）

## 生产环境部署

1. 设置 `JEEFLOW_DEMO_STORE=mysql` 并配置 MySQL 连接参数
2. 执行 `packages/repository-pdo/sql/schema-mysql.sql` 建表
3. 接入用户/组织服务（`IUserProvider` SPI）
4. 添加认证/授权中间件
5. 使用 PHP-FPM + Nginx 部署
