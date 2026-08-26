# 流程定义 JSON 格式

> 流程定义使用 **LogicFlow 兼容的 JSON 格式**，与四语言（Java/Go/Python/Node）完全一致，同一份 JSON 可在任意语言引擎部署。
> 完整字段字典（属性枚举 / 配置项）见[规范 02 · 流程定义格式](../../spec/02-flow-definition)（唯一事实来源）。

## 基本结构

```json
{
  "name": "leave",
  "displayName": "请假审批",
  "type": "approval",
  "instanceUrl": "/form/leave",
  "nodes": [ ... ],
  "edges": [ ... ]
}
```

| 字段 | 必填 | 说明 |
|------|------|------|
| `name` | ✅ | 唯一编码 |
| `displayName` | ✅ | 显示名称 |
| `type` | ❌ | 流程分类 |
| `instanceUrl` | ❌ | 发起表单地址 |
| `nodes` | ✅ | 节点列表 |
| `edges` | ✅ | 边列表 |

## 节点类型与 PHP 模型对应

| 节点类型 | PHP 模型 | 说明 |
|----------|----------|------|
| `snaker:start` | `StartModel` | 流程入口，每个流程有且只有一个 |
| `snaker:task` | `TaskModel` | 审批节点，支持普通参与 / 会签（`performType`） |
| `snaker:decision` | `DecisionModel` | 决策节点，按表达式（`expr` / 边 `expr`）分流 |
| `snaker:fork` | `ForkModel` | 并行分支 |
| `snaker:join` | `JoinModel` | 合并，等待所有并行分支完成 |
| `snaker:subProcess` | `SubProcessModel` | 子流程节点（`StartSubProcessHandler`） |
| `snaker:end` | `EndModel` | 流程出口 |

> ⚠️ **`snaker:custom` 自定义节点未实现**（PHP 1.0.x 无 CustomModel）。含 custom 节点的流程 JSON 在 PHP 引擎上无法解析，部署时需去掉或改用 handler 体系。

## 任务节点

```json
{
  "id": "task1",
  "type": "snaker:task",
  "properties": {
    "form": "leave-form",
    "assignee": "leader",
    "taskType": 0,
    "performType": 0,
    "field": {
      "candidateUsers": "user1,user2",
      "candidateGroups": "dept1",
      "countersignType": "PARALLEL",
      "countersignCompletionCondition": "#nrOfCompletedInstances==2"
    }
  }
}
```

| 属性 | 说明 |
|------|------|
| `form` | 表单标识，前端据此加载对应表单 |
| `assignee` | 静态参与者，支持逗号分隔多人；`"applicant"` 解析为流程发起人（`instance.operator`）；支持 `${变量名}` 从流程变量取值 |
| `taskType` | 0=主办 1=协办 2=记录 |
| `performType` | 0=普通参与（任一人完成即推进） 1=会签参与（`CountersignHandler`） |
| `candidateUsers` / `candidateGroups` | 候选人 / 候选组（参与人解析扩展点，见[SPI 实现指南](./spi-guide.md)） |
| `countersignType` | `PARALLEL` 并行会签 / `SEQUENTIAL` 串行会签 |
| `countersignCompletionCondition` | 会签完成条件表达式；特殊值 `ONE_VOTE_VETO` = 开启一票否决 |

### 会签完成条件

| 条件 | 表达式 |
|------|--------|
| 全部完成 | 为空或 `#nrOfCompletedInstances==#nrOfInstances` |
| 按数量通过 | `#nrOfCompletedInstances==N` |
| 一票通过 | `#nrOfCompletedInstances==1` |
| 一票否决 | 节点条件填 `ONE_VOTE_VETO` 后，任一成员 submitType=20 即推进整单；**未配置时 submitType=20 为软拒绝**（flag 记录、不阻断） |

## 决策节点与条件边

决策节点通过**节点 `expr`**（默认分支）和**出边 `expr`**（条件边）决定流向：

```json
{
  "id": "decision1",
  "type": "snaker:decision",
  "properties": { "expr": "amount > 1000" }
}
```

```json
{
  "id": "e3",
  "type": "snaker:transition",
  "sourceNodeId": "decision1",
  "targetNodeId": "manager",
  "properties": { "expr": "amount > 1000" }
}
```

### ⚠️ 表达式求值器必须注册（PHP 特有）

`DecisionModel` 和 `CountersignHandler` 通过 `ServiceContext` 查找表达式 SPI，**引擎核心不内置求值器**（与 Java 内置表达式引擎不同）：

- 节点 `expr` 非空且未注册 → 抛 `JeeflowException('未注册表达式求值器 SPI')`
- 条件边 `expr` 未注册 → 该边被跳过（不抛异常）；若所有出边都未命中 → 抛 `无法确定下一节点`
- 会签完成条件同理走该 SPI

```php
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;

ServiceContext::put(ExpressionEvaluatorInterface::class, new class implements ExpressionEvaluatorInterface {
    /** @param array<string, mixed> $variables */
    public function eval(string $expr, array $variables): mixed
    {
        // 例：支持 $amount > 1000 与 #nrOfCompletedInstances==N 两类表达式
        // 参考实现见 jeeflow-php/tests/Fixture/SimpleExpressionEvaluator.php
    }
});
```

> 只跑不含决策/会签条件的线性流程可以不注册；demo-slim 已按需注册（见 `demo-slim/public/index.php`）。

## 边

```json
{
  "id": "e1",
  "type": "snaker:transition",
  "sourceNodeId": "start",
  "targetNodeId": "task1",
  "properties": { "expr": "amount > 1000" }
}
```

## 部署与共享流程 JSON

- 部署：`processDefine/deploy` action（`content` 传 JSON 字符串），版本自增
- demo-slim 初始化时从本仓 `flows/` 加载**全部共享流程 JSON**（唯一编辑源在 `jeeflow-java/jeeflow-core/src/test/resources/flows/`，`jeeflow-flows-dir.php` 在维护者机器上执行时把 Java 源精确镜像进本仓；与 Python/Node 对齐），见[演示站](./demo.md)
- 流程定义里的 key（`assignee`、`u_*` 变量、表达式）须与 mldong 框架兼容（接入 mldong 生态时）
