<?php

declare(strict_types=1);

/**
 * تهيئة المنظومة: المحمّل التلقائي، متغيرات البيئة، الدوال المساعدة، ثم الحاوية.
 * يُرجع نسخة جاهزة من App\Container.
 *
 * الاستخدام: $container = require __DIR__ . '/bootstrap.php';
 */

use App\Autoloader;
use App\Container;

$root = __DIR__;

// نفضّل محمّل Composer إن وُجد، وإلا نستخدم المحمّل الداخلي المتوافق مع PSR-4.
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    require_once $root . '/src/Autoloader.php';
    Autoloader::register($root . '/src');
}

require_once $root . '/src/helpers.php';

return Container::boot($root);
