#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * سكربت cron لفحص قائمة مراقبة أسماء المستخدمين وإطلاق تنبيهات webhook
 * عند تحوّل حالة أي اسم. لا يُسجّل دخولًا لأي حساب ولا يُغيّر أي اسم مستخدم
 * — يكتفي بالفحص والتنبيه، وفعل التسجيل يبقى يدويًا من صاحب القرار.
 */

// مثال جدولة كل 15 دقيقة (عدّل المسار حسب مكان المشروع):
//   */15 * * * * /usr/bin/php /path/to/project/bin/check_watchlist.php >> /path/to/project/storage/watchlist.log 2>&1

$root = dirname(__DIR__);

/** @var App\Container $container */
$container = require $root . '/bootstrap.php';

$repository = $container->watchlistRepository();
$entries = $repository->all();

if ($entries === []) {
    fwrite(STDOUT, '[' . date(DATE_ATOM) . "] لا توجد أسماء في قائمة المراقبة.\n");
    exit(0);
}

fwrite(STDOUT, '[' . date(DATE_ATOM) . '] فحص ' . count($entries) . " اسمًا...\n");

$results = $container->watchlistChecker()->checkAll();

foreach ($results as $username => $result) {
    $status = (string) ($result['status'] ?? 'error');
    fwrite(STDOUT, sprintf("  - %s: %s\n", $username, $status));
}

fwrite(STDOUT, '[' . date(DATE_ATOM) . "] انتهى الفحص.\n");
