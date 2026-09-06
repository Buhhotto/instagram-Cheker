<?php

declare(strict_types=1);

namespace App\PropertyAlerts\Contracts;

/**
 * عقد إرسال تنبيه واتساب عند ظهور إعلان جديد مطابق لاشتراك.
 */
interface Notifier
{
    /** يُرجع true عند نجاح الإرسال (استُقبل من مزوّد واتساب)، و false غير ذلك. */
    public function send(string $toPhone, string $message): bool;
}
