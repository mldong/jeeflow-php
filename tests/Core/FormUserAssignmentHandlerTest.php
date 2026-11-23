<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\Domain\FlowData;
use Jeeflow\Core\Handler\AssignmentHandlerRegistry;
use Jeeflow\Core\Handler\Builtin\FormFieldAssigneeHandler;
use Jeeflow\Core\Handler\Builtin\FormUserDeptAssignmentHandlerBase;
use Jeeflow\Core\Handler\Builtin\FormUserDeptLeaderAssignmentHandler;
use Jeeflow\Core\Handler\Builtin\FormUserDeptMainLeaderAssignmentHandler;
use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Parser\ModelParser;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\BuiltinJsonProvider;
use Jeeflow\Core\Spi\JsonProviderInterface;
use Jeeflow\Core\Spi\OrgUserProviderInterface;
use Jeeflow\Core\Spi\UserProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * 「表单指定用户所属部门经理／分管领导」两个内置处理器（PHP 栈）。
 *
 * 契约＝执行卡 `docs/2026-11-22-form-user-assignment-handler-handoff.md` §3（八语言同形）；
 * 行为基准＝jeeflow-go `v1.8.67`（本轮样章）；场景来源＝mldong-fastapi-wf `fe9233d`（HR 代员工提交）。
 *
 * ⚠️ 本栈历史上**没有任何测试走过 assignmentHandler 这条解析腿**（内置处理类只挂在元数据里，
 * 注册由集成方在 ServiceProvider 里做）⇒ 这一档改错不会有任何一格变红。本件既钉取值语义，
 * 也钉「引擎经 `AssignmentHandlerRegistry` 真把处理器取到」这条接线。
 */
final class FormUserAssignmentHandlerTest extends TestCase
{
    private const FQ_LEADER = 'com.mldong.jeeflow.interceptor.impl.FormUserDeptLeaderAssignmentHandler';
    private const FQ_MAIN = 'com.mldong.jeeflow.interceptor.impl.FormUserDeptMainLeaderAssignmentHandler';

    private InMemoryProcessRepository $repo;
    private JeeflowEngine $engine;

    protected function setUp(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
        ServiceContext::put(JsonProviderInterface::class, new BuiltinJsonProvider());
        ServiceContext::put(UserProviderInterface::class, new class implements UserProviderInterface {
            public function getUser(string $userId): ?array
            {
                // emp→D01（两位经理＋一位分管领导）、other→D02（无领导）、nodept→无部门、其余查无此人
                if ($userId === 'emp' || $userId === '0') {
                    return ['userId' => $userId, 'realName' => '员工', 'deptId' => 'D01'];
                }
                if ($userId === 'other') {
                    return ['userId' => $userId, 'realName' => '别的部门', 'deptId' => 'D02'];
                }
                if ($userId === 'nodept') {
                    return ['userId' => $userId, 'realName' => '无部门', 'deptId' => null];
                }
                return null;
            }
            public function searchUsers(string $keyword, int $pageNo, int $pageSize): array { return []; }
        });
        ServiceContext::put(OrgUserProviderInterface::class, new class implements OrgUserProviderInterface {
            public function findByRole(string $roleCode): array { return []; }
            public function findDeptLeaders(string $deptId): array { return $deptId === 'D01' ? ['leader1', 'leader2'] : []; }
            public function findDeptMainLeaders(string $deptId): array { return $deptId === 'D01' ? ['boss1'] : []; }
        });

        $registry = new AssignmentHandlerRegistry();
        $registry->register(self::FQ_LEADER, new FormUserDeptLeaderAssignmentHandler());
        $registry->register(self::FQ_MAIN, new FormUserDeptMainLeaderAssignmentHandler());
        ServiceContext::put(AssignmentHandlerRegistry::class, $registry);

        $this->repo = new InMemoryProcessRepository();
        $this->engine = new JeeflowEngine($this->repo);
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
        ModelParser::reset();
    }

    // ═══ 正向：四档并集（顺序即优先级）════════════════════════════════════════

