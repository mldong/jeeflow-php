# Facade 契约（40 action）

> `JeeflowFacade` 统一门面，对齐 Java `JeeflowFacade` 的 40 个 action。
> 完整契约规范见 `jeeflow-doc/docs/spec/06-facade.md`。

## 调用方式

```php
$facade = new JeeflowFacade($engine, $repo, $extRepo);
$result = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 10]);
// $result = ['code' => 0, 'msg' => 'ok', 'data' => [...]]
```

## action 清单

### 流程定义（7）

| action | 说明 |
|--------|------|
| `processDefine/page` | 定义分页 |
| `processDefine/detail` | 定义详情 |
| `processDefine/deploy` | 部署（版本自增） |
| `processDefine/redeploy` | 重新部署（原地替换） |
| `processDefine/remove` | 删除定义 |
| `processDefine/upAndDown` | 启用/停用 |
| `processDefine/startAndExecute` | 发起并自动完成申请节点 |

### 流程实例（5）

| action | 说明 |
|--------|------|
| `processInstance/page` | 实例分页 |
| `processInstance/detail` | 实例详情 |
| `processInstance/startAndExecute` | 同 processDefine/startAndExecute |
| `processInstance/withdraw` | 撤回 |
| `processInstance/approvalRecord` | 审批记录 |

### 流程任务（9）

| action | 说明 |
|--------|------|
| `processTask/todoList` | 待办列表 |
| `processTask/doneList` | 已办列表 |
| `processTask/execute` | 执行任务（submitType 分发） |
| `processTask/detail` | 任务详情 |
| `processTask/jumpAbleTaskNameList` | 可跳转节点 |
| `processTask/surrogate` | 委托执行 |
| `processTask/latest` | 最新任务 |
| `processTask/highLight` | 高亮路径 |
| `processTask/getAssigneeTextData` | 审批人文本 |

### 视图端点（7）

| action | 说明 |
|--------|------|
| `processDefine/getLastByName` | 按名称查最新定义 |
| `processInstance/highLight` | 高亮（同 processTask） |
| `processInstance/approvalRecord` | 审批记录（同 processInstance） |
| `processInstance/createCCInstance` | 创建抄送 |
| `processInstance/ccList` | 抄送列表 |
| `processInstance/updateCCStatus` | 更新抄送状态 |
| `processInstance/getAssigneeTextData` | 审批人文本（同 processTask） |

### 流程设计（9，需 IProcessExtRepository）

| action | 说明 |
|--------|------|
| `processDesign/page` | 设计分页 |
| `processDesign/detail` | 设计详情 |
| `processDesign/save` | 保存设计 |
| `processDesign/update` | 更新设计 |
| `processDesign/updateDefine` | 更新设计内容（JSON） |
| `processDesign/remove` | 删除设计 |
| `processDesign/deploy` | 部署设计 |
| `processDesign/redeploy` | 重新部署设计 |
| `processDesign/listByType` | 按类型列出 |

### 委托代理（3，需 IProcessExtRepository）

| action | 说明 |
|--------|------|
| `processSurrogate/page` | 委托分页 |
| `processSurrogate/save` | 保存委托 |
| `processSurrogate/remove` | 删除委托 |

## 统一响应格式

```json
{
  "code": 0,
  "msg": "ok",
  "data": { ... }
}
```

- `code = 0` 成功
- `code = 99999999` 业务异常（msg 含错误描述）

## submitType 枚举

| 值 | 含义 | 行为 |
|----|------|------|
| `apply` (0) | 发起申请 | 完成申请节点，流转到下一节点 |
| `approve` (1) | 同意 | 完成当前任务，流转到下一节点 |
| `reject` (2) | 拒绝 | 跳结束，实例状态→45 |
| `rollback` (3) | 退回上一步 | 退回到上一任务节点 |
| `jump` (4) | 跳转 | 跳转到指定节点 |
| `rollbackToOperator` (6) | 退回发起人 | 退回到第一个任务节点，参与者=发起人 |
