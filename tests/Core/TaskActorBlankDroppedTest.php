<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\ProcessTask;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\Core\Util\CcActorUtil;
use Jeeflow\WebContract\JeeflowFacade;
use PHPUnit\Framework\TestCase;

/**
 * 任务参与者写侧归属值归一（issues/142 B 批 · spec 06-facade.md §2.11 · PHP 栈，内存仓一路）。
 *
 * 条文逐字（§2.11＝§2.10 的四点实现要求搬到任务侧）：
 * ① **两层都挡**——门面腿（addCandidate/surrogate/transfer 解析集合时归一）＋ 仓储写侧
 *    （`addTaskActor` 自己也丢），只修门面则绕过门面直连仓储的调用方照样能灌空值；
 * ② **落库与比较一律取 trim 后的值**（`" 123 "` 与 `"123"` 是同一个人，不 trim 会与写侧判重错开，
 *    同一人落两行）；
 * ③ 空入参档**沿用本仓既有的"缺参数"错误信封**（`actorIds 缺失` / `processTaskId 缺失或非法`，
 *    码 99999999），不新造错误码或文案——本栈旧形状在 `actorIds` 为空时返回 **code=0 成功**
 *    （八栈独一份，§2.11 ③ 点名），属违反本条，本件钉成报错；
 * ④ **反向哨兵**：`'0'`／`'00'`／`'1'`／`'01'` 是**四个不同的人**，谁都不许被吃掉或被折叠，
 *    且严禁用语言自带的假值判据判空——本栈旧串腿 `array_filter(array_map('trim', explode(...)))`
 *    不带回调＝假值判据，实测吃掉 `'0'`（§2.10 要求④在这条腿上失守）。
 *
 * 主键类参数另判一档（§2.11 末段）：`processTaskId` 缺失/空串必须响亮报错，不得拿 `''` 当 id
 * 往下落库；旧 `JeeflowFacade::taskSurrogate` 完全不校验 taskId，`addTaskActor('', …)` 照跑。
 *
 * 判据**复用 §2.10 已落地的那一枚单点**（`CcActorUtil`，本轮补出通用入口
 * {@see CcActorUtil::normalizeActors()} / {@see CcActorUtil::normalizeActor()}），
 * cc 支继续走同一枚——两份判据迟早分叉（本栈上一轮实测到"归一函数严格比较、仓储写侧却用
 * 松散 in_array ⇒ `'0' == '00'` 把第二个人静默丢掉"，已由 commit a45dd13 收口）。
 *
 * SQL 仓一路见 `Jeeflow\Tests\RepositoryPDO\PdoSqliteTaskActorBlankTest`，
 * 两仓必须给同一个答案（issues/117 场景 27）。
 */
final class TaskActorBlankDroppedTest extends TestCase
{
    private InMemoryProcessRepository $repo;
    private JeeflowFacade $facade;