    public function testFieldLookupFourTiers(): void
    {
        $cases = [
            'f_ 主通道' => [['f_manager' => 'emp'], 'manager', 'emp'],
            '裸名回落' => [['manager' => 'emp'], 'manager', 'emp'],
            '# 右半（字段名本身带 f_）' => [['f_userId' => 'emp'], 'manager#f_userId', 'emp'],
            '# 右半的 f_ 变体' => [['f_userId' => 'emp'], 'manager#userId', 'emp'],
            '_NN 去后缀的 f_ 形' => [['f_manager' => 'emp'], 'manager_01', 'emp'],
            '_NN 去后缀的裸名形' => [['manager' => 'emp'], 'manager_01', 'emp'],
        ];
        foreach ($cases as $label => [$vars, $nodeKey, $want]) {
            $args = FlowData::create();
            foreach ($vars as $k => $v) {
                $args->set($k, $v);
            }
            $this->assertSame($want, FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($args, $nodeKey), $label);
        }
    }

    public function testFPrefixBeatsBareName(): void
    {
        $args = FlowData::create()->set('f_x', 'emp')->set('x', 'other');
        $this->assertSame('emp', FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($args, 'x'));
    }

    public function testHashTierPrefersBareFieldThenFPrefix(): void
    {
        $args = FlowData::create()->set('userId', 'emp')->set('f_userId', 'other');
        $this->assertSame('emp', FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($args, 'manager#userId'));
    }

    public function testNumberSuffixTierPrefersFPrefix(): void
    {
        // jeeflow 变量存 f_ ⇒ 第 4 档先 f_ 再裸名（参照实现 fastapi-wf 没有这一支，属两侧差异已被卡 §3.2 吸收）
        $args = FlowData::create()->set('f_manager', 'emp')->set('manager', 'other');
        $this->assertSame('emp', FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($args, 'manager_01'));
    }

    public function testTakesFirstNonEmptyIdOnly(): void
    {
        $this->assertSame('a', FormUserDeptAssignmentHandlerBase::firstFormUserId('a,b'));
        $this->assertSame('b', FormUserDeptAssignmentHandlerBase::firstFormUserId(['', 'b', 'c']));
        $this->assertSame('d', FormUserDeptAssignmentHandlerBase::firstFormUserId('  ,  ,d'));
        $this->assertSame('0', FormUserDeptAssignmentHandlerBase::firstFormUserId('0'),
            '"0" 是合法 id，不能被 PHP 弱判据（empty/"0"===false）吃掉');
        $this->assertNull(FormUserDeptAssignmentHandlerBase::firstFormUserId(['', '  ']), '全空 ⇒ null');
        $this->assertNull(FormUserDeptAssignmentHandlerBase::firstFormUserId(null));
    }

    // ═══ 引擎级：HR 代员工提交两段链路 ════════════════════════════════════════

    public function testHrProxyFlowLandsOnFormUserDeptLeaders(): void
    {
        $instance = $this->startFlow('fu-hr-proxy', $this->hrFlow(), FlowData::create()->set('f_userId', 'emp'));
        $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertSame('apply', $doing[0]->getTaskName(), '夹具：先落 apply 待办');
        $this->engine->executeProcessTask((string) $doing[0]->getTaskId(), 'user1', FlowData::create()->set('submitType', 1));

        $manager = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertSame('manager#f_userId', $manager[0]->getTaskName());
        $this->assertSame(['leader1', 'leader2'], $manager[0]->getActorIds(),
            '经理节点参与者必须落在**表单指定用户**所属部门的经理上（不是操作人的）');

        $this->engine->executeProcessTask((string) $manager[0]->getTaskId(), 'leader1', FlowData::create()->set('submitType', 1));
        $main = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertSame('mainLeader#f_userId', $main[0]->getTaskName());
        $this->assertSame(['boss1'], $main[0]->getActorIds());
    }

    // ═══ 负向：空参与者、不报错、实例仍进行中 ════════════════════════════════

    public function testNegativeShapesLeaveEmptyActorsWithoutException(): void
    {
        $cases = [
            '表单没给字段' => FlowData::create(),
            '值为空串' => FlowData::create()->set('f_userId', ''),
            '值为纯空白' => FlowData::create()->set('f_userId', '   '),
            '列表全空' => FlowData::create()->set('f_userId', ['', '  ']),
            '指向查无此人' => FlowData::create()->set('f_userId', 'ghost'),
            '指向无部门用户' => FlowData::create()->set('f_userId', 'nodept'),
            '指向别的部门（该部门无领导）' => FlowData::create()->set('f_userId', 'other'),
        ];
        foreach ($cases as $label => $args) {
            $instance = $this->startFlow('fu-neg-' . md5($label), $this->hrFlow(), $args);
            $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
            $this->engine->executeProcessTask((string) $doing[0]->getTaskId(), 'user1', FlowData::create()->set('submitType', 1));
            $manager = $this->repo->findDoingTasks((string) $instance->getInstanceId());
            $this->assertSame('manager#f_userId', $manager[0]->getTaskName(), $label . '：仍应建待办行');
            $this->assertSame([], $manager[0]->getActorIds(), $label);
            $fresh = $this->repo->findInstanceById((string) $instance->getInstanceId());
            $this->assertSame(10, $fresh->getState(), $label . '：实例仍进行中，未被空参与者打断');
        }
    }

