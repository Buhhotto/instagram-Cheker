<?php

declare(strict_types=1);

namespace App\Instagram\Providers;

use App\Cache\CacheInterface;
use App\Http\HttpClient;
use App\Instagram\Contracts\ProfileProvider;
use App\Instagram\DTO\Comment;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Instagram\Exception\TransportException;

/**
 * المزوّد الرسمي المبني على Instagram Graph API.
 *
 * - بيانات أي حساب أعمال/منشئ محتوى عبر business_discovery.
 * - التعليقات والردود متاحة فقط للحساب المرتبط بالتوكن (قيد من إنستاجرام نفسه).
 */
final class GraphApiProvider implements ProfileProvider
{
    private const BASE_URL = 'https://graph.facebook.com';

    /** رموز أخطاء Graph التي تعني "الحساب غير موجود / غير قابل للاكتشاف". */
    private const NOT_FOUND_SUBCODES = [2207013, 2207020, 2207023];

    public function __construct(
        private HttpClient $http,
        private CacheInterface $cache,
        private string $accessToken = '',
        private string $igUserId = '',
        private string $ownerUsername = '',
        private string $apiVersion = 'v21.0',
        private int $cacheTtl = 900,
    ) {
    }

    public function name(): string
    {
        return 'graph_api';
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '' && $this->igUserId !== '';
    }

    public function fetchProfile(string $username): ?Profile
    {
        $payload = $this->businessDiscovery($username, 0);
        if ($payload === null) {
            return null;
        }

        return Profile::fromArray([
            'username' => $payload['username'] ?? $username,
            'id' => $payload['id'] ?? null,
            'full_name' => $payload['name'] ?? null,
            'biography' => $payload['biography'] ?? null,
            'followers' => $payload['followers_count'] ?? 0,
            'following' => $payload['follows_count'] ?? 0,
            'media_count' => $payload['media_count'] ?? 0,
            'is_private' => false, // business_discovery لا يُرجع سوى الحسابات العامة
            'is_verified' => $payload['is_verified'] ?? false,
            'profile_picture' => $payload['profile_picture_url'] ?? null,
            'external_url' => $payload['website'] ?? null,
        ], $this->name());
    }

    /** @return list<Media> */
    public function fetchMedia(string $username, int $limit = 25): array
    {
        $limit = max(1, min(50, $limit));

        if ($this->isOwner($username)) {
            return $this->fetchOwnedMedia($limit);
        }

        $payload = $this->businessDiscovery($username, $limit);
        $items = $payload['media']['data'] ?? [];

        return $this->mapMedia(is_array($items) ? $items : []);
    }

    /** @return list<Comment> */
    public function fetchComments(string $username, string $mediaId, int $limit = 50): array
    {
        if (!$this->supportsComments($username) || $mediaId === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $cacheKey = "graph:comments:{$mediaId}:{$limit}";

        $raw = $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($mediaId, $limit): array {
            $response = $this->http->getJson(
                sprintf('%s/%s/%s/comments', self::BASE_URL, $this->apiVersion, $mediaId),
                [
                    'fields' => 'id,text,timestamp,username,like_count,replies{id,text,timestamp,username,like_count}',
                    'limit' => $limit,
                    'access_token' => $this->accessToken,
                ]
            );

            return is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
        });

        return $this->mapComments(is_array($raw) ? $raw : [], $mediaId);
    }

    public function supportsComments(string $username): bool
    {
        return $this->isConfigured() && $this->isOwner($username);
    }

