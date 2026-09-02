<?php

declare(strict_types=1);

namespace App\Instagram\Analysis;

use App\Instagram\DTO\Media;
use App\Support\Stats;
use App\Support\Text;

/**
 * كشف المنشورات المُعاد نشرها (Repost).
 *
 * لا توفّر واجهات إنستاجرام حقل "معاد نشره"، لذلك نعتمد مؤشرات نصية مرجّحة
 * (كلمات دالة، وسوم، نسب المصدر) ونُرجع درجة ثقة بدل ادّعاء يقين.
 */
final class RepostDetector
{
    /** أوزان المؤشرات؛ المجموع الأقصى مضبوط عند 1.0 بعد القصّ. */
    private const KEYWORD_WEIGHTS = [
        'repost' => 0.55,
        'reposted' => 0.55,
        'اعاده نشر' => 0.55,
        'اعادة نشر' => 0.55,
        'معاد نشره' => 0.55,
        'ريبوست' => 0.55,
        'credit' => 0.35,
        'credits' => 0.35,
        'credit to' => 0.4,
        'cr:' => 0.35,
        'via' => 0.3,
        'source' => 0.2,
        'المصدر' => 0.3,
        'الكريدت' => 0.35,
        'بواسطه' => 0.2,
        'منقول' => 0.5,
        'shared from' => 0.45,
        'rp' => 0.2,
        'dc:' => 0.3,
    ];

    private const HASHTAGS = ['repost', 'reposted', 'regram', 'ريبوست', 'اعادة_نشر', 'منقول'];

    private const THRESHOLD = 0.45;

    /**
     * تحليل منشور واحد.
     *
     * @return array{is_repost:bool,confidence:float,signals:list<string>,credited:?string}
     */
    public function inspect(Media $media): array
    {
        $normalized = Text::normalize($media->caption);
        $score = 0.0;
        $signals = [];

        foreach (self::KEYWORD_WEIGHTS as $keyword => $weight) {
            if ($normalized !== '' && str_contains($normalized, $keyword)) {
                $score += $weight;
                $signals[] = 'كلمة دالة: ' . $keyword;
            }
        }

        foreach ($media->hashtags() as $hashtag) {
            if (in_array(Text::normalize($hashtag), self::HASHTAGS, true)) {
                $score += 0.4;
                $signals[] = 'وسم: #' . $hashtag;
            }
        }

        $mentions = $media->mentions();

        if ($media->sharedFrom !== null && $media->sharedFrom !== '') {
            $score += 0.6;
            $signals[] = 'مصدر معلن: @' . $media->sharedFrom;
        }

        // إشارة في بداية التسمية غالبًا نسبة للمصدر الأصلي.
        if ($mentions !== [] && preg_match('/^\s*@[A-Za-z0-9._]+/u', $media->caption) === 1) {
            $score += 0.15;
            $signals[] = 'إشارة في بداية النص: @' . $mentions[0];
        }

        $credited = $media->sharedFrom ?? ($mentions[0] ?? null);
        $confidence = round(min(1.0, $score), 2);

        return [
            'is_repost' => $confidence >= self::THRESHOLD,
            'confidence' => $confidence,
            'signals' => array_values(array_unique($signals)),
            'credited' => $confidence >= self::THRESHOLD ? $credited : null,
        ];
    }

    /**
     * تحليل مجموعة منشورات وإرجاع ملخص إعادة النشر.
     *
     * @param list<Media> $mediaItems
     * @return array{count:int,total:int,ratio:float,originality_score:float,top_sources:list<array{value:string,count:int}>,samples:list<array<string,mixed>>,method:string}
     */
    public function summarize(array $mediaItems): array
    {
        $reposts = [];
        $sources = [];

        foreach ($mediaItems as $media) {
            $result = $this->inspect($media);
            if (!$result['is_repost']) {
                continue;
            }

            if ($result['credited'] !== null) {
                $sources[] = $result['credited'];
            }

            $reposts[] = [
                'id' => $media->id,
                'permalink' => $media->permalink,
                'published_at' => $media->publishedAt(),
                'caption_preview' => Text::truncate($media->caption, 110),
                'confidence' => $result['confidence'],
                'signals' => $result['signals'],
                'credited' => $result['credited'],
            ];
        }

        $total = count($mediaItems);
        $count = count($reposts);
        $ratio = Stats::percent((float) $count, (float) $total);

        return [
            'count' => $count,
            'total' => $total,
            'ratio' => $ratio,
            'originality_score' => round(Stats::clamp(100 - $ratio), 1),
            'top_sources' => Stats::topCounts($sources, 5),
            'samples' => array_slice($reposts, 0, 5),
            'method' => 'تحليل نصي لمؤشرات إعادة النشر (كلمات دالة، وسوم، نسب المصدر) — نتيجة تقديرية.',
        ];
    }
}
