<?php

declare(strict_types=1);

namespace Jeeflow\Core\Repository;

use Jeeflow\Core\Spi\IdGeneratorInterface;
use Jeeflow\Core\Spi\InMemoryIdGenerator;
use Jeeflow\Core\Spi\PageQuery;
use Jeeflow\Core\Spi\PageResult;
use Jeeflow\Core\Spi\ProcessExtRepositoryInterface;

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
        $rows = array_values($this->surrogates);
        $total = count($rows);
        $slice = array_slice($rows, $query->getOffset(), $query->getPageSize());
        return new PageResult($query->getPageNum(), $query->getPageSize(), $total, $slice);
    }

    public function findSurrogateById(int|string $id): ?array
    {
        return $this->surrogates[(string) $id] ?? null;
    }

    public function saveSurrogate(array $surrogate): string
    {
        $id = (string) ($surrogate['id'] ?? $this->idGenerator->nextId());
        $surrogate['id'] = $id;
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
