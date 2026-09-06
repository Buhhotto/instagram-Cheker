<?php

declare(strict_types=1);

namespace App\Support;

/**
 * أدوات معالجة نصوص عربية/إنجليزية مستخدمة في تحليل التعليقات والتسميات التوضيحية.
 */
final class Text
{
    private const ARABIC_DIACRITICS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    /** توحيد شكل النص: حروف صغيرة، حذف التشكيل، وتوحيد الألف والياء والتاء المربوطة. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = (string) preg_replace(self::ARABIC_DIACRITICS, '', $text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace(['ى'], 'ي', $text);
        $text = str_replace(['ة'], 'ه', $text);
        $text = str_replace(['ؤ', 'ئ'], 'ء', $text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** @return list<string> تقسيم النص إلى كلمات بعد التوحيد. */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }

    /** @return list<string> استخراج الوسوم (#hashtag) بصيغة موحّدة بدون علامة #. */
    public static function hashtags(string $text): array
    {
        preg_match_all('/#([\p{L}\p{N}_]{1,100})/u', $text, $matches);

        return array_values(array_map(
            static fn (string $tag): string => mb_strtolower($tag, 'UTF-8'),
            $matches[1] ?? []
        ));
    }

    /** @return list<string> استخراج الإشارات (@mention) بدون علامة @. */
    public static function mentions(string $text): array
    {
        preg_match_all('/@([A-Za-z0-9._]{1,30})/u', $text, $matches);

        return array_values(array_map(
            static fn (string $m): string => rtrim(strtolower($m), '.'),
            $matches[1] ?? []
        ));
    }

    /** عدد الرموز التعبيرية داخل النص. */
    public static function countEmoji(string $text): int
    {
        $pattern = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{1F000}-\x{1F2FF}]/u';
        preg_match_all($pattern, $text, $matches);

        return count($matches[0] ?? []);
    }

    public static function containsQuestion(string $text): bool
    {
        return str_contains($text, '?') || str_contains($text, '؟');
    }

    public static function truncate(string $text, int $limit = 140): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1, 'UTF-8') . '…';
    }
}
