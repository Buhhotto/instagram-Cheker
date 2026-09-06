<?php

declare(strict_types=1);

namespace App\Instagram\DTO;

/**
 * تمثيل موحّد لملف حساب إنستاجرام مهما كان المزوّد الذي جلبه.
 */
final class Profile implements \JsonSerializable
{
    public function __construct(
        public readonly string $username,
        public readonly ?string $id = null,
        public readonly ?string $fullName = null,
        public readonly ?string $biography = null,
        public readonly int $followers = 0,
        public readonly int $following = 0,
        public readonly int $mediaCount = 0,
        public readonly bool $isPrivate = false,
        public readonly bool $isVerified = false,
        public readonly ?string $profilePicture = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $category = null,
        public readonly string $source = 'unknown',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $source = 'unknown'): self
    {
        return new self(
            username: strtolower((string) ($data['username'] ?? '')),
            id: isset($data['id']) ? (string) $data['id'] : null,
            fullName: isset($data['full_name']) ? (string) $data['full_name'] : null,
            biography: isset($data['biography']) ? (string) $data['biography'] : null,
            followers: (int) ($data['followers'] ?? 0),
            following: (int) ($data['following'] ?? 0),
            mediaCount: (int) ($data['media_count'] ?? 0),
            isPrivate: (bool) ($data['is_private'] ?? false),
            isVerified: (bool) ($data['is_verified'] ?? false),
            profilePicture: isset($data['profile_picture']) ? (string) $data['profile_picture'] : null,
            externalUrl: isset($data['external_url']) ? (string) $data['external_url'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            source: $source,
        );
    }

    public function profileUrl(): string
    {
        return 'https://www.instagram.com/' . rawurlencode($this->username) . '/';
    }

    /** نسبة المتابِعين إلى المتابَعين — مؤشر أولي على طبيعة الحساب. */
    public function followerRatio(): float
    {
        if ($this->following <= 0) {
            return (float) $this->followers;
        }

        return round($this->followers / $this->following, 2);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->username,
            'id' => $this->id,
            'full_name' => $this->fullName,
            'biography' => $this->biography,
            'followers' => $this->followers,
            'following' => $this->following,
            'media_count' => $this->mediaCount,
            'is_private' => $this->isPrivate,
            'is_verified' => $this->isVerified,
            'profile_picture' => $this->profilePicture,
            'external_url' => $this->externalUrl,
            'category' => $this->category,
            'follower_ratio' => $this->followerRatio(),
            'profile_url' => $this->profileUrl(),
            'source' => $this->source,
        ];
    }
}
