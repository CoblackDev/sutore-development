<?php

declare(strict_types=1);

namespace SutoreMarketplace\Bootstrap;

final class Autoloader
{
    private const PREFIX = 'SutoreMarketplace\\';

    private const ROOT_SEGMENTS = [
        'Bootstrap' => 'Bootstrap',
        'Shared' => 'Shared',
        'Modules' => 'Modules',
        'Admin' => 'Admin',
        'Frontend' => 'Frontend',
        'Cli' => 'Cli',
    ];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $parts = explode('\\', $relative);
        $root = $parts[0] ?? '';

        if (!isset(self::ROOT_SEGMENTS[$root])) {
            return;
        }

        $className = array_pop($parts);
        if ($parts !== [] && $parts[0] === $root) {
            array_shift($parts);
        }
        $subPath = $parts !== [] ? implode('/', $parts) . '/' : '';
        $file = SUTORE_MARKETPLACE_PATH . 'includes/' . $root . '/' . $subPath . 'class-' . self::kebabCase($className) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }

    private static function kebabCase(string $name): string
    {
        $name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name) ?? $name;

        return strtolower($name);
    }
}
