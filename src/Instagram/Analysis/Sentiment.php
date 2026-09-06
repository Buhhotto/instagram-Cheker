<?php

declare(strict_types=1);

namespace App\Instagram\Analysis;

use App\Support\Text;

/**
 * محلّل نبرة بسيط قائم على معجم عربي/إنجليزي مع معالجة النفي.
 *
 * ليس بديلًا عن نموذج لغوي، لكنه كافٍ لقياس اتجاه عام للتعليقات والتسميات.
 */
final class Sentiment
{
    private const POSITIVE = [
        'رائع', 'ممتاز', 'جميل', 'شكرا', 'شكرًا', 'احسنت', 'أحسنت', 'مبدع', 'مفيد', 'حلو', 'يعجبني',
        'أحب', 'احب', 'موفق', 'تهانينا', 'مبروك', 'قمه', 'قمة', 'اجمل', 'أجمل', 'سعيد', 'ممتع', 'نجاح',
        'good', 'great', 'amazing', 'awesome', 'love', 'nice', 'perfect', 'thanks', 'thank', 'best',
        'helpful', 'beautiful', 'excellent', 'congrats', 'wow', 'happy', 'brilliant',
    ];

    private const NEGATIVE = [
        'سيء', 'سيئ', 'ضعيف', 'ممل', 'مبالغ', 'كذب', 'مزعج', 'فاشل', 'خطا', 'خطأ', 'مشكله', 'مشكلة',
        'اسوا', 'أسوأ', 'حزين', 'غاضب', 'رديء', 'تافه', 'مخيب', 'زعلان',
        'bad', 'worst', 'boring', 'hate', 'awful', 'terrible', 'poor', 'wrong', 'fake', 'scam',
        'disappointed', 'annoying', 'useless', 'sad',
    ];

    private const NEGATIONS = ['لا', 'ما', 'مش', 'ليس', 'غير', 'not', 'no', 'never', "don't", 'dont'];

    /**
     * تحليل نص واحد.
     *
     * @return array{label:string,score:float,positive:int,negative:int}
     */
    public function analyze(string $text): array
    {
        $words = Text::words($text);
        $positive = 0;
        $negative = 0;
        $negated = false;

        foreach ($words as $word) {
            if (in_array($word, self::NEGATIONS, true)) {
                $negated = true;
                continue;
            }

            $isPositive = $this->matches($word, self::POSITIVE);
            $isNegative = $this->matches($word, self::NEGATIVE);

            if ($isPositive || $isNegative) {
                // النفي يقلب اتجاه الكلمة التي تليه مباشرة فقط.
                if ($negated) {
                    [$isPositive, $isNegative] = [$isNegative, $isPositive];
                }

                $positive += $isPositive ? 1 : 0;
                $negative += $isNegative ? 1 : 0;
            }

            $negated = false;
        }

        $total = $positive + $negative;
        $score = $total === 0 ? 0.0 : round(($positive - $negative) / $total, 3);

        return [
            'label' => match (true) {
                $score >= 0.25 => 'positive',
                $score <= -0.25 => 'negative',
                default => 'neutral',
            },
            'score' => $score,
            'positive' => $positive,
            'negative' => $negative,
        ];
    }

    /**
     * تحليل مجموعة نصوص وإرجاع التوزيع العام.
     *
     * @param list<string> $texts
     * @return array{positive:int,negative:int,neutral:int,total:int,score:float,label:string}
     */
    public function summarize(array $texts): array
    {
        $counts = ['positive' => 0, 'negative' => 0, 'neutral' => 0];
        $scores = [];

        foreach ($texts as $text) {
            $result = $this->analyze($text);
            $counts[$result['label']]++;
            $scores[] = $result['score'];
        }

        $total = count($texts);
        $average = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 3);

        return $counts + [
            'total' => $total,
            'score' => $average,
            'label' => match (true) {
                $average >= 0.15 => 'positive',
                $average <= -0.15 => 'negative',
                default => 'neutral',
            },
        ];
    }

    /** @param list<string> $lexicon */
    private function matches(string $word, array $lexicon): bool
    {
        if (in_array($word, $lexicon, true)) {
            return true;
        }

        // مطابقة السوابق العربية الشائعة (ال، و، ف) وصيغ الجمع الإنجليزية.
        foreach (['ال', 'و', 'ف', 'ب'] as $prefix) {
            if (str_starts_with($word, $prefix) && in_array(mb_substr($word, mb_strlen($prefix)), $lexicon, true)) {
                return true;
            }
        }

        return str_ends_with($word, 's') && in_array(substr($word, 0, -1), $lexicon, true);
    }
}
