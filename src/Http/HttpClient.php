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

        return $this->retry(fn (): array => $this->execute($url, $headers));
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

        return $this->decodeJson($response);
    }

    /**
     * تنفيذ طلب POST بجسم خام.
     *
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->retry(fn (): array => $this->execute($url, $headers, 'POST', $body));
    }

    /**
     * تنفيذ طلب POST بجسم JSON وفك ترميز الاستجابة كـ JSON.
     *
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     * @return array{status:int,json:array<mixed>|null,body:string}
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->post($url, $encoded === false ? '{}' : $encoded, $headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return $this->decodeJson($response);
    }

    /**
     * تنفيذ محاولة مع إعادة المحاولة عند فشل الشبكة، بتأخير تصاعدي.
     *
     * @param callable():array{status:int,body:string,headers:array<string,string>} $attempt
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function retry(callable $attempt): array
    {
        $lastError = null;

        for ($try = 0; $try <= $this->retries; $try++) {
            try {
                return $attempt();
            } catch (TransportException $e) {
                $lastError = $e;
                if ($try < $this->retries) {
                    usleep((int) (250_000 * (2 ** $try)));
                }
            }
        }

        throw $lastError ?? new TransportException('فشل الطلب لسبب غير معروف.');
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>} $response
     * @return array{status:int,json:array<mixed>|null,body:string}
     */
    private function decodeJson(array $response): array
    {
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
    private function execute(string $url, array $headers, string $method = 'GET', ?string $body = null): array
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
            // تقييد البروتوكولات (حتى عبر إعادة التوجيه) يمنع استغلال روابط مُدخلة من
            // المستخدم (كـ webhook) للوصول إلى file:// أو بروتوكولات أخرى غير HTTP.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($responseBody === false) {
            throw new TransportException('فشل الاتصال بالخدمة: ' . ($error !== '' ? $error : 'خطأ شبكة'));
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'headers' => $responseHeaders];
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
