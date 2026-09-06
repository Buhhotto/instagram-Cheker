<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

/**
 * اشتراك تنبيه: رقم واتساب + فلتر (نوع أرض اختياري، ونص موقع اختياري
 * يُطابَق جزئيًا مع نص موقع كل إعلان — لأن قيم الولاية/المحافظة الفعلية
 * في نموذج الموقع غير معروفة لهذا المستودع، فالمطابقة نصّية بدل قائمة
 * مقفلة، وتبقى قابلة للتشديد لاحقًا إن اكتُشفت القيم الدقيقة).
 */
final class PropertySubscription implements \JsonSerializable
{
    /** @param list<string> $seenListingIds */
    public function __construct(
        public readonly string $id,
        public readonly string $whatsappNumber,
        public ?string $propertyType,
        public ?string $location,
        public readonly string $createdAt,
        public array $seenListingIds = [],
        public ?string $lastCheckedAt = null,
        public int $checkCount = 0,
        public ?string $lastError = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $seen = $data['seen_listing_ids'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? bin2hex(random_bytes(8))),
            whatsappNumber: (string) ($data['whatsapp_number'] ?? ''),
            propertyType: !empty($data['property_type']) ? (string) $data['property_type'] : null,
            location: !empty($data['location']) ? (string) $data['location'] : null,
            createdAt: (string) ($data['created_at'] ?? date(DATE_ATOM)),
            seenListingIds: is_array($seen) ? array_values(array_map('strval', $seen)) : [],
            lastCheckedAt: isset($data['last_checked_at']) ? (string) $data['last_checked_at'] : null,
            checkCount: (int) ($data['check_count'] ?? 0),
            lastError: isset($data['last_error']) ? (string) $data['last_error'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'whatsapp_number' => $this->whatsappNumber,
            'property_type' => $this->propertyType,
            'property_type_label' => $this->propertyType !== null ? PropertyType::label($this->propertyType) : null,
            'location' => $this->location,
            'created_at' => $this->createdAt,
            'seen_listing_ids' => $this->seenListingIds,
            'last_checked_at' => $this->lastCheckedAt,
            'check_count' => $this->checkCount,
            'last_error' => $this->lastError,
        ];
    }
}
