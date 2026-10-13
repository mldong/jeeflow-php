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
| 记录类节点处理器 | `CustomHandlerInterface`（经 `CustomHandlerRegistry` 按名注册） | 无（未注册 ⇒ custom 节点只落历史行＋续流并记 WARNING，不打断建单） | `snaker:custom` 节点 `properties.clazz` 的解析目标，见下文 |

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

> ⚠ **连接必须同源**（spec/12 §12.1 覆盖面，audit8 R9 145-7）：模板 `beginTransaction()` 的那条
> 连接，必须就是注入给 `PdoProcessRepository`（以及扩展仓 / persist writer）的**同一枚 `\PDO`**。
> 模板里另开一条连接（例如 `getMyPdo()` 再 new 一个）会得到"模板 begin 一条、仓储写另一条"的
> **空转事务**——引擎检不出来，回滚时写腿照样落库。`inTransaction()` 是引擎的**嵌套并入探测口**：
> 引擎五入口与门面 `flow()` 都经它判断（有活动事务则并入最外层，不二次 begin、不提前提交）。
>
> ⚠ **派发时序**（spec/11 §11.3.1／spec/12 §12.3.1，audit8 R25）：模板接在门面上之后，流程事件
> 的派发必须排在 **COMMIT 之后**（否则回滚场景站内信/通知已经发出去了）。引擎用 `afterCommit()`
> 探测（method_exists，**可选实现**）——实现了它，事务内 fire 的事件会挪到提交后广播、回滚自动
> 丢弃；不实现则保持"落库后即时广播"旧行为。**生产接入请实现它**：

```php
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\ServiceContext;

// $pdo 就是上面接入 MySQL 时创建、注入给 PdoProcessRepository 的那一枚
ServiceContext::put(TransactionTemplateInterface::class, new class($pdo) implements TransactionTemplateInterface {
    /** @var callable[] 提交后派发队列（issues/191） */
    private array $afterCommitCallbacks = [];

    public function __construct(private \PDO $pdo) {}

    public function required(callable $action): mixed {
        $this->pdo->beginTransaction();
        try {
            $result = $action();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->afterCommitCallbacks = [];   // 回滚：丢弃待派发
            $this->pdo->rollBack();
            throw $e;
        }
        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        foreach ($callbacks as $callback) { $callback(); }
        return $result;
    }

    // 不实现则嵌套回落 required（同一 PDO 二次 beginTransaction 会抛"已有活动事务"）
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }

    // 派发时序腿（spec/11 §11.3.1）：提交后执行、回滚丢弃、无事务立即执行
    public function afterCommit(callable $callback): void {
        if ($this->pdo->inTransaction()) { $this->afterCommitCallbacks[] = $callback; return; }
        $callback();
    }
});
```

> Laravel 集成形态（连接池自管）：`DB::connection('mysql')->getPdo()` 交给仓储/扩展仓/writer，
> 模板用 `DB::connection('mysql')->transaction(...)` ＋ `transactionLevel() > 0`——同一枚连接，
> 嵌套由 Laravel 事务层级计数兜底；`afterCommit()` 直接委托 Laravel 原生
> `DB::connection('mysql')->afterCommit($callback)`（root 提交后执行、回滚丢弃）。
> 参见 `mldong-laravel-jeeflow` 的 `WfTransactionTemplate`。

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
| ④ enabled（读侧判据） | **只认整数 1**（issues/130 案 A，与 Java `Integer.valueOf(1).equals(enabled)` 同阵营）：`'1'` / `1.0` / `true` 等"等价写法"与 `0` / `2` / 脏值 / `null` 一律不生效。整数列被驱动回读成字符串时由**仓储侧**先还原（`SurrogateRule::hydrateEnabled()`，内置 PDO 仓已接），引擎读侧不再做 `(int)` 宽松转换；自定义 SPI 仓储传非整数按停用 |
| ④ enabled（写侧入参） | 缺键→`1`；`''` / `'abc'` / `'1abc'` 等不可解析脏值→`0` 且**不抛错**；布尔 `true→1 / false→0`（`SurrogateRule::normalizeEnabledArg`，门面 save/update 与 PDO 落库前都过它） |
| 附：多条命中 | 取**主键 id 最大**那条（SQL 侧 `ORDER BY id DESC`，内存侧同样按 id 数值序，不得取遍历首条） |

