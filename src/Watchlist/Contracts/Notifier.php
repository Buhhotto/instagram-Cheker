<?php

declare(strict_types=1);

namespace App\Watchlist\Contracts;

/**
 * عقد إرسال تنبيه خارجي (webhook) عند تحوّل حالة اسم مراقَب.
 */
interface Notifier
{
    /**
     * إرسال التنبيه؛ يُرجع true عند نجاح الإرسال (حالة 2xx) و false غير ذلك.
     *
     * @param array<string,mixed> $payload
     */
    public function send(string $url, array $payload): bool;
}
