<?php

declare(strict_types=1);

namespace App\Watchlist;

use RuntimeException;

/**
 * تخزين قائمة المراقبة في ملف JSON واحد.
 *
 * كل تعديل يمرّ عبر mutate() التي تحصل على قفل حصري (flock) طوال دورة
 * القراءة-التعديل-الكتابة، لمنع تضارب الكتابة بين طلبات الويب وسكربت الـ
 * cron العامل بالتوازي على نفس الملف.
 */
final class WatchlistRepository
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

    /** @return list<WatchedUsername> */
    public function all(): array
    {
        return $this->mutate(static fn (array $items): array => $items);
    }

    public function find(string $username): ?WatchedUsername
    {
        $username = strtolower($username);

        foreach ($this->all() as $entry) {
            if ($entry->username === $username) {
                return $entry;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** إضافة إدخال جديد؛ لا تُنشئ تكرارًا إذا كان الاسم موجودًا بالفعل. */
    public function add(WatchedUsername $entry): WatchedUsername
    {
        $this->mutate(static function (array $items) use ($entry): array {
            foreach ($items as $existing) {
                if ($existing->username === $entry->username) {
                    return $items;
                }
            }

            $items[] = $entry;

            return $items;
        });

        return $this->find($entry->username) ?? $entry;
    }

    /**
     * تعديل إدخال موجود عبر دالة تُرجع نسخة محدَّثة منه.
     *
     * @param callable(WatchedUsername):WatchedUsername $updater
     */
    public function update(string $username, callable $updater): ?WatchedUsername
    {
        $username = strtolower($username);
        $updated = null;

        $this->mutate(function (array $items) use ($username, $updater, &$updated): array {
            foreach ($items as $index => $entry) {
                if ($entry->username === $username) {
                    $items[$index] = $updated = $updater($entry);
                    break;
                }
            }

            return $items;
        });

        return $updated;
    }

    public function remove(string $username): bool
    {
        $username = strtolower($username);
        $removed = false;

        $this->mutate(function (array $items) use ($username, &$removed): array {
            return array_values(array_filter(
                $items,
                static function (WatchedUsername $entry) use ($username, &$removed): bool {
                    if ($entry->username === $username) {
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
     * تنفيذ تعديل داخل قفل حصري واحد يغطي القراءة والتعديل والكتابة معًا.
     *
     * @param callable(list<WatchedUsername>):list<WatchedUsername> $mutator
     * @return list<WatchedUsername>
     */
    private function mutate(callable $mutator): array
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('تعذّر فتح ملف قائمة المراقبة: ' . $this->file);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('تعذّر قفل ملف قائمة المراقبة.');
            }

            $raw = stream_get_contents($handle);
            $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];

            $items = is_array($decoded)
                ? array_values(array_map(
                    static fn (mixed $row): WatchedUsername => WatchedUsername::fromArray(is_array($row) ? $row : []),
                    array_filter($decoded, 'is_array')
                ))
                : [];

            $items = array_values($mutator($items));

            $encoded = json_encode(
                array_map(static fn (WatchedUsername $e): array => $e->jsonSerialize(), $items),
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
