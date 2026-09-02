<?php

declare(strict_types=1);

namespace App\Support;

/**
 * مولّد أرقام شبه عشوائي حتمي (LCG) لا يعتمد على الحالة العامة لـ mt_rand،
 * حتى تكون البيانات التجريبية ثابتة لنفس اسم المستخدم.
 */
final class SeededRandom
{
    private int $state;

    public function __construct(string|int $seed)
    {
        $this->state = is_int($seed) ? abs($seed) : (int) sprintf('%u', crc32((string) $seed));
        $this->state = ($this->state % 2147483646) + 1;
    }

    /** عدد صحيح ضمن المدى [min, max]. */
    public function int(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        return $min + (int) floor($this->float() * (($max - $min) + 1 - PHP_FLOAT_EPSILON));
    }

    /** عدد عشري في المدى [0, 1). */
    public function float(): float
    {
        $this->state = (int) ((16807 * $this->state) % 2147483647);

        return ($this->state - 1) / 2147483646;
    }

    public function bool(float $probability = 0.5): bool
    {
        return $this->float() < $probability;
    }

    /** @template T @param list<T> $items @return T */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** @template T @param list<T> $items @return list<T> */
    public function pickMany(array $items, int $count): array
    {
        $picked = [];
        for ($i = 0; $i < $count && $items !== []; $i++) {
            $picked[] = $this->pick($items);
        }

        return array_values(array_unique($picked, SORT_REGULAR));
    }
}
