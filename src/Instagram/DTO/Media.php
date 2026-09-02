<?php

declare(strict_types=1);

namespace App\Instagram\DTO;

use App\Support\Text;

/**
 * تمثيل موحّد لمنشور واحد (صورة، فيديو، ريلز، أو ألبوم).
 */
final class Media implements \JsonSerializable
{
    public const TYPE_IMAGE = 'IMAGE';
    public const TYPE_VIDEO = 'VIDEO';
    public const TYPE_REEL = 'REEL';
    public const TYPE_CAROUSEL = 'CAROUSEL';

    public function __construct(
        public readonly string $id,
        public readonly string $type = self::TYPE_IMAGE,
        public readonly string $caption = '',
        public readonly int $likes = 0,
        public readonly int $comments = 0,
        public readonly int $views = 0,
        public readonly int $timestamp = 0,
        public readonly ?string $permalink = null,
        public readonly ?string $thumbnail = null,
        public readonly ?string $sharedFrom = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            type: self::normalizeType((string) ($data['type'] ?? self::TYPE_IMAGE)),
            caption: (string) ($data['caption'] ?? ''),
            likes: (int) ($data['likes'] ?? 0),
            comments: (int) ($data['comments'] ?? 0),
            views: (int) ($data['views'] ?? 0),
            timestamp: (int) ($data['timestamp'] ?? 0),
            permalink: isset($data['permalink']) ? (string) $data['permalink'] : null,
            thumbnail: isset($data['thumbnail']) ? (string) $data['thumbnail'] : null,
            sharedFrom: isset($data['shared_from']) ? (string) $data['shared_from'] : null,
        );
    }

    public static function normalizeType(string $type): string
    {
        return match (strtoupper($type)) {
            'VIDEO' => self::TYPE_VIDEO,
            'REEL', 'REELS', 'CLIPS' => self::TYPE_REEL,
            'CAROUSEL_ALBUM', 'CAROUSEL', 'ALBUM' => self::TYPE_CAROUSEL,
            default => self::TYPE_IMAGE,
        };
    }

    public function engagement(): int
    {
        return $this->likes + $this->comments;
    }

    /** @return list<string> */
    public function hashtags(): array
    {
        return Text::hashtags($this->caption);
    }

    /** @return list<string> */
    public function mentions(): array
    {
        return Text::mentions($this->caption);
    }

    public function publishedAt(string $format = 'Y-m-d H:i'): string
    {
        return $this->timestamp > 0 ? date($format, $this->timestamp) : '—';
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'caption' => $this->caption,
            'caption_preview' => Text::truncate($this->caption, 120),
            'likes' => $this->likes,
            'comments' => $this->comments,
            'views' => $this->views,
            'engagement' => $this->engagement(),
            'timestamp' => $this->timestamp,
            'published_at' => $this->timestamp > 0 ? date(DATE_ATOM, $this->timestamp) : null,
            'permalink' => $this->permalink,
            'thumbnail' => $this->thumbnail,
            'hashtags' => $this->hashtags(),
            'mentions' => $this->mentions(),
            'shared_from' => $this->sharedFrom,
        ];
    }
}
