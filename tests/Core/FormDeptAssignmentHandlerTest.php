<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Execution;
use Jeeflow\Core\Handler\AssignmentHandlerRegistry;
use Jeeflow\Core\Handler\Builtin\FormDeptLeaderAssignmentHandler;
use Jeeflow\Core\Handler\Builtin\FormDeptMainLeaderAssignmentHandler;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\OrgUserProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/258 表单指定 1–N 个部门（分管）领导。
 *
 * 行为基准＝jeeflow-java FormDeptAssignSupport / FormDeptAssignmentHandlerTest；
 * 字段查找复用 PHP issues/254 的四档并集，旧 FormUser 两枚 handler 不变。
 */
final class FormDeptAssignmentHandlerTest extends TestCase
{
    private const FQ_LEADER = 'com.mldong.jeeflow.interceptor.impl.FormDeptLeaderAssignmentHandler';
    private const FQ_MAIN = 'com.mldong.jeeflow.interceptor.impl.FormDeptMainLeaderAssignmentHandler';

    private const LEADERS = [
        'deptA' => ['leaderA', 'shared'],
        'deptB' => ['leaderB', 'shared'],
        'deptC' => ['leaderC1', 'leaderC2'],
        '0' => ['zeroLeader'],
        '00' => ['doubleZeroLeader'],
    ];

    private const MAIN_LEADERS = [
        'deptA' => ['mainA'],
        'deptB' => ['mainB'],
        'deptC' => ['mainC'],
        '0' => ['zeroMain'],
    ];

    private FormDeptOrgUserProvider $org;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->org = new FormDeptOrgUserProvider(self::LEADERS, self::MAIN_LEADERS);
        ServiceContext::put(OrgUserProviderInterface::class, $this->org);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ═══ 四档字段查找（顺序即优先级）══════════════════════════════════════════

    public function testPrefixedFieldWinsOverBareName(): void
    {
        $args = FlowData::of(['f_depts' => 'deptA', 'depts' => 'deptB']);
        $this->assertSame('leaderA,shared', $this->leaders('depts', $args));
    }

    public function testBareFieldFallbackAndMiss(): void
    {
        $this->assertSame('leaderB,shared', $this->leaders('depts', FlowData::of(['depts' => 'deptB'])));
        $this->assertNull($this->leaders('depts', FlowData::of(['other' => 'deptB'])));
    }

    public function testHashTierUsesLastHashAndPrefersBareField(): void
    {
        $args = FlowData::of(['deptIds' => 'deptA', 'f_deptIds' => 'deptB']);
        $this->assertSame('leaderA,shared', $this->leaders('manager#deptIds', $args));
        $this->assertSame('leaderB,shared', $this->leaders('manager#deptIds', FlowData::of(['f_deptIds' => 'deptB'])));
        $this->assertSame('leaderA,shared', $this->leaders('a#b#deptIds', FlowData::of(['deptIds' => 'deptA'])));
    }

    public function testNumberSuffixTierPrefersPrefixedBase(): void
    {
        $args = FlowData::of(['f_depts' => 'deptA', 'depts' => 'deptB']);
        $this->assertSame('leaderA,shared', $this->leaders('depts_01', $args));
        $this->assertSame('leaderA,shared', $this->leaders('depts_01', FlowData::of(['depts' => 'deptA'])));
    }

    public function testNumberSuffixTierUsesLazyBaseAndRejectsInvalidShapes(): void
    {
        $args = FlowData::of(['a_b' => 'deptA', 'a' => 'deptB']);
        $this->assertSame('leaderA,shared', $this->leaders('a_b_01', $args));
        $this->assertNull($this->leaders('_01', FlowData::of(['f_' => 'deptA'])));
        $this->assertNull($this->leaders('depts_01x', FlowData::of(['depts' => 'deptA'])));
    }

    public function testExistingNullFieldDoesNotFallBack(): void
    {
        $args = FlowData::of(['f_depts' => null, 'depts' => 'deptB']);
        $this->assertNull($this->leaders('depts', $args));
        $this->assertSame([], $this->org->leaderCalls, 'null 键已命中，不得继续查询裸名字段');
    }

    // ═══ 部门列表解析与合并 ═══════════════════════════════════════════════════

    public function testCommaStringResolvesAllDepartmentsInOrder(): void
    {
        $this->assertSame(
            'leaderA,shared,leaderB',
            $this->leaders('depts', FlowData::of(['depts' => ' , deptA, ,deptB, ']))
        );
        $this->assertSame(['deptA', 'deptB'], $this->org->leaderCalls);
    }

