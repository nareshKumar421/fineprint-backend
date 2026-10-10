<?php
/**
 * SystemController — settings, the audit log, and the system status page.
 *
 * Three read-mostly screens for the operator:
 *
 *   /settings   the app_settings table, one form per row. The only mutation
 *               in this module goes through Settings::set(), which validates
 *               by declared type and hands back the previous value so the
 *               audit row carries a real before/after.
 *   /audit      the audit_log, paginated and filterable. Every other module
 *               writes here; this is where those rows are read.
 *   /system     what the service is running on — versions, sizes, row
 *               counts, the last sync and job per type, and the environment
 *               with every value masked.
 *
 * Deliberately NOT here: the ranking constants. They live in
 * src/Services/Scoring.php and jobs/rollup_stats.php and must agree; an
 * editable copy in a table would let the two drift silently.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\ApiException;
use App\Db;
use App\Env;
use App\Request;
use App\Services\Audit;
use App\Services\Settings;

final class SystemController extends Page
{
    /**
     * audit_log.entity → the dashboard page that shows that entity, keyed
     * by ?id=. Tables not listed here render as plain text in the log.
     */
    private const ENTITY_ROUTES = [
        'blog_sources'    => '/sources/view?id=',
        'articles'        => '/articles/view?id=',
        'category_master' => '/categories/view?id=',
        'users'           => '/users/view?id=',
        'app_settings'    => '/settings#',
    ];

    /** Entities offered in the audit filter. Any exact string is accepted. */
    private const ENTITY_OPTIONS = [
        'blog_sources', 'articles', 'category_master', 'category_blog_map',
        'users', 'app_settings', 'admin_jobs', 'donations', 'sync_status',
    ];

    /** ORDER BY whitelist for /audit. The request picks a KEY, never a column. */
    private const AUDIT_SORT = [
        'created_at'  => 'a.created_at',
        'actor_email' => 'a.actor_email',
        'action'      => 'a.action',
        'entity'      => 'a.entity',
    ];

    /** Env var prefixes listed on /system. Values are never printed. */
    private const ENV_PREFIXES = ['FEED_', 'APP_', 'TOKEN_', 'PAYMENTS_', 'DONATION_'];

    /* ---------------------------------------------------------------- */
    /*  settings                                                         */
    /* ---------------------------------------------------------------- */

    /** GET /settings */
    public function settings(Request $request): void
    {
        $this->admin();

        $rows = Db::all('
            SELECT s.key, s.value, s.value_type, s.description, s.updated_at,
                   u.email AS updated_by_email
              FROM app_settings s
              LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.key');

        $this->render('system/settings', [
            'rows' => $rows,
        ], 'Settings', 'settings');
    }

    /** POST /settings/save {key, value} */
    public function saveSetting(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        try {
            $key   = $this->requireString('key', 64);
            $value = $_POST['value'] ?? '';
            if (!is_string($value)) {
                throw new ApiException('VALIDATION_ERROR', 'value must be a string.', 400);
            }
            if (mb_strlen($value) > 10000) {
                throw new ApiException('VALIDATION_ERROR', 'value is too long (max 10000).', 400);
            }

            // Settings::set validates by the row's declared type and returns
            // the previous value — the "before" snapshot without a second read.
            $prev = Settings::set($key, $value, $admin['id']);
        } catch (ApiException $e) {
            $this->redirect('/settings', $e->getMessage(), 'bad');
            return;
        }

        $new = (string) Settings::get($key);

        if ($prev === $new) {
            $this->redirect('/settings', "{$key} is unchanged.", 'warn');
            return;
        }

        Audit::record($admin['id'], $admin['email'], 'setting.update', 'app_settings', $key,
            ['value' => $prev], ['value' => $new]);

        $this->redirect('/settings', "Saved {$key}: " . self::short($prev) . ' → ' . self::short($new) . '.', 'ok');
    }

    private static function short(?string $v, int $max = 40): string
    {
        $v = (string) $v;
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    /* ---------------------------------------------------------------- */
    /*  audit log                                                        */
    /* ---------------------------------------------------------------- */

    /** GET /audit */
    public function audit(Request $request): void
    {
        $this->admin();

        $str = static function (string $k): string {
            $v = $_GET[$k] ?? '';
            return is_string($v) ? trim($v) : '';
        };

        $filters = [
            'actor'  => mb_substr($str('actor'), 0, 255),
            'action' => mb_substr($str('action'), 0, 64),
            'entity' => mb_substr($str('entity'), 0, 64),
            'from'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $str('from')) ? $str('from') : '',
            'to'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $str('to'))   ? $str('to')   : '',
        ];

        $sortKey = $str('sort');
        if (!isset(self::AUDIT_SORT[$sortKey])) {
            $sortKey = 'created_at';
        }
        $dir = strtolower($str('dir')) === 'asc' ? 'ASC' : 'DESC';

        $where  = [];
        $params = [];
        if ($filters['actor'] !== '') {
            $where[]  = 'a.actor_email ILIKE ?';
            $params[] = '%' . self::likeEscape($filters['actor']) . '%';
        }
        if ($filters['action'] !== '') {
            $where[]  = 'a.action ILIKE ?';
            $params[] = '%' . self::likeEscape($filters['action']) . '%';
        }
        if ($filters['entity'] !== '') {
            $where[]  = 'a.entity = ?';
            $params[] = $filters['entity'];
        }
        if ($filters['from'] !== '') {
            $where[]  = 'a.created_at >= ?::date';
            $params[] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            // Inclusive end date: everything before the following midnight.
            $where[]  = "a.created_at < ?::date + INTERVAL '1 day'";
            $params[] = $filters['to'];
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $pager = new Paginator(50);
        $total = (int) Db::scalar('SELECT count(*) FROM audit_log a' . $whereSql, $params);

        // Secondary key on id so equal timestamps page deterministically.
        $rows = Db::all(
            'SELECT a.id, a.created_at, a.actor_email, a.action, a.entity, a.entity_id,
                    a.before_json, a.after_json, a.ip
               FROM audit_log a' . $whereSql . '
              ORDER BY ' . self::AUDIT_SORT[$sortKey] . ' ' . $dir . ', a.id ' . $dir . '
              LIMIT ? OFFSET ?',
            [...$params, $pager->perPage, $pager->offset]
        );

        $this->render('system/audit', [
            'rows'          => $rows,
            'page'          => $pager->describe($total),
            'filters'       => $filters,
            'sort'          => $sortKey,
            'dir'           => strtolower($dir),
            'sortKeys'      => array_keys(self::AUDIT_SORT),
            'entityOptions' => self::ENTITY_OPTIONS,
            'entityRoutes'  => self::ENTITY_ROUTES,
        ], 'Audit log', 'audit');
    }

    /** Escape LIKE metacharacters so a search for "100%" means the text. */
    private static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /* ---------------------------------------------------------------- */
    /*  system status                                                    */
    /* ---------------------------------------------------------------- */

    /** GET /system */
    public function system(Request $request): void
    {
        $this->admin();

        // Server facts in ONE round trip.
        $db = Db::one('
            SELECT current_setting(?) AS server_version,
                   current_database()                       AS db_name,
                   pg_database_size(current_database())     AS db_size,
                   current_user                             AS db_user,
                   (SELECT count(*) FROM pg_stat_activity
                     WHERE datname = current_database())    AS connections',
            ['server_version']) ?? [];

        // Every table in the public schema, counted in one statement. Names
        // come from the catalogue, not the request, and are still validated
        // before being quoted into the UNION.
        $tables = [];
        foreach (Db::all(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = ? AND table_type = ? ORDER BY table_name',
            ['public', 'BASE TABLE']) as $r) {
            $t = (string) $r['table_name'];
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $t)) {
                $tables[] = $t;
            }
        }
        $rowCounts = [];
        if ($tables !== []) {
            $parts = [];
            foreach ($tables as $t) {
                $parts[] = "SELECT '{$t}' AS t, count(*) AS n FROM \"{$t}\"";
            }
            foreach (Db::all(implode(' UNION ALL ', $parts)) as $r) {
                $rowCounts[(string) $r['t']] = (int) $r['n'];
            }
            arsort($rowCounts);
        }

        $lastSync = Db::one('SELECT * FROM sync_status ORDER BY run_at DESC LIMIT 1');

        $jobs = Db::all('
            SELECT DISTINCT ON (type) type, status, requested_at, started_at, finished_at,
                   exit_code, summary
              FROM admin_jobs
             ORDER BY type, requested_at DESC');

        // Health facts for the checks list, again one statement.
        $h = Db::one("
            SELECT (SELECT max(run_at) FROM sync_status)                                 AS last_sync,
                   (SELECT count(*) FROM blog_sources WHERE NOT is_active)              AS dead,
                   (SELECT count(*) FROM blog_sources WHERE is_active AND failure_count > 0) AS failing,
                   (SELECT count(*) FROM users WHERE role = 'admin' AND is_active)      AS admins,
                   (SELECT count(*) FROM article_events
                     WHERE created_at > NOW() - INTERVAL '24 hours')                     AS events_24h
        ") ?? [];

        $uptime = null;
        if (@is_readable('/proc/1')) {
            $mt = @filemtime('/proc/1');
            if ($mt !== false && $mt > 0) {
                $uptime = time() - $mt;
            }
        }

        $env = [];
        foreach (getenv() as $k => $v) {
            foreach (self::ENV_PREFIXES as $p) {
                if (str_starts_with((string) $k, $p)) {
                    $env[(string) $k] = ['set' => $v !== '', 'length' => strlen((string) $v)];
                    break;
                }
            }
        }
        ksort($env);

        $lastSyncTs = isset($h['last_sync']) ? strtotime((string) $h['last_sync']) : false;
        $syncAge    = $lastSyncTs === false ? null : time() - $lastSyncTs;

        $checks = [
            [
                'label'  => 'Last sync under 36 hours ago',
                'state'  => $syncAge === null ? 'bad' : ($syncAge < 36 * 3600 ? 'ok' : 'bad'),
                'detail' => $syncAge === null ? 'No sync recorded.'
                          : self::ago($syncAge) . ' ago (' . substr((string) $h['last_sync'], 0, 16) . ' UTC).',
            ],
            [
                'label'  => 'No deactivated sources',
                'state'  => (int) ($h['dead'] ?? 0) === 0 ? 'ok' : 'warn',
                'detail' => (int) ($h['dead'] ?? 0) === 0 ? 'Every source is active.'
                          : (int) $h['dead'] . ' source(s) deactivated. They are never retried on their own.',
            ],
            [
                'label'  => 'No failing sources',
                'state'  => (int) ($h['failing'] ?? 0) === 0 ? 'ok' : 'warn',
                'detail' => (int) ($h['failing'] ?? 0) === 0 ? 'No active source has a failure count.'
                          : (int) $h['failing'] . ' active source(s) with failures.',
            ],
            [
                'label'  => 'At least one active admin',
                'state'  => (int) ($h['admins'] ?? 0) >= 1 ? 'ok' : 'bad',
                'detail' => (int) ($h['admins'] ?? 0) . ' active admin account(s).',
            ],
            [
                'label'  => 'Reader events in the last 24 hours',
                'state'  => (int) ($h['events_24h'] ?? 0) > 0 ? 'ok' : 'warn',
                'detail' => (int) ($h['events_24h'] ?? 0) > 0
                          ? number_format((int) $h['events_24h']) . ' event(s). The feed is learning.'
                          : 'None. Either nobody is reading or the client is not flushing.',
            ],
        ];

        $this->render('system/system', [
            'php'         => PHP_VERSION,
            'os'          => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'sapi'        => PHP_SAPI,
            'db'          => $db,
            'tables'      => $rowCounts,
            'lastSync'    => $lastSync,
            'jobs'        => $jobs,
            'uptime'      => $uptime,
            'syncEnabled' => Settings::bool('sync.enabled', true),
            'env'         => $env,
            'checks'      => $checks,
            'appEnv'      => Env::get('APP_ENV', 'local'),
        ], 'System', 'system');
    }

    /** "3h 12m", "4d 2h". */
    public static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
        }
        return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
    }

    /** 1536 → "1.5 KB". */
    public static function bytes(float|int|string|null $n): string
    {
        $n = (float) ($n ?? 0);
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($n < 1024 || $unit === 'TB') {
                return ($unit === 'B' ? (string) (int) $n : number_format($n, 1)) . ' ' . $unit;
            }
            $n /= 1024;
        }
        return '0 B';
    }
}
