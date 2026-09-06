<?php

declare(strict_types=1);

namespace App\Instagram\Exception;

/** خطأ في طبقة الشبكة (اتصال، مهلة، DNS). */
final class TransportException extends InstagramException
{
}
