<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/156 · highLight 模型补全腿在 **PDO 档** + **纯数字参与者 id** 下的回归件。
 *
 * 立案现场：13 栈跨栈门禁第一次在活栈上跑 L2-09，其余 12 栈喂同一份夹具都出
 * `nodeProgress=2节点/2成员/2有名`，laravel 出 `0节点/0成员/0有名`，而同一次运行里
 * laravel 其余 46 格全绿。
 *
 * 现读取证定到的根因（不是案文猜的 A/B 任一条）：
 *   `buildNodeProgress` 用 `$memberSet[$aid] = true` 去重，再 `array_keys()` 取回成员——
 *   PHP 的数组键会把**纯数字串折叠成 int**，`array_keys` 取回来就是 `int`；
 *   SPI 签名是 `getUser(string $userId)` 且门面文件 `declare(strict_types=1)`
 *   ⇒ `TypeError` ⇒ 被 `catch (\Throwable $ignored) {}` 整片吞掉
 *   ⇒ `$nodeProgress` 停在 `[]`，且同一 try 里排在其后的 `collectPath` 也一并没跑（边腿同残）。
 *   真库读回的 actor_id 是 VARCHAR 字符串，但**键折叠发生在门面自己的去重集合里**，
 *   与仓储档位无关 ⇒ 案文"只有内存档测过"只是覆盖假象：内存档那件用的 id 是
 *   `user1/userA/userB` 这类**非数字串**，恰好绕开了折叠。
 *
 * 因此本件钉三件事（缺一条就会再次无声）：
 *   ① nodeProgress 出节点、成员 `id` 出口必须是**字符串**（19 位雪花出 number 会被 JS 截精度，
 *      与 issues/75/92 同族）；
 *   ② IUserProvider 姓名解析腿真的跑到（有名数>0，与门禁 L2-09 义务 3 同尺子）；
 *   ③ 模型边腿与成员腿共用那条 try ⇒ `historyEdgeNames` 非空，用来抓"整块被吞"这种形状。
 */
class PdoSqliteHighLightNodeProgressTest extends TestCase
{
    /** 雪花形纯数字 id：正是会把 PHP 数组键折叠成 int 的那一类 */
    public const APPLICANT = '1777000000000000001';
    public const MEMBER_A = '1777000000000000002';
    public const MEMBER_B = '1777000000000000003';

    private \PDO $pdo;
    private PdoProcessRepository $repo;
    private JeeflowFacade $facade;
    private string $logFile = '';

