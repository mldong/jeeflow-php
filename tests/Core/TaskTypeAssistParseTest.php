<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\TaskType;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\JeeflowException;
use Jeeflow\Core\Model\CustomModel;
use Jeeflow\Core\Model\TaskModel;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * issues/252 · 节点级 `taskType=1`（协办）**解析期禁配**（PHP 栈）。
 *
 * 契约出处：spec 02 §4.4 末条 bullet ＋ §4 字段表 `taskType` 行。协办档今后只由运行期
 * `processTask/aidant` 派生（issues/253，本轮不做）；节点上配协办在 snaker 原义下是死路——
 * 协办任务办结不产生执行对象、跳转吃同一道闸 ⇒ 实例仍运行中却零待办无后继（流程悬挂）。
 * 所以这一档在**解析期**就拦，不是运行时降级。
 *
 * ⚠️ **本栈容错集合现读**（与 java/c# 不同，禁配判据必须按本栈实际折值写）：
 * `TaskNodeParser` 读 `taskType` 走的是 `(int)` 直折，**没有 codeOf 别名表**
 * （`Enum\TaskType` 只有 MAIN/ASSIST/RECORD 三枚整型常量 ＋ `label()`，无 `from`/`codeOf`）。
 * ⇒ 能折成协办(1) 的只有**数字档**：`1`、`"1"`、`1.9`、`true`、`"1abc"`（PHP 前导数字串）。
 * ⇒ `"协办"`／`"SECONDARY"`／`"ASSIST"`／`"Aidant"` 这些串形在本栈 `(int)` 一律折 **0**，
 *    属"不可识别值"，按 issues/252 §5-3 边界**维持既有静默回落主办**，不得顺手扩成 fail-fast
 *    （issues/245 口径：配置类错误优先静默降级）。本文件把这条边界钉成回归格。
 *
 * 夹具走内联 JSON，**不碰共享 `flows/` 目录**（与 {@see RecallTest}／{@see AddSignAfterTest}
 * 同款纪律：`flows/` 有镜像与漂移门禁，唯一编辑源在 jeeflow-java）。
 */
final class TaskTypeAssistParseTest extends TestCase
{
    /** 逐字契约文案：八栈同尺；改字面量要走 issue，不要顺手改 */
    private const VERBATIM = '节点[review]不允许配置协办任务类型（taskType=1）';

    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $seq = 0;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ═══ 正向：凡折成协办(1) 的输入形 ⇒ 解析期报错 ═══

    /**
     * 本栈 `(int)` 折值下能落到协办(1) 的全部输入形。
     *
     * @return array<string, array{mixed}>
     */
    public static function assistForms(): array
    {
        return [
            'int 1' => [1],
            'string "1"' => ['1'],
            'float 1.9（(int) 截断成 1）' => [1.9],
            'bool true（(int) 成 1）' => [true],
            'string "1abc"（PHP 前导数字串）' => ['1abc'],
        ];
    }

    /** @dataProvider assistForms */
    public function testAssistFormsAreRejectedAtParseTime(mixed $form): void
    {
        $caught = null;
        try {
            ModelParser::parse($this->chain(['taskType' => $form]));
        } catch (\Throwable $t) {
            $caught = $t;
        }

        self::assertNotNull($caught, '协办档必须解析期报错，不得静默落回主办');
        self::assertInstanceOf(
            JeeflowException::class,
            $caught,
            '必须是引擎契约异常（门面才透出逐字 msg，非契约异常会被转成「流程处理失败」）；实际='
                . get_class($caught)
        );
        self::assertSame(self::VERBATIM, $caught->getMessage(), '对外 msg 必须逐字带节点名（取节点 id）');
        self::assertStringNotContainsString('2001', $caught->getMessage(), '内部码不得外泄');
    }

    /** 报错发生在**解析/部署**阶段而不是办理阶段，且零写入：实例与任务一行都不落。 */
    public function testRejectionHappensBeforeAnyWrite(): void
    {
        $defineId = $this->register($this->chain(['taskType' => 1]));

        $caught = null;
        try {
            $this->engine->startProcessInstanceById($defineId, 'applicant', FlowData::create());
        } catch (\Throwable $t) {
            $caught = $t;
        }

        self::assertInstanceOf(JeeflowException::class, $caught);
        self::assertSame(self::VERBATIM, $caught->getMessage());
        self::assertSame([], $this->repo->getAllInstances(), '解析期报错 ⇒ 实例零写入');
        self::assertFalse($this->repo->hasInstancesByDefineId($defineId), '该定义下不得挂任何实例');
    }

    /** 申请节点（首任务节点）配协办同样拦——禁配不看节点位置，只看折值。 */
    public function testApplyNodeAssistIsAlsoRejected(): void
    {
        $this->expectException(JeeflowException::class);
        $this->expectExceptionMessage('节点[apply]不允许配置协办任务类型（taskType=1）');
        ModelParser::parse($this->chain([], ['taskType' => '1']));
    }

    // ═══ 回归：其余输入形维持既有折值，不扩成 fail-fast ═══

    /**
     * 不可识别值（含设计器现状写的 `Aidant`）与主办档 ⇒ 解析成功且折值不变。
     *
     * @return array<string, array{mixed, int|null}>
     */
    public static function toleratedForms(): array
    {
        return [
            'int 0' => [0, TaskType::MAIN],
            'string "0"' => ['0', TaskType::MAIN],
            'string "Major"（枚举名，本栈 (int) 折 0）' => ['Major', TaskType::MAIN],
            'string "Aidant"（设计器现状值，八栈都不认）' => ['Aidant', TaskType::MAIN],
            'string "SECONDARY"（本栈无别名表 ⇒ 折 0）' => ['SECONDARY', TaskType::MAIN],
            'string "ASSIST"（本栈常量名，非可识别输入）' => ['ASSIST', TaskType::MAIN],
            'string "协办"（本栈无中文别名 ⇒ 折 0）' => ['协办', TaskType::MAIN],
            'string "主办"' => ['主办', TaskType::MAIN],
            '空串' => ['', TaskType::MAIN],
            'null 值（isset 为假 ⇒ 不写键同档）' => [null, null],
        ];
    }

