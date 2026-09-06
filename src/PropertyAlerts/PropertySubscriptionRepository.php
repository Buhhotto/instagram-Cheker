<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

use RuntimeException;

/**
 * تخزين اشتراكات التنبيه في ملف JSON واحد، بنفس آلية القفل الحصري
 * (flock) المستخدمة في WatchlistRepository لمنع تضارب الكتابة بين طلبات
 * الويب وسكربت الـ cron العامل بالتوازي على نفس الملف.
 */
final class PropertySubscriptionRepository
{
    public function __construct(private string $file)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_file($this->file)) {
            @file_put_contents($this->file, '[]');
        }
    }

    /** @return list<PropertySubscription> */
    public function all(): array
    {
        return $this->mutate(static fn (array $items): array => $items);
    }

    public function find(string $id): ?PropertySubscription
    {
        foreach ($this->all() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function add(PropertySubscription $entry): PropertySubscription
    {
        $this->mutate(static function (array $items) use ($entry): array {
            $items[] = $entry;

            return $items;
        });

        return $entry;
    }

    /**
     * @param callable(PropertySubscription):PropertySubscription $updater
     */
    public function update(string $id, callable $updater): ?PropertySubscription
    {
        $updated = null;

        $this->mutate(function (array $items) use ($id, $updater, &$updated): array {
            foreach ($items as $index => $entry) {
                if ($entry->id === $id) {
                    $items[$index] = $updated = $updater($entry);
                    break;
                }
            }

            return $items;
        });

        return $updated;
    }

    public function remove(string $id): bool
    {
        $removed = false;

        $this->mutate(function (array $items) use ($id, &$removed): array {
            return array_values(array_filter(
                $items,
                static function (PropertySubscription $entry) use ($id, &$removed): bool {
                    if ($entry->id === $id) {
                        $removed = true;

                        return false;
                    }

                    return true;
                }
            ));
        });

        return $removed;
    }

    /**
     * @param callable(list<PropertySubscription>):list<PropertySubscription> $mutator
     * @return list<PropertySubscription>
     */
    private function mutate(callable $mutator): array
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('تعذّر فتح ملف اشتراكات التنبيه: ' . $this->file);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر قفل ملف اشتراكات التنبيه.');
            }

            $raw = stream_get_contents($handle);
            $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];

            $items = is_array($decoded)
                ? array_values(array_map(
                    static fn (mixed $row): PropertySubscription => PropertySubscription::fromArray(is_array($row) ? $row : []),
                    array_filter($decoded, 'is_array')
                ))
                : [];

            $items = array_values($mutator($items));

            $encoded = json_encode(
                array_map(static fn (PropertySubscription $e): array => $e->jsonSerialize(), $items),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            if ($encoded !== false) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $encoded);
                fflush($handle);
            }

            return $items;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
