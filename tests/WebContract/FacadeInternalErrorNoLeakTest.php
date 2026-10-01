<?php

declare(strict_types=1);

namespace Jeeflow\Tests\WebContract;

use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * issues/137 §3-1（php 腿）：门面内部异常出口形状 —— 固定文案 `流程处理失败` ＋ `isForeignDetail` 判别式。
 * 判据形状照 java 基准 `FacadeInternalErrorNoLeakTest`（走真实调用路径，不是只测纯函数）：
 *
 * - **负向**＝运行时/驱动/JSON 解析器/裸包装/第三方 provider 的原文不得进 msg，只能进
 *   **日志与 previous 链**（`logInternalFailure` 的 error_log ＋ `JeeflowException::getPrevious()`，
 *   两侧都断言——只断言 msg 的话，"把原文整个丢掉"也能绿）；
 * - **正向/回归**＝引擎写的中文契约文案必须**逐字**留在 msg。本栈普查（2026-10-02，各 packages 包
 *   src/ 下全部 29 处 throw）证实契约文案**不是**全走 `JeeflowException`：ProcessTask/ProcessInstance
 *   的领域守卫、ModelParser「读取流程定义 JSON 失败」、门面 requireExt/idListArgs 都是裸
 *   `\RuntimeException`/`\InvalidArgumentException` 携带契约文案 ⇒ 判据落满 java 同款五条，
 *   收窄成"只透契约异常族/一律固定文案"都会静默改写契约面（改前红样见批三 §3-1 落地记录）。
 *
 * spec 依据：06-facade.md §2.12（判别式五条）＋「失败 msg 跨栈统一文案」表末行。
 */
