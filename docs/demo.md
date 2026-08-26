# 演示站（Demo）

> 参考 demo 是 **demo-slim**（Slim 4 应用）：SQLite 持久化（默认）/ MySQL（生产形态），初始化时从共享流程 JSON 部署全部示例流程，对接 jeeflow-ui 体验完整流程。

## 快速启动

```bash
git clone https://github.com/mldong/jeeflow-php.git
cd jeeflow-php/demo-slim
composer install
php bin/demo-init.php    # 初始化 SQLite + 部署示例流程（幂等）
composer start           # php -S localhost:8090 -t public
```

- 访问 `http://localhost:8090`（`/health` 健康检查、`/demo/flows` 已部署流程）
- 对接 jeeflow-ui（`:5173`）时右上角切到 `🐘 PHP :8090`
- 接口规范（code=0/msg、submitType 枚举）见[统一门面接口文档](../../spec/06-facade)

> demo-init 从本仓 `flows/` 加载全部共享流程 JSON（唯一编辑源在 `jeeflow-java/jeeflow-core/src/test/resources/flows/`，`jeeflow-flows-dir.php` 在维护者机器上执行时把 Java 源精确镜像进本仓；与 Python/Node 对齐）。单语言用户下载即用，无需检出 jeeflow-java。

## 存储模式切换（`JEEFLOW_DEMO_STORE`）

| 模式 | 值 | 说明 |
|------|----|------|
| SQLite | `sqlite`（默认） | 文件 `demo-slim/data/jeeflow-demo.sqlite`，首次访问自动建表 |
| MySQL | `mysql` | 生产形态，DSN `JEEFLOW_MYSQL_DSN`（默认 `mysql:host=127.0.0.1;port=3306;dbname=jeeflow_demo;charset=utf8mb4`）+ `JEEFLOW_MYSQL_USER` / `JEEFLOW_MYSQL_PASS` |
| 内存 | `memory` | **仅供 `smoke_test.php` 单进程使用**，HTTP 模式禁止（见下方限制） |

```bash
# MySQL 模式
JEEFLOW_DEMO_STORE=mysql php bin/demo-init.php
JEEFLOW_DEMO_STORE=mysql JEEFLOW_MYSQL_DSN="mysql:host=127.0.0.1;dbname=jeeflow_demo" composer start
```

建表 SQL：`packages/repository-pdo/sql/schema-mysql.sql`（八表）/ `schema-sqlite.sql`。

## HTTP 端点

| 端点 | 说明 |
|------|------|
| `POST /wf/{action}` | jeeflow 统一门面入口，URL 路径段即 action（如 `/wf/processDefine/startAndExecute`），走 `JeeflowRequestHandler`（PSR-15） |
| `GET /health` | 健康检查：`{"status":"ok","engine":"jeeflow-php","store":"sqlite"}` |
| `GET /demo/flows` | 已部署流程列表（demo 专用） |

```bash
# 冒烟：发起流程（startAndExecute 自动完成申请节点）
curl -s -X POST http://localhost:8090/wf/processDefine/startAndExecute \
  -H "Content-Type: application/json" \
  -d '{"processDefineId":"<deploy 返回的 id>","operator":"user1"}'
# → {"code":0,"msg":"成功","data":{"processInstanceId":"..."}}
```

## 全链路冒烟测试（单进程）

`demo-slim/smoke_test.php` 在**单个进程内**跑通 部署 → 发起 → 审批 → 结束 全链路（9/9 步骤）：

```bash
php demo-slim/smoke_test.php
```

> 引擎行为验证以该脚本 + PHPUnit（195 tests / 736 assertions）为准。

## ⚠️ 内置服务器（php -S）的限制

`php -S` 每收到一个 HTTP 请求都会**新起一个进程**，进程内的 `InMemory` 仓储无法跨请求共享状态：

- `JEEFLOW_DEMO_STORE=memory` + HTTP 模式：发起后待办/详情会"消失"——这是服务器模型限制，**不是引擎 bug**（smoke_test 已证明引擎单进程内全链路正确）
- 因此 HTTP demo 必须用 `sqlite` 或 `mysql` 持久化
- 生产部署：MySQL + 常驻进程（FPM/Swoole/RoadRunner 均可，PDO 仓储与运行环境无关）

## Docker 部署

仓库根 `Dockerfile.demo` 提供镜像构建（流程定义用本仓 `flows/` 副本 + composer install）：

```bash
docker build -f Dockerfile.demo -t jeeflow-php-demo .
docker run -d -p 8090:8090 jeeflow-php-demo
```