    public function testMissingRegistryDoesNotThrow(): void
    {
        // SPI 未注册那一档：摘掉 AssignmentHandlerRegistry ⇒ 引擎取不到处理器，参与者为空但不报错
        ServiceContext::remove(AssignmentHandlerRegistry::class);
        $instance = $this->startFlow('fu-no-registry', $this->hrFlow(), FlowData::create()->set('f_userId', 'emp'));
        $doing = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->engine->executeProcessTask((string) $doing[0]->getTaskId(), 'user1', FlowData::create()->set('submitType', 1));
        $manager = $this->repo->findDoingTasks((string) $instance->getInstanceId());
        $this->assertSame([], $manager[0]->getActorIds());
    }

    // ═══ 回归：现存 handler 没被并集查找渗进去 ════════════════════════════════

    public function testFormFieldAssigneeStillUsesItsOwnThreeTiers(): void
    {
        // 现存 FormFieldAssigneeHandler 不认 # 右半的 f_ 变体；新类认 ⇒ 两条腿各自独立
        $args = FlowData::create()->set('f_userId', 'emp');
        $legacy = new FormFieldAssigneeHandler();
        $execution = new \Jeeflow\Core\Execution();
        $execution->setArgs($args);
        $taskModel = new \Jeeflow\Core\Model\TaskModel();
        $taskModel->setName('manager#userId');
        $execution->setNodeModel($taskModel);
        $this->assertNull($legacy->assign($execution), '老类第 3 档只认 _NN，不认 # ⇒ 读不到值应返回 null');
        $this->assertSame('emp', FormUserDeptAssignmentHandlerBase::findFormUserFieldValue($args, 'manager#userId'),
            '新类同一入参应命中 # 右半的 f_ 变体');
    }

    // ═══ 夹具 ═════════════════════════════════════════════════════════════════

    private function hrFlow(): string
    {
        $task = static fn(string $id, string $handler, int $y): array => [
            'id' => $id, 'type' => 'snaker:task', 'x' => 480, 'y' => $y,
            'text' => ['value' => $id],
            'properties' => ['width' => 120, 'height' => 80, 'assignee' => '', 'taskType' => 0,
                             'performType' => 0, 'assignmentHandler' => $handler],
        ];
        return json_encode([
            'name' => 'fu-hr-proxy', 'displayName' => 'HR代员工提交（表单指定用户）', 'type' => 'approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'snaker:start', 'x' => 480, 'y' => 200, 'text' => ['value' => '开始'], 'properties' => []],
                ['id' => 'apply', 'type' => 'snaker:task', 'x' => 480, 'y' => 350, 'text' => ['value' => '发起申请'],
                 'properties' => ['assignee' => 'applicant', 'taskType' => 0, 'performType' => 0]],
                $task('manager#f_userId', self::FQ_LEADER, 500),
                $task('mainLeader#f_userId', self::FQ_MAIN, 650),
                ['id' => 'end', 'type' => 'snaker:end', 'x' => 480, 'y' => 800, 'text' => ['value' => '结束'], 'properties' => []],
            ],
            'edges' => [
                ['id' => 'e0', 'sourceNodeId' => 'start', 'targetNodeId' => 'apply', 'properties' => []],
                ['id' => 'e1', 'sourceNodeId' => 'apply', 'targetNodeId' => 'manager#f_userId', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'manager#f_userId', 'targetNodeId' => 'mainLeader#f_userId', 'properties' => []],
                ['id' => 'e3', 'sourceNodeId' => 'mainLeader#f_userId', 'targetNodeId' => 'end', 'properties' => []],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function startFlow(string $name, string $json, FlowData $args): \Jeeflow\Core\Domain\ProcessInstance
    {
        $id = 'd-' . $name;
        $this->repo->addDefine(['id' => $id, 'name' => $name, 'displayName' => '表单指定用户用例',
            'type' => 'approval', 'state' => 1, 'content' => $json, 'version' => 1]);
        return $this->engine->startProcessInstanceById($id, 'user1', $args);
    }
}
