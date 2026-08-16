<?php
/**
 * HealthController — GET /
 *
 * What an uptime monitor polls, and the first thing to open in a browser
 * when someone says "the app is broken".
 *
 * IT MUST ACTUALLY TOUCH THE DATABASE. A health check that only proves
 * "PHP is running" reports green while every real endpoint 500s, which is
 * worse than no check at all — it delays the discovery of an outage.
 *
 * IT MUST RETURN 503 WHEN UNHEALTHY. Monitors watch the status code, not
 * the body. A 200 carrying {"status":"error"} never pages anyone.
 *
 * IT MUST NOT LEAK. No versions, no hostnames, no connection strings, no
 * exception messages — this is the most-scanned path on the internet.
 * The database error goes to the log; the caller gets "down".
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Env;
use App\Request;
use App\Response;
use Throwable;

final class HealthController
{
    public function index(Request $request): void
    {
        $started = microtime(true);

        $database = 'up';
        $content  = null;

        try {
            /*
             * One round trip, not three. Each separate query would add a
             * full network hop to a hosted database, and this endpoint gets
             * polled every minute forever.
             *
             * COUNT is over an indexed, 60-day-capped table, so it stays
             * cheap; last_sync_at is what actually reveals a dead cron job,
             * which is the failure this endpoint exists to catch.
             */
            $row = Db::one(
                'SELECT (SELECT COUNT(*) FROM articles)          AS articles,
                        (SELECT MAX(run_at) FROM sync_status)    AS last_sync_at'
            );

            $content = [
                'articles'     => (int) ($row['articles'] ?? 0),
                'last_sync_at' => $row['last_sync_at'] ?? null,
            ];
        } catch (Throwable $e) {
            // The message can contain the DSN, including the password.
            // Log it; never return it.
            error_log('Health check: database unreachable — ' . $e->getMessage());
            $database = 'down';
        }

        $healthy = $database === 'up';

        $body = [
            'status'   => $healthy ? 'ok' : 'degraded',
            'service'  => 'fineprint-api',
            // ISO-8601 in UTC. A local-time stamp from a server in another
            // zone is unreadable next to a log line from anywhere else.
            'time'     => gmdate('Y-m-d\TH:i:s\Z'),
            'database' => $database,
        ];

        if ($content !== null) {
            $body['content'] = $content;
        }

        // Only outside production. Knowing the environment is handy while
        // deploying and is free reconnaissance once live.
        if (!Env::isProduction()) {
            $body['environment'] = Env::get('APP_ENV', 'local');
            $body['payments']    = Env::get('PAYMENTS_ENABLED', 'false') === 'true'
                ? 'enabled' : 'disabled';
        }

        $body['response_ms'] = (int) round((microtime(true) - $started) * 1000);

        Response::json($body, $healthy ? 200 : 503);
    }
}
