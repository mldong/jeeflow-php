<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Persist;

use Jeeflow\Persist\PdoDynamicTableWriter;
use PHPUnit\Framework\TestCase;

/**
 * PdoDynamicTableWriter 单元层（issues/82-11：PHP persist 覆盖对齐 Java/Go/Python/Node）
 *
 * 纯 writer 行为，无引擎参与——对齐 Python test_persist.py ⑥-⑮ / Go writer_test.go /
 * Node persist.test.ts ⑥-⑮。覆盖：全字段插入 / 缺列过滤 / NULL+防注入 / exists 幂等 /
 * BIGINT 用户列 / 默认用户回落 / 驼峰↔下划线宽松+严格 / 主键生成器 / 缺生成器报错。
 */
class PdoDynamicTableWriterTest extends TestCase
{
    /** @return array{0:PdoDynamicTableWriter, 1:\PDO} */
    private function writer(string $ddl): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($ddl);
        return [new PdoDynamicTableWriter($pdo, 'sqlite'), $pdo];
    }

    private const ORDER = <<<SQL
CREATE TABLE biz_order (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    amount TEXT,
    process_instance_id TEXT,
    apply_user_id TEXT,
    apply_dept_id TEXT,
    create_time TEXT,
    create_user TEXT,
    update_time TEXT,
    update_user TEXT,
    is_deleted INTEGER DEFAULT 0
)
SQL;

    // ─── ⑥ writer 全字段插入（业务 + 系统字段）───

    public function testInsertFullWithSystemFields(): void
    {
        [$w, $pdo] = $this->writer(self::ORDER);
        // 注：纯 writer 层用户列回落读 snake_case apply_user_id（fillContext 在拦截器层负责
        // 从实例 operator 归一为 apply_user_id），与 Python test_writer_insert_full 同契约
        $data = ['title' => '年假申请', 'amount' => '800', 'processInstanceId' => '7',
                 'apply_user_id' => 'user1', 'applyDeptId' => 'D01'];
        $w->fillSystemFields($data, true);
        $w->insert('biz_order', $data);
        $row = $pdo->query('SELECT title, amount, process_instance_id, apply_user_id, '
            . 'apply_dept_id, create_time, create_user, update_time, update_user, is_deleted '
            . 'FROM biz_order')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('年假申请', $row['title']);
        $this->assertSame('800', $row['amount']);
        $this->assertSame('7', $row['process_instance_id']);
        $this->assertSame('user1', $row['apply_user_id']);
        $this->assertSame('D01', $row['apply_dept_id']);
        $this->assertSame('user1', $row['create_user'], 'issues/19: 用户列默认值优先 operator(applyUserId)');
        $this->assertSame('user1', $row['update_user']);
        $this->assertNotSame('', (string) $row['create_time'], 'create_time 系统字段应填充');
        $this->assertSame('0', (string) $row['is_deleted']);
    }

    // ─── ⑦ writer 缺列过滤（filterColumns 只留真实列）───

    public function testFilterColumnsDropsUnknown(): void
    {
        [$w] = $this->writer(self::ORDER);
        $kept = $w->filterColumns('biz_order', ['title', 'no_such_col', 'amount', 'ghost']);
        sort($kept);
        $this->assertSame(['amount', 'title'], $kept);
    }

    // ─── ⑧ NULL 值 + 表名防注入 / 表名安全 ───

    public function testNullValuesAndTableSafety(): void
    {
        [$w, $pdo] = $this->writer(self::ORDER);
        // NULL 值正常落库（占位符绑定）
        $w->insert('biz_order', ['title' => 't', 'amount' => null, 'applyDeptId' => null]);
        // 形似 SQL 注入的“值”作为绑定参数，安全落库不报错
        $w->insert('biz_order', ['title' => "x'); DROP TABLE biz_order; --"]);
        $cnt = (int) $pdo->query('SELECT COUNT(1) FROM biz_order')->fetchColumn();
        $this->assertSame(2, $cnt, '两行都应成功插入（值走绑定参数）');

        // sys_ 前缀表名拒绝
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sys_ prefix/');
        $w->insert('sys_user', ['x' => 1]);
    }

    public function testIllegalTableNameRejected(): void
    {
        [$w] = $this->writer(self::ORDER);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/illegal characters/');
        $w->insert('biz_order; DROP TABLE biz_order', ['x' => 1]);
    }

    public function testEmptyTableNameRejected(): void
    {
        [$w] = $this->writer(self::ORDER);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/table name is empty/');
        $w->insert('', ['x' => 1]);
    }

    public function testFilterColumnsRejectsSysPrefix(): void
    {
        [$w] = $this->writer(self::ORDER);
        $this->expectException(\RuntimeException::class);
        $w->filterColumns('sys_user', ['x']);
    }

    // ─── ⑨ writer exists 幂等（业务键存在判定）───

    public function testExistsIdempotent(): void
    {
        [$w] = $this->writer(self::ORDER);
        $w->insert('biz_order', ['title' => 't', 'processInstanceId' => '99']);
        $this->assertTrue($w->exists('biz_order', 'process_instance_id', '99'));
        $this->assertFalse($w->exists('biz_order', 'process_instance_id', '100'));
    }

    // ─── ⑩ BIGINT 用户列（issues/19）：INTEGER 用户列存 operator ───

    public function testBigintUserColumnStoresOperator(): void
    {
        $ddl = 'CREATE TABLE biz_settle (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT,
            process_instance_id TEXT, apply_user_id INTEGER,
            create_user INTEGER, update_user INTEGER, is_deleted INTEGER DEFAULT 0)';
        [$w, $pdo] = $this->writer($ddl);
        $data = ['title' => '结算单', 'processInstanceId' => '5', 'apply_user_id' => '123'];
        $w->fillSystemFields($data, true);
        $w->insert('biz_settle', $data);
        $row = $pdo->query('SELECT create_user, update_user, apply_user_id FROM biz_settle')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(123, (int) $row['create_user'], 'BIGINT 用户列应为 operator');
        $this->assertSame(123, (int) $row['update_user']);
        $this->assertSame(123, (int) $row['apply_user_id']);
    }

    // ─── ⑪ 用户列默认值回落：优先 apply_user_id，否则配置默认值 ───

    public function testDefaultUserValueFallback(): void
    {
        [$w] = $this->writer(self::ORDER);
        $data = ['title' => 't', 'apply_user_id' => 'abc'];
        $w->fillSystemFields($data, true);
        $this->assertSame('abc', $data['create_user'], '有 apply_user_id 时优先之');

        $w->defaultUserValue = 'fallback-user';
        $data2 = ['title' => 't'];
        $w->fillSystemFields($data2, true);
        $this->assertSame('fallback-user', $data2['create_user'], '无 apply_user_id 回落配置默认值');
    }

    // ─── ⑫ 宽松列匹配（issues/20）：驼峰 key ↔ 下划线列 ───

    public function testLooseCamelMatch(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE biz_leave (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, start_time TEXT,
            process_instance_id TEXT)');
        $w = new PdoDynamicTableWriter($pdo, 'sqlite');
        $w->insert('biz_leave', ['startTime' => '09:00:00', 'processInstanceId' => '55', 'title' => 'camel']);
        $row = $pdo->query('SELECT start_time, process_instance_id FROM biz_leave')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('09:00:00', $row['start_time'], '驼峰 key 应落到下划线列');
        $this->assertSame('55', $row['process_instance_id']);
        $kept = $w->filterColumns('biz_leave', ['startTime', 'processInstanceId', 'no_such']);
        sort($kept);
        $this->assertSame(['processInstanceId', 'startTime'], $kept);
    }

    // ─── ⑬ 严格列匹配（issues/20）：显式开启后驼峰不再匹配 ───

    public function testStrictColumnMatch(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE biz_leave (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, start_time TEXT)');
        $w = new PdoDynamicTableWriter($pdo, 'sqlite');
        $w->strictColumnMatch = true;
        $w->insert('biz_leave', ['startTime' => '09:00:00', 'title' => 'strict']);
        $row = $pdo->query('SELECT title, start_time FROM biz_leave')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('strict', $row['title']);
        $this->assertNull($row['start_time'], '严格模式应过滤驼峰 key，start_time 不填');
    }

    // ─── ⑭ 非自增主键生成（issues/21）：配生成器后插入成功 ───

    public function testPrimaryKeyGenerator(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE biz_snow (id TEXT PRIMARY KEY, title TEXT)');
        $w = new PdoDynamicTableWriter($pdo, 'sqlite');
        $w->primaryKeyGenerator = fn(string $t): string => 'snow-888';
        $w->insert('biz_snow', ['title' => 'snow']);
        $row = $pdo->query('SELECT id, title FROM biz_snow')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('snow-888', $row['id'], '主键应由生成器生成');
        // data 已含主键值 → 用之，不调生成器
        $w->insert('biz_snow', ['id' => 'manual-1', 'title' => 'm']);
        $n = (int) $pdo->query("SELECT COUNT(1) FROM biz_snow WHERE id='manual-1'")->fetchColumn();
        $this->assertSame(1, $n);
    }

    // ─── ⑮ 非自增主键未配生成器（issues/21）：清晰报错 ───

    public function testMissingPrimaryKeyGenerator(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE biz_snow (id TEXT PRIMARY KEY, title TEXT)');
        $w = new PdoDynamicTableWriter($pdo, 'sqlite'); // 未配置生成器
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/primary key generator/');
        $w->insert('biz_snow', ['title' => 'x']);
    }
}
