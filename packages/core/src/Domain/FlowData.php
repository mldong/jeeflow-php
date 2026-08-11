<?php

declare(strict_types=1);

namespace Jeeflow\Core\Domain;

/**
 * 流程数据载体 —— 对齐 Java FlowData（extends LinkedHashMap）
 *
 * PHP 的关联数组天然有序，直接用 ArrayObject 语义的封装。
 */
class FlowData implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public static function create(): self
    {
        return new self();
    }

    public static function of(array $map): self
    {
        return new self($map);
    }

    public function copy(): self
    {
        return new self($this->data);
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function setAll(array $map): self
    {
        foreach ($map as $k => $v) {
            $this->data[$k] = $v;
        }
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    // ---- 类型安全取值 ----

    public function getStr(string $key, ?string $default = null): ?string
    {
        $v = $this->get($key);
        return $v !== null ? (string) $v : $default;
    }

    public function getLong(string $key): ?int
    {
        $v = $this->get($key);
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return null;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $v = $this->get($key);
        if ($v === null) return $default;
        if (is_int($v) || is_float($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return $default;
    }

    public function getBool(string $key): ?bool
    {
        $v = $this->get($key);
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        $s = strtolower((string) $v);
        return in_array($s, ['true', '1', 'yes'], true);
    }

    public function getArray(string $key): array
    {
        $v = $this->get($key);
        if (is_array($v)) return $v;
        if ($v === null) return [];
        return [$v];
    }

    // ---- ArrayAccess ----

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove((string) $offset);
    }

    // ---- Countable ----

    public function count(): int
    {
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    // ---- IteratorAggregate ----

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }
}
