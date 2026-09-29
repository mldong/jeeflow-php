<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\ProcessRepositoryInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/138 · `processInstance/ccList` 行形状三要件（基准＝mldong-boot2 内置版，owner 2026-09-29 拍）
 *
 * 条文（jeeflow-doc `docs/spec/06-facade.md` §processInstance/ccList「行形状三要件」，逐字）：
 *   「`ccList` 的每一行**必须来自 `wf_process_instance`**（cc 表只当过滤/关联用），
 *    `operator` 必须是**实例的 `operator`＝流程发起人**，行主键键名必须是 **`id`（实例 id）**」
 *   ⇒ 三个常见错法都算违反契约：① 把 `cc.actor_id`（被抄送人，恒等于查询者）投成 `operator`；
 *      ② 把 `cc.create_user`（发起这次抄送的人）投成 `operator`；
 *      ③ 行主键出 `processInstanceId` 而没有 `id`。
 * 外加同节 :994「rows 同 processInstance/page 行结构」，与 `08-compliance.md` 场景 37
 * （「`operator` 值＝流程发起人且不得等于查询者本人……"非空"判据照不出来 ⇒ 必须比"值对到具体那一方"」）。
 *
 * 本栈修复前的形状（PdoProcessRepository::pageCcInstances 旧行源 `FROM wf_process_cc_instance t`）
 * 正踩错法 ③：主键是 `processInstanceId`、无 `id`，还多一个 cc 表专属的 `actorId`；
 * 内存仓储更彻底 —— 直接返回 cc 行原样，`operator` 这一格根本没有。
 *
 * 判据设计要点：三个种子用户**互不相同**（发起人 / 被抄送人 / 抄送发起人），
 * 于是「operator 非空」这种弱判据被换成分明指向某一方的三条断言 ——
 * 投 cc.actor_id 会撞上"等于查询者"，投 cc.create_user 会撞上"等于抄送发起人"，
 * 两条都在同一格里红。归属过滤本身另用一条诱饵单钉住：被抄送人自己也发过一单（没抄送给任何人），
 * 若过滤列错成 `t.operator`（或整条被丢），那一行会混进来且 operator 恰等于查询者。
 *
 * 两个后端各跑一遍：PDO(SQLite 内存库，与线上 MySQL 同一条 SQL 构造路) + InMemory(轻量形态)。
 */
final class CcListRowShapeTest extends TestCase
{
    /** 流程发起人 ⇒ 实例的 operator，ccList 行里 operator 必须等于它（要件二） */
    private const APPLICANT = 'mldong138_applicant';
    /** 被抄送人 = cc.actor_id = 查询者本人；operator 投成他即错法 ① */
    private const RECEIVER = 'mldong138_receiver';
    /** 发起这次抄送的人 = cc.create_user；operator 投成他即错法 ② */
    private const SENDER = 'mldong138_sender';

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    public function testRowShapeOnPdoSqliteBackend(): void
    {
        $this->assertShape($this->pdoBackend(), 'PDO/SQLite');
    }

    public function testRowShapeOnInMemoryBackend(): void
    {
        $this->assertShape($this->inMemoryBackend(), 'InMemory');
    }

    /**
     * 要件三的第二半：行结构必须与 `processInstance/page` 同构（spec 06:994）。
     * 打在**键名集合**上 —— 只在 ccList 那条路把 `id` 改回 `processInstanceId`（或补回 `actorId`）
     * 立刻红，而不会因为两栈行序差异误报。
     */
    public function testRowKeySetEqualsProcessInstancePageOnBothBackends(): void
    {
        foreach (['PDO/SQLite' => $this->pdoBackend(), 'InMemory' => $this->inMemoryBackend()] as $label => [$facade, $repo]) {
            [$instanceId] = $this->seed($facade, $repo);

            $page = $facade->flow('processInstance/page', ['operator' => self::APPLICANT, 'pageSize' => 50]);
            $this->assertSame(0, $page['code'], $label . ' page: ' . json_encode($page, JSON_UNESCAPED_UNICODE));
            $pageRow = $this->rowById($page['data']['rows'], $instanceId, $label . ' processInstance/page');

            $cc = $facade->flow('processInstance/ccList', ['operator' => self::RECEIVER, 'pageSize' => 50]);
            $this->assertSame(0, $cc['code'], $label . ' ccList: ' . json_encode($cc, JSON_UNESCAPED_UNICODE));
            $ccRow = $this->rowById($cc['data']['rows'], $instanceId, $label . ' processInstance/ccList');

            $this->assertSame($this->sortedKeys($pageRow), $this->sortedKeys($ccRow),
                "{$label}：ccList 行键集合须与 processInstance/page 行键集合逐字相同（spec 06:994）");
        }
    }

