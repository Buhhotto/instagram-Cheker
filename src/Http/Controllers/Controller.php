<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Container;
use App\Http\RateLimiter;
use App\Http\Request;
use App\Instagram\Exception\RateLimitException;
use App\View\View;

/**
 * أساس مشترك للمتحكمات: الوصول إلى الحاوية، العرض، وتطبيق حدّ الطلبات.
 */
abstract class Controller
{
    public function __construct(protected Container $container, protected View $view)
    {
    }

    /**
     * تطبيق حدّ الطلبات على العمليات التي تستهلك الشبكة.
     *
     * @throws RateLimitException
     */
    protected function throttle(Request $request, string $bucket): void
    {
        $limiter = $this->container->rateLimiter();
        $state = $limiter->hit($bucket . ':' . $request->clientId());

        if (!$state['allowed']) {
            throw new RateLimitException(sprintf(
                'تجاوزت الحد المسموح (%d طلب/دقيقة). أعد المحاولة بعد %d ثانية.',
                $state['limit'],
                $state['retry_after']
            ));
        }
    }

    /** @return array<string,mixed> بيانات مشتركة لكل الصفحات. */
    protected function pageContext(string $active): array
    {
        return [
            'active' => $active,
            'appName' => $this->container->config()->string('app.name', 'منظومة تحليل إنستاجرام'),
            'demoOnly' => $this->container->isDemoOnly(),
            'sources' => $this->container->providers()->activeNames(),
        ];
    }

    /** @return RateLimiter */
    protected function limiter(): RateLimiter
    {
        return $this->container->rateLimiter();
    }
}