    public function testArrayResolvesAllDepartmentsInOrder(): void
    {
        $this->assertSame(
            'leaderA,shared,leaderB',
            $this->leaders('depts', FlowData::of(['depts' => ['deptA', ' deptB ', '', null, '  ']]))
        );
    }

    public function testTraversableCollectionResolvesAllDepartmentsInOrder(): void
    {
        $this->assertSame(
            'leaderA,shared,leaderB',
            $this->leaders('depts', FlowData::of(['depts' => new \ArrayIterator(['deptA', 'deptB'])]))
        );
    }

    public function testArrayElementIsNotSplitAgain(): void
    {
        $this->assertNull($this->leaders('depts', FlowData::of(['depts' => ['deptA,deptB']])));
        $this->assertSame(
            'leaderC1,leaderC2',
            $this->leaders('depts', FlowData::of(['depts' => ['deptA,deptB', 'deptC']]))
        );
    }

    public function testDuplicateDepartmentsAndLeadersAreDeduped(): void
    {
        $this->assertSame(
            'leaderA,shared,leaderB',
            $this->leaders('depts', FlowData::of(['depts' => 'deptA,deptB,deptA']))
        );
        $this->assertSame(['deptA', 'deptB'], $this->org->leaderCalls, '重复部门只能查询一次');
    }

    public function testInvalidDepartmentIsSkipped(): void
    {
        $this->assertSame(
            'leaderB,shared',
            $this->leaders('depts', FlowData::of(['depts' => 'ghost,deptB']))
        );
        $this->assertSame(['ghost', 'deptB'], $this->org->leaderCalls);
    }

    public function testZeroDepartmentIdIsLegalAndDedupeIsStrict(): void
    {
        $this->assertSame('zeroLeader', $this->leaders('depts', FlowData::of(['depts' => 0])));
        $this->assertSame('zeroLeader', $this->leaders('depts', FlowData::of(['depts' => '0'])));
        $this->assertSame(
            'zeroLeader,doubleZeroLeader',
            $this->leaders('depts', FlowData::of(['depts' => '0,00,0']))
        );
    }

    public function testMainLeaderVariantUsesMainLeaderSpi(): void
    {
        $args = FlowData::of(['depts' => 'deptA,deptB']);
        $this->assertSame('mainA,mainB', $this->mainLeaders('depts', $args));
        $this->assertSame(['deptA', 'deptB'], $this->org->mainLeaderCalls);
        $this->assertSame([], $this->org->leaderCalls, '分管领导档不得调用负责人 SPI');
    }

    public function testMultipleLeadersKeepSpiOrder(): void
    {
        $this->assertSame('leaderC1,leaderC2', $this->leaders('depts', FlowData::of(['depts' => 'deptC'])));
    }

    public function testSpiFailureSkipsDepartmentAndContinues(): void
    {
        ServiceContext::put(OrgUserProviderInterface::class, new FormDeptOrgUserProvider(
            self::LEADERS,
            self::MAIN_LEADERS,
            ['deptA'],
            ['deptA']
        ));
        $args = FlowData::of(['depts' => 'deptA,deptB']);
        $this->assertSame('leaderB,shared', $this->leaders('depts', $args));
        $this->assertSame('mainB', $this->mainLeaders('depts', $args));
    }

    public function testSpiUserIdsAreTrimmedAndDeduped(): void
    {
        ServiceContext::put(OrgUserProviderInterface::class, new FormDeptOrgUserProvider(
            ['deptDirty' => [' leaderX ', '', 'leaderX', 'leaderY', null]],
            []
        ));
        $this->assertSame(
            'leaderX,leaderY',
            $this->leaders('depts', FlowData::of(['depts' => 'deptDirty']))
        );
    }

    // ═══ 失败形状：空结果且不抛 ═══════════════════════════════════════════════

    public function testEmptyAndNullValuesAreEmpty(): void
    {
        $cases = [
            '字段缺失' => FlowData::create(),
            '空串' => FlowData::of(['depts' => '']),
            '纯空白' => FlowData::of(['depts' => '   ']),
            '空数组' => FlowData::of(['depts' => []]),
            '数组全空' => FlowData::of(['depts' => ['', '  ', null]]),
            'null 键' => FlowData::of(['depts' => null]),
        ];
        foreach ($cases as $label => $args) {
            $this->assertNull($this->leaders('depts', $args), $label);
            $this->assertNull($this->mainLeaders('depts', $args), $label);
        }
        $this->assertSame([], $this->org->leaderCalls);
        $this->assertSame([], $this->org->mainLeaderCalls);
    }

