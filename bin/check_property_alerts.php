#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * سكربت cron لفحص اشتراكات التنبيهات العقارية وإرسال رسائل واتساب عند
 * ظهور إعلانات جديدة. لا يتصفّح نيابة عن أحد ولا يحجز شيئًا — رصد ومقارنة
 * وتنبيه فقط على بيانات عامة.
 */

// مثال جدولة كل 20 دقيقة (عدّل المسار حسب مكان المشروع):
//   */20 * * * * /usr/bin/php /path/to/project/bin/check_property_alerts.php >> /path/to/project/storage/property_alerts.log 2>&1

$root = dirname(__DIR__);

/** @var App\Container $container */
$container = require $root . '/bootstrap.php';

$repository = $container->propertySubscriptionRepository();
$entries = $repository->all();

if ($entries === []) {
    fwrite(STDOUT, '[' . date(DATE_ATOM) . "] لا توجد اشتراكات تنبيهات عقارية.\n");
    exit(0);
}

fwrite(STDOUT, '[' . date(DATE_ATOM) . '] فحص ' . count($entries) . " اشتراكًا...\n");

$results = $container->propertyAlertChecker()->checkAll();

foreach ($results as $id => $result) {
    if (($result['ok'] ?? false) === true) {
        fwrite(STDOUT, sprintf(
            "  - %s: إعلانات جديدة=%d، تنبيهات مُرسلة=%d\n",
            $id,
            (int) ($result['new_listings'] ?? 0),
            (int) ($result['notified'] ?? 0)
        ));
    } else {
        fwrite(STDOUT, sprintf("  - %s: خطأ — %s\n", $id, (string) ($result['error'] ?? 'غير معروف')));
    }
}

fwrite(STDOUT, '[' . date(DATE_ATOM) . "] انتهى الفحص.\n");
