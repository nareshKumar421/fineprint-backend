<?php
/**
 * RateLimit — fixed-window counters held in PostgreSQL.
 *
 * Backed by a table rather than APCu because APCu is per-process (and off by
 * default in CLI), so it silently fails to limit anything across PHP-FPM
 * workers. A table is correct on one server and still correct on three.
 *
 * docs/03 §5:
 *   /api/login, /api/register   5 per IP per minute
 *   /api/donation/create       10 per user per hour
 *   everything else           120 per token per minute
 */

declare(strict_types=1);

namespace App\Middleware;

use App\ApiException;
use App\Db;
use App\Request;
use PDOException;

final class RateLimit
{
    /**
     * @param bool $perUser key on the authenticated user rather than the IP.
     *
     * Per-IP is right for login and register: there is no user yet, and the
     * IP is the only thing an attacker cannot trivially vary.
     *
     * Per-user is right for donations. docs/03 §5 specifies "10 per user per
     * hour", and keying that on IP would make everyone behind one office or
     * mobile-carrier NAT share a single quota — one person donating would
     * lock out the building.
     */
    public static function check(
        Request $request,
        string $bucket,
        int $max,
        int $seconds,
        bool $perUser = false,
    ): void {
        $key = $perUser && $request->userId !== null
            ? $bucket . ':u' . $request->userId
            : $bucket . ':' . $request->ip();

        try {
            // One atomic statement. The CASE resets the window when it has
            // lapsed, so there is no read-then-write race between workers.
            $hits = (int) Db::scalar(
                "INSERT INTO rate_limits (bucket, hits, window_start)
                      VALUES (?, 1, NOW())
                 ON CONFLICT (bucket) DO UPDATE SET
                      hits = CASE
                                WHEN rate_limits.window_start < NOW() - (CAST(? AS int) * INTERVAL '1 second')
                                THEN 1
                                ELSE rate_limits.hits + 1
                             END,
                      window_start = CASE
                                WHEN rate_limits.window_start < NOW() - (CAST(? AS int) * INTERVAL '1 second')
                                THEN NOW()
                                ELSE rate_limits.window_start
                             END
                 RETURNING hits",
                [$key, $seconds, $seconds]
            );
        } catch (PDOException) {
            // Never let the limiter take the API down. If its table is missing
            // or unreachable, log and allow the request through.
            error_log("RateLimit: counter unavailable for bucket {$bucket}");
            return;
        }

        if ($hits > $max) {
            throw new ApiException(
                'RATE_LIMITED',
                'Too many attempts. Please try again in a minute.',
                429
            );
        }
    }

    /** Housekeeping — drop windows that can no longer matter. */
    public static function purgeOld(): int
    {
        return Db::exec("DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL '1 day'");
    }
}
