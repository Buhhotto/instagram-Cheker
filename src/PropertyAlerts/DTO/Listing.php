<?php

declare(strict_types=1);

namespace App\PropertyAlerts\DTO;

/**
 * إعلان أرض/عقار واحد كما استُخرج من صفحة نتائج الموقع.
 */
final class Listing implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $url,
        public readonly ?string $location,
        public readonly ?string $price,
    ) {
    }

    /**
     * معرّف ثابت للإعلان يُستخدم في كشف التكرار: رابط الإعلان إن وُجد
     * (أكثر استقرارًا)، وإلا بصمة من العنوان + الموقع + السعر.
     */
    public static function makeId(?string $url, string $title, ?string $location, ?string $price): string
    {
        if ($url !== null && $url !== '') {
            return 'url:' . hash('sha256', $url);
        }

        return 'fp:' . hash('sha256', $title . '|' . ($location ?? '') . '|' . ($price ?? ''));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'location' => $this->location,
            'price' => $this->price,
        ];
    }
}
