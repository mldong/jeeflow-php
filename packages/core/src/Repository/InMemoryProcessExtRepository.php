<?php

declare(strict_types=1);

namespace Jeeflow\Core\Repository;

use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;
use Jeeflow\Core\Util\SurrogateRule;

/**
 * 内存扩展仓储 —— 用于单元测试
 */
class InMemoryProcessExtRepository implements ProcessExtRepositoryInterface
{
    /** @var array<string, array> 设计稿 */
    private array $designs = [];

    /** @var array<string, array[]> 设计历史 [designId => [his, ...]] */
    private array $designHis = [];

    /** @var array<string, array> 委托代理 */
    private array $surrogates = [];

    private IdGeneratorInterface $idGenerator;

    public function __construct(?IdGeneratorInterface $idGenerator = null)
    {
        $this->idGenerator = $idGenerator ?? new InMemoryIdGenerator();
    }

    // ── 设计 ──

    public function pageDesigns(PageQuery $query): PageResult
    {
        $rows = array_values($this->designs);
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    public function findDesignById(int|string $id): ?array
    {
        return $this->designs[(string) $id] ?? null;
    }

    public function saveDesign(array $design): string
    {
        $id = (string) ($design['id'] ?? $this->idGenerator->nextId());
        $design['id'] = $id;
        $design['isDeployed'] = $design['isDeployed'] ?? 0;
        $design['createTime'] = $design['createTime'] ?? date('Y-m-d H:i:s');
        $design['updateTime'] = date('Y-m-d H:i:s');
        $this->designs[$id] = $design;
        return $id;
    }

    public function updateDesign(array $design): void
    {
        $id = (string) $design['id'];
        if (isset($this->designs[$id])) {
            $this->designs[$id] = array_merge($this->designs[$id], $design);
            $this->designs[$id]['updateTime'] = date('Y-m-d H:i:s');
        }
    }

    public function saveDesignHis(int|string $designId, string $content, ?string $operator = null): void
    {
        $did = (string) $designId;
        // 去重：如果最新快照内容相同则不重复存
        $latest = $this->findLatestDesignHis($did);
        if ($latest !== null && ($latest['content'] ?? '') === $content) {
            return;
        }
        $this->designHis[$did][] = [
            'designId' => $did,
            'content' => $content,
            'createUser' => $operator,
            'createTime' => date('Y-m-d H:i:s'),
        ];
    }

    public function findLatestDesignHis(int|string $designId): ?array
    {
        $did = (string) $designId;
        $list = $this->designHis[$did] ?? [];
        return empty($list) ? null : end($list);
    }

    public function findDesignHisList(int|string $designId): array
    {
        return $this->designHis[(string) $designId] ?? [];
    }

    public function removeDesign(int|string $id): void
    {
        $did = (string) $id;
        unset($this->designs[$did], $this->designHis[$did]);
    }

    public function updateDesignDeployed(int|string $designId, int $isDeployed): void
    {
        $did = (string) $designId;
        if (isset($this->designs[$did])) {
            $this->designs[$did]['isDeployed'] = $isDeployed;
        }
    }

    public function listDesignsByType(): array
    {
        $grouped = [];
        foreach ($this->designs as $d) {
            $type = $d['type'] ?? 'default';
            $grouped[$type][] = $d;
        }
        return $grouped;
    }

    // ── 委托 ──

    public function pageSurrogates(PageQuery $query): PageResult
    {
        // m_ 条件（issues/82-7 委托搜索，对齐 Java/Go/Python/Node）
        $conditions = $query->getConditions();
        $all = array_values($this->surrogates);
        if ($conditions !== []) {
            $all = array_values(array_filter($all, function (array $s) use ($conditions) {
                return $this->matchSurrogateConditions($s, $conditions);
            }));
        }
        $total = count($all);
        $slice = array_slice($all, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    /**
     * 委托分页 m_ 条件匹配（issues/82-7，内存路径）
     *
     * 白名单列 + 操作符对齐核心 InMemoryProcessRepository::matchCondition 与 JDBC 实现：
     * 内存行键为 camelCase，条件列为 t.<snake_case>。
     */
    private function matchSurrogateConditions(array $row, array $conditions): bool
    {
        $fields = [
            't.id' => $row['id'] ?? null,
            't.process_name' => $row['processName'] ?? null,
            't.operator' => $row['operator'] ?? null,
            't.surrogate' => $row['surrogate'] ?? null,
            't.enabled' => $row['enabled'] ?? null,
            't.start_time' => $row['startTime'] ?? null,
            't.end_time' => $row['endTime'] ?? null,
            't.create_time' => $row['createTime'] ?? null,
            't.update_time' => $row['updateTime'] ?? null,
        ];
        foreach ($conditions as $cond) {
            $col = $cond['column'];
            if (!array_key_exists($col, $fields)) {
                continue; // 白名单外列跳过（对齐其他语言）
            }
            $actual = $fields[$col];
            $value = $cond['value'];
            if ($actual === null || $value === null || $value === '') {
                continue;
            }
            $ok = match ($cond['op']) {
                'EQ' => (string) $actual === (string) $value,
                'NE' => (string) $actual !== (string) $value,
                'LIKE' => str_contains((string) $actual, (string) $value),
                'LLIKE' => str_ends_with((string) $actual, (string) $value),
                'RLIKE' => str_starts_with((string) $actual, (string) $value),
                'IN' => is_array($value) && in_array((string) $actual, array_map('strval', $value), true),
                'NIN' => is_array($value) && !in_array((string) $actual, array_map('strval', $value), true),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function findSurrogateById(int|string $id): ?array
    {
        return $this->surrogates[(string) $id] ?? null;
    }

    /** 查生效中的委托（issues/82-12 引入，issues/116 批次 D 补齐四判据，issues/123 定顺序）：
     *  **先**在流程作用域内按主键 id 取**最新一条**（{@link SurrogateRule::pickLatest}，
     *  不带任何生效判据过滤），**再**由 {@link SurrogateRule::isEffective} 裁决这一条
     *  ——06 §4.5 条款 1.4。顺序反了（先滤 enabled/窗口/自委托、剩下的才取最新）就等于
     *  "历史上留过一条窗内委托就永久生效"，用户后来改停用/挪窗外/自委托都不算数，
     *  正是 issues/123 里 13 栈 L2-17/L2-18 全红的成因。
     *  作用域规则：processName 精确作用域里**只要存在记录**就由它裁决（不回落全局）；
     *  一条都没有才看 `processName` 为 null/'' 的全流程委托作用域。
     *  $time 缺省取当前时间。与 PDO 仓 `PdoProcessExtRepository::getSurrogate` 同形、同答案。 */
    public function getSurrogate(string $operator, string $processName, ?string $time = null): ?array
    {
        $at = SurrogateRule::timeText($time) ?? date('Y-m-d H:i:s');
        $newest = $this->newestSurrogateInScope($operator, $processName);
        if (SurrogateRule::isEffective($newest, $operator, $at)) {
            return $newest;
        }
        if ($processName === '') {
            return null;
        }
        // 精确作用域判否（含池空）⇒ 仍要看全流程作用域的最新一条（条款 1.4 后半句）。
        // "本流程这条废了"不等于"我没委托"：Java 既有测试
        // JdbcProcessExtRepositoryTest#testSurrogateCrudAndGet 钉的是「精确已过期 → 兜底全流程」。
        $global = $this->newestSurrogateInScope($operator, '');
        return SurrogateRule::isEffective($global, $operator, $at) ? $global : null;
    }

    /** 该授权人在指定流程作用域内 id 最大（最新）的一条委托；作用域内无记录返回 null。
     *  $processName 为 '' 表示"全流程委托"作用域（process_name 为 null/''）。 */
    private function newestSurrogateInScope(string $operator, string $processName): ?array
    {
        $scope = [];
        foreach ($this->surrogates as $s) {
            if ((string) ($s['operator'] ?? '') !== $operator) continue;
            $pn = (string) ($s['processName'] ?? '');
            if ($processName === '') {
                if ($pn !== '') continue;
            } elseif ($pn !== $processName) {
                continue;
            }
            $scope[] = $s;
        }
        return $scope === [] ? null : SurrogateRule::pickLatest($scope);
    }

    public function saveSurrogate(array $surrogate): string
    {
        $id = (string) ($surrogate['id'] ?? $this->idGenerator->nextId());
        $surrogate['id'] = $id;
        // 写侧判据归一（06 §4.5 条款 5「写侧」，与 PDO 仓 saveSurrogate 同一套）：
        // 缺键/null→1、布尔 true→1 false→0、'' 与不可解析脏值→0 且不抛错。
        // 不归一的话内存仓会把 'abc' / '1abc' 原样存进去，PDO 仓则落 0/1——
        // 台账回显与"脏值不生效"这条判据在两仓就对不上（条款 6 双仓同答案）。
        $surrogate['enabled'] = SurrogateRule::normalizeEnabledArg($surrogate['enabled'] ?? null);
        $surrogate['createTime'] = $surrogate['createTime'] ?? date('Y-m-d H:i:s');
        $surrogate['updateTime'] = date('Y-m-d H:i:s');
        $this->surrogates[$id] = $surrogate;
        return $id;
    }

    public function updateSurrogate(array $surrogate): void
    {
        $id = (string) ($surrogate['id'] ?? '');
        if (isset($this->surrogates[$id])) {
            $this->surrogates[$id] = array_merge($this->surrogates[$id], $surrogate);
            $this->surrogates[$id]['updateTime'] = date('Y-m-d H:i:s');
        }
    }

    public function removeSurrogate(int|string $id): void
    {
        unset($this->surrogates[(string) $id]);
    }
}
