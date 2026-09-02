<?php

declare(strict_types=1);

namespace App\Http;

/**
 * استجابة HTTP بسيطة (حالة + ترويسات + جسم).
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return new self(
            $encoded === false ? '{"ok":false,"error":"تعذّر ترميز الاستجابة."}' : $encoded,
            $status,
            $headers + ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /** @param array<string,mixed> $extra */
    public static function jsonError(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
