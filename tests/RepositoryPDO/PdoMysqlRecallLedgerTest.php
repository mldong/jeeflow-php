<?php

declare(strict_types=1);

namespace Jeeflow\Tests\RepositoryPDO;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Enum\FlowConst;
use Jeeflow\Core\Enum\ProcessTaskState;
use Jeeflow\Core\Enum\SubmitType;
use Jeeflow\Core\Event\ProcessEventListenerRegistry;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\ExpressionEvaluatorInterface;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\RepositoryPDO\PdoProcessRepository;
use Jeeflow\Tests\Fixture\SimpleExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * 「已办撤回账本落库往返」在 PDO 仓储上的真读回（issues/249）。
 *
 * 内存仓 updateInstance 不级联任务行，账本怎么写都在；PDO 仓 updateInstance 会按聚合副本
 * 逐行 updateTask——target 若是 findTaskById 的独立副本，刚盖的 tf_recallHistory 会被聚合里
 * 没账本的旧副本抹掉。本类走真实 SQL 往返：recallTask 后重新 findTaskById 读回被撤行，
 * 断言账本恰好 +1 且条目含 {operator, time, recalledTaskId} 三键。
 */
final class PdoMysqlRecallLedgerTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private static string $skipReason = '';
    private PdoProcessRepository $repo;
    private JeeflowEngine $engine;
    private int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        [self::$pdo, self::$skipReason] = PdoTestDb::connectOrSkip();
        if (self::$skipReason !== '') {
            return;
        }
        $schema = file_get_contents(__DIR__ . '/../../packages/repository-pdo/sql/schema-mysql.sql');
        self::$pdo->exec($schema);
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM wf_process_task_actor');
        $pdo->exec('DELETE FROM wf_process_task');
        $pdo->exec('DELETE FROM wf_process_instance');
        $pdo->exec('DELETE FROM wf_process_cc_instance');
        $pdo->exec('DELETE FROM wf_process_define');

        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(ExpressionEvaluatorInterface::class, new SimpleExpressionEvaluator());

        $this->repo = new PdoProcessRepository($pdo);
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ProcessEventListenerRegistry::clear();
        ModelParser::reset();
    }

    public function testRecallLedgerSurvivesInstanceCascadeOnPdo(): void
    {
        $defId = $this->register($this->chain(['allowRecall' => true]));

        $instance = $this->engine->startProcessInstanceById($defId, 'user1', FlowData::create());
        $iid = $instance->getInstanceId();
        $inst = $this->repo->findInstanceById($iid);
        $apply = $this->findDoingTask($inst, 'apply');
        $this->engine->executeProcessTask($apply->getTaskId(), 'user1', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::APPLY]));

        $inst = $this->repo->findInstanceById($iid);
        $review = $this->findDoingTask($inst, 'review');
        $this->assertNotNull($review);
        $oldReviewId = (string) $review->getTaskId();
        $this->engine->executeProcessTask($oldReviewId, 'userA', FlowData::of([FlowConst::SUBMIT_TYPE => SubmitType::AGREE]));

        $this->assertNull(
            $this->repo->findTaskById($oldReviewId)->getVariables()->get(FlowConst::RECALL_HISTORY),
            '撤回前被撤行不应有账本'
        );

        $resurrected = $this->engine->recallTask($oldReviewId, 'userA');
        $this->assertNotNull($resurrected);
        $this->assertSame('review', $resurrected->getTaskName());

        $row = $this->repo->findTaskById($oldReviewId);
        $history = $row->getVariables()->get(FlowConst::RECALL_HISTORY);
        $this->assertNotNull($history, '撤回后真库读回被撤行应有 tf_recallHistory（issues/249）');
        $this->assertCount(1, $history, '账本应恰好 +1 条');
        $entry = $history[0];
        $this->assertSame('userA', $entry['operator']);
        $this->assertNotNull($entry['time']);
        $this->assertSame((string) $resurrected->getTaskId(), (string) $entry['recalledTaskId'],
            'recalledTaskId 应指向复活行');
        $this->assertSame(ProcessTaskState::FINISHED, $row->getTaskState(),
            '原行仍应留 FINISHED');
    }

    // ═══ 夹具 ═══

    private function chain(array $reviewProps): string
    {
        $body = [
            'name' => 'recallledger-' . (++$this->seq),
            'displayName' => '撤回账本真库腿',
            'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '开始']],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'apply-form', 'taskType' => 0, 'performType' => 0,
                     'assignee' => 'applicant'],
                 'text' => ['value' => '发起申请']],
                ['id' => 'review', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => array_merge(['form' => 'form-r', 'assignee' => 'userA',
                     'taskType' => 0, 'performType' => 0], $reviewProps),
                 'text' => ['value' => '审批']],
                ['id' => 'final', 'type' => 'snaker:task', 'x' => 0, 'y' => 0,
                 'properties' => ['form' => 'form-f', 'assignee' => 'userF',
                     'taskType' => 0, 'performType' => 0],
                 'text' => ['value' => '终审']],
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 0, 'y' => 0,
                 'properties' => [], 'text' => ['value' => '结束']],
            ],
            'edges' => [
                ['id' => 'e1', 'type' => 'snaker:transition', 'sourceNodeId' => 'start',
                 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e2', 'type' => 'snaker:transition', 'sourceNodeId' => 'apply',
                 'targetNodeId' => 'review', 'properties' => []],
                ['id' => 'e3', 'type' => 'snaker:transition', 'sourceNodeId' => 'review',
                 'targetNodeId' => 'final', 'properties' => []],
                ['id' => 'e4', 'type' => 'snaker:transition', 'sourceNodeId' => 'final',
                 'targetNodeId' => 'end', 'properties' => []],
            ],
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        return $json;
    }

    private function register(string $flowJson): string
    {
        $defineId = (string) (910000 + $this->seq);
        $decoded = json_decode($flowJson, true);
        $this->repo->addDefine([
            'id' => $defineId, 'name' => $decoded['name'] ?? $defineId,
            'displayName' => $decoded['displayName'] ?? '撤回账本真库腿',
            'type' => 'approval', 'state' => 1, 'content' => $flowJson, 'version' => 1,
        ]);
        return $defineId;
    }

    private function findDoingTask(\Jeeflow\Core\Domain\ProcessInstance $inst, string $name): ?\Jeeflow\Core\Domain\ProcessTask
    {
        foreach ($inst->getDoingTasks() as $t) {
            if ($t->getTaskName() === $name) return $t;
        }
        return null;
    }
}