    /**
     * 负向：没被抄送的人（这里是发起抄送的那位）查 ccList 不得看到这一行。
     * 挡住"归属谓词被放宽成实例全库"这一类回归 —— 行源换成实例表后，cc 的 JOIN 是唯一收口。
     */
    public function testNonRecipientSeesEmptyPageOnBothBackends(): void
    {
        foreach (['PDO/SQLite' => $this->pdoBackend(), 'InMemory' => $this->inMemoryBackend()] as $label => [$facade, $repo]) {
            $this->seed($facade, $repo);
            $cc = $facade->flow('processInstance/ccList', ['operator' => self::SENDER, 'pageSize' => 50]);
            $this->assertSame(0, $cc['code'], $label . ': ' . json_encode($cc, JSON_UNESCAPED_UNICODE));
            $this->assertSame([], $cc['data']['rows'],
                "{$label}：cc.sender 不是被抄送人，不得出现在 RECEIVER 之外任何人的 ccList 里（spec 06 §2.5 口径表）");
        }
    }

    /**
     * 绕过门面直打仓储：三要件在仓储层同样成立（spec 06:105「只修门面那一层不算修完：仓储仍可被
     * 别的调用方（或下一版门面改动）绕过」）。
     */
    public function testRepositoryLevelShapeWithoutFacadeOnPdoBackend(): void
    {
        [$facade, $repo] = $this->pdoBackend();
        [$instanceId] = $this->seed($facade, $repo);

        $query = new PageQuery(1, 50);
        $query->add('cc.actor_id', 'EQ', self::RECEIVER);
        $rows = $repo->pageCcInstances($query)->getRows();
        $this->assertCount(1, $rows, '前置：归属谓词 cc.actor_id 应只放出被抄送人那一条');
        $this->assertArrayHasKey('id', $rows[0], '仓储层同样须出行主键 id（要件三）');
        $this->assertSame(self::APPLICANT, (string) $rows[0]['operator'],
            '仓储层同样须出实例 operator＝发起人（要件二）');
    }

    // ── 断言主体 ──

