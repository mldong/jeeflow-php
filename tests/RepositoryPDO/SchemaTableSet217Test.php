<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use PHPUnit\Framework\TestCase;

/**
 * issues/217 · audit8 队列 R49（C99 §3.3 层位 3 / C1-L2 ①-6）——建表 SQL 表集合断言（T0，无 DB 依赖）。
 *
 * "谁盯着表集合"此前四栈四答案（本栈无／rust test_schema_* 群／moon 零枚／csharp 静态形状）：
 * 本件补上仓内牙，与 rust `test_schema_contains_8_tables`/`test_schema_table_names`
 * 及 csharp/moon 同轮新增件同形——剥 `--` 行首注释 → count(CREATE TABLE) == 8 → 8 名单逐字在场
 * （canonical 前缀形式 `CREATE TABLE IF NOT EXISTS <name> (`，防子串误判：`wf_process_task`
 * 是 `wf_process_task_actor` 的前缀）。
 *
 * 盯的对象＝本仓分发副本 packages/repository-pdo/sql/schema-mysql.sql（T1 腿 exec 进真库的同一份）；
 * 字节级"与编辑源全等"由 hub check-doc-schema.py 门禁在管，本件只管单语言下载即用的仓内表集合牙
 * （"php 曾被漏分发 50 天"的形态防复发）。
 */
class SchemaTableSet217Test extends TestCase
{
    /** 8 名单：与 rust test_schema_table_names / java 编辑源同名同序。 */
    private const EXPECTED_TABLES = [
        'wf_process_define',
        'wf_process_instance',
        'wf_process_task',
        'wf_process_task_actor',
        'wf_process_cc_instance',
        'wf_process_design',
        'wf_process_design_his',
        'wf_process_surrogate',
    ];

    /** 读副本原文并剥 `--` 行首注释（与 T1 腿/各栈分句器同口径）。 */
    private static function strippedSchema(): string
    {
        $path = dirname(__DIR__, 2) . '/packages/repository-pdo/sql/schema-mysql.sql';
        $ddl = file_get_contents($path);
        self::assertNotFalse($ddl, "读不到 schema-mysql.sql：{$path}");
        $kept = [];
        foreach (explode("\n", $ddl) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $kept[] = $line;
            }
        }
        return implode("\n", $kept);
    }

    public function testExactlyEightCreateTableStatements(): void
    {
        self::assertSame(
            8,
            substr_count(self::strippedSchema(), 'CREATE TABLE'),
            'schema-mysql.sql 剥注释后应恰有 8 句 CREATE TABLE'
        );
    }

    public function testAllEightTableNamesPresent(): void
    {
        $schema = self::strippedSchema();
        foreach (self::EXPECTED_TABLES as $table) {
            self::assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS {$table} (",
                $schema,
                "缺表或缺 canonical 前缀形式：{$table}"
            );
        }
    }
}
