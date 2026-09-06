<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

use App\PropertyAlerts\Contracts\Notifier;
use App\PropertyAlerts\Contracts\Scraper;
use App\PropertyAlerts\DTO\Listing;
use Throwable;

/**
 * ينفّذ الفحص الدوري: لكل اشتراك، يجلب الإعلانات المطابقة لفلتره، يقارنها
 * بما سبق رصده (seenListingIds)، ويُطلق تنبيه واتساب لكل إعلان جديد فقط —
 * لا يُنبّه أبدًا عن كل إعلانات الفحص الأول لاشتراك جديد (كانت كلها "قديمة"
 * من منظور المستخدم)، فقط يسجّلها كخط أساس، تمامًا مثل أي أداة رصد تغييرات
 * سليمة (لا تُغرق المستخدم برسائل عن محتوى موجود أصلًا وقت الاشتراك).
 */
final class PropertyAlertChecker
{
    /** أقصى عدد إعلانات محفوظة في seenListingIds لكل اشتراك، لمنع نمو الملف بلا حدود. */
    private const MAX_SEEN_IDS = 500;

    public function __construct(
        private PropertySubscriptionRepository $repository,
        private Scraper $scraper,
        private Notifier $notifier,
        private int $maxNotificationsPerRun = 5,
    ) {
    }

    /**
     * فحص اشتراك واحد.
     *
     * @return array{ok:bool,new_listings:int,notified:int,error?:string}
     */
    public function checkOne(string $subscriptionId): array
    {
        $entry = $this->repository->find($subscriptionId);
        if ($entry === null) {
            throw new \InvalidArgumentException('الاشتراك غير موجود.');
        }

        $isFirstCheck = $entry->seenListingIds === [];
        $now = date(DATE_ATOM);

        try {
            $listings = $this->scraper->search($entry->propertyType, $entry->location);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $this->repository->update($entry->id, static function (PropertySubscription $sub) use ($now, $message): PropertySubscription {
                $sub->lastCheckedAt = $now;
                $sub->checkCount++;
                $sub->lastError = $message;

                return $sub;
            });

            return ['ok' => false, 'new_listings' => 0, 'notified' => 0, 'error' => $message];
        }

        $seen = array_flip($entry->seenListingIds);
        /** @var list<Listing> $newListings */
        $newListings = [];

        foreach ($listings as $listing) {
            if (!isset($seen[$listing->id])) {
                $newListings[] = $listing;
            }
        }

        $notified = 0;

        // عند الفحص الأول لاشتراك جديد: تُسجَّل كل النتائج كخط أساس بلا تنبيه،
        // بدل إرسال دفعة رسائل واتساب عن إعلانات موجودة قبل الاشتراك أصلًا.
        if (!$isFirstCheck && $entry->whatsappNumber !== '') {
            foreach (array_slice($newListings, 0, $this->maxNotificationsPerRun) as $listing) {
                if ($this->notifier->send($entry->whatsappNumber, $this->formatMessage($entry, $listing))) {
                    $notified++;
                }
            }
        }

        $updatedSeen = array_slice(
            array_values(array_unique(array_merge($entry->seenListingIds, array_map(
                static fn (Listing $l): string => $l->id,
                $listings
            )))),
            -self::MAX_SEEN_IDS
        );

        $this->repository->update($entry->id, static function (PropertySubscription $e) use ($updatedSeen, $now): PropertySubscription {
            $e->seenListingIds = $updatedSeen;
            $e->lastCheckedAt = $now;
            $e->checkCount++;
            $e->lastError = null;

            return $e;
        });

        return ['ok' => true, 'new_listings' => count($newListings), 'notified' => $notified];
    }

    /**
     * فحص كل الاشتراكات؛ تُستخدم من صفحة الويب ومن سكربت الـ cron.
     *
     * @return array<string,array<string,mixed>> نتيجة لكل اشتراك، مفتاحها معرّفه
     */
    public function checkAll(): array
    {
        $results = [];

        foreach ($this->repository->all() as $entry) {
            try {
                $results[$entry->id] = $this->checkOne($entry->id);
            } catch (Throwable $e) {
                $results[$entry->id] = ['ok' => false, 'new_listings' => 0, 'notified' => 0, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function formatMessage(PropertySubscription $entry, Listing $listing): string
    {
        $lines = ['🏠 إعلان أرض جديد يطابق تنبيهك:', $listing->title];

        if ($listing->location !== null) {
            $lines[] = '📍 ' . $listing->location;
        }
        if ($listing->price !== null) {
            $lines[] = '💰 ' . $listing->price;
        }
        if ($entry->propertyType !== null) {
            $lines[] = 'النوع: ' . PropertyType::label($entry->propertyType);
        }
        if ($listing->url !== null) {
            $lines[] = $listing->url;
        }

        return implode("\n", $lines);
    }
}