    /** @dataProvider toleratedForms */
    public function testNonAssistFormsStillParseAndFoldUnchanged(mixed $form, ?int $expected): void
    {
        $model = ModelParser::parse($this->chain(['taskType' => $form]));
        $review = $model->getNode('review');
        self::assertInstanceOf(TaskModel::class, $review);
        self::assertSame($expected, $review->getTaskType(), '既有静默回落口径不得被本轮改动');
    }

    /** 缺省不写键 ⇒ taskType 留 null（与改前逐字同形）。 */
    public function testAbsentKeyKeepsNull(): void
    {
        $model = ModelParser::parse($this->chainOmittingTaskType());
        $review = $model->getNode('review');
        self::assertInstanceOf(TaskModel::class, $review);
        self::assertNull($review->getTaskType());
    }

    /** RECORD(2) 档不受影响：数字与数字串都原样落 2。 */
    public function testRecordTypeIsUntouched(): void
    {
        foreach ([2, '2'] as $form) {
            $model = ModelParser::parse($this->chain(['taskType' => $form]));
            $review = $model->getNode('review');
            self::assertInstanceOf(TaskModel::class, $review);
            self::assertSame(TaskType::RECORD, $review->getTaskType());
        }
    }

    /**
     * custom（记录类）节点不受影响：本栈 `CustomNodeParser` 根本不读 `taskType`，
     * 记录行的档由聚合根 `createHistoryTask` 决定 ⇒ 节点上写 1 也不该被任务节点那把刀拦。
     */
    public function testCustomNodeWithAssistValueStillParses(): void
    {
        $model = ModelParser::parse($this->chainWithCustom(['taskType' => 1]));
        $custom = $model->getNode('rec');
        self::assertInstanceOf(CustomModel::class, $custom);
    }

    // ═══ 夹具（内联 JSON，不碰共享 flows/）═══

    /**
     * 五节点链 start→apply→review→final→end；覆盖片段用 `array_merge`（`+` 是左键优先，
     * 会把覆盖用的 taskType 静默吃掉——同 {@see RecallTest::chainJson()} 那条注）。
     *
     * @param array<string,mixed> $reviewProps
     * @param array<string,mixed> $applyProps
     */
    private function chain(array $reviewProps, array $applyProps = []): string
    {
        return $this->encode([
            'name' => 'tt252-' . (++$this->seq),
            'displayName' => '协办禁配夹具',
            'type' => 'approval',
            'nodes' => [
                $this->node('start', 'snaker:start', [], '开始'),
                $this->node('apply', 'snaker:task', array_merge(
                    ['form' => 'apply-form', 'taskType' => 0, 'performType' => 0, 'assignee' => 'applicant'],
                    $applyProps
                ), '发起申请'),
                $this->node('review', 'snaker:task', array_merge(
                    ['form' => 'form-r', 'assignee' => 'userA', 'taskType' => 0, 'performType' => 0],
                    $reviewProps
                ), '审批'),
                $this->node('final', 'snaker:task',
                    ['form' => 'form-f', 'assignee' => 'userF', 'taskType' => 0, 'performType' => 0], '终审'),
                $this->node('end', 'snaker:end', [], '结束'),
            ],
            'edges' => [
                $this->edge('e1', 'start', 'apply'),
                $this->edge('e2', 'apply', 'review'),
                $this->edge('e3', 'review', 'final'),
                $this->edge('e4', 'final', 'end'),
            ],
        ]);
    }

    /** 把 final 换成 custom 记录节点（clazz 必填），供 RECORD/custom 回归格。 @param array<string,mixed> $props */
    private function chainWithCustom(array $props): string
    {
        $body = json_decode($this->chain([]), true);
        self::assertIsArray($body);
        $body['nodes'][3] = $this->node('rec', 'snaker:custom', array_merge(
            ['clazz' => 'noopHandler', 'methodName' => 'run'],
            $props
        ), '记录');
        $body['name'] = 'tt252custom-' . (++$this->seq);
        return $this->encode($body);
    }

    /** review 节点**真正不写** `taskType` 键（缺省档），供 null 回归格。 */
    private function chainOmittingTaskType(): string
    {
        $body = json_decode($this->chain([]), true);
        self::assertIsArray($body);
        unset($body['nodes'][2]['properties']['taskType']);
        $body['name'] = 'tt252absent-' . (++$this->seq);
        return $this->encode($body);
    }

    /**
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private function node(string $id, string $type, array $props, string $label): array
    {
        return ['id' => $id, 'type' => $type, 'x' => 0, 'y' => 0,
            'properties' => $props, 'text' => ['value' => $label]];
    }

    /** @return array<string,mixed> */
    private function edge(string $id, string $from, string $to): array
    {
        return ['id' => $id, 'type' => 'snaker:transition', 'sourceNodeId' => $from,
            'targetNodeId' => $to, 'properties' => []];
    }

    /** @param array<string,mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        self::assertNotFalse($json);
        return $json;
    }

    /** 只塞定义行（不解析）；解析发生在发起那一跳，供零写入格。 */
    private function register(string $flowJson): string
    {
        $defineId = 'tt252-' . $this->seq;
        $decoded = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => $decoded['name'] ?? $defineId,
            'displayName' => '协办禁配夹具', 'type' => 'approval', 'state' => 1,
            'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }
}