<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

/**
 * MySQL 版 PDO 套件的连接口（issues/118 §2.1）。
 *
 * 之前三套 PDO 测试把 DSN 写死成 `127.0.0.1/jeeflow_test`，本机没有这个库 ⇒ 每套都在
 * `setUpBeforeClass` 抛 2002，被当成既有噪声，结果 PDO 仓储路径长期无人验。
 * 现在走 `JEFFLOW_DB_*`（与其它语言栈同一套变量名），不可达时给出**显式跳过原因**；
 * `REQUIRE_MYSQL=1` 时不跳过而是报错——发版机用它把"跳过"逼回"要么真跑要么红"。
 */
final class PdoTestDb
{
    /**
     * 本套件会建表并 DELETE 全表，所以只能连**专用测试库**。库名单独用一个变量，
     * 不借 `JEFFLOW_DB_NAME`——那个变量在本仓默认是 `jeeflow`（共享库），
     * 谁按其它栈的习惯 export 一下，这里就会把共享库当测试库清表。
     */
    public const DEFAULT_DB = 'jeeflow_test';

    public static function connect(): ?\PDO
    {
        $conn = self::resolve();
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $conn[0], $conn[1], $conn[2]),
            $conn[3],
            $conn[4],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );
        return $pdo;
    }

    /**
     * 给三个 MySQL 套件共用：拿到可用的 PDO，或拿到一条**必须显式说明**的跳过理由。
     *
     * @return array{0:?\PDO,1:string}
     */
    public static function connectOrSkip(): array
    {
        $reason = self::skipReason();
        if ($reason !== null) {
            if (getenv('REQUIRE_MYSQL') === '1') {
                throw new \RuntimeException($reason);
            }
            return [null, $reason];
        }
        $pdo = self::connect();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return [$pdo, ''];
    }

    /** 连接失败/显式跳过时的原因文本；null 表示可以连。 */
    public static function skipReason(): ?string
    {
        if (getenv('SKIP_MYSQL') === '1') {
            return 'SKIP_MYSQL=1：显式跳过 MySQL 版 PDO 套件';
        }
        try {
            self::connect();
        } catch (\PDOException $e) {
            [$host, $port, $db] = self::target();
            return sprintf(
                'MySQL PDO 套件不可达 %s:%s/%s（%s）——export JEFFLOW_DB_HOST/PORT/USER/PWD 指到已建好的'
                . ' %s 库；发版机请设 REQUIRE_MYSQL=1 让这一格报错而不是静默跳过',
                $host,
                $port,
                $db,
                $e->getMessage(),
                self::DEFAULT_DB
            );
        }
        return null;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} host port db user pwd */
    private static function resolve(): array
    {
        [$host, $port, $db] = self::target();
        $user = getenv('JEFFLOW_DB_USER') ?: 'root';
        $pwd = getenv('JEFFLOW_DB_PWD');
        return [$host, $port, $db, $user, $pwd === false ? '' : $pwd];
    }

    /** @return array{0:string,1:string,2:string} */
    private static function target(): array
    {
        return [
            getenv('JEFFLOW_DB_HOST') ?: '127.0.0.1',
            getenv('JEFFLOW_DB_PORT') ?: '3306',
            getenv('JEFFLOW_PDO_TEST_DB') ?: self::DEFAULT_DB,
        ];
    }
}
