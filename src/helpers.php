<?php

declare(strict_types=1);

use App\View\View;

if (!function_exists('e')) {
    /** هروب HTML للقوالب. */
    function e(mixed $value): string
    {
        return View::escape($value);
    }
}

if (!function_exists('num')) {
    /** تنسيق الأعداد الكبيرة بصيغة مختصرة (12.4K / 3.1M). */
    function num(int|float|null $value, int $decimals = 0): string
    {
        $value = (float) ($value ?? 0);

        return match (true) {
            abs($value) >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M',
            abs($value) >= 10_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'K',
            default => number_format($value, $decimals),
        };
    }
}

if (!function_exists('pct')) {
    /** تنسيق نسبة مئوية. */
    function pct(int|float|null $value, int $decimals = 1): string
    {
        return number_format((float) ($value ?? 0), $decimals) . '%';
    }
}

if (!function_exists('score_class')) {
    /** تصنيف لوني للدرجات من 0 إلى 100. */
    function score_class(int|float|null $score): string
    {
        $score = (float) ($score ?? 0);

        return match (true) {
            $score >= 70 => 'good',
            $score >= 40 => 'mid',
            default => 'low',
        };
    }
}
