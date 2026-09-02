<?php

declare(strict_types=1);

namespace App\Http;

/**
 * تغليف بيانات الطلب الواردة بدل الوصول المباشر للمتغيرات العامة.
 */
final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) ? rtrim($path, '/') ?: '/' : '/',
            $_GET,
            $_POST,
            $_SERVER
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function intInput(string $key, int $default): int
    {
        $value = $this->input($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');

        return str_starts_with($this->path, '/api/')
            || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));
    }

    /** معرّف العميل المستخدم في تحديد معدّل الطلبات. */
    public function clientId(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? 'cli');
        $agent = (string) ($this->server['HTTP_USER_AGENT'] ?? '');

        return hash('sha256', $ip . '|' . $agent);
    }
}
