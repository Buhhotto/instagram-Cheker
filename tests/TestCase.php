<?php

declare(strict_types=1);

namespace Tests;

/**
 * مُشغّل اختبارات صغير بدون اعتمادات خارجية.
 */
final class TestCase
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n\033[1m» {$name}\033[0m\n";
    }

    public function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m {$message}\n";

            return;
        }

        $this->failures[] = $this->group . ' → ' . $message;
        echo "  \033[31m✗\033[0m {$message}\n";
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert(
            $expected === $actual,
            $message . ($expected === $actual ? '' : sprintf(
                ' (متوقع: %s، الناتج: %s)',
                var_export($expected, true),
                var_export($actual, true)
            ))
        );
    }

    public function assertBetween(float $value, float $min, float $max, string $message): void
    {
        $inRange = $value >= $min && $value <= $max;

        $this->assert(
            $inRange,
            $inRange ? $message : $message . sprintf(' (القيمة %.2f خارج المدى [%.1f, %.1f])', $value, $min, $max)
        );
    }

    public function assertThrows(string $exceptionClass, callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assert($e instanceof $exceptionClass, $message);

            return;
        }

        $this->assert(false, $message . ' (لم يُرمَ أي استثناء)');
    }

    public function summary(): int
    {
        $failed = count($this->failures);

        echo "\n" . str_repeat('─', 60) . "\n";

        if ($failed === 0) {
            echo "\033[32mنجحت جميع الاختبارات: {$this->passed}\033[0m\n";

            return 0;
        }

        echo "\033[31mفشل {$failed} من " . ($this->passed + $failed) . " اختبارًا:\033[0m\n";
        foreach ($this->failures as $failure) {
            echo "  - {$failure}\n";
        }

        return 1;
    }
}
