<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * audit8 R81（issues/187 的 php 同形档）——flows 镜像腿的原子性与稳态零写。
 *
 * 旧形状＝`jeeflow-flows-dir.php` 逐份 `copy()`：就地 truncate→write 非原子、且每次都写 ⇒
 * 读侧（隔壁进程）可能撞在半写态上。改后＝稳态零写 + `.tmp.<pid>` + `rename` + 失败响亮抛出。
 *
 * ⚠ 档位按 **php 自己的门票**定，不是照 node/python 抄（案面「范围」列那条纪律）：本栈 phpunit
 * 单进程串行（`phpunit.xml` 只有 `executionOrder`，无并行套件），真实暴露面＝"跑测试的同时
 * 另一个进程也在镜像"。⇒ 三档：
 *   ① 镜像语义（全量复制＋删孤儿＋稳态零写＋无 Java 源静默）；
 *   ② **结构档**：`rename` 不可用时必须响亮抛出且不留临时件——这一档同时是"坏形状探测器"，
 *      旧 `copy()` 忽略返回值 ⇒ 同一场景静默通过（变异对照实测过，见 hub `notes/R81.md`）；
 *   ③ 大文件写入窗口内读者只许看到完整内容（子进程轮询，撕裂计数必须为 0）。
 *
 * 全局函数由 phpunit bootstrap（`tests/bootstrap.php`）引入，这里直接调。
 */
final class FlowsMirrorAtomicR81Test extends TestCase
{
    private const JAVA_REL = 'jeeflow-java/jeeflow-core/src/test/resources/flows';
    private const FILES = ['01-a.json', '02-b.json', '03-c.json', '04-d.json', '05-e.json', '06-f.json'];
    private const PAD = 2097152; // 2MB：真件只有几 KB，窗口太短撞不出东西

