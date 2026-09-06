<?php

declare(strict_types=1);

namespace App\Instagram\Exception;

/** تجاوز الحد المسموح من الطلبات محليًا أو من طرف إنستاجرام. */
final class RateLimitException extends InstagramException
{
}
