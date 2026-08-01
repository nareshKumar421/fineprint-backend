<?php
/**
 * Env — load backend/.env into the environment.
 *
 * Kept deliberately tiny and dependency-free. A real environment variable
 * always wins over the file, so production can override without editing
 * anything on disk.
 */

declare(strict_types=1);

namespace App;

final class Env
{
    public static function load(?string $path = null): void
    {
        $path ??= dirname(__DIR__) . '/.env';
        if (!is_readable($path)) {
            return;                      // real env vars may already be set
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip one layer of matching quotes; leave the contents alone.
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"')
                 || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : (int) $v;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'local') === 'production';
    }
}
