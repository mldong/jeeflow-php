<?php

declare(strict_types=1);

namespace Jeeflow\Core\Metadata;

/**
 * 处理器元数据注册中心（设计器字典）——对齐 Java HandlerRegistry
 */
final class HandlerRegistry
{
    /** @var HandlerMeta[] */
    private array $items = [];

    public function register(HandlerMeta $meta): void
    {
        $this->items[] = $meta;
    }

    /**
     * @return HandlerMeta[]
     */
    public function listHandlers(?string $type = null, ?string $group = null): array
    {
        $out = [];
        foreach ($this->items as $item) {
            if ($type !== null && $item->type !== $type) {
                continue;
            }
            if ($group !== null && $item->group !== $group) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }
}
