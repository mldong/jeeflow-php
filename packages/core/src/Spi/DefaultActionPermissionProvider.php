<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

use Jeeflow\Core\ServiceContext;

/**
 * action → 权限码映射默认实现 —— 逐条对齐 Java
 * {@code com.mldong.jeeflow.spi.DefaultActionPermissionProvider}（issues/29，语义不许自创）：
 *
 * - 默认规则：{@code wf:{action 的 / 换 :}}（如 {@code processDefine/page} → {@code wf:processDefine:page}）
 * - OR 语义 action：任一权限码持有即可（boot3 {@code @SaCheckPermission(value={...}, mode=OR)}）
 * - 无权限注解 action：放行（登录即可，boot3 无注解＝登录即可）
 *
 * 三档优先级（java {@code permissionCodes} 的判据次序）：
 * ① 放行清单 {@see self::NO_PERM_ACTIONS}（11 条）→ null；
 * ② OR 表 {@see self::OR_RULES}（8 条）→ 权限码数组；
 * ③ 默认派生 {@code "wf:" . str_replace('/', ':', $action)}。
 */
final class DefaultActionPermissionProvider implements ActionPermissionProviderInterface
{
    /** 元数据类型名（与 java HandlerRegistry／go／python 的登记逐字一致） */
    public const META_TYPE = 'ActionPermissionProvider';

    /** 元数据类名串（四语言通用约定：注册名使用 Java 类全限定名） */
    public const META_CLASS = 'com.mldong.jeeflow.spi.DefaultActionPermissionProvider';

    /**
     * OR 规则表（对齐 java OR_RULES，8 条——条目数是跨栈契约形状锚，加一条要八栈同批改）。
     */
    public const OR_RULES = [
        'processDefine/detail' => ['wf:processDefine:detail', 'wf:processDesign:listByType'],
        'processDefine/startAndExecute' => ['wf:processDefine:startAndExecute', 'wf:processDesign:listByType'],
        'processDefine/getLastByName' => ['wf:processDefine:detail', 'wf:processDesign:listByType',
                                          'wf:processDefine:getLastByName'],
        'processTask/candidatePage' => ['wf:processTask:execute', 'wf:processTask:candidatePage'],
        'processTask/jumpAbleTaskNameList' => ['wf:processTask:execute'],
        // issues/246／247 两枚新 action（owner 2026-11-04 拍 B）：**复用"办理"这枚码**，不另建菜单按钮种子。
        // 判据是既有惯例——transfer／removeTaskActor／candidatePage／latest 都没有 sys_menu 按钮行，
        // candidatePage／jumpAbleTaskNameList 就是走本表复用 execute；而撤回／撤会签的主体按引擎判据
        // 本来就必然是该行参与者（＝有办理权的人），拆出独立码实际给不出去，只会多一处存量库补种子的迁移。
        // 六语言同款落点见各集成壳的权限表（本轮同批，hub issues/248）。
        'processTask/recall' => ['wf:processTask:execute'],
        'processTask/revokeDynamicCountersign' => ['wf:processTask:execute'],
        // issues/253 动态协办：照 246/247 B 案惯例复用"办理"这枚码，不建按钮种子行——
        // 派生协办的主体按引擎判据本来就必然是主办行名册内的人（＝有办理权的人）。
        'processTask/aidant' => ['wf:processTask:execute'],
    ];

    /**
     * 放行清单（对齐 java NO_PERM_ACTIONS，11 条——boot3 无权限注解的 action，登录即可）。
     */
    public const NO_PERM_ACTIONS = [
        'processInstance/detail', 'processInstance/highLight', 'processInstance/approvalRecord',
        'processInstance/getAssigneeTextData', 'processInstance/bizData',
        'processTask/detail', 'processTask/addCandidate', 'processTask/latest',
        // 统计三 action：登录即可、不配权限码（spec 06 §2.6 放行清单 / 契约 12 §1，
        // issues/103 收口后普通用户分析页被默认规则 wf:processInstance:stats:* 拦截的修复）
        'processInstance/stats/overview', 'processInstance/stats/trend', 'processInstance/stats/group',
    ];

    /**
     * 三档判据（与 java 同序）：放行 → OR → 默认派生；action 为 null 同放行档。
     *
     * 参数放宽为 {@code ?string} 对齐 java {@code if (action == null) return null;}
     * （接口腿 {@code string} 是其协点子集，实现合法）。
     */
    public function permissionCodes(?string $action = null): ?array
    {
        if ($action === null) {
            return null;                                   // 对齐 java null 档：放行
        }
        if (in_array($action, self::NO_PERM_ACTIONS, true)) {
            return null;                                   // 放行
        }
        $or = self::OR_RULES[$action] ?? null;
        if ($or !== null) {
            return $or;                                    // OR 语义
        }
        return ['wf:' . str_replace('/', ':', $action)];   // 默认
    }

    // ─── 可选覆盖位（java ServiceContext.find(IActionPermissionProvider) 的同位）───
    //
    // 与 java 一致的兜底语义：**没注册就等于用内置默认件**，集成方不配也有统一权限语义；
    // 特殊集成方（另一套权限码体系）覆盖注册后按自家规则走；注册 null（setProvider(null)
    // 即从 ServiceContext 摘除）回落到内置默认件。

    /**
     * 取当前生效的提供者（恒非 null，故调用方不必判空）。
     * 每次重新解析、不缓存，以免集成方在首个调用之后才注册自己的实现。
     */
    public static function providerOrDefault(): ActionPermissionProviderInterface
    {
        $provider = ServiceContext::find(ActionPermissionProviderInterface::class);
        return $provider instanceof ActionPermissionProviderInterface
            ? $provider
            : new self();
    }

    /** 覆盖注册（传 null 从 ServiceContext 摘除、回落内置默认件）。 */
    public static function setProvider(?ActionPermissionProviderInterface $provider): void
    {
        if ($provider === null) {
            ServiceContext::remove(ActionPermissionProviderInterface::class);
            return;
        }
        ServiceContext::put(ActionPermissionProviderInterface::class, $provider);
    }

    /** 便捷出口：按当前生效提供者取 action 的权限码集合（OR 语义；null／空数组＝放行）。 */
    public static function permissionCodesFor(?string $action): ?array
    {
        if ($action === null) {
            return null;                                   // 对齐 java null 档：放行
        }
        return self::providerOrDefault()->permissionCodes($action);
    }
}