    /** 第三方自实现仓储姿势的替身：写侧**不归一**，好让门面腿单独可观测。 */
    private RawTaskActorSpyRepository $spy;
    private JeeflowFacade $spyFacade;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
            public function required(callable $action): mixed { return $action(); }
        });

        $this->repo = new InMemoryProcessRepository();
        $this->facade = new JeeflowFacade(new JeeflowEngine($this->repo), $this->repo);

        $this->spy = new RawTaskActorSpyRepository();
        $this->spyFacade = new JeeflowFacade(new JeeflowEngine($this->spy), $this->spy);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ── 夹具辅助 ──

    /**
     * 部署 01-simple 并起一单，返回 task1（参与者＝leader）的 taskId。
     * 走门面而不是手写任务行，保证"实测的就是那条 action 腿"。
     */
    private function task1Id(JeeflowFacade $facade, InMemoryProcessRepository $repo): string
    {
        $deploy = $facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/01-simple.json'),
            'operator' => 'user1',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $facade->flow('processDefine/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'user1',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));

        foreach ($repo->findDoingTasks((string) $start['data']['processInstanceId']) as $task) {
            if ($task->getTaskName() === 'task1') {
                $this->assertSame(['leader'], $task->getActorIds(), '前置：task1 参与者只有 leader');
                return (string) $task->getTaskId();
            }
        }
        $this->fail('前置：应有 task1 待办');
    }

    private function task(string $taskId): ProcessTask
    {
        $t = $this->repo->findTaskById($taskId);
        $this->assertNotNull($t, "任务 {$taskId} 应读得到");
        return $t;
    }

    /** @return string[] 该任务落库的参与者序列（取证走仓储读回值，不断返回码） */
    private function actorsOf(string $taskId): array
    {
        return $this->task($taskId)->getActorIds();
    }

    // ═══ 归一单点本体（§2.10 那一枚，本轮补出通用入口） ═══

    /** 逗号串与数组**两形同判据**：trim → 空串/纯空白/null 丢弃 → 同次调用折叠 → 连续下标。 */
    public function testNormalizeActorsTwoFormsShareOneRule(): void
    {
        $this->assertSame(['a', 'b'], CcActorUtil::normalizeActors(' a ,, b '), '串腿：逐项 trim＋丢空');
        $this->assertSame(['a', 'b'], CcActorUtil::normalizeActors([' a ', '', '  ', 'b']), '数组腿：同一条尺子');
        $this->assertSame(CcActorUtil::normalizeActors(['a', 'b']), CcActorUtil::normalizeActors('a,,b'),
            '两形必须给出逐字相同的答案（§2.11 表第一行：不得只修一条腿）');
        $this->assertSame(['a', 'b'], CcActorUtil::normalizeActors(['a', 'b', ' a ', 'b']),
            '同一次调用内的重复折叠（trim 后同值算同人），顺序保持');
        $this->assertSame([], CcActorUtil::normalizeActors(null), 'null ⇒ 空集（不串化成 "null"）');
        $this->assertSame([], CcActorUtil::normalizeActors(''), '空串 ⇒ 空集（explode 那一个空元素也丢）');
        $this->assertSame([], CcActorUtil::normalizeActors('   '), '纯空白 ⇒ 空集');
        $this->assertSame([], CcActorUtil::normalizeActors(['', '  ', null]), '全空元素 ⇒ 空集');
        $this->assertSame([0, 1], array_keys(CcActorUtil::normalizeActors(['a', '', '  ', 'b'])),
            '返回值必须连续下标（旧 array_filter 腿留键洞，json 会把数组变对象）');
    }

    /** 反向哨兵（实现要求④）：'0'／'00'／'1'／'01' 是四个人，谁都不许被吃掉或折叠。 */
    public function testNormalizeActorsKeepsLookAlikeBlankSentinels(): void
    {
        $this->assertSame(['0'], CcActorUtil::normalizeActors('0'),
            "本栈旧串腿 array_filter() 无回调＝假值判据，会把 '0' 吃掉");
        $this->assertSame(['0', '00', '1', '01'], CcActorUtil::normalizeActors('0,00,1,01'),
            '逗号串形态：四个都是有效 id，不得两两折叠');
        $this->assertSame(['0', '00', '1', '01'], CcActorUtil::normalizeActors(['0', '00', 1, '01']),
            '数组形态同判据（数字元素收敛成串，不静默丢、不串化成类型名）');
        $this->assertSame(CcActorUtil::normalize('0,00,1,01'), CcActorUtil::normalizeActors('0,00,1,01'),
            'cc 支与任务支共用同一枚判据 ⇒ 同一答案');
    }

    /** 标量支（transfer 的 fromActor/toActor 用这一支）：与集合支同一条判空尺子。 */
    public function testNormalizeActorScalarLeg(): void
    {
        $this->assertSame('user1', CcActorUtil::normalizeActor(' user1 '), '落库与比较取 trim 后的值');
        $this->assertSame('0', CcActorUtil::normalizeActor('0'), "反向哨兵：'0' 不是空值");
        $this->assertSame('1', CcActorUtil::normalizeActor(1), 'int 转串后存活');
        $this->assertSame('', CcActorUtil::normalizeActor(''), '空串 ⇒ 空');
        $this->assertSame('', CcActorUtil::normalizeActor('   '), '纯空白 ⇒ 空');
        $this->assertSame('', CcActorUtil::normalizeActor(null), 'null ⇒ 空（不串化成 "null"）');
        $this->assertSame('', CcActorUtil::normalizeActor(['a']), '数组不是合法标量归属值 ⇒ 空');
    }

    // ═══ 门面加签腿：两形同判据（写侧换成不归一的替身仓储，让门面层单独有牙） ═══

    /** 逗号串腿：trim＋丢空＋折叠，递给仓储的必须是归一后的集合。 */
    public function testSurrogateCommaStringFormIsNormalizedInFacadeLeg(): void
    {
        $taskId = $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        $resp = $this->spyFacade->flow('processTask/surrogate', [
            'processTaskId' => $taskId, 'actorIds' => ' 9001 ,, 9002 , 9001 ',
        ]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame([['9001', '9002']], $this->spy->received,
            '§2.11②：递给仓储的是 trim＋丢空＋折叠后的集合，不是原始串拆出来的那串');
    }

    /** 数组腿与串腿同判据（本栈旧形状：串腿过 array_filter、数组腿原样直连仓储）。 */
    public function testSurrogateArrayFormSharesTheSameRule(): void
    {
        $taskId = $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        $resp = $this->spyFacade->flow('processTask/surrogate', [
            'processTaskId' => $taskId, 'actorIds' => [' 9001 ', '', '  ', null, '9002'],
        ]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame([['9001', '9002']], $this->spy->received,
            '§2.11：数组腿不得原样直连仓储——两形同判据');
    }

    /** addCandidate 与 surrogate 同体（同一个 taskSurrogate 实现），判据必须一字不差。 */
    public function testAddCandidateIsTheSameBodyAsSurrogate(): void
    {
        foreach (['processTask/surrogate', 'processTask/addCandidate'] as $action) {
            $taskId = $this->task1Id($this->spyFacade, $this->spy);
            $this->spy->reset();

            $empty = $this->spyFacade->flow($action, ['processTaskId' => $taskId, 'actorIds' => ['', '  ']]);
            $this->assertSame(99999999, $empty['code'],
                $action . '：丢完为空必须报错（本栈旧形状 code=0 成功）');
            $this->assertSame([], $this->spy->received, $action . '：空集合不得递进仓储');

            $ok = $this->spyFacade->flow($action, ['processTaskId' => $taskId, 'actorIds' => ' 9301 ,']);
            $this->assertSame(0, $ok['code'], $action . '：有效集合照旧成功');
            $this->assertSame([['9301']], $this->spy->received, $action . '：判据与 surrogate 同一条');
        }
    }

    // ═══ 错误语义缺口①：空 actorIds 不再返回 code=0（八栈独一份的旧形状） ═══

    /**
     * 空集合／全空白／键整个缺席／给标量 ⇒ 一律落进"缺参数"档，**响应逐字同判**
     * （实现要求③：沿用既有信封 `actorIds 缺失`＋码 99999999，不新造错误码或文案）。
     */
    public function testEmptyActorIdsIsAnErrorNotSuccess(): void
    {
        $taskId = $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        $ref = null;
        foreach ([[], ['', '   '], null, '', '   ', 0, false] as $raw) {
            $resp = $this->spyFacade->flow('processTask/surrogate',
                ['processTaskId' => $taskId, 'actorIds' => $raw]);
            $this->assertSame(99999999, $resp['code'],
                '§2.11③：空 actorIds 必须报错（旧形状 code=0 成功是八栈独一份）：' . var_export($raw, true));
            $this->assertSame('actorIds 缺失', $resp['msg'], '沿用既有"缺参数"文案，不新造错误语义');
            if ($ref === null) $ref = $resp;
            $this->assertSame($ref, $resp, '各空档必须逐字同判');
        }

        $this->assertSame([], $this->spy->received, '空档一律不得递进仓储');
        $this->assertSame([], $this->spy->rawRows, '空档不得落任何参与者行');
    }

    /** 键整个缺席（`actorIds` 都不给）与给空集合同档。 */
    public function testAbsentActorIdsKeyIsTheSameErrorSlot(): void
    {
        $taskId = $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        $absent = $this->spyFacade->flow('processTask/surrogate', ['processTaskId' => $taskId]);

        $this->assertSame($this->spyFacade->flow('processTask/surrogate',
            ['processTaskId' => $taskId, 'actorIds' => []]), $absent, '缺席与空集合同判（逐字同响应）');
        $this->assertSame([], $this->spy->received);
    }

    /** 哨兵反例：串腿的 `'00'` 是有效 id，不得被"空 actorIds"档吃掉（与上面的报错档互为对照）。 */
    public function testDoubleZeroActorIsNotTreatedAsMissingParameter(): void
    {
        $taskId = $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        $resp = $this->spyFacade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => '00']);

        $this->assertSame(0, $resp['code'],
            "§2.11④反向哨兵：'00' 不得被当空值判成缺参数：" . json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame([['00']], $this->spy->received);
    }

    // ═══ 错误语义缺口②：processTaskId 缺失/空串 ⇒ 响亮报错（主键档） ═══

    /**
     * 主键类参数另判一档（§2.11 末段）：与"归属值为空 ⇒ 丢弃"是两件事。
     * 旧形状 `JeeflowFacade::taskSurrogate` 完全不校验 taskId，`addTaskActor('', …)` 照跑。
     */
    public function testMissingTaskIdFailsLoudlyAndNeverTouchesRepository(): void
    {
        $this->task1Id($this->spyFacade, $this->spy);
        $this->spy->reset();

        foreach ([null, '', '   '] as $raw) {
            $args = ['actorIds' => ['9401']];
            if ($raw !== null) $args['processTaskId'] = $raw;
            $resp = $this->spyFacade->flow('processTask/surrogate', $args);
            $this->assertSame(99999999, $resp['code'],
                '主键档：processTaskId 缺失/空串必须响亮报错：' . var_export($raw, true));
            $this->assertSame('processTaskId 缺失或非法', $resp['msg'], '沿用既有主键档文案，不新造');
        }

        $this->assertSame([], $this->spy->received, '主键为空 ⇒ 一次仓储都不许调');
        $this->assertSame([], $this->spy->rawRows, "不得把参与者行钉在 process_task_id='' 上");
    }

    // ═══ 正向落库面（真内存仓）：哨兵四人 + trim 与判重咬合 ═══

    /** §2.11④＋①端到端（数组腿）：混给空值时四人全部落库，空值一个不进。 */
    public function testSentinelFourActorsLandEndToEndFromArrayForm(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $resp = $this->facade->flow('processTask/surrogate', [
            'processTaskId' => $taskId, 'actorIds' => ['0', '', '00', '  ', '1', null, '01'],
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));

        $this->assertSame(['leader', '0', '00', '1', '01'], $this->actorsOf($taskId),
            '四个人都是有效归属值（松散比较会把 \'0\'==\'00\'、\'1\'==\'01\' 折掉），空值不进 actor_id');
    }

    /** §2.11④＋①端到端（串腿）：本栈旧 array_filter 假值判据在这里吃掉 '0'（实测形状）。 */
    public function testSentinelZeroActorSurvivesTheCommaStringLeg(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $resp = $this->facade->flow('processTask/surrogate', [
            'processTaskId' => $taskId, 'actorIds' => '0,,00, ,1,01',
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));

        $this->assertSame(['leader', '0', '00', '1', '01'], $this->actorsOf($taskId),
            "串腿的 '0' 必须存活（无回调 array_filter 的踩点，§2.10 要求④在本腿失守）");
    }

    /** §2.11② 落库取 trim 后的值：带空格的同一人不得再落第二个（与写侧判重同一条尺子）。 */
    public function testTrimmedValueHitsTheDedupRule(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->facade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => ['9101']]);
        $resp = $this->facade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => [' 9101 ']]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['leader', '9101'], $this->actorsOf($taskId),
            '" 9101 " 与 "9101" 是同一个人，不得落两行');
    }

    /** 追加语义不回归：加签只追加，原参与人一行不动（issues/115 语义）。 */
    public function testSurrogateIsStillAppendOnly(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->facade->flow('processTask/surrogate', ['processTaskId' => $taskId, 'actorIds' => '9201,9202']);

        $this->assertSame(['leader', '9201', '9202'], $this->actorsOf($taskId), '原人 leader 保留可办');
    }

    // ═══ 写侧兜底：绕过门面直连仓储也灌不进空值（内存仓一路，两层里的第二层） ═══

    /** 门面修了、写侧没修 ⇒ 直连仓储照样灌空值；本组格钉第二层。 */
    public function testMemoryRepoWritePathDropsBlankActors(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->repo->addTaskActor($taskId, ['', '   ', null, '8501']);

        $this->assertSame(['leader', '8501'], $this->actorsOf($taskId),
            '§2.11①：内存仓写侧空串/纯空白/null 一律丢弃');
    }

    /** 写侧的同一枚哨兵：'0'／'00'／'1'／'01' 四个人都得落进去，空值不进。 */
    public function testMemoryRepoWritePathSentinelsAreFourDistinctPeople(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->repo->addTaskActor($taskId, ['0', '', '00', '  ', '1', '01']);

        $this->assertSame(['leader', '0', '00', '1', '01'], $this->actorsOf($taskId),
            '§2.11④：写侧只丢空值，"看起来像空"的正常 id 全部存活');
    }

    /** 写侧判重必须严格比较：库里已有 '0' 时，'00' 是另一个人（松散 in_array 会把他静默丢掉）。 */
    public function testMemoryRepoWritePathDedupeIsStrict(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->repo->addTaskActor($taskId, ['0']);
        $this->repo->addTaskActor($taskId, ['00', ' 1 ', '01']);

        $this->assertSame(['leader', '0', '00', '1', '01'], $this->actorsOf($taskId),
            "PHP 松散比较里 '0' == '00'、'1' == '01'（都是数字串）⇒ 判重必须严格比较");
    }

    /** 写侧同样 trim：落库存 trim 后的串，与既有值判重取同一把尺子。 */
    public function testMemoryRepoWritePathTrimsBeforeComparing(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->repo->addTaskActor($taskId, [' 8601 ', '8601']);

        $this->assertSame(['leader', '8601'], $this->actorsOf($taskId),
            '§2.11②：落库与比较一律取 trim 后的值，同一人只落一次');
    }

    /** 写侧给空集合 ⇒ 早退，既有参与者一字不动（PDO 仓同判，两仓同答案）。 */
    public function testMemoryRepoWritePathWithEmptyListIsNoOp(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $this->repo->addTaskActor($taskId, []);
        $this->repo->addTaskActor($taskId, ['', '  ']);

        $this->assertSame(['leader'], $this->actorsOf($taskId), '空集合/全空白 ⇒ 零写入');
    }

    // ═══ transfer 腿：fromActor/toActor 归一后再用（同一枚单点） ═══

    /** 带空格的 from/to ⇒ 参与者行与转办账本存的都是 trim 后的值。 */
    public function testTransferActorsAreNormalizedBeforeUse(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $resp = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => ' leader ',
            'fromActor' => ' leader ', 'toActor' => ' 9102 ',
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));

        $this->assertSame(['9102'], $this->actorsOf($taskId),
            '§2.11：toActor 归一（trim）后落库，不得存 " 9102 "');

        $ledger = $this->task($taskId)->getVariables()->get(FlowConst::TRANSFER_HISTORY);
        $this->assertTrue(is_array($ledger) && $ledger !== [], '前置：转办账本应已写入');
        $this->assertSame('leader', $ledger[0]['fromActor'], '账本里也是归一后的值');
        $this->assertSame('9102', $ledger[0]['toActor'], '账本里也是归一后的值');
    }

    /** 反向哨兵落在 transfer 腿：toActor='0' 是合法目标人，不得被当成"没填"。 */
    public function testTransferToActorZeroIsNotMistakenForBlank(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $resp = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => 'leader', 'toActor' => '0',
        ]);

        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['0'], $this->actorsOf($taskId), "反向哨兵：'0' 转得过去");
    }

    /**
     * 标量归属位给数组 ⇒ 归一判成空 ⇒ 落进既有"toActor 必填"档响亮报错。
     * 旧形状 `(string)` 强转数组撞 `Array to string conversion` 告警（本仓 failOnWarning 直接红），
     * 那不是"归一"，是把非法入参变成 "Array" 这种垃圾归属值。
     */
    public function testTransferArrayFormActorFallsIntoRequiredError(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);

        $resp = $this->facade->flow('processTask/transfer', [
            'processTaskId' => $taskId, 'operator' => 'leader', 'fromActor' => 'leader', 'toActor' => ['9103'],
        ]);

        $this->assertSame(99999999, $resp['code'], '标量归属位给数组必须报错，不得静默落库');
        $this->assertSame('toActor 必填', $resp['msg'], '沿用既有必填档文案，不新造');
        $this->assertSame(['leader'], $this->actorsOf($taskId), '报错档不得留下半个参与者行');
    }

    /** 主键档在 transfer 腿同一条尺子：processTaskId 纯空白也是"缺失"，不得退化成"任务不存在"。 */
    public function testTransferStillRejectsBlankTaskId(): void
    {
        $resp = $this->facade->flow('processTask/transfer', [
            'processTaskId' => '   ', 'operator' => 'leader', 'fromActor' => 'leader', 'toActor' => 'lisi',
        ]);
        $this->assertSame(99999999, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
        $this->assertSame('processTaskId 缺失或非法', $resp['msg'], '主键档：空串与纯空白同判');
    }

    // ═══ 第二遍收尾（issues/142 §9.2 第二批 · php 落地段）═══
    // 三条腿：CreateTaskHandler 的 nextNodeOperator 两臂换单点 ／ 门面 updateCCStatus
    // 的 operator 归一＋空报错 ／ 两仓 removeTaskActor 的删除位 trim。前两遍已钉 add/
    // surrogate/transfer/addTaskActor，本段钉的是当时仍持手写尺子的剩余写点。

    /** 部署 01-simple 并起一单，返回实例 id（第二遍格用：cc 行挂在实例上）。 */
    private function startedInstanceId(): string
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
        return (string) $start['data']['processInstanceId'];
    }

    /** 部署 02-multi-task 并起一单，给 task1 补参与者 leader 后返回其 taskId（nextNodeOperator 格用：task1 后面是 task2）。 */
    private function multiTask1Id(): string
    {
        $deploy = $this->facade->flow('processDefine/deploy', [
            'content' => file_get_contents(jeeflow_flows_dir() . '/02-multi-task.json'),
            'operator' => 'zhangsan',
        ]);
        $this->assertSame(0, $deploy['code'], json_encode($deploy, JSON_UNESCAPED_UNICODE));
        $start = $this->facade->flow('processInstance/startAndExecute', [
            'processDefineId' => $deploy['data']['processDefineId'], 'operator' => 'zhangsan',
        ]);
        $this->assertSame(0, $start['code'], json_encode($start, JSON_UNESCAPED_UNICODE));
        $instanceId = (string) $start['data']['processInstanceId'];

        foreach ($this->repo->findDoingTasks($instanceId) as $task) {
            if ($task->getTaskName() === 'task1') {
                $taskId = (string) $task->getTaskId();
                $this->repo->addTaskActor($taskId, ['leader']);
                return $taskId;
            }
        }
        $this->fail('前置：02-multi-task 起单后应有 task1 待办');
    }

    /** task1 办结（带 tf_nextNodeOperator）后取 task2 行；取不到即 fail。 */
    private function task2AfterTask1(string $instanceId): ProcessTask
    {
        foreach ($this->repo->findDoingTasks($instanceId) as $task) {
            if ($task->getTaskName() === 'task2') return $task;
        }
        $this->fail('前置：task1 办结后应有 task2 待办');
    }

    /** §9.2：nextNodeOperator 串腿走单点——脏串「  manager ,, 」落进 task2 的只剩 manager。 */
    public function testNextNodeOperatorStringLegGoesThroughTheSinglePoint(): void
    {
        $taskId = $this->multiTask1Id();
        $instanceId = $this->task($taskId)->getProcessInstanceId();

        $resp = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader',
            'tf_nextNodeOperator' => '  manager ,,  ',
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));

        $task2 = $this->task2AfterTask1((string) $instanceId);
        $this->assertSame(['manager'], $task2->getActorIds(),
            '§9.2：串臂必须复用单点（trim＋丢空），脏串不得落「 manager」带空格行或空行');
    }

    /** §9.2：nextNodeOperator 数组腿同一枚单点——旧形状的 (string) 强转在数组入参时靠运气。 */
    public function testNextNodeOperatorArrayLegGoesThroughTheSamePoint(): void
    {
        $taskId = $this->multiTask1Id();
        $instanceId = $this->task($taskId)->getProcessInstanceId();

        $resp = $this->facade->flow('processTask/execute', [
            'processTaskId' => $taskId, 'operator' => 'leader',
            'tf_nextNodeOperator' => [' 9101 ', '', '   ', '0'],
        ]);
        $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));

        $task2 = $this->task2AfterTask1((string) $instanceId);
        $this->assertSame(['9101', '0'], $task2->getActorIds(),
            '§9.2：数组臂与串臂同判据（空值丢弃、哨兵 0 存活、连续下标），两臂不得分叉');
    }

    /** §9.2：updateCCStatus 的 operator 归一——带空格打得中规范行；空回落 user1（java 基准）。 */
    public function testUpdateCcStatusTrimsOperatorAndBlankFallsBackToUser1(): void
    {
        $instanceId = $this->startedInstanceId();
        $cc = $this->facade->flow('processInstance/createCCInstance', [
            'processInstanceId' => $instanceId, 'operator' => 'user1', 'actorIds' => ['9101', 'user1'],
        ]);
        $this->assertSame(0, $cc['code'], json_encode($cc, JSON_UNESCAPED_UNICODE));

        $hit = $this->facade->flow('processInstance/updateCCStatus', [
            'processInstanceId' => $instanceId, 'operator' => ' 9101 ',
        ]);
        $this->assertSame(0, $hit['code'], json_encode($hit, JSON_UNESCAPED_UNICODE));
        $rowOf = function (string $actorId): array {
            foreach ($this->repo->getCcInstances() as $r) {
                if ($r['actorId'] === $actorId) return $r;
            }
            $this->fail("前置：应有 {$actorId} 的 cc 行");
        };
        $this->assertSame(1, $rowOf('9101')['state'], '「 9101 」必须打得中 9101 的行（比较位归一）');
        $this->assertSame(0, $rowOf('user1')['state'], '只动 9101 的行');

        // 空档回落 user1（java operatorArg→normalizeActor 的可观测行为，error 分支不可达），
        // 且绝不把 state=1 批量打到历史 actor_id='' 的脏行上（issues/129 写侧对偶）。
        foreach (['', '   '] as $blank) {
            $resp = $this->facade->flow('processInstance/updateCCStatus', [
                'processInstanceId' => $instanceId, 'operator' => $blank,
            ]);
            $this->assertSame(0, $resp['code'], json_encode($resp, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, $rowOf('user1')['state'], '空 operator 回落 user1（issues/129 案 A）');
        }
    }

    /** §9.2：removeTaskActor 删除位 trim——带空格删得掉规范行，空串入参不误删历史脏行。 */
    public function testRemoveTaskActorTrimsMatchAndBlankListIsNoOp(): void
    {
        $taskId = $this->task1Id($this->facade, $this->repo);
        $this->repo->addTaskActor($taskId, [' 8601 ']);
        $this->assertSame(['leader', '8601'], $this->actorsOf($taskId), '前置：写侧落库存 trim 值');

        $this->repo->removeTaskActor($taskId, [' 8601 ']);
        $this->assertSame(['leader'], $this->actorsOf($taskId), '删除位必须按 trim 后的值比较（【 8601 】删得掉 8601）');

        // 历史脏行：库里有 actor_id=空串 的行（旧版本写进去的）。空串入参绝不能把它当"要删的人"。
        $this->task($taskId)->setActorIds(['leader', '']);
        $this->repo->removeTaskActor($taskId, ['']);
        $this->repo->removeTaskActor($taskId, ['  ', null]);
        $this->repo->removeTaskActor($taskId, []);
        $this->assertSame(['leader', ''], $this->actorsOf($taskId),
            '§9.2：归一后为空 ⇒ 什么都不删（空串入参批量误删脏行＝issues/129 写侧复现）');
    }
}

