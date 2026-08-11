<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

class BuiltinJsonProvider implements JsonProviderInterface
{
    public function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function decode(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc);
    }
}
