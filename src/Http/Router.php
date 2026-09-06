<?php

declare(strict_types=1);

namespace App\Http;

/**
 * موجّه مسارات ثابتة (بدون معاملات ديناميكية) يكفي لحجم هذه المنظومة.
 */
final class Router
{
    /** @var array<string,array<string,callable>> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    public function get(string $path, callable $handler): self
    {
        return $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->map('POST', $path, $handler);
    }

    /** تسجيل المسار لطريقتي GET و POST معًا. */
    public function any(string $path, callable $handler): self
    {
        return $this->get($path, $handler)->post($path, $handler);
    }

    public function map(string $method, string $path, callable $handler): self
    {
        $this->routes[strtoupper($method)][$this->normalize($path)] = $handler;

        return $this;
    }

    public function fallback(callable $handler): self
    {
        $this->notFound = $handler;

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method()][$this->normalize($request->path())] ?? null;

        if ($handler === null) {
            if ($this->notFound !== null) {
                return ($this->notFound)($request);
            }

            return Response::html('404 — الصفحة غير موجودة', 404);
        }

        return $handler($request);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
