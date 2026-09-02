<?php

declare(strict_types=1);

namespace App\Instagram\Providers;

use App\Instagram\Contracts\ProfileProvider;
use App\Instagram\DTO\Comment;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Support\SeededRandom;

/**
 * مزوّد بيانات تجريبية حتمية (نفس الاسم ⇒ نفس النتيجة).
 *
 * الهدف تشغيل المنظومة وتجربتها بالكامل بدون توكن ولا اتصال بإنستاجرام.
 * كل ما يُرجعه هذا المزوّد بيانات مُصطنعة ويُوسم بـ source = "demo".
 */
final class DemoProvider implements ProfileProvider
{
    private const CAPTION_TEMPLATES = [
        'يوم جديد وتجربة جديدة 🌤️ شاركونا رأيكم في التعليقات #تصوير #يوميات',
        'أفضل نصيحة تعلمتها هذا الأسبوع: ابدأ صغيرًا واستمر 💪 #تطوير_الذات',
        'ريبوست من @%s — محتوى يستحق المشاهدة 🔁 #repost',
        'خلف الكواليس من التصوير أمس 🎬 ما رأيكم بالإضاءة؟',
        'وصفة سريعة في ٥ دقائق 🍳 احفظ المنشور للرجوع إليه لاحقًا #وصفات',
        'Sharing my honest thoughts on this setup. What would you change? #workspace',
        'شكرًا لكل من حضر أمس ❤️ لقاؤنا القادم قريبًا',
        'Credit: @%s 📸 اضغط حفظ إذا أعجبك #inspiration',
        'سؤال سريع: ما الموضوع الذي تحبون أن أغطيه القادم؟ 🤔',
        'تحديث المشروع: أنجزنا ٧٠٪ من المرحلة الأولى 🚀 #ريادة_أعمال',
    ];

    private const REPLY_TEMPLATES = [
        'شكرًا لك على المتابعة ❤️',
        'سؤال ممتاز، سأخصص له منشورًا قريبًا 🙏',
        'أقدّر ملاحظتك، وسنعمل على تحسينها',
        'Thanks a lot! Glad it helped 🙌',
        'تم التسجيل، تابعنا للتفاصيل',
    ];

    private const COMMENT_TEMPLATES = [
        'محتوى رائع ومفيد جدًا 👏',
        'ما رأيك في تجربة الطريقة الثانية؟',
        'لم يعجبني هذا الجزء صراحة، أراه مبالغًا فيه',
        'Amazing work, keep it up!',
        'متى الجزء الثاني؟ 🤔',
        'أفضل حساب أتابعه في هذا المجال ❤️',
        'الفكرة جيدة لكن التنفيذ يحتاج تحسين',
    ];

    /** أسماء محجوزة/مشهورة تُعامل كموجودة في وضع التجربة. */
    private const KNOWN_TAKEN = ['instagram', 'nasa', 'apple', 'nike', 'cristiano', 'leomessi', 'natgeo'];

    public function __construct(private bool $enabled = true)
    {
    }

