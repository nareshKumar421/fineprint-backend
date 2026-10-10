<?php
/**
 * AdminController — the dashboard.
 *
 * Server-rendered HTML, which is why it does not use Response::json. Every
 * page runs through render(), so the authorisation check and the CSRF token
 * cannot be forgotten on a new page.
 *
 * The API and the dashboard share a host but not a failure mode: an expired
 * API token is a 401 with a JSON body, while an expired dashboard session is
 * a redirect to the sign-in form. A browser handed JSON would render it as
 * text.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Page;
use App\ApiException;
use App\Db;
use App\Request;
use App\Response;
use App\Services\AdminAuth;
use App\Services\Audit;
use App\Services\Settings;
use Throwable;

final class AdminController extends Page
{
    /* ---------------------------------------------------------------- */
    /*  auth                                                             */
    /* ---------------------------------------------------------------- */

    private static function setCsrfCookie(string $csrf): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::CSRF_COOKIE, $csrf, [
            'expires'  => time() + 12 * 3600,
            'path'     => '/',
            'httponly' => false,            // the form must be able to carry it
            'samesite' => 'Lax',
            'secure'   => str_starts_with(\App\Env::get('APP_URL', ''), 'https://'),
        ]);
    }

    /** GET / — the dashboard, or the sign-in form. */
    public function index(Request $request): void
    {
        if (AdminAuth::current() === null) {
            $this->loginForm(null);
            return;
        }
        $this->dashboard();
    }

    private function loginForm(?string $error, string $prefill = ''): void
    {
        // A token for the form itself, so a cross-site page cannot submit a
        // sign-in attempt. Regenerated per render.
        $formToken = bin2hex(random_bytes(16));
        self::setCsrfCookie($formToken);

        $this->render('login', [
            'error'     => $error,
            'formToken' => $formToken,
            'prefill'   => $prefill,
            'active'    => '',
        ], 'Sign in');
    }

    /** POST /login */
    public function login(Request $request): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $csrf     = (string) ($_POST['csrf'] ?? '');

        if ($csrf === '' || !hash_equals((string) ($_COOKIE[self::CSRF_COOKIE] ?? ''), $csrf)) {
            $this->loginForm('That form expired. Please try again.', $username);
            return;
        }

        try {
            $result = AdminAuth::login($username, $password, $request->ip(), $request->userAgent());
        } catch (ApiException $e) {
            // Deliberately the same message whatever the cause — wrong
            // password, no such account, not an admin. Anything more precise
            // turns this form into an account-enumeration oracle.
            $this->loginForm('Email or password is incorrect.', $username);
            return;
        }

        AdminAuth::setCookie($result['session']);
        self::setCsrfCookie($result['csrf']);
        $this->redirect('/');
    }

    /** POST /logout */
    public function logout(Request $request): void
    {
        AdminAuth::logout();
        $this->redirect('/');
    }

    /* ---------------------------------------------------------------- */
    /*  pages                                                            */
    /* ---------------------------------------------------------------- */

    private function dashboard(): void
    {
        /*
         * ONE query for the tiles.
         *
         * Ten scalar sub-selects in a single statement rather than ten
         * round trips. The database is milliseconds away now, but the habit
         * is what keeps a dashboard fast as it grows a section at a time.
         */
        $m = Db::one("
            SELECT (SELECT count(*) FROM articles)                                   AS articles,
                   (SELECT count(*) FROM articles
                     WHERE published_at > NOW() - INTERVAL '30 days')                AS in_window,
                   (SELECT count(*) FROM articles
                     WHERE fetched_at::date = CURRENT_DATE)                          AS today,
                   (SELECT count(*) FROM blog_sources)                               AS sources_total,
                   (SELECT count(*) FROM blog_sources WHERE is_active)               AS sources_active,
                   (SELECT count(*) FROM blog_sources WHERE NOT is_active)           AS sources_dead,
                   (SELECT count(*) FROM blog_sources WHERE failure_count > 0)       AS sources_failing,
                   (SELECT count(*) FROM users WHERE is_active)                      AS users,
                   (SELECT count(*) FROM article_events)                             AS events,
                   (SELECT max(run_at) FROM sync_status)                             AS last_sync
        ") ?? [];

        // Daily ingestion, oldest first so the chart reads left to right.
        $ingest = [];
        foreach (Db::all("
            SELECT fetched_at::date AS d, count(*) AS n
              FROM articles
             WHERE fetched_at > NOW() - INTERVAL '14 days'
             GROUP BY 1 ORDER BY 1") as $r) {
            $ingest[substr((string) $r['d'], 5)] = (int) $r['n'];
        }

        $m['avg_day'] = $ingest === [] ? 0 : (int) round(array_sum($ingest) / count($ingest));

        // Throughput per RUN, not per day: two runs in one day are two
        // points, and averaging them would hide a half-failed one.
        $syncSeries = [];
        foreach (array_reverse(Db::all(
            'SELECT run_at, articles_added FROM sync_status ORDER BY run_at DESC LIMIT 12')) as $r) {
            $syncSeries[substr((string) $r['run_at'], 5, 5)] = (int) $r['articles_added'];
        }

        $categories = [];
        foreach (Db::all("
            SELECT c.name, count(a.id) AS n
              FROM category_master c
              LEFT JOIN articles a ON a.category_id = c.id
             WHERE c.is_active
             GROUP BY c.name HAVING count(a.id) > 0
             ORDER BY n DESC LIMIT 7") as $r) {
            $categories[(string) $r['name']] = (int) $r['n'];
        }

        $topSources = [];
        foreach (Db::all('
            SELECT b.blog_name, count(a.id) AS n
              FROM blog_sources b
              LEFT JOIN articles a ON a.blog_source_id = b.id
             GROUP BY b.blog_name ORDER BY n DESC LIMIT 6') as $r) {
            $topSources[(string) $r['blog_name']] = (int) $r['n'];
        }

        $health = ['healthy' => 0, 'failing' => 0, 'deactivated' => 0];
        foreach (Db::all("
            SELECT CASE WHEN NOT is_active THEN 'deactivated'
                        WHEN failure_count > 0 THEN 'failing'
                        ELSE 'healthy' END AS state, count(*) AS n
              FROM blog_sources GROUP BY 1") as $r) {
            $health[(string) $r['state']] = (int) $r['n'];
        }

        $lastSync = $m['last_sync'] ?? null;
        $stale    = $lastSync === null || strtotime((string) $lastSync) < time() - 36 * 3600;

        $m['window_label'] = $lastSync === null
            ? 'No sync recorded'
            : 'Last sync ' . substr((string) $lastSync, 0, 16) . ' UTC';

        $warnings = [];
        if ((int) ($m['sources_dead'] ?? 0) > 0) {
            $warnings[] = $m['sources_dead'] . ' source(s) deactivated after repeated failures. '
                        . 'They are never retried automatically — reactivate them on Sources.';
        }
        if ((int) ($m['sources_failing'] ?? 0) > 0) {
            $warnings[] = $m['sources_failing'] . ' feed(s) currently failing.';
        }
        if ($stale) {
            $warnings[] = 'No sync in over 36 hours. The feed still serves, but it has stopped learning.';
        }

        $this->render('dashboard', [
            'm'           => $m,
            'warnings'    => $warnings,
            'ingest'      => $ingest,
            'syncSeries'  => $syncSeries,
            'categories'  => $categories,
            'topSources'  => $topSources,
            'health'      => $health,
            'recentSync'  => Db::all('SELECT * FROM sync_status ORDER BY run_at DESC LIMIT 6'),
            'active'      => '',
        ], 'Overview');
    }
}
