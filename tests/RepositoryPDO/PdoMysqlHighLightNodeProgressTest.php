<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

/**
 * issues/156 · 同一套判据跑 **真 MySQL** 档（T1，连 `jeeflow_test` 专用测试库）。
 *
 * 立案文 §6.2 点名的就是这一格：`highLight` 的模型补全腿此前**从没在任何持久化档位上测过**，
 * 只有内存仓储那份用例（参与者 id 是 `user1/userA/userB` 这类非数字串，恰好绕开了
 * PHP 数组键折叠）。本件把同一条腿钉在「行真的落库、再真的读回来」那一档上，
 * 与 `PdoSqliteHighLightNodeProgressTest` 共用断言，档位差只体现在连接与建表两处。
 *
 * 不可达时按本仓既有 env 显式跳过（`SKIP_MYSQL=1`），发版机设 `REQUIRE_MYSQL=1`
 * 会把"跳过"变成"报错"——不留静默空档。
 */
final class PdoMysqlHighLightNodeProgressTest extends PdoSqliteHighLightNodeProgressTest
{
    /** @var string 跳过理由；空串表示可连 */
    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        self::$skipReason = (string) PdoTestDb::skipReason();
    }

    protected function makePdo(): \PDO
    {
        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
        $pdo = PdoTestDb::connect();
        // 与真引擎集成方（laravel 壳）同一档：Laravel 默认关模拟预处理，
        // 本仓 T1 套件默认开——两档都要能过，故这里显式跑模拟档，
        // 关模拟那一档由 repro 轮实证过（见 issues/156 收口读数）。
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        return $pdo;
    }

    protected function createSchema(\PDO $pdo): void
    {
        $schema = file_get_contents(__DIR__ . '/../../packages/repository-pdo/sql/schema-mysql.sql');
        $this->assertNotFalse($schema, 'schema-mysql.sql 读不到');
        $pdo->exec($schema);
        // 本套件跑在专用测试库里，整库清表是本仓 PDO 套件的既有约定
        foreach (['wf_process_task_actor', 'wf_process_task', 'wf_process_cc_instance',
            'wf_process_instance', 'wf_process_define'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
    }
}
