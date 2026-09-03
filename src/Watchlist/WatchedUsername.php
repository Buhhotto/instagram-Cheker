<?php

declare(strict_types=1);

namespace App\Watchlist;

/**
 * إدخال في قائمة مراقبة أسماء المستخدمين: اسم يُفحص دوريًا حتى تتغيّر حالته.
 */
final class WatchedUsername implements \JsonSerializable
{
    public function __construct(
        public readonly string $username,
        public ?string $webhookUrl = null,
        public readonly string $addedAt = '',
        public ?string $lastStatus = null,
        public ?string $lastCheckedAt = null,
        public ?string $statusChangedAt = null,
        public ?string $lastSource = null,
        public int $checkCount = 0,
        public ?string $lastError = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            username: strtolower((string) ($data['username'] ?? '')),
            webhookUrl: !empty($data['webhook_url']) ? (string) $data['webhook_url'] : null,
            addedAt: (string) ($data['added_at'] ?? date(DATE_ATOM)),
            lastStatus: isset($data['last_status']) ? (string) $data['last_status'] : null,
            lastCheckedAt: isset($data['last_checked_at']) ? (string) $data['last_checked_at'] : null,
            statusChangedAt: isset($data['status_changed_at']) ? (string) $data['status_changed_at'] : null,
            lastSource: isset($data['last_source']) ? (string) $data['last_source'] : null,
            checkCount: (int) ($data['check_count'] ?? 0),
            lastError: isset($data['last_error']) ? (string) $data['last_error'] : null,
        );
    }

    public function profileUrl(): string
    {
        return 'https://www.instagram.com/' . rawurlencode($this->username) . '/';
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->username,
            'webhook_url' => $this->webhookUrl,
            'added_at' => $this->addedAt,
            'last_status' => $this->lastStatus,
            'last_checked_at' => $this->lastCheckedAt,
            'status_changed_at' => $this->statusChangedAt,
            'last_source' => $this->lastSource,
            'check_count' => $this->checkCount,
            'last_error' => $this->lastError,
            'profile_url' => $this->profileUrl(),
        ];
    }
}
