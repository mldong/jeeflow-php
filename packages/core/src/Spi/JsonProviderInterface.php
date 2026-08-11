<?php

declare(strict_types=1);

namespace Jeeflow\Core\Spi;

/**
 * JSON 解析 SPI —— 对齐 Java IJsonProvider
 */
interface JsonProviderInterface
{
    public function encode(mixed $value): string;
    public function decode(string $json, bool $assoc = true): mixed;
}
