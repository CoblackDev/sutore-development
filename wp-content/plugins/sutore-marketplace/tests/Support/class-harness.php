<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Support;

final class Harness
{
    /** @var list<array{suite:string,name:string,status:string,detail:string,ms:int}> */
    public static array $results = [];

    public static string $currentSuite = '';

    public static function run(string $name, callable $fn): void
    {
        $started = microtime(true);
        try {
            $fn();
            self::$results[] = [
                'suite' => self::$currentSuite,
                'name' => $name,
                'status' => 'PASS',
                'detail' => '',
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Skipped $e) {
            self::$results[] = [
                'suite' => self::$currentSuite,
                'name' => $name,
                'status' => 'SKIP',
                'detail' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Failed $e) {
            self::$results[] = [
                'suite' => self::$currentSuite,
                'name' => $name,
                'status' => 'FAIL',
                'detail' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            self::$results[] = [
                'suite' => self::$currentSuite,
                'name' => $name,
                'status' => 'FAIL',
                'detail' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    public static function skip(string $reason): void
    {
        throw new Skipped($reason);
    }

    public static function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new Failed($message !== '' ? $message : 'Expected true, got ' . self::dump($value));
        }
    }

    public static function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new Failed($message !== '' ? $message : 'Expected false, got ' . self::dump($value));
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $prefix = $message !== '' ? $message . ' — ' : '';
            throw new Failed($prefix . 'expected ' . self::dump($expected) . ', got ' . self::dump($actual));
        }
    }

    public static function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new Failed($message !== '' ? $message : 'Did not expect ' . self::dump($actual));
        }
    }

    public static function assertEqualsFloat(float $expected, float $actual, string $message = '', float $delta = 0.001): void
    {
        if (abs($expected - $actual) > $delta) {
            $prefix = $message !== '' ? $message . ' — ' : '';
            throw new Failed($prefix . 'expected ' . $expected . ', got ' . $actual);
        }
    }

    public static function assertGreaterThan(float|int $min, float|int $actual, string $message = ''): void
    {
        if ($actual <= $min) {
            throw new Failed($message !== '' ? $message : 'Expected > ' . $min . ', got ' . $actual);
        }
    }

    public static function assertNotWpError(mixed $value, string $message = ''): void
    {
        if ($value instanceof \WP_Error) {
            throw new Failed(
                ($message !== '' ? $message . ': ' : '')
                . $value->get_error_code() . ' — ' . $value->get_error_message()
            );
        }
    }

    public static function assertWpError(mixed $value, string $message = '', string $code = ''): void
    {
        if (!$value instanceof \WP_Error) {
            throw new Failed($message !== '' ? $message : 'Expected WP_Error, got ' . self::dump($value));
        }
        if ($code !== '' && $value->get_error_code() !== $code) {
            throw new Failed(
                ($message !== '' ? $message . ': ' : '')
                . 'expected code ' . $code . ', got ' . $value->get_error_code()
            );
        }
    }

    public static function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new Failed($message !== '' ? $message : 'Missing "' . $needle . '" in ' . $haystack);
        }
    }

    private static function dump(mixed $value): string
    {
        if ($value instanceof \WP_Error) {
            return 'WP_Error(' . $value->get_error_code() . ')';
        }
        if (is_object($value)) {
            return $value::class;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : gettype($value);
    }
}
