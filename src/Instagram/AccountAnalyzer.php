<?php

declare(strict_types=1);

namespace App\Instagram;

use App\Instagram\Analysis\RepostDetector;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Instagram\Exception\NotFoundException;
use App\Instagram\Exception\ValidationException;
use App\Support\Stats;
use App\Support\Text;

/**
 * تحليل حساب: المتابعون، اللايكات، التعليقات، معدّل التفاعل، وإعادة النشر.
 */
final class AccountAnalyzer
{
    public function __construct(
        private ProviderChain $providers,
        private RepostDetector $repostDetector,
        private UsernameChecker $usernameChecker,
        private int $defaultLimit = 25,
    ) {
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException|ValidationException
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

        return [
            'username' => $profile->username,
            'generated_at' => date(DATE_ATOM),
            'source' => $this->providers->lastSource() ?? $profile->source,
            'profile' => $profile->jsonSerialize(),
            'sample' => [
                'analyzed_posts' => count($media),
                'requested_limit' => $limit,
                'covers_days' => $this->coverageDays($media),
            ],
            'engagement' => $this->engagement($profile, $media),
            'likes' => $this->likeStats($media),
            'comments' => $this->commentStats($media),
            'content' => $this->contentBreakdown($media),
            'posting' => $this->postingStats($media),
            'reposts' => $this->repostDetector->summarize($media),
            'top_posts' => $this->topPosts($media),
            'hashtags' => Stats::topCounts($this->collect($media, 'hashtags'), 10),
            'mentions' => Stats::topCounts($this->collect($media, 'mentions'), 10),
            'audience' => $this->audienceSignals($profile, $media),
            'warnings' => array_merge(
                $this->providers->warnings(),
                $this->providers->sourceWarnings(),
                $this->dataWarnings($profile, $media)
            ),
        ];
    }

    /**
     * مؤشرات التفاعل الأساسية.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function engagement(Profile $profile, array $media): array
    {
        $likes = array_map(static fn (Media $m): int => $m->likes, $media);
        $comments = array_map(static fn (Media $m): int => $m->comments, $media);

        $avgLikes = Stats::mean($likes);
        $avgComments = Stats::mean($comments);
        $rate = $profile->followers > 0
            ? round((($avgLikes + $avgComments) / $profile->followers) * 100, 3)
            : 0.0;

        return [
            'avg_likes' => round($avgLikes, 1),
            'avg_comments' => round($avgComments, 1),
            'avg_engagement_per_post' => round($avgLikes + $avgComments, 1),
            'engagement_rate' => $rate,
            'engagement_rate_label' => $this->rateLabel($rate),
            'comments_to_likes_ratio' => $avgLikes > 0 ? round($avgComments / $avgLikes, 3) : 0.0,
            'benchmark' => [
                'ضعيف' => '< 1%',
                'متوسط' => '1% – 3%',
                'جيد' => '3% – 6%',
                'ممتاز' => '> 6%',
            ],
        ];
    }

    /**
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function likeStats(array $media): array
    {
        $likes = array_map(static fn (Media $m): int => $m->likes, $media);

        return [
            'total' => array_sum($likes),
            'average' => round(Stats::mean($likes), 1),
            'median' => round(Stats::median($likes), 1),
            'max' => $likes === [] ? 0 : max($likes),
            'min' => $likes === [] ? 0 : min($likes),
            'std_dev' => round(Stats::stdDev($likes), 1),
            'consistency' => round(Stats::clamp(100 - (Stats::coefficientOfVariation($likes) * 100)), 1),
        ];
    }

    /**
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function commentStats(array $media): array
    {
        $comments = array_map(static fn (Media $m): int => $m->comments, $media);

        return [
            'total' => array_sum($comments),
            'average' => round(Stats::mean($comments), 1),
            'median' => round(Stats::median($comments), 1),
            'max' => $comments === [] ? 0 : max($comments),
            'silent_posts' => count(array_filter($comments, static fn (int $c): bool => $c === 0)),
        ];
    }

    /**
     * توزيع أنواع المحتوى وأداء كل نوع.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function contentBreakdown(array $media): array
    {
        $byType = [];

        foreach ($media as $item) {
            $byType[$item->type][] = $item;
        }

        $types = [];
        foreach ($byType as $type => $items) {
            $likes = array_map(static fn (Media $m): int => $m->likes, $items);
            $comments = array_map(static fn (Media $m): int => $m->comments, $items);

            $types[] = [
                'type' => $type,
                'label' => $this->typeLabel((string) $type),
                'count' => count($items),
                'share' => Stats::percent((float) count($items), (float) count($media)),
                'avg_likes' => round(Stats::mean($likes), 1),
                'avg_comments' => round(Stats::mean($comments), 1),
            ];
        }

        usort($types, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $captionLengths = array_map(
            static fn (Media $m): int => mb_strlen($m->caption, 'UTF-8'),
            $media
        );

        return [
            'types' => $types,
            'best_type' => $this->bestType($types),
            'avg_caption_length' => round(Stats::mean($captionLengths)),
            'avg_hashtags_per_post' => round(Stats::mean(array_map(
                static fn (Media $m): int => count($m->hashtags()),
                $media
            )), 1),
        ];
    }

    /**
     * إيقاع النشر.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function postingStats(array $media): array
    {
        $timestamps = array_values(array_filter(
            array_map(static fn (Media $m): int => $m->timestamp, $media),
            static fn (int $t): bool => $t > 0
        ));

        if (count($timestamps) < 2) {
            return [
                'posts_per_week' => 0.0,
                'avg_gap_hours' => 0.0,
                'first_post' => null,
                'last_post' => null,
                'days_since_last_post' => null,
                'is_active' => false,
            ];
        }

        rsort($timestamps);
        $gaps = [];
        for ($i = 0; $i < count($timestamps) - 1; $i++) {
            $gaps[] = ($timestamps[$i] - $timestamps[$i + 1]) / 3_600;
        }

        $spanDays = max(1.0, ($timestamps[0] - $timestamps[count($timestamps) - 1]) / 86_400);
        $daysSinceLast = (time() - $timestamps[0]) / 86_400;

        return [
            'posts_per_week' => round((count($timestamps) / $spanDays) * 7, 2),
            'avg_gap_hours' => round(Stats::mean($gaps), 1),
            'gap_consistency' => round(Stats::clamp(100 - (Stats::coefficientOfVariation($gaps) * 100)), 1),
            'first_post' => date('Y-m-d', $timestamps[count($timestamps) - 1]),
            'last_post' => date('Y-m-d', $timestamps[0]),
            'days_since_last_post' => round($daysSinceLast, 1),
            'is_active' => $daysSinceLast <= 14,
        ];
    }

    /**
     * @param list<Media> $media
     * @return list<array<string,mixed>>
     */
    private function topPosts(array $media, int $limit = 5): array
    {
        usort($media, static fn (Media $a, Media $b): int => $b->engagement() <=> $a->engagement());

        return array_map(static fn (Media $m): array => [
            'id' => $m->id,
            'permalink' => $m->permalink,
            'type' => $m->type,
            'likes' => $m->likes,
            'comments' => $m->comments,
            'engagement' => $m->engagement(),
            'published_at' => $m->publishedAt(),
            'caption_preview' => Text::truncate($m->caption, 110),
        ], array_slice($media, 0, $limit));
    }

