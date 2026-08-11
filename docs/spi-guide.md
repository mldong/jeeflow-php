# SPI 实现指南

> jeeflow-php 通过 SPI（Service Provider Interface）扩展点接入自定义实现。

## SPI 清单

| SPI | 接口 | 默认实现 | 说明 |
|-----|------|----------|------|
| 流程仓储 | `ProcessRepositoryInterface` | `InMemoryProcessRepository` | 流程定义/实例/任务持久化 |
| 扩展仓储 | `ProcessExtRepositoryInterface` | `InMemoryProcessExtRepository` | 设计稿/委托代理管理 |
| ID 生成 | `IdGeneratorInterface` | `InMemoryIdGenerator` | 雪花 ID / UUID |
| 表达式 | `ExpressionEvaluatorInterface` | `SimpleExpressionEvaluator` | 条件分支/会签表达式 |
| 事务 | `TransactionTemplateInterface` | — | 事务模板（生产必接） |
| 用户 | `IUserProvider` | — | 用户信息查询 |

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
use Jeeflow\Core\Spi\IUserProvider;
use Jeeflow\Core\ServiceContext;

ServiceContext::put(IUserProvider::class, new class implements IUserProvider {
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

```php
use Jeeflow\Core\Spi\IdGeneratorInterface;

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
