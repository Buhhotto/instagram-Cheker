<?php

declare(strict_types=1);

namespace App\Watchlist;

use App\Instagram\UsernameChecker;
use App\Watchlist\Contracts\Notifier;
use Throwable;

/**
 * ينفّذ الفحص الدوري لقائمة المراقبة: يعيد استخدام UsernameChecker نفسه
 * الذي تعتمد عليه صفحة/واجهة التحقق من الاسم، ويُطلق تنبيه webhook فقط
 * عند تحوّل واثق بين الحالات (متاح ⇄ مستخدم/محجوز) — لا عند حالات "غير
 * معروف" المؤقتة، حتى لا تُغرق التنبيهات بإنذارات كاذبة من انقطاع مؤقت.
 *
 * لا يُطلق أي تنبيه إذا كان مصدر النتيجة "demo"، لأن تلك بيانات مُصطنعة
 * ولا يجب أن تُحرّك إجراءً حقيقيًا لدى المستخدم.
 */
final class WatchlistChecker
{
    private const CONFIDENT_STATUSES = ['available', 'taken', 'reserved'];

    public function __construct(
        private WatchlistRepository $repository,
        private UsernameChecker $usernameChecker,
        private Notifier $notifier,
    ) {
    }

    /**
     * فحص اسم واحد من القائمة وتحديث حالته.
     *
     * @return array<string,mixed> نتيجة UsernameChecker::check() كاملة
     */
    public function checkOne(string $username): array
    {
        $entry = $this->repository->find($username);
        if ($entry === null) {
            throw new \InvalidArgumentException('الاسم غير موجود في قائمة المراقبة.');
        }

        $result = $this->usernameChecker->check($entry->username, false);
        $newStatus = (string) $result['status'];
        $oldStatus = $entry->lastStatus;
        $now = date(DATE_ATOM);

        $transitioned = $oldStatus !== null
            && $oldStatus !== $newStatus
            && in_array($newStatus, self::CONFIDENT_STATUSES, true)
            && in_array($oldStatus, self::CONFIDENT_STATUSES, true);

        $updated = $this->repository->update($entry->username, function (WatchedUsername $e) use (
            $newStatus,
            $now,
            $result,
            $transitioned,
        ): WatchedUsername {
            $e->lastStatus = $newStatus;
            $e->lastCheckedAt = $now;
            $e->lastSource = is_string($result['source'] ?? null) ? $result['source'] : null;
            $e->checkCount++;
            $e->lastError = $newStatus === 'unknown' ? (string) ($result['message'] ?? null) : null;

            if ($transitioned) {
                $e->statusChangedAt = $now;
            }

            return $e;
        });

        $isDemoSource = ($result['source'] ?? null) === 'demo';

        if ($transitioned && !$isDemoSource && $updated?->webhookUrl !== null) {
            $this->notify($updated, $oldStatus, $newStatus);
        }

        return $result;
    }

    /**
     * فحص كل عناصر القائمة؛ تُستخدم من صفحة الويب ومن سكربت الـ cron.
     *
     * @return array<string,array<string,mixed>> نتيجة لكل اسم، مفتاحها اسم المستخدم
     */
    public function checkAll(): array
    {
        $results = [];

        foreach ($this->repository->all() as $entry) {
            try {
                $results[$entry->username] = $this->checkOne($entry->username);
            } catch (Throwable $e) {
                $results[$entry->username] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function notify(WatchedUsername $entry, ?string $oldStatus, string $newStatus): void
    {
        $becameAvailable = $newStatus === 'available';

        $message = $becameAvailable
            ? sprintf('🔔 الاسم @%s الذي تراقبه أصبح متاحًا الآن على إنستاجرام.', $entry->username)
            : sprintf('⚠️ الاسم @%s الذي كنت تراقبه أصبح غير متاح (استخدمه طرف آخر).', $entry->username);

        // نُدرج content وtext إلى جانب الحقول العامة حتى يعمل الرابط مباشرة
        // مع Discord (يقرأ content) وSlack (يقرأ text) دون أي إعداد إضافي.
        $this->notifier->send((string) $entry->webhookUrl, [
            'event' => $becameAvailable ? 'username_available' : 'username_taken',
            'username' => $entry->username,
            'previous_status' => $oldStatus,
            'new_status' => $newStatus,
            'checked_at' => $entry->lastCheckedAt,
            'profile_url' => $entry->profileUrl(),
            'message' => $message,
            'content' => $message,
            'text' => $message,
        ]);
    }
}
