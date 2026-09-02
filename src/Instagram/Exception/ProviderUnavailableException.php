<?php

declare(strict_types=1);

namespace App\Instagram\Exception;

/** لا يوجد مزوّد بيانات مهيّأ وقادر على تنفيذ الطلب. */
final class ProviderUnavailableException extends InstagramException
{
}
