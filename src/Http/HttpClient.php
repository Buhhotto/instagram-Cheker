<?php

declare(strict_types=1);

namespace App\Http;

use App\Instagram\Exception\TransportException;

/**
 * غلاف رفيع حول cURL لطلبات GET مع دعم JSON وإعادة المحاولة.
 */
final class HttpClient
{
    /** @param array<string,string> $defaultHeaders */
    public function __construct(
        private int $timeout = 12,
        private int $retries = 2,
        private array $defaultHeaders = [],
        private string $userAgent = 'InstagramInsights/1.0 (+https://github.com/Buhhotto/instagram-Cheker)',
    ) {
    }

    /**
     * تنفيذ طلب GET وإرجاع الحالة والجسم.
     *
     * @param array<string,string|int> $query
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function get(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $lastError = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            try {
                return $this->execute($url, $headers);
            } catch (TransportException $e) {
                $lastError = $e;
                if ($attempt < $this->retries) {
                    usleep((int) (250_000 * (2 ** $attempt)));
                }
            }
        }

        throw $lastError ?? new TransportException('فشل الطلب لسبب غير معروف.');
    }

    /**
     * تنفيذ طلب GET وفك ترميز الاستجابة كـ JSON.
     *
     * @param array<string,string|int> $query
     * @param array<string,string> $headers
     * @return array{status:int,json:array<mixed>|null,body:string}
     */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        $response = $this->get($url, $query, $headers + ['Accept' => 'application/json']);
        $decoded = json_decode($response['body'], true);

        return [
            'status' => $response['status'],
            'json' => is_array($decoded) ? $decoded : null,
            'body' => $response['body'],
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function execute(string $url, array $headers): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('تعذّر تهيئة cURL.');
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers + $this->defaultHeaders),
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new TransportException('فشل الاتصال بالخدمة: ' . ($error !== '' ? $error : 'خطأ شبكة'));
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $responseHeaders];
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
