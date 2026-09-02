<?php

declare(strict_types=1);

namespace App\Http;

use App\Cache\CacheInterface;

/**
 * محدّد معدّل بنافذة زمنية ثابتة يحمي المنظومة وحصّة API من الاستهلاك الزائد.
 */
final class RateLimiter
{
    public function __construct(
        private CacheInterface $cache,
        private int $maxRequests = 30,
        private int $windowSeconds = 60,
    ) {
    }

    /**
     * تسجيل محاولة وإرجاع حالة الحد.
     *
     * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
     */
    public function hit(string $identifier): array
    {
        if ($this->maxRequests <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0, 'limit' => 0];
        }

        $key = 'ratelimit:' . $identifier;
        $now = time();
        $state = $this->cache->get($key);

        if (!is_array($state) || (int) ($state['window_start'] ?? 0) + $this->windowSeconds <= $now) {
            $state = ['window_start' => $now, 'count' => 0];
        }

        $state['count'] = (int) $state['count'] + 1;
        $windowStart = (int) $state['window_start'];
        $retryAfter = max(0, $windowStart + $this->windowSeconds - $now);

        $this->cache->set($key, $state, $this->windowSeconds);

        return [
            'allowed' => $state['count'] <= $this->maxRequests,
            'remaining' => max(0, $this->maxRequests - $state['count']),
            'retry_after' => $retryAfter,
            'limit' => $this->maxRequests,
        ];
    }
}
