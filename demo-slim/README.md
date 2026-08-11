# jeeflow PHP 引擎参考 demo（Slim 4）

基于 Slim 4 框架的 jeeflow PHP 工作流引擎参考实现。

## 快速启动

```bash
# 安装依赖
composer install

# 启动服务（PHP 内置服务器）
composer start
# 或
php -S localhost:8090 -t public
```

服务启动后：

- 健康检查：`GET http://localhost:8090/health`
- 已部署流程：`GET http://localhost:8090/demo/flows`
- 工作流接口：`POST http://localhost:8090/wf/{action}`

## 预部署流程

启动时自动部署以下示例流程（来自 `jeeflow-java` 共享流程定义）：

| 流程名 | 文件 | 说明 |
|--------|------|------|
| `01-simple` | 01-simple.json | 简单审批（发起人→组长） |
| `02-multi-instance` | 02-multi-instance.json | 多实例（并行/串行会签） |
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
├── composer.json         # Slim 4 + jeeflow-php 依赖
├── smoke_test.php        # 单进程端到端冒烟测试
└── public/
    └── index.php         # 入口：初始化引擎 + 注册路由
```

- **JeeflowRequestHandler**（PSR-15）：将 `/wf/{action}` 请求转发到 `JeeflowFacade.flow()`
- **InMemoryRepository**：内存仓储（demo 用，生产环境换 `PdoProcessRepository`）
- **8 个具名用户**：user1/userA/userB/userC/leader/manager/director/boss（与其他语言 demo 统一）

## ⚠️ PHP 进程模型限制

PHP 内置服务器（`php -S`）和 PHP-FPM 采用 **每请求独立进程** 模型，与 Java/Go/Python/Node 的常驻进程不同：

| 运行方式 | 进程模型 | InMemory 共享 |
|----------|----------|---------------|
| `php -S`（内置 dev server） | 每请求新进程 | ❌ 不共享 |
| PHP-FPM + Nginx | 进程池，跨 worker 不共享 | ⚠️ 有限 |
| Swoole / RoadRunner | 常驻内存 | ✅ 完全共享 |

**因此**：
- 本 demo 使用 `InMemoryRepository`，**仅适合单进程验证**（如 `smoke_test.php`）
- 跨请求的完整流程（发起→审批→定稿）在 HTTP 模式下无法保持状态
- **生产环境必须使用 `PdoProcessRepository`（MySQL）**

## 生产环境部署

1. 替换 `InMemoryRepository` 为 `PdoProcessRepository`（MySQL）
2. 执行 `packages/repository-pdo/sql/schema-mysql.sql` 建表
3. 添加真实事务模板（`TransactionTemplateInterface`）
4. 接入用户/组织服务（`IUserProvider` SPI）
5. 添加认证/授权中间件
6. 使用 PHP-FPM + Nginx 或 Swoole/RoadRunner 部署