class FacadeInternalErrorNoLeakTest extends TestCase
{
    private ThrowingRepo $repo;
    private CapturingFacade $facade;
    private string $logFile = '';
    private string $prevLogIni = '';

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->repo = new ThrowingRepo();
        $this->facade = new CapturingFacade(new JeeflowEngine($this->repo), $this->repo);
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });
        // error_log 重定向到临时文件：cause 分离的"日志"那一半要能读到原文（error_log ini 是 ALL，可运行时改）
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'jeeflow-noLeak-');
        $this->prevLogIni = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLogIni);
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
        ServiceContext::clear();
    }

    // ── 夹具 ──

    private function callPage(): array
    {
        return $this->facade->flow('processInstance/page', ['operator' => 'user1', 'pageNum' => 1, 'pageSize' => 10]);
    }

    /** 让 pageInstances 抛指定异常后走一次真实门面调用（java 基准的 Proxy throwing repo 等价物） */
    private function callPageWithThrow(\Throwable $t): array
    {
        $this->repo->toThrow = $t;
        $this->facade->internalFailures = [];
        return $this->callPage();
    }

    private function logContents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /**
     * 负向公共断言：出口 msg **逐字**等于固定文案 ＋ 原文各片段不得进 msg ＋
     * 原文对象确实进了 previous 链与 error_log（两侧缺一不可）。
     *
     * @param string[] $forbidden
     */
    private function assertLeakSealed(array $resp, \Throwable $thrown, array $forbidden, string $logMarker, string $action = 'processInstance/page'): void
    {
        $this->assertSame(99999999, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame(JeeflowFacade::INTERNAL_FAILURE_MSG, $resp['msg'], '出口 msg 必须逐字等于固定文案');
        $this->assertSame('流程处理失败', $resp['msg'], '固定文案八栈逐字，措辞不许漂');
        $this->assertNull($resp['data']);
        foreach ($forbidden as $marker) {
            $this->assertStringNotContainsString($marker, (string) $resp['msg'], '内部文案不得进 msg，泄漏了：' . $marker);
        }
        $this->assertCount(1, $this->facade->internalFailures, '内部失败恰好走一次 logInternalFailure 副作用出口');
        $entry = $this->facade->internalFailures[0];
        $this->assertSame($action, $entry['action'], '日志要指出是哪个 action');
        $this->assertSame($thrown, $entry['wrapped']->getPrevious(), '原异常对象进包装异常的 previous 链');
        $this->assertStringContainsString($logMarker, $this->logContents(), '原文要进 error_log');
    }

    /** 部署 01-simple 并发起 ⇒ 停在 task1（参与者＝leader），同 RemoveTaskActor115Test 夹具 */
    private function startTask(): string
    {
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $doing = $this->repo->findDoingTasks($start['data']['processInstanceId']);
        $this->assertCount(1, $doing, '前置：实例应停在一个进行中任务');
        return $doing[0]->getTaskId();
    }

    // ═══ 负向：内部/外来异常原文不得进 msg（判别式第 1/3/4/5 条各有活体） ═══

    public function testTypeErrorTextKeepsInternalsOutOfMsg(): void
    {
        $t = new \TypeError('Jeeflow\Core\Spi\PageQuery::add(): Argument #2 ($value) must be of type string, null given');
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['Argument #2', 'must be of type', 'TypeError', 'PageQuery'], 'must be of type string');
    }

    public function testValueErrorTextKeepsInternalsOutOfMsg(): void
    {
        // PHP 8 数字/类型错（java NumberFormatException「For input string」的本栈等价档）
        $t = new \ValueError('A non-numeric value encountered');
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['non-numeric', 'ValueError'], 'A non-numeric value encountered');
    }

    public function testDivisionByZeroErrorTextKeepsInternalsOutOfMsg(): void
    {
        $t = new \DivisionByZeroError('Division by zero');
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['Division by zero', 'DivisionByZeroError'], 'Division by zero');
    }

    public function testErrorWithEmptyMessageFallsToFixedText(): void
    {
        // 规则 1 档：message 为空 ⇒ 旧形状 `?: (string) $e` 的兜底会吐「Error in …php:NN + Stack trace」
        $t = new \Error();
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['Error', 'Stack trace', '.php'], 'Error in ');
    }

    public function testJsonDecodeFailureTextKeepsInternalsOutOfMsg(): void
    {
        // json_decode(JSON_THROW_ON_ERROR) 的解析器原文形状
        $t = new \JsonException('Syntax error');
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['Syntax error', 'JsonException'], 'Syntax error');
    }

    public function testPdoDriverExceptionTextKeepsInternalsOutOfMsg(): void
    {
        // 驱动族（java SQLException 档）。注意 PDOException 继承 \RuntimeException——
        // 若判别式漏掉它，规则 5 之外没有任何一条能拦，这条就是防回归钉。
        $t = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['SQLSTATE', '2002', 'Connection refused'], 'SQLSTATE[HY000]');
    }

    public function testBareWrapperKeepsCauseTextOutOfMsg(): void
    {
        // 规则 3：message === cause 原文（裸包装只是搬运下层原文，引擎没写过它）
        $cause = new \LogicException('内部驱动细节 12345');
        $bare = new \RuntimeException($cause->getMessage(), 0, $cause);
        $r = $this->callPageWithThrow($bare);
        $this->assertLeakSealed($r, $bare, ['12345', '内部驱动细节'], '内部驱动细节 12345');
        $this->assertSame($cause, $this->facade->internalFailures[0]['wrapped']->getPrevious()->getPrevious(),
            'previous 链完整保留（wrapped → bare → cause）');

        // 规则 3 另一形：message === (string) cause（含类名/文件/栈的字符串化搬运）
        $cause2 = new \LogicException('驱动原文 67890');
        $bare2 = new \RuntimeException((string) $cause2, 0, $cause2);
        $r2 = $this->callPageWithThrow($bare2);
        $this->assertLeakSealed($r2, $bare2, ['67890', 'LogicException', 'Stack trace'], '驱动原文 67890');
    }

    public function testThirdPartyAndAnonymousThrowsAreSealed(): void
    {
        // 规则 5 活体一：非引擎命名空间的类里抛的（集成方 provider 形状）——抛出点在测试命名空间桩里，
        // trace[0]['class'] 带 Jeeflow\Tests\ 前缀 ⇒ 排除档命中（纯函数矩阵另测完全外来命名空间）
        $third = new ThirdPartyProviderStub();
        try {
            $third->getUser('u1');
            $this->fail('夹具应抛异常');
        } catch (\Throwable $t) {
            // 重新抛出保留原 trace（php 异常 trace 在构造时定格）
        }
        $r = $this->callPageWithThrow($t);
        $this->assertLeakSealed($r, $t, ['provider db down', '10.0.0.1', '3306'], 'provider db down at 10.0.0.1');

        // 规则 5 活体二：匿名类（class@anonymous… 不属引擎命名空间）
        $anon = new class {
            public function bad(): void
            {
                throw new \RuntimeException('anonymous internal detail ABCDEF');
            }
        };
        try {
            $anon->bad();
            $this->fail('夹具应抛异常');
        } catch (\Throwable $t2) {
        }
        $r2 = $this->callPageWithThrow($t2);
        $this->assertLeakSealed($r2, $t2, ['ABCDEF', 'anonymous'], 'anonymous internal detail ABCDEF');
    }

    // ═══ 正向/回归：引擎契约文案逐字透出，没被收窄 ═══

    public function testContractExceptionMessageStillPassesThroughVerbatim(): void
    {
        // 规则 2：JeeflowException（契约异常族）⇒ 逐字透出，即便抛出点在测试桩仓储里
        foreach (['任务不存在', 'operator 必填', '流程定义不存在', '刷新令牌已失效或不存在'] as $text) {
            $biz = new JeeflowException($text);
            $r = $this->callPageWithThrow($biz);
            $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertSame($text, $r['msg'], '契约文案必须逐字留在 msg');
            $this->assertSame([], $this->facade->internalFailures, '引擎契约文案不该被记成内部异常日志');
        }
    }

    public function testEngineBareRuntimeExceptionContractTextStillPassesThrough(): void
    {
        // java 基准 engineIllegalStateOutsideContractTypeStillPassesThrough 的同款活证：
        // requireExt() 抛的是裸 \RuntimeException('未接入 IProcessExtRepository…')，抛出点在引擎门面类内
        // ⇒ 文案照旧逐字透出。改成固定文案就会打断集成方排障与其余栈的逐字对齐。
        $noExt = new CapturingFacade(new JeeflowEngine($this->repo), $this->repo, null);
        $r = $noExt->flow('processDesign/page', ['operator' => 'user1', 'pageNum' => 1, 'pageSize' => 10]);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame('未接入 IProcessExtRepository，设计/委托 action 不可用', $r['msg']);
        $this->assertSame([], $noExt->internalFailures, '引擎自己写的文案不该被记成内部异常日志');

        // 同款另一腿：idListArgs() 的裸 \InvalidArgumentException('id 缺失或非法')
        $r2 = $this->facade->flow('processDefine/remove', []);
        $this->assertSame(99999999, $r2['code'], json_encode($r2, JSON_UNESCAPED_UNICODE));
        $this->assertSame('id 缺失或非法', $r2['msg']);
        $this->assertSame([], $this->facade->internalFailures);
    }

    public function testModelParserJsonFailureTextStillPassesThrough(): void
    {
        // ModelParser:70 裸 \RuntimeException('读取流程定义 JSON 失败')——引擎写的契约文案，
        // 走真实 deploy 路径进顶层 catch 后必须逐字透出（判别式过宽的第一红样位）
        $r = $this->facade->flow('processDefine/deploy', ['content' => '{not-valid-json', 'operator' => 'user1']);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame('读取流程定义 JSON 失败', $r['msg']);
        $this->assertSame([], $this->facade->internalFailures);
    }

    public function testSpecFailureTextsStayVerbatim(): void
    {
        // spec 06「失败 msg 跨栈统一文案」表逐字回归：这些腿走 error() 直返不经 catch，
        // 一并钉住——判别式接线不得误伤常规错误信封
        $r = $this->facade->flow('processInstance/withdraw', ['id' => '404404']);
        $this->assertSame(99999999, $r['code']);
        $this->assertSame('operator 必填', $r['msg']);

        $r = $this->facade->flow('processDefine/detail', ['id' => '404404']);
        $this->assertSame('流程定义不存在', $r['msg']);

        $r = $this->facade->flow('processTask/removeTaskActor', [
            'processTaskId' => '404404', 'actorIds' => ['x'], 'operator' => 'x',
        ]);
        $this->assertSame('任务不存在', $r['msg']);

        // 需要真实现场的两条：非进行中摘除 / 摘空
        $taskId = $this->startTask();
        $finish = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'submitType' => SubmitType::AGREE,
        ]);
        $this->assertSame(0, $finish['code'], json_encode($finish, JSON_UNESCAPED_UNICODE));
        $r = $this->facade->flow('processTask/removeTaskActor', [
            'processTaskId' => $taskId, 'actorIds' => ['leader'], 'operator' => 'flow.admin',
        ]);
        $this->assertSame(99999999, $r['code']);
        $this->assertSame('任务非进行中，不可摘除参与人', $r['msg']);

        $taskId2 = $this->startTask();
        $r = $this->facade->flow('processTask/removeTaskActor', [
            'processTaskId' => $taskId2, 'actorIds' => ['leader'], 'operator' => 'leader',
        ]);
        $this->assertSame(99999999, $r['code']);
        $this->assertSame('至少需保留一名参与人', $r['msg']);
    }

    // ═══ bizData 腿（覆盖面②）：前缀是契约文案、原文拼接是泄漏 ═══

    public function testBizDataLegSealsDriverText(): void
    {
        // 对齐 java（137-G）/csharp（批二 dca1b33）：msg 只留契约固定文案「业务数据读取失败」，
        // 不带冒号不带原文；reader/驱动原文只进日志与 previous 链
        $json = json_decode(file_get_contents(jeeflow_flows_dir() . '/01-simple.json'), true);
        $json['relTableName'] = 'biz_leave';
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => json_encode($json, JSON_UNESCAPED_UNICODE), 'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = $start['data']['processInstanceId'];

        $thrown = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'biz_leave' doesn't exist");
        ServiceContext::put('metaTableReader', new class ($thrown) {
            public function __construct(private \Throwable $t) {}
            public function readByProcessInstance(string $tableName, mixed $processInstanceId): array
            {
                throw $this->t;
            }
        });

        $r = $this->facade->flow('processInstance/bizData', ['processInstanceId' => $instanceId]);
        $this->assertSame(99999999, $r['code'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame('业务数据读取失败', $r['msg'], 'msg 逐字＝跨栈契约文案，不得拼原文');
        foreach (['SQLSTATE', '42S02', '1146', 'biz_leave', ':'] as $marker) {
            $this->assertStringNotContainsString($marker, $r['msg'], '驱动原文/拼接痕迹不得进 msg：' . $marker);
        }
        $this->assertCount(1, $this->facade->internalFailures);
        $entry = $this->facade->internalFailures[0];
        $this->assertSame('processInstance/bizData', $entry['action']);
        $this->assertSame($thrown, $entry['wrapped']->getPrevious(), '原异常进 previous 链');
        $this->assertStringContainsString('SQLSTATE[42S02]', $this->logContents(), '原文要进 error_log');
    }

    // ═══ 判别式纯函数矩阵（文案判据独立可测，五条各钉两侧） ═══

    public function testIsForeignDetailPureFunctionMatrix(): void
    {
        $engineTrace = [['class' => 'Jeeflow\\Core\\Domain\\ProcessTask', 'function' => 'complete']];
        $thirdTrace = [['class' => 'Vendor\\Mod\\Thing', 'function' => 'run']];
        $testTrace = [['class' => 'Jeeflow\\Tests\\WebContract\\ThrowingRepo', 'function' => 'pageInstances']];
        $anonTrace = [['class' => "class@anonymous\x00/tmp/x.php:1", 'function' => 'bad']];

        // 规则 1：message null/空串 ⇒ 内部（即便类型是契约族——顺序即优先级）
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, null, null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '', null, $engineTrace));

        // 规则 2：契约异常族 ⇒ 透出（优先于规则 3/5：空 trace、带 cause 都不影响）
        $this->assertFalse(JeeflowFacade::isForeignDetail(JeeflowException::class, 'x', null, []));
        $this->assertFalse(JeeflowFacade::isForeignDetail(JeeflowException::class, 'x', new \LogicException('x'), []));

        // 规则 3：裸包装（message 恰等于 cause 原文 / cause 字符串化）
        $cause = new \LogicException('cause 原文');
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, 'cause 原文', $cause, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, (string) $cause, $cause, $engineTrace));
        // 反例：引擎写了**自己的**文案再挂 cause ⇒ 不是裸包装，照旧透出
        $this->assertFalse(JeeflowFacade::isForeignDetail(\RuntimeException::class, '读取流程定义 JSON 失败', new \JsonException('Syntax error'), $engineTrace));

        // 规则 4：运行时/驱动族即便"抛在引擎命名空间里"也是内部（php 等价类型逐一对）
        $this->assertTrue(JeeflowFacade::isForeignDetail(\TypeError::class, 'must be of type string', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\ValueError::class, 'A non-numeric value encountered', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\DivisionByZeroError::class, 'Division by zero', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\ArithmeticError::class, 'modulo by zero', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\Error::class, 'Call to a member function getId() on null', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\PDOException::class, 'SQLSTATE[HY000]', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\JsonException::class, 'Syntax error', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\ReflectionException::class, 'Method x() does not exist', null, $engineTrace));

        // 规则 5：归属判别——引擎命名空间透出；第三方/测试桩/匿名类/来路不明（空 trace 且无文件）一律内部
        $this->assertFalse(JeeflowFacade::isForeignDetail(\RuntimeException::class, '引擎契约文案', null, $engineTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '第三方文案', null, $thirdTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '测试桩文案', null, $testTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '匿名类文案', null, $anonTrace));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '来路不明', null, []));
        // 命名空间函数腿（无 class 键）
        $this->assertFalse(JeeflowFacade::isForeignDetail(\RuntimeException::class, 'x', null,
            [['function' => 'Jeeflow\\Core\\Util\\someHelper']]));

        // 闭包/顶层抛出的文件兜底：trace[0]['file'] 是调用方文件不可用，getFile() 归属
        $engineFile = (new \ReflectionClass(JeeflowException::class))->getFileName();
        $this->assertIsString($engineFile);
        $this->assertFalse(JeeflowFacade::isForeignDetail(\RuntimeException::class, '闭包契约文案', null,
            [['function' => '{closure:/x.php:1}']], $engineFile));
        $this->assertTrue(JeeflowFacade::isForeignDetail(\RuntimeException::class, '闭包第三方文案', null,
            [['function' => '{closure:/x.php:1}']], sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vendor-thing.php'));
    }
}

