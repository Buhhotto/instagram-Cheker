<?php

declare(strict_types=1);

namespace App\Instagram;

use App\Instagram\Analysis\RepostDetector;
use App\Instagram\Analysis\Sentiment;
use App\Instagram\DTO\Comment;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Instagram\Exception\NotFoundException;
use App\Support\Stats;
use App\Support\Text;

/**
 * قياس سلوك حساب: متى ينشر، كيف يكتب، كيف يتفاعل الجمهور معه،
 * وكيف يردّ صاحب الحساب على التعليقات.
 */
final class BehaviorAnalyzer
{
    private const DAY_NAMES = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    private const CTA_WORDS = [
        'شارك', 'شاركونا', 'اكتب', 'اكتبوا', 'احفظ', 'احفظوا', 'تابع', 'تابعوني', 'علق', 'رايك', 'رأيك',
        'comment', 'share', 'save', 'follow', 'tag', 'link', 'الرابط', 'البايو',
    ];

    public function __construct(
        private ProviderChain $providers,
        private UsernameChecker $usernameChecker,
        private Sentiment $sentiment,
        private RepostDetector $repostDetector,
        private int $defaultLimit = 25,
        private int $commentPostsSample = 5,
        private int $commentsPerPost = 50,
    ) {
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function analyze(string $username, ?int $limit = null): array
    {
        $username = $this->usernameChecker->normalize($username);
        $limit = max(1, min(50, $limit ?? $this->defaultLimit));

        $profile = $this->providers->fetchProfile($username);
        if ($profile === null) {
            throw new NotFoundException(sprintf('لم يُعثر على الحساب "%s" أو أنه غير متاح للقراءة.', $username));
        }

        $media = $this->providers->fetchMedia($username, $limit);
        $comments = $this->collectComments($username, $media);

        $timing = $this->timingBehavior($media);
        $writing = $this->writingBehavior($media);
        $responses = $this->responseBehavior($username, $media, $comments);
        $reposts = $this->repostDetector->summarize($media);
        $scores = $this->scores($profile, $media, $timing, $writing, $responses, $reposts);

        return [
            'username' => $profile->username,
            'generated_at' => date(DATE_ATOM),
            'source' => $this->providers->lastSource() ?? $profile->source,
            'profile' => $profile->jsonSerialize(),
            'sample' => [
                'analyzed_posts' => count($media),
                'analyzed_comments' => count($comments),
                'comment_posts_sample' => min($this->commentPostsSample, count($media)),
            ],
            'timing' => $timing,
            'writing' => $writing,
            'responses' => $responses,
            'originality' => [
                'score' => $reposts['originality_score'],
                'repost_ratio' => $reposts['ratio'],
                'repost_count' => $reposts['count'],
            ],
            'scores' => $scores,
            'archetype' => $this->archetype($scores, $writing, $responses),
            'recommendations' => $this->recommendations($scores, $timing, $writing, $responses),
            'warnings' => array_merge(
                $this->providers->warnings(),
                $this->providers->sourceWarnings(),
                $this->limitations($username, $media, $comments)
            ),
        ];
    }

    /**
     * سلوك التوقيت: خريطة النشاط، أفضل الأوقات، والانتظام.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function timingBehavior(array $media): array
    {
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $hours = array_fill(0, 24, 0);
        $days = array_fill(0, 7, 0);
        $timestamps = [];

        foreach ($media as $item) {
            if ($item->timestamp <= 0) {
                continue;
            }

            $day = (int) date('w', $item->timestamp);
            $hour = (int) date('G', $item->timestamp);

            $heatmap[$day][$hour]++;
            $hours[$hour]++;
            $days[$day]++;
            $timestamps[] = $item->timestamp;
        }

        $gaps = [];
        if (count($timestamps) > 1) {
            rsort($timestamps);
            for ($i = 0; $i < count($timestamps) - 1; $i++) {
                $gaps[] = ($timestamps[$i] - $timestamps[$i + 1]) / 3_600;
            }
        }

        arsort($hours);
        $topHours = array_slice(array_keys(array_filter($hours)), 0, 3);
        arsort($days);
        $topDays = array_slice(array_keys(array_filter($days)), 0, 3);

        return [
            'heatmap' => $heatmap,
            'day_labels' => self::DAY_NAMES,
            'peak_hours' => array_map(
                static fn (int $hour): string => sprintf('%02d:00', $hour),
                array_values($topHours)
            ),
            'peak_days' => array_map(
                static fn (int $day): string => self::DAY_NAMES[$day],
                array_values($topDays)
            ),
            'night_owl_ratio' => $this->nightRatio($media),
            'avg_gap_hours' => round(Stats::mean($gaps), 1),
            'rhythm_consistency' => round(Stats::clamp(100 - (Stats::coefficientOfVariation($gaps) * 100)), 1),
        ];
    }

    /**
     * سلوك الكتابة والنبرة.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function writingBehavior(array $media): array
    {
        $captions = array_map(static fn (Media $m): string => $m->caption, $media);
        $nonEmpty = array_values(array_filter($captions, static fn (string $c): bool => trim($c) !== ''));

        $lengths = array_map(static fn (string $c): int => mb_strlen($c, 'UTF-8'), $nonEmpty);
        $emojiCounts = array_map(static fn (string $c): int => Text::countEmoji($c), $nonEmpty);
        $questions = array_filter($nonEmpty, static fn (string $c): bool => Text::containsQuestion($c));
        $ctas = array_filter($nonEmpty, fn (string $c): bool => $this->hasCta($c));

        $total = max(1, count($nonEmpty));

        return [
            'captions_analyzed' => count($nonEmpty),
            'empty_caption_ratio' => Stats::percent((float) (count($captions) - count($nonEmpty)), (float) max(1, count($captions))),
            'avg_length' => round(Stats::mean($lengths)),
            'median_length' => round(Stats::median($lengths)),
            'style' => $this->lengthStyle(Stats::mean($lengths)),
            'avg_emoji_per_caption' => round(Stats::mean($emojiCounts), 2),
            'question_ratio' => Stats::percent((float) count($questions), (float) $total),
            'cta_ratio' => Stats::percent((float) count($ctas), (float) $total),
            'avg_hashtags' => round(Stats::mean(array_map(static fn (Media $m): int => count($m->hashtags()), $media)), 1),
            'avg_mentions' => round(Stats::mean(array_map(static fn (Media $m): int => count($m->mentions()), $media)), 1),
            'tone' => $this->sentiment->summarize($nonEmpty),
        ];
    }

    /**
     * سلوك الاستجابة: كيف يردّ صاحب الحساب وكيف يتفاعل الجمهور.
     *
     * @param list<Media> $media
     * @param list<Comment> $comments
     * @return array<string,mixed>
     */
    private function responseBehavior(string $username, array $media, array $comments): array
    {
        if ($comments === []) {
            return [
                'available' => false,
                'reason' => $this->providers->supportsComments($username)
                    ? 'لم تُرجع المصادر تعليقات لهذه المنشورات.'
                    : 'قراءة التعليقات تتطلب Graph API مع توكن الحساب نفسه (قيد من إنستاجرام).',
                'audience_sentiment' => null,
                'owner' => null,
            ];
        }

        $ownerComments = array_values(array_filter($comments, static fn (Comment $c): bool => $c->fromOwner));
        $audienceComments = array_values(array_filter($comments, static fn (Comment $c): bool => !$c->fromOwner));

        $latencies = [];
        $byId = [];
        foreach ($comments as $comment) {
            $byId[$comment->id] = $comment;
        }

        foreach ($ownerComments as $reply) {
            $parent = $reply->parentId !== null ? ($byId[$reply->parentId] ?? null) : null;
            if ($parent !== null && $reply->timestamp > $parent->timestamp) {
                $latencies[] = ($reply->timestamp - $parent->timestamp) / 3_600;
            }
        }

        $replyRate = Stats::percent((float) count($ownerComments), (float) max(1, count($audienceComments)));
        $medianLatency = Stats::median($latencies);

        return [
            'available' => true,
            'total_comments' => count($comments),
            'audience_comments' => count($audienceComments),
            'owner_replies' => count($ownerComments),
            'reply_rate' => min(100.0, $replyRate),
            'reply_speed' => [
                'median_hours' => round($medianLatency, 1),
                'avg_hours' => round(Stats::mean($latencies), 1),
                'fastest_hours' => $latencies === [] ? null : round(min($latencies), 1),
                'label' => $this->speedLabel($medianLatency, $latencies === []),
            ],
            'owner' => [
                'avg_reply_length' => round(Stats::mean(array_map(
                    static fn (Comment $c): int => mb_strlen($c->text, 'UTF-8'),
                    $ownerComments
                ))),
                'emoji_usage' => round(Stats::mean(array_map(
                    static fn (Comment $c): int => Text::countEmoji($c->text),
                    $ownerComments
                )), 2),
                'tone' => $this->sentiment->summarize(array_map(
                    static fn (Comment $c): string => $c->text,
                    $ownerComments
                )),
                'style' => $this->replyStyle($ownerComments),
            ],
            'audience_sentiment' => $this->sentiment->summarize(array_map(
                static fn (Comment $c): string => $c->text,
                $audienceComments
            )),
            'top_commenters' => Stats::topCounts(array_map(
                static fn (Comment $c): string => $c->username,
                $audienceComments
            ), 5),
            'sample_replies' => array_map(static fn (Comment $c): array => [
                'text' => Text::truncate($c->text, 120),
                'published_at' => $c->timestamp > 0 ? date('Y-m-d H:i', $c->timestamp) : '—',
            ], array_slice($ownerComments, 0, 5)),
        ];
    }

    /**
     * درجات سلوكية من 0 إلى 100.
     *
     * @param list<Media> $media
     * @param array<string,mixed> $timing
     * @param array<string,mixed> $writing
     * @param array<string,mixed> $responses
     * @param array<string,mixed> $reposts
     * @return array<string,float>
     */
    private function scores(
        Profile $profile,
        array $media,
        array $timing,
        array $writing,
        array $responses,
        array $reposts,
    ): array {
        $timestamps = array_filter(array_map(static fn (Media $m): int => $m->timestamp, $media));
        $spanDays = count($timestamps) > 1 ? max(1.0, (max($timestamps) - min($timestamps)) / 86_400) : 0.0;
        $perWeek = $spanDays > 0 ? (count($timestamps) / $spanDays) * 7 : 0.0;

        // نشاط: 4 منشورات أسبوعيًا تُعتبر إيقاعًا كاملًا.
        $activity = Stats::clamp(($perWeek / 4) * 100);

        $likes = array_map(static fn (Media $m): int => $m->likes, $media);
        $avgEngagement = Stats::mean($likes) + Stats::mean(array_map(static fn (Media $m): int => $m->comments, $media));
        $rate = $profile->followers > 0 ? ($avgEngagement / $profile->followers) * 100 : 0.0;

        // تفاعل: 6% معدّل تفاعل = الدرجة الكاملة.
        $engagement = Stats::clamp(($rate / 6) * 100);

        $responsiveness = ($responses['available'] ?? false) === true
            ? Stats::clamp(
                ((float) $responses['reply_rate'] * 0.7)
                + ($this->speedScore((float) ($responses['reply_speed']['median_hours'] ?? 0)) * 0.3)
            )
            : 0.0;

        $interactivity = Stats::clamp(
            ((float) $writing['question_ratio'] * 0.5) + ((float) $writing['cta_ratio'] * 0.5)
        );

        $scores = [
            'activity' => round($activity, 1),
            'consistency' => round((float) $timing['rhythm_consistency'], 1),
            'engagement' => round($engagement, 1),
            'responsiveness' => round($responsiveness, 1),
            'originality' => round((float) $reposts['originality_score'], 1),
            'interactivity' => round($interactivity, 1),
        ];

        $weights = ['activity' => 0.2, 'consistency' => 0.15, 'engagement' => 0.25, 'responsiveness' => 0.2, 'originality' => 0.1, 'interactivity' => 0.1];
        $overall = 0.0;
        $appliedWeight = 0.0;

        foreach ($weights as $key => $weight) {
            // نتجاهل الاستجابة في المعدّل العام عند عدم توفر بيانات التعليقات.
            if ($key === 'responsiveness' && ($responses['available'] ?? false) !== true) {
                continue;
            }
            $overall += $scores[$key] * $weight;
            $appliedWeight += $weight;
        }

        $scores['overall'] = $appliedWeight > 0 ? round($overall / $appliedWeight, 1) : 0.0;

        return $scores;
    }

    /**
     * @param array<string,float> $scores
     * @param array<string,mixed> $writing
     * @param array<string,mixed> $responses
     * @return array{key:string,label:string,description:string}
     */
    private function archetype(array $scores, array $writing, array $responses): array
    {
        $responsive = ($responses['available'] ?? false) === true && $scores['responsiveness'] >= 55;

        return match (true) {
            $scores['activity'] >= 70 && $responsive => [
                'key' => 'community_builder',
                'label' => 'بانٍ لمجتمع',
                'description' => 'ينشر بانتظام ويردّ على جمهوره بسرعة، ما يبني علاقة مستمرة مع المتابعين.',
            ],
            $scores['engagement'] >= 70 && $scores['activity'] < 40 => [
                'key' => 'quality_over_quantity',
                'label' => 'الجودة قبل الكمية',
                'description' => 'ينشر قليلًا لكن كل منشور يحقق تفاعلًا مرتفعًا نسبة إلى حجم الجمهور.',
            ],
            $scores['originality'] < 60 => [
                'key' => 'curator',
                'label' => 'مُنسّق محتوى',
                'description' => 'يعتمد بدرجة ملحوظة على إعادة نشر محتوى الآخرين مع نسبه لمصادره.',
            ],
            (float) $writing['cta_ratio'] >= 40 => [
                'key' => 'marketer',
                'label' => 'حساب تسويقي',
                'description' => 'يستخدم دعوات صريحة لاتخاذ إجراء في أغلب المنشورات.',
            ],
            $scores['activity'] < 25 => [
                'key' => 'dormant',
                'label' => 'حساب خامل',
                'description' => 'وتيرة النشر منخفضة جدًا مقارنة بالحسابات النشطة.',
            ],
            default => [
                'key' => 'steady_creator',
                'label' => 'منشئ محتوى منتظم',
                'description' => 'إيقاع نشر وتفاعل متوازن دون نمط متطرف في أي اتجاه.',
            ],
        };
    }

    /**
     * @param array<string,float> $scores
     * @param array<string,mixed> $timing
     * @param array<string,mixed> $writing
     * @param array<string,mixed> $responses
     * @return list<string>
     */
    private function recommendations(array $scores, array $timing, array $writing, array $responses): array
    {
        $tips = [];

        if ($scores['activity'] < 50) {
            $tips[] = 'زد وتيرة النشر إلى ٣–٤ منشورات أسبوعيًا للحفاظ على ظهور مستقر.';
        }

        if ($scores['consistency'] < 50) {
            $tips[] = 'الفجوات بين المنشورات متذبذبة؛ جدولة أسبوعية ثابتة ستحسّن الانتظام.';
        }

        if ((float) $writing['question_ratio'] < 20) {
            $tips[] = 'اطرح سؤالًا مباشرًا في نهاية التسمية لرفع عدد التعليقات.';
        }

        if (($responses['available'] ?? false) === true && $scores['responsiveness'] < 50) {
            $tips[] = 'نسبة الردّ على التعليقات منخفضة؛ الردّ خلال أول ساعتين يضاعف استمرارية النقاش.';
        }

        if ($scores['originality'] < 70) {
            $tips[] = 'وازن بين المحتوى المُعاد نشره والمحتوى الأصلي لتقوية هوية الحساب.';
        }

        if ((float) $writing['avg_hashtags'] > 15) {
            $tips[] = 'عدد الوسوم مرتفع؛ ٥–١٠ وسوم دقيقة أفضل من قائمة طويلة.';
        }

        if ($timing['peak_hours'] !== []) {
            $tips[] = 'أعلى نشاط للنشر عند ' . implode('، ', $timing['peak_hours']) . ' — اختبر التركيز على هذه الأوقات.';
        }

        return $tips;
    }

    /**
     * جلب تعليقات أحدث المنشورات ضمن العيّنة المحددة.
     *
     * @param list<Media> $media
     * @return list<Comment>
     */
    private function collectComments(string $username, array $media): array
    {
        if (!$this->providers->supportsComments($username)) {
            return [];
        }

        $comments = [];

        foreach (array_slice($media, 0, $this->commentPostsSample) as $item) {
            foreach ($this->providers->fetchComments($username, $item->id, $this->commentsPerPost) as $comment) {
                $comments[] = $comment;
            }
        }

        return $comments;
    }

    /**
     * @param list<Media> $media
     * @param list<Comment> $comments
     * @return list<string>
     */
    private function limitations(string $username, array $media, array $comments): array
    {
        $warnings = [];

        if ($media === []) {
            $warnings[] = 'لا توجد منشورات متاحة، لذلك مؤشرات السلوك غير محسوبة.';
        }

        if ($comments === [] && !$this->providers->supportsComments($username)) {
            $warnings[] = 'قياس سلوك الردود يحتاج صلاحية قراءة التعليقات (Graph API لحساب تملكه).';
        }

        return $warnings;
    }

    /** @param list<Media> $media نسبة المنشورات بين منتصف الليل والسادسة صباحًا. */
    private function nightRatio(array $media): float
    {
        $timestamps = array_filter(array_map(static fn (Media $m): int => $m->timestamp, $media));
        if ($timestamps === []) {
            return 0.0;
        }

        $night = array_filter($timestamps, static fn (int $t): bool => (int) date('G', $t) < 6);

        return Stats::percent((float) count($night), (float) count($timestamps));
    }

    private function hasCta(string $caption): bool
    {
        $normalized = Text::normalize($caption);

        foreach (self::CTA_WORDS as $word) {
            if (str_contains($normalized, Text::normalize($word))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Comment> $replies */
    private function replyStyle(array $replies): string
    {
        if ($replies === []) {
            return 'غير متاح';
        }

        $avgLength = Stats::mean(array_map(
            static fn (Comment $c): int => mb_strlen($c->text, 'UTF-8'),
            $replies
        ));

        return match (true) {
            $avgLength < 25 => 'ردود قصيرة ومباشرة',
            $avgLength < 80 => 'ردود متوسطة الطول',
            default => 'ردود مفصّلة وشارحة',
        };
    }

    private function lengthStyle(float $avgLength): string
    {
        return match (true) {
            $avgLength < 60 => 'مختصر',
            $avgLength < 200 => 'متوازن',
            default => 'سردي مطوّل',
        };
    }

    private function speedLabel(float $medianHours, bool $missing): string
    {
        return match (true) {
            $missing => 'غير متاح',
            $medianHours <= 2 => 'سريع جدًا',
            $medianHours <= 12 => 'سريع',
            $medianHours <= 48 => 'متوسط',
            default => 'بطيء',
        };
    }

    /** تحويل زمن الاستجابة إلى درجة: ساعتان أو أقل = 100، ٧٢ ساعة = 0. */
    private function speedScore(float $medianHours): float
    {
        if ($medianHours <= 0.0) {
            return 0.0;
        }

        return Stats::clamp(100 - (($medianHours - 2) / 70) * 100);
    }
}