    public function name(): string
    {
        return 'demo';
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function fetchProfile(string $username): ?Profile
    {
        if (!$this->enabled) {
            return null;
        }

        $username = strtolower($username);
        $random = new SeededRandom('profile:' . $username);
        $followers = $random->int(1_200, 480_000);

        return Profile::fromArray([
            'username' => $username,
            'id' => (string) $random->int(1_000_000, 9_999_999),
            'full_name' => ucfirst($username) . ' | حساب تجريبي',
            'biography' => 'حساب تجريبي لعرض إمكانات المنظومة 🚀 | بيانات مُصطنعة',
            'followers' => $followers,
            'following' => $random->int(80, 2_400),
            'media_count' => $random->int(40, 900),
            'is_private' => false,
            'is_verified' => $random->bool(0.15),
            'profile_picture' => null,
            'external_url' => $random->bool(0.4) ? 'https://example.com/' . $username : null,
            'category' => $random->pick(['منشئ محتوى', 'أعمال', 'تعليم', 'رياضة', 'طعام']),
        ], $this->name());
    }

    /** @return list<Media> */
    public function fetchMedia(string $username, int $limit = 25): array
    {
        if (!$this->enabled) {
            return [];
        }

        $username = strtolower($username);
        $profile = $this->fetchProfile($username);
        $followers = $profile?->followers ?? 10_000;
        $random = new SeededRandom('media:' . $username);

        $limit = max(1, min(50, $limit));
        $media = [];
        $timestamp = time() - $random->int(0, 3 * 86_400);
        $baseEngagement = max(0.01, $random->float() * 0.06 + 0.01);

        for ($i = 0; $i < $limit; $i++) {
            $type = $random->pick([Media::TYPE_IMAGE, Media::TYPE_IMAGE, Media::TYPE_REEL, Media::TYPE_CAROUSEL, Media::TYPE_VIDEO]);
            $template = $random->pick(self::CAPTION_TEMPLATES);
            $caption = str_contains($template, '%s')
                ? sprintf($template, $random->pick(['creator_' . $random->int(10, 99), 'daily.pics', 'studio_' . $random->int(10, 99)]))
                : $template;

            $likes = (int) round($followers * $baseEngagement * ($random->float() * 1.6 + 0.4));
            $comments = (int) round($likes * ($random->float() * 0.09 + 0.01));

            $media[] = Media::fromArray([
                'id' => sprintf('demo_%s_%d', substr(md5($username), 0, 8), $i),
                'type' => $type,
                'caption' => $caption,
                'likes' => max(0, $likes),
                'comments' => max(0, $comments),
                'views' => in_array($type, [Media::TYPE_REEL, Media::TYPE_VIDEO], true) ? $likes * $random->int(6, 20) : 0,
                'timestamp' => $timestamp,
                'permalink' => 'https://www.instagram.com/p/demo' . $i . '/',
            ]);

            // فجوة زمنية متغيرة بين المنشورات لمحاكاة نمط نشر واقعي.
            $timestamp -= $random->int(14, 96) * 3_600;
        }

        return $media;
    }

    /** @return list<Comment> */
    public function fetchComments(string $username, string $mediaId, int $limit = 50): array
    {
        if (!$this->enabled) {
            return [];
        }

        $username = strtolower($username);
        $random = new SeededRandom('comments:' . $username . ':' . $mediaId);
        $media = $this->findMedia($username, $mediaId);
        $mediaTime = $media?->timestamp ?? time() - 86_400;

        $count = min(max(1, $limit), $random->int(3, 12));
        $comments = [];
        $replyRate = $random->float() * 0.7 + 0.1;

        for ($i = 0; $i < $count; $i++) {
            $commentId = $mediaId . '_c' . $i;
            $commentTime = $mediaTime + $random->int(300, 172_800);

            $comments[] = Comment::fromArray([
                'id' => $commentId,
                'media_id' => $mediaId,
                'username' => 'user_' . $random->int(1_000, 9_999),
                'text' => $random->pick(self::COMMENT_TEMPLATES),
                'timestamp' => $commentTime,
                'likes' => $random->int(0, 40),
                'from_owner' => false,
            ]);

            if ($random->bool($replyRate)) {
                $comments[] = Comment::fromArray([
                    'id' => $commentId . '_r',
                    'media_id' => $mediaId,
                    'username' => $username,
                    'text' => $random->pick(self::REPLY_TEMPLATES),
                    'timestamp' => $commentTime + $random->int(600, 129_600),
                    'likes' => $random->int(0, 15),
                    'parent_id' => $commentId,
                    'from_owner' => true,
                ]);
            }
        }

        return $comments;
    }

    public function supportsComments(string $username): bool
    {
        return $this->enabled;
    }

    public function usernameExists(string $username): ?bool
    {
        if (!$this->enabled) {
            return null;
        }

        $username = strtolower($username);

        if (in_array($username, self::KNOWN_TAKEN, true)) {
            return true;
        }

        // نتيجة حتمية مُصطنعة: الأسماء القصيرة "مأخوذة" غالبًا كما في الواقع.
        $random = new SeededRandom('exists:' . $username);
        $takenProbability = match (true) {
            mb_strlen($username) <= 4 => 0.95,
            mb_strlen($username) <= 7 => 0.75,
            mb_strlen($username) <= 12 => 0.5,
            default => 0.25,
        };

        return $random->bool($takenProbability);
    }

    private function findMedia(string $username, string $mediaId): ?Media
    {
        foreach ($this->fetchMedia($username, 50) as $media) {
            if ($media->id === $mediaId) {
                return $media;
            }
        }

        return null;
    }
}
