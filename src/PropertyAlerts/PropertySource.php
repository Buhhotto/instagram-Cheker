<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

/**
 * مصادر الإعلانات العقارية المدعومة. القيمة الداخلية تطابق مفتاح
 * config('property_alerts.sources.<key>')، حيث تُقرأ إعدادات كل مصدر
 * (رابط الأساس، معاملات الفلترة، مُحدِّدات XPath) وتسميته للعرض.
 */
final class PropertySource
{
    public const OMANREAL = 'omanreal';
    public const OPENSOOQ = 'opensooq';

    public const ALL = [self::OMANREAL, self::OPENSOOQ];

    public const DEFAULT = self::OMANREAL;

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
