<?php

declare(strict_types=1);

namespace App;

/**
 * محمّل تلقائي بسيط متوافق مع PSR-4 حتى تعمل المنظومة بدون Composer.
 */
final class Autoloader
{
    public static function register(string $baseDir, string $prefix = 'App\\'): void
    {
        spl_autoload_register(static function (string $class) use ($baseDir, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = rtrim($baseDir, '/') . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