/**
 * 第三方自实现仓储的替身：`addTaskActor` **一条判据都不做**（不丢空、不 trim、不判重），
 * 并把每次收到的原始入参留在 `received` 里取证。
 *
 * 为什么需要它（issues/141 实测教训，本栈踩过）：§2.11①"两层都挡"落地后，参与者行同时受
 * 门面与写侧两层保护，只还原其中一层时另一层会兜住、格子照不出红——把写侧换成这个不归一的
 * 替身，门面层就成了唯一在挡的那一层（c#/node/php 三栈同病、同一处置）。
 */
class RawTaskActorSpyRepository extends InMemoryProcessRepository
{
    /** @var array<int, string[]> 递进写入口 `addTaskActor` 的归一后入参（替身自己不再归一，故＝门面给的） */
    public array $received = [];

    /** @var array<int, array{taskId: string, actorId: mixed}> 原样落下的参与者行（未经任何归一） */
    public array $rawRows = [];

    public function reset(): void
    {
        $this->received = [];
        $this->rawRows = [];
    }

    public function addTaskActor(int|string $taskId, array $actorIds): void
    {
        $this->received[] = $actorIds;
        foreach ($actorIds as $actorId) {
            $this->rawRows[] = ['taskId' => (string) $taskId, 'actorId' => $actorId];
        }
    }
}
