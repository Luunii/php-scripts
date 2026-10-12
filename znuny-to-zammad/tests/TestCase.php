<?php

declare(strict_types=1);

namespace Znuny2Zammad\Tests;

/**
 * Minimale Testbasis, damit die Tests ohne PHPUnit/Composer laufen.
 */
abstract class TestCase
{
    /** @var int */
    public $assertions = 0;

    /** @var string[] */
    private $tempFiles = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    protected function tempFile(string $suffix = ''): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'z2z');
        if ($suffix !== '') {
            rename($file, $file . $suffix);
            $file .= $suffix;
        }
        $this->tempFiles[] = $file;
        $this->tempFiles[] = $file . '.tmp';

        return $file;
    }

    /**
     * @return array<string,mixed>
     */
    protected function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Fixture ' . $name . ' ist ungueltig.');
        }

        return $data;
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    protected function assertSame($expected, $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                "%sErwartet: %s\nErhalten: %s",
                $message !== '' ? $message . "\n" : '',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    /**
     * @param mixed $value
     */
    protected function assertTrue($value, string $message = ''): void
    {
        $this->assertSame(true, $value, $message);
    }

    /**
     * @param mixed $value
     */
    protected function assertFalse($value, string $message = ''): void
    {
        $this->assertSame(false, $value, $message);
    }

    /**
     * @param array<mixed>|\Countable $haystack
     */
    protected function assertCount(int $expected, $haystack, string $message = ''): void
    {
        $this->assertSame($expected, count($haystack), $message);
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailed(sprintf("%s\"%s\" nicht enthalten in:\n%s", $message !== '' ? $message . "\n" : '', $needle, mb_substr($haystack, 0, 2000)));
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (strpos($haystack, $needle) !== false) {
            throw new AssertionFailed(sprintf("%s\"%s\" unerwartet enthalten in:\n%s", $message !== '' ? $message . "\n" : '', $needle, mb_substr($haystack, 0, 2000)));
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    protected function assertThrows(string $class, callable $callback, string $messagePart = ''): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed(sprintf('Erwartet %s, erhalten %s: %s', $class, get_class($e), $e->getMessage()));
            }
            if ($messagePart !== '' && strpos($e->getMessage(), $messagePart) === false) {
                throw new AssertionFailed(sprintf('Meldung "%s" enthaelt nicht "%s"', $e->getMessage(), $messagePart));
            }

            return;
        }
        throw new AssertionFailed(sprintf('Erwartete Exception %s wurde nicht geworfen.', $class));
    }
}

final class AssertionFailed extends \RuntimeException
{
}
