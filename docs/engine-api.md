# 引擎 API

> jeeflow-php 引擎对外方法（`JeeflowEngine`）。语义与 Java 参考实现一一对应。

## 构造引擎

```php
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;

$repo = new InMemoryProcessRepository();     // 内存仓储（演示/测试用）
$engine = new JeeflowEngine($repo);          // 构造只收仓储，无其他注入
```

> SPI 装配方式：表达式 / 事务 / 用户走 `ServiceContext::put(...)`，ID 生成走仓储构造注入——见 [SPI 实现指南](./spi-guide.md)。

## 核心方法

| 方法 | 说明 |
|------|------|
| `startProcessInstanceById(defineId, operator, flowArgs)` | 启动流程实例（flowArgs 为流程变量） |
| `executeProcessTask(taskId, operator, flowArgs)` | 执行任务（同意/发起/会签等） |
| `executeAndJumpToEnd(taskId, operator, flowArgs)` | 拒绝（REJECT）→ 跳结束，实例→45 |
| `executeAndJumpTask(taskId, operator, flowArgs, targetTaskName)` | 跳转（JUMP）/ 退回上一步（ROLLBACK） |
| `executeAndJumpToFirstTaskNode(taskId, operator, flowArgs)` | 退回发起人 → 第一个任务节点重执行 |

```php
// 启动并自动完成申请节点（startAndExecute 契约）
$inst = $engine->startProcessInstanceById($defineId, 'user1', $flowArgs);
foreach ($repo->findDoingTasks($inst->getInstanceId()) as $task) {
    $repo->addTaskActor($task->getTaskId(), ['user1']);
    $engine->executeProcessTask($task->getTaskId(), 'user1', $flowArgs);
}
```

## 变量注入

引擎每次操作自动注入用户信息到流程变量（需注册 `UserProviderInterface` SPI，见 [SPI 实现指南](./spi-guide.md)）。

## 状态码

- 实例：`10` 进行中 / `20` 已完成 / `45` 已拒绝
- 任务：`10` 待办 / `20` 已完成 / `99` 已废弃

> submitType 全枚举行为见[规范 06 统一门面](../../spec/06-facade)。
