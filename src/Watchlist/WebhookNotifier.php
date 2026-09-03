<?php

declare(strict_types=1);

namespace App\Watchlist;

use App\Http\HttpClient;
use App\Instagram\Exception\TransportException;
use App\Watchlist\Contracts\Notifier;

/**
 * إرسال تنبيهات webhook عند تحوّل حالة اسم مراقَب، بصيغة تعمل مباشرة مع
 * Discord وSlack وأي مستقبل JSON عام (Zapier، Make، n8n، ...).
 *
 * الرابط مُدخل من المستخدم، لذلك يُفحص أولًا لمنع استغلاله في الوصول إلى
 * عناوين داخلية (SSRF): يُرفض أي مضيف يُحلّ إلى نطاق خاص أو محلي.
 */
final class WebhookNotifier implements Notifier
{
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * فحص أمان الرابط قبل قبوله من المستخدم: بروتوكول HTTP(S) فقط،
     * ومضيف لا يُحلّ إلى عنوان محلي أو خاص أو رابط للأجهزة (loopback/private/link-local).
     */
    public function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);

        if ($host === 'localhost') {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        // فشل تحليل المضيف يعني رفضًا افتراضيًا، لا قبولًا.
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $payload */
    public function send(string $url, array $payload): bool
    {
        if (!$this->isSafeUrl($url)) {
            return false;
        }

        try {
            $response = $this->http->postJson($url, $payload);
        } catch (TransportException) {
            return false;
        }

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
