<?php

declare(strict_types=1);

namespace App\Support;

/**
 * حاوية إعدادات للقراءة فقط تدعم المفاتيح المتداخلة بصيغة "instagram.graph.token".
 */
final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public static function fromFile(string $file): self
    {
        $items = require $file;

        return new self(is_array($items) ? $items : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<int|string,mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }
}
