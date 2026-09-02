<?php

declare(strict_types=1);

namespace App\View;

use RuntimeException;

/**
 * محرّك عرض بسيط: قوالب PHP مع تخطيط مشترك وهروب افتراضي للنصوص.
 */
final class View
{
    /** @param array<string,mixed> $shared */
    public function __construct(private string $directory, private array $shared = [])
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);

        if ($layout === '') {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    /** هروب HTML — يُستخدم في كل القوالب عبر الدالة e(). */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $file = rtrim($this->directory, '/') . '/' . ltrim($template, '/') . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('القالب "%s" غير موجود.', $template));
        }

        $view = $this;
        extract($this->shared + $data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
