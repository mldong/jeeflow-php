# SPI 实现指南

> jeeflow-php 通过 SPI（Service Provider Interface）扩展点接入自定义实现。

## SPI 清单

| SPI | 接口 | 默认实现 | 说明 |
|-----|------|----------|------|
| 流程仓储 | `ProcessRepositoryInterface` | `InMemoryProcessRepository` | 流程定义/实例/任务持久化；内置 `getIdGenerator()` |
| 扩展仓储 | `ProcessExtRepositoryInterface` | `InMemoryProcessExtRepository` | 设计稿/委托代理管理 |
| ID 生成 | `IdGeneratorInterface` | `InMemoryIdGenerator`（随仓储返回） | 雪花 ID / UUID，通过 `repository->getIdGenerator()` 获取，仓储构造可注入 |
| 表达式 | `ExpressionEvaluatorInterface` | 无（未注册时决策节点 expr 抛异常、条件边跳过） | 条件分支/会签表达式，`ServiceContext` 注册 |
| 事务 | `TransactionTemplateInterface` | 无（生产必接） | 事务模板，`ServiceContext` 注册 |
| 用户 | `UserProviderInterface` | `NoOpUserProvider` | 用户信息查询 |
| JSON | `JsonProviderInterface` | `BuiltinJsonProvider` | JSON 编解码 |
| 委托应用 | `SurrogateInterceptor`（内置类） | 引擎默认开启，见下文「委托代理自动生效」 | 建单时把生效中的被委托人并入参与者 |

## 接入表达式求值（决策/会签必接）

引擎核心**不内置表达式求值器**，`DecisionModel`（节点 `expr` / 条件边 `expr`）与 `CountersignHandler`（会签完成条件）通过 `ServiceContext` 查找：

- 节点 `expr` 非空且未注册 → 抛 `JeeflowException('未注册表达式求值器 SPI')`
- 条件边 `expr` 未注册 → 该边被跳过；所有出边未命中 → 抛 `无法确定下一节点`

```php
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;

ServiceContext::put(ExpressionEvaluatorInterface::class, new class implements ExpressionEvaluatorInterface {
    public function eval(string $expr, array $variables): mixed
    {
        // 例：$amount > 1000 / #nrOfCompletedInstances==N
        // 参考：tests/Fixture/SimpleExpressionEvaluator.php
    }
});
```

> 只跑不含决策/会签条件的线性流程可以不注册。

## 接入 MySQL（PDO）

```php
use Jeeflow\RepositoryPDO\PdoProcessRepository;

$pdo = new PDO('mysql:host=localhost;dbname=jeeflow;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new PdoProcessRepository($pdo);
```

建表 SQL：`packages/repository-pdo/sql/schema-mysql.sql`（八表）。

## 接入事务

```php
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\ServiceContext;

ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
    public function required(callable $action): mixed {
        // 包装到数据库事务中
        $pdo = getMyPdo();
        $pdo->beginTransaction();
        try {
            $result = $action();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
});
```

## 接入用户体系

```php
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\Core\ServiceContext;

ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
    public function getUser(string $userId): ?array {
        // 从你的用户表查
        return [
            'userId' => $userId,
            'realName' => '张三',
            'deptId' => 'D01',
            'deptName' => '研发部',
            'postId' => 'P01',
            'postName' => '工程师',
        ];
    }
});
```

## 自定义 ID 生成

ID 生成器由**仓储**持有（`repository->getIdGenerator()`），默认 `InMemoryIdGenerator`；自定义时作为仓储构造第二参注入：

```php
use Jeeflow\Core\Repository\InMemoryProcessRepository;

$repo = new InMemoryProcessRepository(new SnowflakeIdGenerator());
// PDO 版同理：new PdoProcessRepository($pdo, new SnowflakeIdGenerator())

class SnowflakeIdGenerator implements IdGeneratorInterface {
    private int $epoch = 1700000000000;
    private int $seq = 0;
    
    public function nextId(): string {
        $ts = (int)(microtime(true) * 1000) - $this->epoch;
        $this->seq = ($this->seq + 1) & 0xFFF;
        return (string)(($ts << 10) | $this->seq);
    }
}
```