`processName` 的取值口径（06 §4.5 条款 1.1）是**流程模型 name 优先，模型未带 name 时才回落
`wf_process_define.name`**（`SurrogateInterceptor::resolveProcessName`）：正常情况下
`processDefine/deploy` 执行 `def.setName(model.getName())`，两者恒等；回落不能省——空串只会命中
全流程兜底行，该流程自己配的委托一条都查不到。
委托在待办列表的合并展示属集成方视图层职责（引擎只负责让代理人真的进 `wf_process_task_actor`）。


## 接入记录类（自定义）节点处理器

`snaker:custom` 是**记录类**节点（自动执行／留痕，不是待办）。java 端 `CustomModel.exec` 走
`Class.forName(clazz)` 反射实例化，**PHP 没有 JVM 类路径可反射**——流程 JSON 是八栈共享夹具
（`flows/08-custom-node.json` 里 `clazz` 就是一个 Java 全限定类名），所以本栈与 python/c# 同策：
**按名注册实例**，键＝`clazz` 原样字符串。

```php
use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\CustomHandlerRegistry;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\CustomHandlerInterface;

$registry = new CustomHandlerRegistry();
$registry->register('com.mldong.jeeflow.test.TestCustomHandler', new class implements CustomHandlerInterface {
    public function handle(Execution $execution, array $args = []): mixed
    {
        // $args ＝ properties.args 逗号串按变量 key 从执行变量取出的实参
        return doSomethingExternal($args);   // 非 null ⇒ 写进 properties.val 指的执行变量键（缺省 custom_return_val）
    }
});
ServiceContext::put(CustomHandlerRegistry::class, $registry);
```

三种处理器形状都被接受（引擎的调用式统一是 `$handler->{$method}($execution, $args)`，
`$method` ＝ `properties.methodName`，未配回落 `handle`；PHP 用户态函数忽略多余实参）：

| 形状 | 返回值 | 对齐 |
|------|--------|------|
| 实现 `CustomHandlerInterface`（`handle(Execution, array): mixed`） | 非 null 即落执行变量 | python `ICustomHandler` |
| 实现既有 `HandlerInterface`（`handle(Execution): void`） | void ⇒ 不写变量键 | java `IHandler` 那一支（自己不写返回值） |
| 任意对象 ＋ `methodName` 指向其公开方法（或 `Closure`／`__invoke` 对象，`methodName` 未配时） | 非 null 即落执行变量 | java 反射调方法那一支 |

行为边界（规范 02 §6.2，owner 2026-09-30 拍，**不要改成抛异常**）：

- `clazz` **为空串／缺失** 与 **非空但未注册** 是两档：各记一条可分别诊断的 `error_log` WARNING，
  然后**照常落 `task_state=20` 历史行 + 令牌沿出边继续流转**，严禁抛异常打断建单
  （与「节点属性配错不该把流程炸掉」同一条哲学）。注册表整体缺席也归"未注册"这一档。
- 处理器**自身执行失败**（已解析到、跑炸了）**不在豁免内** ⇒ 照旧外抛，那是业务错误不是配错形状；
  `methodName` 指的是对象上没有的方法同样外抛（`自定义模型[class=...]无法找到方法名称:...`，java 逐字同档）。
- 历史行**必须真落库**（内存仓与 PDO 仓两条 INSERT 通道都由仓储 `saveTask` 兑现），
  且**不走**"新待办"那条腿：不发 `PROCESS_TASK_START`（码 3 表达"新待办产生"）、
  不过委托代理、不进 `executeProcessTask` 的返回值。
