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
