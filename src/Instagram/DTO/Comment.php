<?php

declare(strict_types=1);

namespace App\Instagram\DTO;

/**
 * تعليق أو ردّ على منشور، يُستخدم في قياس سلوك الاستجابة.
 */
final class Comment implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $mediaId,
        public readonly string $username,
        public readonly string $text = '',
        public readonly int $timestamp = 0,
        public readonly int $likes = 0,
        public readonly ?string $parentId = null,
        public readonly bool $fromOwner = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            mediaId: (string) ($data['media_id'] ?? ''),
            username: strtolower((string) ($data['username'] ?? '')),
            text: (string) ($data['text'] ?? ''),
            timestamp: (int) ($data['timestamp'] ?? 0),
            likes: (int) ($data['likes'] ?? 0),
            parentId: isset($data['parent_id']) ? (string) $data['parent_id'] : null,
            fromOwner: (bool) ($data['from_owner'] ?? false),
        );
    }

    public function isReply(): bool
    {
        return $this->parentId !== null && $this->parentId !== '';
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'media_id' => $this->mediaId,
            'username' => $this->username,
            'text' => $this->text,
            'timestamp' => $this->timestamp,
            'published_at' => $this->timestamp > 0 ? date(DATE_ATOM, $this->timestamp) : null,
            'likes' => $this->likes,
            'parent_id' => $this->parentId,
            'is_reply' => $this->isReply(),
            'from_owner' => $this->fromOwner,
        ];
    }
}