## 委托代理自动生效（引擎内置、默认开启，issues/116）

`processSurrogate/*` 五个 action 只是**台账 CRUD**。真正的能力是「建任务那一刻自动应用生效中的委托」——
用户在「我的委托」里配好"休假期间张三替我批"，新单到达任务节点时**李四的待办也要出现该单，张三的保留**
（委托不是转办，任一可办）。契约见 jeeflow-doc `spec/06-facade.md` §4.5「运行期语义」。

PHP 侧落点是 `Jeeflow\Core\Interceptor\SurrogateInterceptor`，由 `JeeflowEngine::saveNewTask()` 在
`saveTask` **之前**调用（新任务落库的唯一收口：发起 / 办理推进 / 串行会签每一步推进 / 跳转 四条路径都走它）。
命中即把被委托人**并入参与者集合本身**，随任务一起全量写 `wf_process_task_actor`——
**不是**"事后再调一次 `addTaskActor` 补写"：挂点处 taskId 尚未分配，补写打在空 id 上静默无效。

集成方**零配置即生效**：`new JeeflowFacade($engine, $repo, $extRepo)` 时门面会把扩展仓储桥接进
`ServiceContext`，引擎按 `ProcessExtRepositoryInterface` 解析。未配置扩展仓储（或仓储自身报错，如表未建）
一律**静默跳过**，不打断建单——委托是增强能力，缺仓储属正常部署形态。

### 关闭（三条路，回到"仅台账"行为）

```php
use Jeeflow\Core\Interceptor\NullSurrogateInterceptor;
use Jeeflow\Core\Interceptor\SurrogateInterceptor;
use Jeeflow\Core\ServiceContext;

// ① 构造参数 / setter（主路径）
$engine = new JeeflowEngine($repo, surrogateAutoApply: false);
$engine->setSurrogateAutoApply(false);          // 等价写法

// ② 注册空实现（不改引擎开关，按部署形态关）
ServiceContext::put(SurrogateInterceptor::CONTEXT_KEY, new NullSurrogateInterceptor());

// ③ 自建扩展仓储让 getSurrogate 恒返回 null（等价于"没有生效委托"）
$engine->setSurrogateApplier(new NullSurrogateInterceptor());   // 也可整体换掉应用器
```

### 查询四判据（内存仓与 SQL 仓必须同答案）

`getSurrogate($operator, $processName, $time)` 的生效判据集中在
`Jeeflow\Core\Util\SurrogateRule`（`InMemoryProcessExtRepository` 与 `PdoProcessExtRepository` 共用）：

| 判据 | 语义 |
|------|------|
| ① 空 processName 兜底 | 先按当前流程名精确查，未命中再查 `process_name` 为 NULL/`''` 的全流程委托 |
| ② 时间窗 | `start_time <= now <= end_time`，**一侧为 NULL/空即该侧不限** |
| ③ 自委托过滤 | `surrogate <> operator`（自己委托给自己不生效；代理人为空串同样不生效） |
| ④ enabled | **只有 1 生效**，脏值（`'abc'` 等不可解析为整数的值）不得当启用 |
| 附：多条命中 | 取**主键 id 最大**那条（SQL 侧 `ORDER BY id DESC`，内存侧同样按 id 数值序，不得取遍历首条） |

`processName` 的取值口径是**流程定义 name**（`wf_process_define.name`）：`processDefine/deploy` 落库时
执行的正是模型名，故与流程 JSON 的 `name` 同值，跨栈对拍认库内这一列。
委托在待办列表的合并展示属集成方视图层职责（引擎只负责让代理人真的进 `wf_process_task_actor`）。