/** 按需抛出的内存仓：pageInstances 被点名先抛 $toThrow（其余方法原样委托，同 RemoveTaskActor115Test 的 spy 姿势） */
class ThrowingRepo extends InMemoryProcessRepository
{
    public ?\Throwable $toThrow = null;

    public function pageInstances(PageQuery $query): PageResult
    {
        if ($this->toThrow !== null) {
            throw $this->toThrow;
        }
        return parent::pageInstances($query);
    }
}

/** 捕获副作用出口的门面子类：logInternalFailure 记录 wrapped（含 previous 链），"原文进了日志/previous"才可断言 */
class CapturingFacade extends JeeflowFacade
{
    /** @var list<array{action:string, wrapped:JeeflowException}> */
    public array $internalFailures = [];

    protected function logInternalFailure(string $action, JeeflowException $wrapped): void
    {
        $this->internalFailures[] = ['action' => $action, 'wrapped' => $wrapped];
        parent::logInternalFailure($action, $wrapped);   // 真发 error_log（setUp 已重定向到临时文件）
    }
}

/** 「第三方 provider 抛的东西」夹具：测试命名空间（Jeeflow\Tests\）⇒ 判别式第 5 条排除档 */
class ThirdPartyProviderStub
{
    public function getUser(string $id): void
    {
        throw new \RuntimeException('provider db down at 10.0.0.1:3306/userdb');
    }
}
