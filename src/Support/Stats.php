<?php

declare(strict_types=1);

namespace App\Support;

/**
 * دوال إحصائية مساعدة للتحليل الرقمي.
 */
final class Stats
{
    /** @param list<int|float> $values */
    public static function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /** @param list<int|float> $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /** @param list<int|float> $values الانحراف المعياري للمجتمع. */
    public static function stdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = self::mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / $count);
    }

    /**
     * معامل الاختلاف (الانحراف المعياري ÷ المتوسط) — كلما قلّ زاد الاتساق.
     *
     * @param list<int|float> $values
     */
    public static function coefficientOfVariation(array $values): float
    {
        $mean = self::mean($values);
        if ($mean <= 0.0) {
            return 0.0;
        }

        return self::stdDev($values) / $mean;
    }

    public static function percent(float $part, float $whole, int $precision = 2): float
    {
        if ($whole <= 0.0) {
            return 0.0;
        }

        return round(($part / $whole) * 100, $precision);
    }

    public static function clamp(float $value, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $value));
    }

    /**
     * ترتيب تنازلي لعناصر متكررة مع إرجاع أعلى N.
     *
     * @param list<string> $items
     * @return list<array{value:string,count:int}>
     */
    public static function topCounts(array $items, int $limit = 10): array
    {
        if ($items === []) {
            return [];
        }

        $counts = array_count_values($items);
        arsort($counts);
        $top = array_slice($counts, 0, $limit, true);

        $result = [];
        foreach ($top as $value => $count) {
            $result[] = ['value' => (string) $value, 'count' => (int) $count];
        }

        return $result;
    }
}
