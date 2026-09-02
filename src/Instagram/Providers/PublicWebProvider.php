<?php

declare(strict_types=1);

namespace App\Instagram\Providers;

use App\Cache\CacheInterface;
use App\Http\HttpClient;
use App\Instagram\Contracts\ProfileProvider;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Instagram\Exception\TransportException;

/**
 * مزوّد يعتمد على الواجهة العامة لموقع إنستاجرام (بدون توكن).
 *
 * مفيد أساسًا للتحقق من توفّر اسم المستخدم. إنستاجرام قد يحدّ من الطلبات
 * أو يطلب تسجيل دخول، ولذلك تُعامل كل نتيجة هنا على أنها "أفضل جهد ممكن":
 * عند الفشل نُرجع null بدل ادّعاء نتيجة غير مؤكدة.
 */
final class PublicWebProvider implements ProfileProvider
{
    private const PROFILE_INFO_URL = 'https://www.instagram.com/api/v1/users/web_profile_info/';
    private const PROFILE_URL = 'https://www.instagram.com/';

    /** معرّف تطبيق الويب العام المستخدم من واجهة إنستاجرام في المتصفح. */
    private const WEB_APP_ID = '936619743392459';

    public function __construct(
        private HttpClient $http,
        private CacheInterface $cache,
        private bool $enabled = true,
        private int $cacheTtl = 600,
    ) {
    }

    public function name(): string
    {
        return 'public_web';
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function fetchProfile(string $username): ?Profile
    {
        $user = $this->profilePayload($username);
        if ($user === null) {
            return null;
        }

        return Profile::fromArray([
            'username' => $user['username'] ?? $username,
            'id' => $user['id'] ?? null,
            'full_name' => $user['full_name'] ?? null,
            'biography' => $user['biography'] ?? null,
            'followers' => $user['edge_followed_by']['count'] ?? 0,
            'following' => $user['edge_follow']['count'] ?? 0,
            'media_count' => $user['edge_owner_to_timeline_media']['count'] ?? 0,
            'is_private' => $user['is_private'] ?? false,
            'is_verified' => $user['is_verified'] ?? false,
            'profile_picture' => $user['profile_pic_url_hd'] ?? ($user['profile_pic_url'] ?? null),
            'external_url' => $user['external_url'] ?? null,
            'category' => $user['category_name'] ?? null,
        ], $this->name());
    }

    /** @return list<Media> */
    public function fetchMedia(string $username, int $limit = 25): array
    {
        $user = $this->profilePayload($username);
        if ($user === null || ($user['is_private'] ?? false)) {
            return [];
        }

        $edges = $user['edge_owner_to_timeline_media']['edges'] ?? [];
        if (!is_array($edges)) {
            return [];
        }

        $media = [];

        foreach (array_slice($edges, 0, max(1, $limit)) as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (!is_array($node)) {
                continue;
            }

            $caption = '';
            $captionEdges = $node['edge_media_to_caption']['edges'][0]['node']['text'] ?? null;
            if (is_string($captionEdges)) {
                $caption = $captionEdges;
            }

            $media[] = Media::fromArray([
                'id' => $node['id'] ?? '',
                'type' => $this->resolveType($node),
                'caption' => $caption,
                'likes' => $node['edge_liked_by']['count'] ?? ($node['edge_media_preview_like']['count'] ?? 0),
                'comments' => $node['edge_media_to_comment']['count'] ?? 0,
                'views' => $node['video_view_count'] ?? 0,
                'timestamp' => $node['taken_at_timestamp'] ?? 0,
                'permalink' => isset($node['shortcode'])
                    ? 'https://www.instagram.com/p/' . $node['shortcode'] . '/'
                    : null,
                'thumbnail' => $node['thumbnail_src'] ?? ($node['display_url'] ?? null),
            ]);
        }

        return $media;
    }

    /** التعليقات تتطلب جلسة مسجّلة، ولذلك لا يدعمها هذا المزوّد. */
    public function fetchComments(string $username, string $mediaId, int $limit = 50): array
    {
        return [];
    }

    public function supportsComments(string $username): bool
    {
        return false;
    }

    public function usernameExists(string $username): ?bool
    {
        if (!$this->enabled) {
            return null;
        }

        $cacheKey = 'public:exists:' . strtolower($username);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && array_key_exists('exists', $cached)) {
            return $cached['exists'] === null ? null : (bool) $cached['exists'];
        }

        $exists = $this->probeExistence($username);

        if ($exists !== null) {
            $this->cache->set($cacheKey, ['exists' => $exists], $this->cacheTtl);
        }

        return $exists;
    }

    /** @return array<string,mixed>|null */
    private function profilePayload(string $username): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $username = strtolower($username);
        $cacheKey = 'public:profile:' . $username;

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return ($cached['found'] ?? false) === true && is_array($cached['data']) ? $cached['data'] : null;
        }

        $response = $this->http->getJson(self::PROFILE_INFO_URL, ['username' => $username], [
            'X-IG-App-ID' => self::WEB_APP_ID,
            'Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8',
            'Referer' => self::PROFILE_URL . $username . '/',
        ]);

        if ($response['status'] === 404) {
            $this->cache->set($cacheKey, ['found' => false, 'data' => null], $this->cacheTtl);

            return null;
        }

        if ($response['status'] === 429) {
            throw new TransportException('إنستاجرام يحدّ الطلبات حاليًا (429). حاول بعد قليل.');
        }

        $user = $response['json']['data']['user'] ?? null;

        if (!is_array($user)) {
            return null;
        }

        $this->cache->set($cacheKey, ['found' => true, 'data' => $user], $this->cacheTtl);

        return $user;
    }

    /**
     * فحص وجود الاسم عبر صفحة الحساب العامة؛ null عند عدم اليقين.
     *
     * إذا فشل المسارَان لسبب شبكي نرمي الاستثناء بدل كتم الخطأ، حتى تُسجَّل
     * السلسلة تحذيرًا واضحًا بأن هذا المصدر لم يُستخدم.
     *
     * @throws TransportException
     */
    private function probeExistence(string $username): ?bool
    {
        $transportError = null;

        try {
            $user = $this->profilePayload($username);
            if ($user !== null) {
                return true;
            }
        } catch (TransportException $e) {
            $transportError = $e;
        }

        try {
            $response = $this->http->get(self::PROFILE_URL . rawurlencode($username) . '/', [], [
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8',
            ]);
        } catch (TransportException $e) {
            throw $transportError ?? $e;
        }

        if ($transportError !== null && $response['status'] >= 400 && $response['status'] !== 404) {
            throw $transportError;
        }

        return match (true) {
            $response['status'] === 404 => false,
            $response['status'] === 200 => $this->looksLikeProfilePage($response['body']) ? true : null,
            default => null,
        };
    }

    private function looksLikeProfilePage(string $html): bool
    {
        return str_contains($html, '"profile_pic_url"')
            || str_contains($html, 'og:description')
            || str_contains($html, '"edge_followed_by"');
    }

    /** @param array<string,mixed> $node */
    private function resolveType(array $node): string
    {
        $typename = (string) ($node['__typename'] ?? '');

        return match (true) {
            ($node['product_type'] ?? '') === 'clips' => Media::TYPE_REEL,
            $typename === 'GraphSidecar' => Media::TYPE_CAROUSEL,
            $typename === 'GraphVideo' || ($node['is_video'] ?? false) === true => Media::TYPE_VIDEO,
            default => Media::TYPE_IMAGE,
        };
    }
}
