<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

/**
 * أنواع الأرض المدعومة للفلترة. القيمة الداخلية ثابتة؛ قيمة معامل الاستعلام
 * الفعلية التي يفهمها كل موقع تُقرأ من config('property_alerts.sources.<مصدر>.types').
 */
final class PropertyType
{
    public const AGRICULTURAL = 'agricultural';
    public const RESIDENTIAL = 'residential';
    public const INDUSTRIAL = 'industrial';
    public const COMMERCIAL = 'commercial';

    public const ALL = [self::AGRICULTURAL, self::RESIDENTIAL, self::INDUSTRIAL, self::COMMERCIAL];

    /** @return array<string,string> قيمة داخلية ⇄ تسمية عربية للعرض */
    public static function labels(): array
    {
        return [
            self::AGRICULTURAL => 'زراعي',
            self::RESIDENTIAL => 'سكني',
            self::INDUSTRIAL => 'صناعي',
            self::COMMERCIAL => 'تجاري',
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? $type;
    }
}