    private string $root = '';
    private string $proj = '';
    private string $javaFlows = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/r81-' . bin2hex(random_bytes(4));
        $this->proj = $this->root . '/proj';
        $this->javaFlows = $this->root . '/' . self::JAVA_REL;
        self::mk($this->proj . '/flows');
        self::mk($this->javaFlows);
    }

    protected function tearDown(): void
    {
        self::rm($this->root);
    }

    public function test镜像语义全量复制加删孤儿加稳态零写加无源静默(): void
    {
        file_put_contents($this->javaFlows . '/01-a.json', self::body('a', 64));
        file_put_contents($this->proj . '/flows/orphan.json', '{"id":99}');

        jeeflow_flows_mirror($this->proj);
        self::assertSame(
            self::body('a', 64),
            file_get_contents($this->proj . '/flows/01-a.json'),
            'Java 源应整份镜像'
        );
        self::assertFalse(is_file($this->proj . '/flows/orphan.json'), '孤儿 .json 应被删');

        // 稳态零写：把 mtime 钉到一个过去的时刻，再镜像两次——被重写过就一定不等于它。
        // （不用"两次取 now 比相等"那种写法：Windows 的 mtime 粒度到秒，同秒内改过也看不出来。）
        $ts = 1000000000;
        touch($this->proj . '/flows/01-a.json', $ts);
        clearstatcache();
        jeeflow_flows_mirror($this->proj);
        jeeflow_flows_mirror($this->proj);
        clearstatcache();
        self::assertSame($ts, filemtime($this->proj . '/flows/01-a.json'), '内容相同不得重写');
        self::assertSame([], self::tmps($this->proj . '/flows'), '不该留下临时件');

        // 内容变了要落盘
        file_put_contents($this->javaFlows . '/01-a.json', self::body('b', 64));
        jeeflow_flows_mirror($this->proj);
        self::assertSame(self::body('b', 64), file_get_contents($this->proj . '/flows/01-a.json'), '源变更应镜像落盘');

        // 无 Java 源（用户单仓 / 容器）：静默跳过，不抛不写。
        // ⚠ 得往深里放一层——`jeeflow_flows_mirror()` 认的源是 "<root>/../jeeflow-java/..."，
        //   直接放 $this->root/bare 会命中 setUp 造的那份假源 ⇒ 测的就不是"无源"这一档了。
        $bare = $this->root . '/deep/bare';
        self::mk($bare . '/flows');
        file_put_contents($bare . '/flows/keep.json', '{"id":7}');
        jeeflow_flows_mirror($bare);
        self::assertSame('{"id":7}', file_get_contents($bare . '/flows/keep.json'));
    }

    public function test改名不可用时响亮抛出且不留临时件(): void
    {
        file_put_contents($this->javaFlows . '/01-a.json', self::body('c', 64));
        // 目标名被目录占位 ⇒ rename 必失败。旧形状 `copy()` 返回 false 被忽略＝静默丢夹具，
        // 所以这一档同时是"必须走临时件＋rename"的结构探测器（变异对照见 notes/R81.md）。
        mkdir($this->proj . '/flows/01-a.json');

        $thrown = null;
        try {
            jeeflow_flows_mirror($this->proj);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        self::assertNotNull($thrown, '镜像失败必须抛出，不能静默丢夹具');
        self::assertStringContainsString('镜像', $thrown->getMessage(), '文案要点名是镜像这条腿失败');
        self::assertSame([], self::tmps($this->proj . '/flows'), '抛出前应清掉自己的临时件');
    }

    public function test大文件写入窗口内读者只看到完整内容(): void
    {
        foreach (self::FILES as $f) {
            self::put($this->javaFlows . '/' . $f, self::body('a'));
        }
        jeeflow_flows_mirror($this->proj);

        $reader = $this->root . '/reader.php';
        file_put_contents($reader, <<<'PHP'
<?php
// 子进程读者：像真实测试那样反复读，只分三档计数——完整读到的、内容撕裂的、被拒绝的。
[$self, $dir, $file, $dur, $padLen] = array_pad($argv, 5, '');
$t0 = microtime(true);
$ok = $torn = $denied = 0;
$samples = [];
while (microtime(true) - $t0 < (float) $dur) {
    $raw = @file_get_contents($dir . '/' . $file);
    if ($raw === false) {
        $denied++;
        usleep(2000);
        continue;
    }
    $j = json_decode($raw, true);
    if (is_array($j) && ($j['id'] ?? null) === 1 && strlen((string) ($j['pad'] ?? '')) === (int) $padLen) {
        $ok++;
    } else {
        $torn++;
        if (count($samples) < 2) {
            $samples[] = substr((string) $raw, 0, 40);
        }
    }
    usleep(1000);
}
echo json_encode(['ok' => $ok, 'torn' => $torn, 'denied' => $denied, 'samples' => $samples]);
PHP);

        $all = [];
        for ($i = 0; $i < 2; $i++) {
            $proc = proc_open(
                [PHP_BINARY, $reader, $this->proj . '/flows', '01-a.json', '2.5', (string) self::PAD],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertNotFalse($proc, '读者子进程应起得来');
            $all[] = [$proc, $pipes];
        }

        // 父进程＝写侧：逐轮翻面（内容每轮都变 ⇒ 稳态零写不成立，写入腿真被压到）
        for ($round = 0; $round < 3; $round++) {
            foreach (self::FILES as $f) {
                self::put($this->javaFlows . '/' . $f, self::body($round % 2 === 0 ? 'b' : 'a'));
            }
            jeeflow_flows_mirror($this->proj);
        }

        $okTotal = 0;
        $tornTotal = 0;
        foreach ($all as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), '读者进程应正常退出：' . substr((string) $err, 0, 200));
            $j = json_decode(trim((string) $out), true);
            self::assertIsArray($j, '读者应给出读数：' . substr((string) $out, 0, 200));
            $okTotal += $j['ok'];
            $tornTotal += $j['torn'];
        }

        self::assertGreaterThan(100, $okTotal, "读者得真读到足够多次才构成判据（实读 ok={$okTotal}）");
        self::assertSame(0, $tornTotal, '内容撕裂读数（半写）必须为 0');
        self::assertSame([], self::tmps($this->proj . '/flows'), '并发跑完不得留临时件');
    }

    // ── 夹具小件 ────────────────────────────────────────────────────────────

    private static function body(string $ch, ?int $n = null): string
    {
        return json_encode(['id' => 1, 'pad' => str_repeat($ch, $n ?? self::PAD)], JSON_UNESCAPED_SLASHES);
    }

    /** 源腿自己走原子替换：本用例只审镜像写 dst 这条腿，别让源侧半写混进来（那是假红）。 */
    private static function put(string $path, string $data): void
    {
        $tmp = $path . '.tmp.src';
        file_put_contents($tmp, $data);
        rename($tmp, $path);
    }

    private static function tmps(string $dir): array
    {
        return array_values(array_filter(scandir($dir), static fn ($f) => str_contains((string) $f, '.tmp.')));
    }

    private static function mk(string $dir): void
    {
        mkdir($dir, 0777, true);
    }

    private static function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rm($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
