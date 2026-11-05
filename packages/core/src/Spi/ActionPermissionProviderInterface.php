<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * action → 权限码映射提供者 SPI —— 对齐 Java {@code com.mldong.jeeflow.spi.IActionPermissionProvider}
 * （issues/29；hub issues/248 的 PHP 腿，语义参照实现＝java
 * {@code DefaultActionPermissionProvider}，其测试是权威）。
 *
 * **边界（与 java／go／python 逐字同口径）**：引擎**不依赖任何鉴权框架**
 * （sa-token / JWT / 中间件都是宿主框架的事），本 SPI 只提供"action → 权限码"
 * 这一份**元数据**；校验执行仍由集成方框架层完成（如 {@code StpUtil.checkPermissionOr}）。
 *
 * **为什么要在引擎里放这张表**（而不是各集成壳自备）：现读各壳各抄一份
 * （laravel {@code WfPermission.php} 等），新 action 或粒度调整要改十处＋引擎，
 * 且"只用引擎、自写集成层"的客户跨栈读出两套码。本目录两张表与 java 的
 * OR／放行清单**逐条一致**。
 */
interface ActionPermissionProviderInterface
{
    /**
     * 返回 action 的权限码集合（**OR 语义**——任一持有即可访问）。
     *
     * @param string $action facade action（如 {@code "processDefine/detail"}）
     * @return ?string[] 权限码数组；{@code null}／空数组＝放行（登录即可）
     */
    public function permissionCodes(string $action): ?array;
}
