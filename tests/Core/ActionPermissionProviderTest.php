<?php

declare(strict_types=1);

namespace Jeeflow\Tests\Core;

use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\ActionPermissionProviderInterface;
use Jeeflow\Core\Spi\DefaultActionPermissionProvider;
use PHPUnit\Framework\TestCase;

/**
 * action → 权限码映射 SPI 单元测试（issues/29 跨语言续腿，hub issues/248 PHP 腿）。
 *
 * 行为基准＝java {@code DefaultActionPermissionProvider}（其测试是权威）：
 * 放行 11 条 → OR 8 条 → 默认派生 wf:{action 的 / 换 :}，三档优先级逐条照抄。
 */
class ActionPermissionProviderTest extends TestCase
{
    private DefaultActionPermissionProvider $provider;

    protected function setUp(): void
    {
        ServiceContext::clear();
        $this->provider = new DefaultActionPermissionProvider();
    }

    protected function tearDown(): void
    {
        ServiceContext::clear();
    }

    // ═══ ③ 默认派生档 ═══

    public function testDefaultDerivation(): void
    {
        $this->assertSame(['wf:processDefine:page'], $this->provider->permissionCodes('processDefine/page'));
        $this->assertSame(['wf:processTask:complete'], $this->provider->permissionCodes('processTask/complete'));
        // 统计族不在放行清单里的成员仍走默认派生（放行的是三条具体 action，不是整族前缀）
        $this->assertSame(['wf:processInstance:stats:export'],
            $this->provider->permissionCodes('processInstance/stats/export'));
    }

    // ═══ ② OR 表档（任一持有即有权） ═══

    public function testOrRules(): void
    {
        $this->assertSame(
            ['wf:processDefine:detail', 'wf:processDesign:listByType'],
            $this->provider->permissionCodes('processDefine/detail')
        );
        $this->assertSame(
            ['wf:processTask:execute', 'wf:processTask:candidatePage'],
            $this->provider->permissionCodes('processTask/candidatePage')
        );
        $this->assertSame(
            ['wf:processDefine:detail', 'wf:processDesign:listByType', 'wf:processDefine:getLastByName'],
            $this->provider->permissionCodes('processDefine/getLastByName')
        );
        $this->assertSame(
            ['wf:processDefine:startAndExecute', 'wf:processDesign:listByType'],
            $this->provider->permissionCodes('processDefine/startAndExecute')
        );
        $this->assertSame(
            ['wf:processTask:execute'],
            $this->provider->permissionCodes('processTask/jumpAbleTaskNameList')
        );
    }

    // ═══ issues/246／247 两枚新 action：恰复用"办理"码（B 案） ═══

    public function testNewActionsReuseExecuteCode(): void
    {
        $this->assertSame(['wf:processTask:execute'], $this->provider->permissionCodes('processTask/recall'));
        $this->assertSame(['wf:processTask:execute'],
            $this->provider->permissionCodes('processTask/revokeDynamicCountersign'));
    }

    /**
     * 反向哨兵：transfer／removeTaskActor 与两枚新 action 同族但**不在** OR 表——
     * 若有人"顺手"把它们也抬进表里复用 execute，本条当场红（B 案判据只拍了 recall／revoke 两枚）。
     */
    public function testReverseSentinelsStillDefaultDerived(): void
    {
        $this->assertSame(['wf:processTask:transfer'],
            $this->provider->permissionCodes('processTask/transfer'));
        $this->assertSame(['wf:processTask:removeTaskActor'],
            $this->provider->permissionCodes('processTask/removeTaskActor'));
    }

    // ═══ ① 放行档（登录即可） ═══

    public function testNoPermActionsPass(): void
    {
        $pass = [
            'processInstance/detail', 'processInstance/highLight', 'processInstance/approvalRecord',
            'processInstance/getAssigneeTextData', 'processInstance/bizData',
            'processTask/detail', 'processTask/addCandidate', 'processTask/latest',
            // 统计三条（issues/103 收口成果）必须逐枚钉死，防"整族前缀匹配"式偷懒改写
            'processInstance/stats/overview', 'processInstance/stats/trend', 'processInstance/stats/group',
        ];
        foreach ($pass as $action) {
            $this->assertNull($this->provider->permissionCodes($action), "放行清单成员 $action 应返回 null");
        }
    }

    public function testNullActionPasses(): void
    {
        // 对齐 java `if (action == null) return null;`
        $this->assertNull($this->provider->permissionCodes(null));
        $this->assertNull(DefaultActionPermissionProvider::permissionCodesFor(null));
    }

    // ═══ 两表条目数＝跨栈契约形状锚（8 与 11，加一条要八栈同批改） ═══

    public function testTableEntryCounts(): void
    {
        $this->assertCount(8, DefaultActionPermissionProvider::OR_RULES,
            'OR 表条目数钉死 8（java OR_RULES 同数；issues/253 协办 +1）');
        $this->assertCount(11, DefaultActionPermissionProvider::NO_PERM_ACTIONS,
            '放行清单条目数钉死 11（java NO_PERM_ACTIONS 同数）');
    }

    // ═══ 可选覆盖位：未注册＝内置默认，注册即生效，注册 null 回落 ═══

    public function testProviderOrDefaultIsBuiltInWhenUnregistered(): void
    {
        $p = DefaultActionPermissionProvider::providerOrDefault();
        $this->assertInstanceOf(DefaultActionPermissionProvider::class, $p);
    }

    public function testOverrideRegistrationTakesEffect(): void
    {
        $custom = new class implements ActionPermissionProviderInterface {
            public function permissionCodes(string $action): ?array
            {
                return $action === 'processDefine/detail' ? null : ['custom:' . $action];
            }
        };
        DefaultActionPermissionProvider::setProvider($custom);
        $this->assertSame($custom, DefaultActionPermissionProvider::providerOrDefault());
        $this->assertNull(DefaultActionPermissionProvider::permissionCodesFor('processDefine/detail'));
        $this->assertSame(['custom:processTask/recall'],
            DefaultActionPermissionProvider::permissionCodesFor('processTask/recall'));
    }

    public function testRegisterNullFallsBackToDefault(): void
    {
        DefaultActionPermissionProvider::setProvider(new class implements ActionPermissionProviderInterface {
            public function permissionCodes(string $action): ?array
            {
                return ['anything'];
            }
        });
        DefaultActionPermissionProvider::setProvider(null);
        $this->assertInstanceOf(DefaultActionPermissionProvider::class,
            DefaultActionPermissionProvider::providerOrDefault());
        $this->assertSame(['wf:processTask:execute'],
            DefaultActionPermissionProvider::permissionCodesFor('processTask/recall'));
    }

    // ═══ 元数据清单条目（java HandlerRegistry 同约定） ═══

    public function testMetadataConstants(): void
    {
        $this->assertSame('ActionPermissionProvider', DefaultActionPermissionProvider::META_TYPE);
        $this->assertSame('com.mldong.jeeflow.spi.DefaultActionPermissionProvider',
            DefaultActionPermissionProvider::META_CLASS);
    }
}