    /** 连接口留给 MySQL 版子类覆写（同一套判据跑两个档位）。 */
    protected function makePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    protected function createSchema(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  display_name TEXT NOT NULL,
  type TEXT NULL,
  state INTEGER NULL,
  content TEXT NULL,
  version INTEGER NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY,
  parent_id TEXT NULL,
  process_define_id TEXT NULL,
  state INTEGER NULL,
  parent_node_name TEXT NULL,
  business_no TEXT NULL,
  operator TEXT NULL,
  expire_time TEXT NULL,
  variable TEXT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY,
  process_instance_id TEXT NOT NULL,
  task_name TEXT NOT NULL,
  display_name TEXT NOT NULL,
  task_type INTEGER NULL,
  perform_type INTEGER NULL,
  task_state INTEGER NULL,
  operator TEXT NULL,
  finish_time TEXT NULL,
  expire_time TEXT NULL,
  form_key TEXT NULL,
  task_parent_id TEXT NULL,
  variable TEXT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY,
  process_task_id TEXT NOT NULL,
  actor_id TEXT NOT NULL,
  create_time TEXT NULL,
  create_user TEXT NULL
);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY,
  process_instance_id TEXT NOT NULL,
  actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0,
  create_time TEXT NULL,
  create_user TEXT NULL,
  update_time TEXT NULL,
  update_user TEXT NULL
);
SQL);
    }

    protected function setUp(): void
    {
        $this->pdo = $this->makePdo();
        $this->createSchema($this->pdo);
        ServiceContext::clear();
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        $this->registerUserProvider();
        $this->repo = new PdoProcessRepository($this->pdo);
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);
        // 模型腿的可观测记录走 error_log；把目标指到临时文件才能断言"吞之前留了痕"
        $this->logFile = sys_get_temp_dir() . '/jeeflow-156-' . getmypid() . '.log';
        @unlink($this->logFile);
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
        @unlink($this->logFile);
    }

    private function registerUserProvider(): void
    {
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                $names = [
                    PdoSqliteHighLightNodeProgressTest::APPLICANT => '发起人',
                    PdoSqliteHighLightNodeProgressTest::MEMBER_A => '甲',
                    PdoSqliteHighLightNodeProgressTest::MEMBER_B => '乙',
                ];
                return isset($names[$userId])
                    ? ['userId' => $userId, 'realName' => $names[$userId], 'deptId' => null,
                       'deptName' => null, 'postId' => null, 'postName' => null]
                    : null;
            }
        });
    }

    /** 顺序会签两成员（id 全为雪花形数字串），夹具形状对齐门禁 L2-09 那份 linear_flow */
    private function deployFlow(): string
    {
        $model = json_encode([
            'name' => 'issue156-numeric-actor',
            'displayName' => '156 数字参与人高亮',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'properties' => new \stdClass()],
                ['id' => 'apply', 'type' => 'snaker:task',
                 'properties' => ['assignee' => 'applicant', 'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '发起申请']],
                ['id' => 'task1', 'type' => 'snaker:task',
                 'properties' => [
                     'assignee' => self::MEMBER_A . ',' . self::MEMBER_B,
                     'taskType' => 0, 'performType' => 1, 'countersignType' => 'SEQUENTIAL',
                     'field' => ['candidateUsers' => self::MEMBER_A . ',' . self::MEMBER_B],
                 ],
                 'text' => ['value' => '会签审批']],
                ['id' => 'end', 'type' => 'snaker:end', 'properties' => new \stdClass()],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => new \stdClass()],
                ['id' => 'e_apply_1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'task1', 'properties' => new \stdClass()],
                ['id' => 'e2', 'sourceNodeId' => 'task1', 'targetNodeId' => 'end', 'properties' => new \stdClass()],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $deploy = $this->facade->flow('processDefine/deploy', ['content' => $model, 'operator' => self::APPLICANT]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => self::APPLICANT]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE) . "\n" . (string) @file_get_contents($this->logFile));
        return $start['data']['processInstanceId'];
    }

    /**
     * 正向：PDO 档读回的行喂进模型补全腿，nodeProgress 必须出节点、成员 id 出字符串、姓名解析出来；
     * 同一 try 里的边腿也必须跑到（否则整块被吞的形状就又只剩"0 节点"这一句人肉读数）。
     */
    public function testNodeProgressOnNumericActorIdsFromPdoRows(): void
    {
        $instanceId = $this->deployFlow();

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertSame(0, $hl['code'], json_encode($hl, JSON_UNESCAPED_UNICODE));
        $np = $hl['data']['nodeProgress'];

        $this->assertIsArray($np, 'nodeProgress 应为节点字典（空对象形状是"模型腿没产出"的指纹）');
        $this->assertArrayHasKey('apply', $np, 'nodeProgress 应含 apply 节点');
        $this->assertArrayHasKey('task1', $np, 'nodeProgress 应含会签 task1 节点');

        $applyMembers = $np['apply']['members'];
        $this->assertCount(1, $applyMembers);
        // ① 出口形状：id 必须是字符串。修复前这里是 array_keys 折叠出来的 int，
        //    19 位雪花出 number 会被 JS 截精度（issues/75/92 同族）。
        $this->assertSame(self::APPLICANT, $applyMembers[0]['id']);
        // ② SPI 姓名腿真跑到：修复前 TypeError 被吞 ⇒ 整个 nodeProgress 空
        $this->assertSame('发起人', $applyMembers[0]['name'], 'IUserProvider 姓名应解析出来');
        $this->assertTrue($applyMembers[0]['done'] ?? false, 'apply 发起人应 done');

        $this->assertSame('SEQUENTIAL', $np['task1']['type'] ?? null, '顺序会签 type 应=SEQUENTIAL');
        $m1 = $np['task1']['members'];
        $this->assertCount(2, $m1, '会签成员应为甲+乙');
        $this->assertSame(self::MEMBER_A, $m1[0]['id']);
        $this->assertSame('甲', $m1[0]['name']);
        // active 判的是 $mid === $activeActor：修复前 int 与 string 永不相等 ⇒ 标记一起丢
        $this->assertTrue($m1[0]['active'] ?? false, '会签首位（进行中）应 active');
        $this->assertSame(self::MEMBER_B, $m1[1]['id']);
        $this->assertSame('乙', $m1[1]['name']);
        $this->assertArrayNotHasKey('active', $m1[1], '非首位未完成成员不应 active');
        $this->assertArrayNotHasKey('done', $m1[1], '未完成成员不应 done');

        // ③ 边腿与成员腿同一条 try：成员腿抛错时边腿也一并没跑
        $this->assertNotEmpty($hl['data']['historyEdgeNames'], '模型边腿应与 nodeProgress 一起产出');
        $this->assertSame([], $this->swallowLog(), '正常路径不得留下"模型腿失败"记录');
    }

    /**
     * 可观测性（案文 §6.3）：模型腿坏掉时行为不变（仍不高亮、仍返回 code=0），
     * 但**不许再无声**——吞之前必须落一条能定位的记录。
     *
     * 造坏方式选 SPI 抛错（与本案同一条腿）：门面不得因此 500，日志必须留下异常类型与位置。
     */
    public function testModelLegFailureIsLoggedNotSilent(): void
    {
        $instanceId = $this->deployFlow();
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                throw new \RuntimeException('provider 故意炸：模拟 laravel 侧查库异常');
            }
        });

        $hl = $this->facade->flow('processInstance/highLight', ['id' => $instanceId]);
        $this->assertSame(0, $hl['code'], '模型腿坏掉仍应返回成功信封（行为不变）');
        $this->assertSame([], (array) $hl['data']['nodeProgress'], '坏点之后不高亮');

        $log = $this->swallowLog();
        $this->assertNotEmpty($log, '吞掉之前必须落一条可观测记录（issues/156 的"不可见"本身就是缺陷的一半）');
        $this->assertStringContainsString('RuntimeException', $log[0]);
        $this->assertStringContainsString('provider 故意炸', $log[0]);
    }

    /** @return string[] 命中"模型腿"关键字的 error_log 行（其它噪声不算） */
    private function swallowLog(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        return array_values(array_filter($lines, fn($l) => str_contains($l, 'highLight')));
    }
}