    public function usernameExists(string $username): ?bool
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            return $this->fetchProfile($username) !== null ? true : null;
        } catch (TransportException) {
            return null;
        }
    }

    /**
     * استدعاء business_discovery وإرجاع الحقول، أو null إذا كان الحساب غير موجود.
     *
     * @return array<string,mixed>|null
     */
    private function businessDiscovery(string $username, int $mediaLimit): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $username = strtolower($username);
        $cacheKey = "graph:discovery:{$username}:{$mediaLimit}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return ($cached['found'] ?? false) === true ? $cached['data'] : null;
        }

        $fields = 'business_discovery.username(' . $username . '){'
            . 'id,username,name,biography,followers_count,follows_count,media_count,profile_picture_url,website';

        if ($mediaLimit > 0) {
            $fields .= ',media.limit(' . $mediaLimit . '){'
                . 'id,caption,like_count,comments_count,media_type,media_product_type,permalink,timestamp,thumbnail_url,media_url}';
        }

        $fields .= '}';

        $response = $this->http->getJson(
            sprintf('%s/%s/%s', self::BASE_URL, $this->apiVersion, $this->igUserId),
            ['fields' => $fields, 'access_token' => $this->accessToken]
        );

        $json = $response['json'] ?? null;
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        if (is_array($error)) {
            $subcode = (int) ($error['error_subcode'] ?? 0);
            if (in_array($subcode, self::NOT_FOUND_SUBCODES, true) || $response['status'] === 404) {
                $this->cache->set($cacheKey, ['found' => false, 'data' => null], $this->cacheTtl);

                return null;
            }

            throw new TransportException(
                'خطأ من Graph API: ' . (string) ($error['message'] ?? 'استجابة غير متوقعة')
            );
        }

        $data = is_array($json) ? ($json['business_discovery'] ?? null) : null;

        if (!is_array($data)) {
            return null;
        }

        $this->cache->set($cacheKey, ['found' => true, 'data' => $data], $this->cacheTtl);

        return $data;
    }

    /** @return list<Media> */
    private function fetchOwnedMedia(int $limit): array
    {
        $cacheKey = "graph:owned_media:{$this->igUserId}:{$limit}";

        $raw = $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($limit): array {
            $response = $this->http->getJson(
                sprintf('%s/%s/%s/media', self::BASE_URL, $this->apiVersion, $this->igUserId),
                [
                    'fields' => 'id,caption,like_count,comments_count,media_type,media_product_type,permalink,timestamp,thumbnail_url,media_url',
                    'limit' => $limit,
                    'access_token' => $this->accessToken,
                ]
            );

            return is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
        });

        return $this->mapMedia(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return list<Media>
     */
    private function mapMedia(array $items): array
    {
        $media = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productType = strtoupper((string) ($item['media_product_type'] ?? ''));
            $type = $productType === 'REELS' ? Media::TYPE_REEL : (string) ($item['media_type'] ?? 'IMAGE');

            $media[] = Media::fromArray([
                'id' => $item['id'] ?? '',
                'type' => $type,
                'caption' => $item['caption'] ?? '',
                'likes' => $item['like_count'] ?? 0,
                'comments' => $item['comments_count'] ?? 0,
                'timestamp' => isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : 0,
                'permalink' => $item['permalink'] ?? null,
                'thumbnail' => $item['thumbnail_url'] ?? ($item['media_url'] ?? null),
            ]);
        }

        return $media;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return list<Comment>
     */
    private function mapComments(array $items, string $mediaId): array
    {
        $comments = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $username = strtolower((string) ($item['username'] ?? ''));
            $comments[] = Comment::fromArray([
                'id' => $item['id'] ?? '',
                'media_id' => $mediaId,
                'username' => $username,
                'text' => $item['text'] ?? '',
                'timestamp' => isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : 0,
                'likes' => $item['like_count'] ?? 0,
                'from_owner' => $username === strtolower($this->ownerUsername),
            ]);

            foreach ($item['replies']['data'] ?? [] as $reply) {
                if (!is_array($reply)) {
                    continue;
                }

                $replyUser = strtolower((string) ($reply['username'] ?? ''));
                $comments[] = Comment::fromArray([
                    'id' => $reply['id'] ?? '',
                    'media_id' => $mediaId,
                    'username' => $replyUser,
                    'text' => $reply['text'] ?? '',
                    'timestamp' => isset($reply['timestamp']) ? strtotime((string) $reply['timestamp']) : 0,
                    'likes' => $reply['like_count'] ?? 0,
                    'parent_id' => $item['id'] ?? null,
                    'from_owner' => $replyUser === strtolower($this->ownerUsername),
                ]);
            }
        }

        return $comments;
    }

    private function isOwner(string $username): bool
    {
        return $this->ownerUsername !== '' && strtolower($username) === strtolower($this->ownerUsername);
    }
}
