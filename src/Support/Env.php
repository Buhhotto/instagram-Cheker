<?php

declare(strict_types=1);

namespace App\Support;

/**
 * قارئ متغيرات البيئة مع دعم ملف .env بصيغة KEY=VALUE.
 */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $loaded = null;

    public static function load(string $file): void
    {
        $values = [];

        if (is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                    $value = substr($value, 1, -1);
                }
                if ($key !== '') {
                    $values[$key] = $value;
                }
            }
        }

        self::$loaded = $values;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$loaded === null) {
            self::$loaded = [];
        }

        $value = self::$loaded[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }
}
