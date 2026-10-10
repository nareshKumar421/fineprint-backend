<?php
/**
 * Settings — values an administrator can change without a deploy.
 *
 * Read-through cached for the life of a request. The whole table is six rows
 * and the database is in another region, so fetching it once beats six
 * lookups by a wide margin — this codebase counts round trips, not queries.
 *
 * NOT every constant in the system lives here, and that is deliberate.
 * Several ranking constants are duplicated in Scoring.php and
 * rollup_stats.php and must agree; making them editable from a web form
 * would let the two drift silently. Settings holds operational values;
 * ranking policy stays in code where it is reviewed.
 */

declare(strict_types=1);

namespace App\Services;

use App\Db;
use Throwable;

final class Settings
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = Db::all('SELECT key, value, value_type FROM app_settings');
        } catch (Throwable $e) {
            // A missing table (migration not yet applied) must not take the
            // whole application down — callers all have defaults.
            error_log('Settings::load failed: ' . $e->getMessage());
            return self::$cache = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = ['value' => $r['value'], 'type' => $r['value_type']];
        }
        return self::$cache = $out;
    }

    /** Raw string value, or null when unset. */
    public static function get(string $key): ?string
    {
        return self::load()[$key]['value'] ?? null;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v !== null && ctype_digit(ltrim($v, '-')) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, array{value: string, type: string, description: ?string}> */
    public static function all(): array
    {
        return Db::all('SELECT key, value, value_type, description, updated_at
                          FROM app_settings ORDER BY key');
    }

    /**
     * Write a value, validating it against its declared type.
     *
     * Returns the previous value so the caller can record a before/after in
     * the audit log without a second read.
     */
    public static function set(string $key, string $value, int $byUserId): ?string
    {
        $row = Db::one('SELECT value, value_type FROM app_settings WHERE key = ?', [$key]);
        if ($row === null) {
            throw new \App\ApiException('VALIDATION_ERROR', "Unknown setting: $key", 400);
        }

        $value = trim($value);

        switch ($row['value_type']) {
            case 'int':
                if (!ctype_digit($value)) {
                    throw new \App\ApiException('VALIDATION_ERROR', "$key must be a whole number.", 400);
                }
                break;
            case 'bool':
                $norm = strtolower($value);
                if (!in_array($norm, ['true', 'false'], true)) {
                    throw new \App\ApiException('VALIDATION_ERROR', "$key must be true or false.", 400);
                }
                $value = $norm;
                break;
            case 'json':
                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \App\ApiException('VALIDATION_ERROR', "$key must be valid JSON.", 400);
                }
                break;
        }

        Db::exec(
            'UPDATE app_settings SET value = ?, updated_at = NOW(), updated_by = ? WHERE key = ?',
            [$value, $byUserId, $key]
        );

        self::$cache = null;                 // next read sees the new value
        return $row['value'];
    }
}
