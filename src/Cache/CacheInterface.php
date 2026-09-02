<?php

declare(strict_types=1);

namespace App\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    /** يُرجع القيمة المخزّنة أو ينفّذ المولّد ويخزّن ناتجه. */
    public function remember(string $key, int $ttl, callable $producer): mixed;

    public function forget(string $key): void;
}