    /**
     * مؤشرات أولية عن الجمهور وجودة المتابعين.
     *
     * @param list<Media> $media
     * @return array<string,mixed>
     */
    private function audienceSignals(Profile $profile, array $media): array
    {
        $likes = array_map(static fn (Media $m): int => $m->likes, $media);
        $avgLikes = Stats::mean($likes);
        $reach = $profile->followers > 0 ? Stats::percent($avgLikes, (float) $profile->followers) : 0.0;

        $flags = [];

        if ($profile->followers > 10_000 && $reach < 0.5) {
            $flags[] = 'نسبة اللايكات إلى المتابعين منخفضة جدًا — قد تشير إلى متابعين غير نشطين.';
        }

        if ($profile->following > 0 && $profile->followers / max(1, $profile->following) < 0.5) {
            $flags[] = 'عدد المتابَعين أعلى من المتابِعين — نمط حسابات المتابعة المتبادلة.';
        }

        if ($media !== [] && Stats::coefficientOfVariation($likes) > 1.2) {
            $flags[] = 'تذبذب كبير في اللايكات بين المنشورات.';
        }

        return [
            'followers' => $profile->followers,
            'following' => $profile->following,
            'follower_ratio' => $profile->followerRatio(),
            'avg_likes_per_follower_percent' => $reach,
            'estimated_active_audience' => (int) round($avgLikes * 3),
            'flags' => $flags,
        ];
    }

    /**
     * @param list<Media> $media
     * @return list<string>
     */
    private function collect(array $media, string $method): array
    {
        $items = [];

        foreach ($media as $item) {
            foreach ($item->{$method}() as $value) {
                $items[] = $value;
            }
        }

        return $items;
    }

    /**
     * @param list<Media> $media
     * @return list<string>
     */
    private function dataWarnings(Profile $profile, array $media): array
    {
        $warnings = [];

        if ($media === []) {
            $warnings[] = $profile->isPrivate
                ? 'الحساب خاص، لذلك لا يمكن قراءة المنشورات.'
                : 'لم تُرجع المصادر أي منشورات لهذا الحساب.';
        } elseif (count($media) < 5) {
            $warnings[] = 'عيّنة المنشورات صغيرة، لذا المؤشرات تقريبية.';
        }

        if ($profile->followers === 0) {
            $warnings[] = 'عدد المتابعين غير متاح من المصدر، ومعدّل التفاعل غير محسوب بدقة.';
        }

        return $warnings;
    }

    /** @param list<Media> $media */
    private function coverageDays(array $media): float
    {
        $timestamps = array_filter(array_map(static fn (Media $m): int => $m->timestamp, $media));

        if (count($timestamps) < 2) {
            return 0.0;
        }

        return round((max($timestamps) - min($timestamps)) / 86_400, 1);
    }

    /** @param list<array<string,mixed>> $types */
    private function bestType(array $types): ?string
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($types as $type) {
            $score = (float) $type['avg_likes'] + ((float) $type['avg_comments'] * 3);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $type['label'];
            }
        }

        return $best;
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            Media::TYPE_REEL => 'ريلز',
            Media::TYPE_VIDEO => 'فيديو',
            Media::TYPE_CAROUSEL => 'ألبوم',
            default => 'صورة',
        };
    }

    private function rateLabel(float $rate): string
    {
        return match (true) {
            $rate <= 0.0 => 'غير متاح',
            $rate < 1 => 'ضعيف',
            $rate < 3 => 'متوسط',
            $rate < 6 => 'جيد',
            default => 'ممتاز',
        };
    }
}