    /** @param array{0:JeeflowFacade,1:ProcessRepositoryInterface} $pair */
    private function assertShape(array $pair, string $label): void
    {
        [$facade, $repo] = $pair;
        [$instanceId] = $this->seed($facade, $repo);

        $result = $facade->flow('processInstance/ccList', ['operator' => self::RECEIVER, 'pageSize' => 50]);
        $this->assertSame(0, $result['code'], $label . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        $rows = $result['data']['rows'] ?? [];

        // 前置 + 归属读数：只有"抄送给我"的那一条，被抄送人自己发起的诱饵单不在里面
        $this->assertCount(1, $rows, $label . '：ccList 须只有抄送给 RECEIVER 的那一条实例行');

        foreach ($rows as $i => $row) {
            $where = "{$label} 第 {$i} 行";

            // ── 要件三：行主键键名必须是 id（实例 id）；出 processInstanceId 而无 id 即错法 ③ ──
            $this->assertArrayHasKey('id', $row, $where . ' 缺行主键键名 id（spec 06:998「行主键键名必须是 id（实例 id）」）');
            $this->assertArrayNotHasKey('processInstanceId', $row,
                $where . ' 出现 cc 行主键 processInstanceId —— 行源还是 wf_process_cc_instance，违 spec 06:1001 错法 ③');
            $this->assertArrayNotHasKey('actorId', $row,
                $where . ' 出现 cc 表专属列 actorId —— 行必须来自 wf_process_instance（spec 06:997）');
            $this->assertSame((string) $instanceId, (string) $row['id'], $where . ' id 须是实例 id');

            // ── 要件二：operator＝实例 operator＝流程发起人，且不是查询者、不是抄送发起人 ──
            $this->assertArrayHasKey('operator', $row, $where . ' 缺 operator 键');
            $this->assertSame(self::APPLICANT, (string) $row['operator'],
                $where . ' operator 须等于流程发起人（spec 06:998，08 场景 37「值对到具体那一方」）');
            $this->assertNotSame(self::RECEIVER, (string) $row['operator'],
                $where . ' operator 等于查询者 ⇒ 投的是 cc.actor_id，违 spec 06:999 错法 ①');
            $this->assertNotSame(self::SENDER, (string) $row['operator'],
                $where . ' operator 等于抄送发起人 ⇒ 投的是 cc.create_user，违 spec 06:1000 错法 ②');

            // 实例行的既有键一并钉住（行源=实例表才会有的列）
            foreach (['processDefineName', 'processDefineDisplayName', 'processDefineVersion', 'state', 'createTime'] as $k) {
                $this->assertArrayHasKey($k, $row, $where . " 缺实例行既有键 {$k}（rows 须同 processInstance/page 行结构，spec 06:994）");
            }
        }

        // 镜像判据：同一实例在 processInstance/page 里的 operator 与 ccList 完全一致（都是发起人）
        $page = $facade->flow('processInstance/page', ['operator' => self::APPLICANT, 'pageSize' => 50]);
        $this->assertSame(0, $page['code'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $pageRow = $this->rowById($page['data']['rows'], $instanceId, $label . ' processInstance/page');
        $this->assertSame((string) $pageRow['operator'], (string) $rows[0]['operator'],
            $label . '：ccList 的 operator 须与 processInstance/page 同实例同值（同一个实例列，不是两张表的列）');
    }

    // ── 造数据 ──

    /**
     * @return array{0:string,1:string} [被抄送给 RECEIVER 的实例 id, RECEIVER 自己发起的诱饵实例 id]
     */
    private function seed(JeeflowFacade $facade, ProcessRepositoryInterface $repo): array
    {
        $deploy = $facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => self::APPLICANT,
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $defineId = (string) $deploy['data']['processDefineId'];

        $target = $this->start($facade, $defineId, self::APPLICANT);
        // 诱饵单：查询者自己发起、没抄送给任何人 —— 归属过滤错成 t.operator / 整条丢掉时它会混进来
        $decoy = $this->start($facade, $defineId, self::RECEIVER);

        $cc = $facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $target,
            'actorIds' => [self::RECEIVER],
            'operator' => self::SENDER,
        ]);
        $this->assertSame(0, $cc['code'], json_encode($cc, JSON_UNESCAPED_UNICODE));

        return [$target, $decoy];
    }

    private function start(JeeflowFacade $facade, string $defineId, string $operator): string
    {
        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $defineId, 'operator' => $operator,
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        return (string) $start['data']['processInstanceId'];
    }

    // ── 后端装配 ──

    /** @return array{0:JeeflowFacade,1:ProcessRepositoryInterface} */
    private function pdoBackend(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE wf_process_define (
  id TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, display_name TEXT NOT NULL, type TEXT NULL,
  state INTEGER NULL, content TEXT NULL, version INTEGER NULL, create_time TEXT NULL,
  create_user TEXT NULL, update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_instance (
  id TEXT NOT NULL PRIMARY KEY, parent_id TEXT NULL, process_define_id TEXT NULL, state INTEGER NULL,
  parent_node_name TEXT NULL, business_no TEXT NULL, operator TEXT NULL, expire_time TEXT NULL,
  variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL, update_time TEXT NULL,
  update_user TEXT NULL);
CREATE TABLE wf_process_task (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, task_name TEXT NOT NULL,
  display_name TEXT NOT NULL, task_type INTEGER NULL, perform_type INTEGER NULL, task_state INTEGER NULL,
  operator TEXT NULL, finish_time TEXT NULL, expire_time TEXT NULL, form_key TEXT NULL,
  task_parent_id TEXT NULL, variable TEXT NULL, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
CREATE TABLE wf_process_task_actor (
  id TEXT NOT NULL PRIMARY KEY, process_task_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  create_time TEXT NULL, create_user TEXT NULL);
CREATE TABLE wf_process_cc_instance (
  id TEXT NOT NULL PRIMARY KEY, process_instance_id TEXT NOT NULL, actor_id TEXT NOT NULL,
  state INTEGER NULL DEFAULT 0, create_time TEXT NULL, create_user TEXT NULL,
  update_time TEXT NULL, update_user TEXT NULL);
SQL);
        $repo = new PdoProcessRepository($pdo);
        return [new JeeflowFacade(new JeeflowEngine($repo), $repo), $repo];
    }

    /** @return array{0:JeeflowFacade,1:ProcessRepositoryInterface} */
    private function inMemoryBackend(): array
    {
        $repo = new InMemoryProcessRepository();
        return [new JeeflowFacade(new JeeflowEngine($repo), $repo), $repo];
    }

    // ── 取数辅助 ──

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function rowById(array $rows, string $instanceId, string $where): array
    {
        foreach ($rows as $row) {
            $this->assertArrayHasKey('id', $row, "{$where} 某行缺 id 键");
            if ((string) $row['id'] === $instanceId) return $row;
        }
        $this->fail("{$where} 里找不到实例 {$instanceId} 的行（前置读数丢失，断言会失去鉴别力）");
    }

    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        return $keys;
    }
}