    public function testAllDepartmentsWithoutLeadersAreEmpty(): void
    {
        $this->assertNull($this->leaders('depts', FlowData::of(['depts' => 'ghost,missing'])));
        $this->assertNull($this->mainLeaders('depts', FlowData::of(['depts' => 'ghost,missing'])));
    }

    public function testMissingOrgSpiIsEmpty(): void
    {
        ServiceContext::remove(OrgUserProviderInterface::class);
        $args = FlowData::of(['depts' => 'deptA']);
        $this->assertNull($this->leaders('depts', $args));
        $this->assertNull($this->mainLeaders('depts', $args));
    }

    public function testNoNodeOrEmptyNodeNameIsEmpty(): void
    {
        $args = FlowData::of(['f_' => 'deptA']);
        $noNode = new Execution();
        $noNode->setArgs($args);
        $this->assertNull((new FormDeptLeaderAssignmentHandler())->assign($noNode));
        $this->assertNull((new FormDeptMainLeaderAssignmentHandler())->assign($noNode));
        $this->assertNull($this->leaders('', $args));
        $this->assertNull($this->mainLeaders('', $args));
    }

    public function testNonScalarValuesAreEmptyWithoutWarnings(): void
    {
        $this->assertNull($this->leaders('depts', FlowData::of(['depts' => new \stdClass()])));
        $this->assertNull($this->leaders('depts', FlowData::of(['depts' => [['deptA']]])));
    }

    public function testRegistrationNamesResolveToNewClasses(): void
    {
        $registry = new AssignmentHandlerRegistry();
        $registry->register(self::FQ_LEADER, new FormDeptLeaderAssignmentHandler());
        $registry->register(self::FQ_MAIN, new FormDeptMainLeaderAssignmentHandler());
        $this->assertInstanceOf(FormDeptLeaderAssignmentHandler::class, $registry->resolve(self::FQ_LEADER));
        $this->assertInstanceOf(FormDeptMainLeaderAssignmentHandler::class, $registry->resolve(self::FQ_MAIN));
    }

    // ═══ 夹具 ═════════════════════════════════════════════════════════════════

    private function leaders(?string $nodeKey, FlowData $args): ?string
    {
        return (new FormDeptLeaderAssignmentHandler())->assign(self::executionOf($nodeKey, $args));
    }

    private function mainLeaders(?string $nodeKey, FlowData $args): ?string
    {
        return (new FormDeptMainLeaderAssignmentHandler())->assign(self::executionOf($nodeKey, $args));
    }

    private static function executionOf(?string $nodeKey, FlowData $args): Execution
    {
        $execution = new Execution();
        $execution->setArgs($args);
        if ($nodeKey !== null) {
            $model = new TaskModel();
            $model->setName($nodeKey);
            $execution->setNodeModel($model);
        }
        return $execution;
    }
}

/** 记录 SPI 调用顺序，确保部门去重与两档分派都可观察。 */
final class FormDeptOrgUserProvider implements OrgUserProviderInterface
{
    /** @var string[] */
    public array $leaderCalls = [];
    /** @var string[] */
    public array $mainLeaderCalls = [];

    /**
     * @param array<string, string[]> $leaders
     * @param array<string, string[]> $mainLeaders
     * @param string[] $leaderFailures
     * @param string[] $mainLeaderFailures
     */
    public function __construct(
        private array $leaders,
        private array $mainLeaders,
        private array $leaderFailures = [],
        private array $mainLeaderFailures = []
    ) {}

    public function findByRole(string $roleCode): array
    {
        return [];
    }

    public function findDeptLeaders(string $deptId): array
    {
        $this->leaderCalls[] = $deptId;
        if (in_array($deptId, $this->leaderFailures, true)) {
            throw new \RuntimeException('leader lookup failed');
        }
        return $this->leaders[$deptId] ?? [];
    }

    public function findDeptMainLeaders(string $deptId): array
    {
        $this->mainLeaderCalls[] = $deptId;
        if (in_array($deptId, $this->mainLeaderFailures, true)) {
            throw new \RuntimeException('main leader lookup failed');
        }
        return $this->mainLeaders[$deptId] ?? [];
    }
}
