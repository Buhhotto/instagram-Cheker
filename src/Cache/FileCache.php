<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * تخزين مؤقت على الملفات لتقليل الطلبات المتكررة نحو إنستاجرام.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private string $directory, private bool $enabled = true)
    {
        if ($this->enabled && !is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['expires_at'])) {
            return null;
        }

        if ((int) $payload['expires_at'] < time()) {
            $this->forget($key);

            return null;
        }

        return $payload['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if (!$this->enabled || $ttl <= 0) {
            return;
        }

        $payload = json_encode(
            ['expires_at' => time() + $ttl, 'value' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            return;
        }

        $file = $this->path($key);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) !== false) {
            @rename($temp, $file);
        }
    }

    public function remember(string $key, int $ttl, callable $producer): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }

        return $value;
    }

    public function forget(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** حذف كل الملفات المنتهية الصلاحية؛ يُرجع عدد المحذوف. */
    public function prune(): int
    {
        $removed = 0;
        foreach (glob(rtrim($this->directory, '/') . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $payload = $raw === false ? null : json_decode($raw, true);
            if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < time()) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $key) . '.json';
    }
}
