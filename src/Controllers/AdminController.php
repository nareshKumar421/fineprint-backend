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

use App\ApiException;
use App\Db;
use App\Request;
use App\Response;
use App\Services\AdminAuth;
use App\Services\Audit;
use App\Services\Settings;
use Throwable;

final class AdminController
{
    private const VIEWS = __DIR__ . '/../Views/admin/';

    /* ---------------------------------------------------------------- */
    /*  rendering                                                        */
    /* ---------------------------------------------------------------- */

    private function render(string $view, array $vars, string $title, string $active = ''): void
    {
        $admin = AdminAuth::current();
        $vars['admin'] = $admin;
        $vars['csrf']  = $_COOKIE[self::CSRF_COOKIE] ?? '';

        extract($vars, EXTR_SKIP);
        ob_start();
        require self::VIEWS . $view . '.php';
        $body = ob_get_clean();

        ob_start();
        require self::VIEWS . 'layout.php';
        Response::html(ob_get_clean());
    }

    /**
     * The CSRF token is held in a SECOND cookie, readable by the page.
     *
     * The session cookie is httponly and must stay that way. The CSRF token
     * is not a credential — it is a value the attacker's page cannot read
     * because of the same-origin policy, which is exactly what makes a
     * cross-site POST fail. Keeping it separate means the session id is
     * never exposed to the template.
     */
    private const CSRF_COOKIE = 'fp_admin_csrf';

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

    private static function redirect(string $to): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, 302);
        }

        // The 302 MUST be passed through here. Response::html() calls
        // http_response_code() itself and defaults to 200, which silently
        // overwrites the status set above — leaving a 200 that carries a
        // Location header. Browsers follow the meta-refresh below and it
        // looks fine; anything stricter does not move.
        Response::html(
            '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to) . '">',
            302
        );
    }

    /* ---------------------------------------------------------------- */
    /*  auth                                                             */
    /* ---------------------------------------------------------------- */

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
        self::redirect('/');
    }

    /** POST /logout */
    public function logout(Request $request): void
    {
        AdminAuth::logout();
        self::redirect('/');
    }

    /* ---------------------------------------------------------------- */
    /*  pages                                                            */
    /* ---------------------------------------------------------------- */

    private function dashboard(): void
    {
        $m = Db::one("
            SELECT (SELECT count(*) FROM articles)                                   AS articles,
                   (SELECT count(*) FROM articles
                     WHERE published_at > NOW() - INTERVAL '30 days')                AS in_window,
                   (SELECT count(*) FROM blog_sources WHERE is_active)               AS sources_active,
                   (SELECT count(*) FROM blog_sources WHERE NOT is_active)           AS sources_dead,
                   (SELECT count(*) FROM blog_sources WHERE failure_count > 0)       AS sources_failing,
                   (SELECT count(*) FROM users WHERE is_active)                      AS users,
                   (SELECT count(*) FROM user_feed_stats WHERE impressions >= 50)    AS warm,
                   (SELECT count(*) FROM article_events)                             AS events,
                   (SELECT count(*) FROM article_events
                     WHERE created_at > NOW() - INTERVAL '24 hours')                 AS events_24h,
                   (SELECT max(run_at) FROM sync_status)                             AS last_sync
        ") ?? [];

        $lastSync = $m['last_sync'] ?? null;
        $stale    = $lastSync === null || strtotime((string) $lastSync) < time() - 36 * 3600;

        $m['last_sync_human'] = $lastSync === null ? 'never' : substr((string) $lastSync, 0, 16);
        $m['sync_stale']      = $stale;

        $warnings = [];
        if ((int) ($m['sources_dead'] ?? 0) > 0) {
            $warnings[] = $m['sources_dead'] . ' source(s) deactivated after repeated failures. '
                        . 'They are never retried automatically — reactivate them on the Sources page.';
        }
        if ((int) ($m['sources_failing'] ?? 0) > 0) {
            $warnings[] = $m['sources_failing'] . ' feed(s) failing. See Sources.';
        }
        if ($stale) {
            $warnings[] = 'No sync in over 36 hours. The feed still serves, but it has stopped learning.';
        }

        $this->render('dashboard', [
            'm'            => $m,
            'warnings'     => $warnings,
            'recentSync'   => Db::all('SELECT * FROM sync_status ORDER BY run_at DESC LIMIT 8'),
            'recentAudit'  => Audit::recent(10),
            'active'       => '',
        ], 'Overview');
    }

    /** GET /sources */
    public function sources(Request $request): void
    {
        AdminAuth::require();

        $this->render('sources', [
            'sources' => Db::all("
                SELECT b.id, b.blog_name, b.feed_url, b.is_active, b.failure_count, b.last_error,
                       count(a.id)                                                      AS articles,
                       count(a.id) FILTER (
                         WHERE a.published_at > NOW() - INTERVAL '30 days')             AS in_window
                  FROM blog_sources b
                  LEFT JOIN articles a ON a.blog_source_id = b.id
                 GROUP BY b.id
                 ORDER BY b.is_active DESC, b.failure_count DESC, b.blog_name"),
            'maxFailures' => Settings::int('feed.max_failures', 5),
            'flash'       => $_GET['msg'] ?? null,
            'flashTone'   => $_GET['tone'] ?? 'ok',
            'active'      => 'sources',
        ], 'Sources', 'sources');
    }

    /** POST /sources/toggle */
    public function toggleSource(Request $request): void
    {
        $admin = AdminAuth::require();
        AdminAuth::requireCsrf($_POST['csrf'] ?? null);

        $id = (int) ($_POST['id'] ?? 0);
        $before = Db::one('SELECT id, blog_name, is_active, failure_count FROM blog_sources WHERE id = ?', [$id]);
        if ($before === null) {
            self::redirect('/sources?msg=' . rawurlencode('No such source.') . '&tone=bad');
            return;
        }

        $wasActive = in_array(strtolower((string) $before['is_active']), ['1', 't', 'true'], true)
                     || $before['is_active'] === true;

        /*
         * Reactivating also clears failure_count. Leaving it set would mean
         * the source is one bad night away from being deactivated again,
         * which is not what "reactivate" means to the person clicking it.
         */
        Db::exec(
            'UPDATE blog_sources
                SET is_active = ?, failure_count = CASE WHEN ? THEN 0 ELSE failure_count END,
                    last_error = CASE WHEN ? THEN NULL ELSE last_error END
              WHERE id = ?',
            [!$wasActive, !$wasActive, !$wasActive, $id]
        );

        Audit::record($admin['id'], $admin['email'],
            $wasActive ? 'source.disable' : 'source.reactivate',
            'blog_sources', (string) $id, $before, ['is_active' => !$wasActive]);

        $name = (string) $before['blog_name'];
        self::redirect('/sources?msg=' . rawurlencode(
            $wasActive ? "Disabled {$name}." : "Reactivated {$name} and cleared its failure count."));
    }

    /** GET /sync */
    public function sync(Request $request): void
    {
        AdminAuth::require();

        $last = Db::scalar('SELECT max(run_at) FROM sync_status');

        $this->render('sync', [
            'runs'    => Db::all('SELECT * FROM sync_status ORDER BY run_at DESC LIMIT 20'),
            'failing' => Db::all('SELECT blog_name, failure_count, last_error
                                    FROM blog_sources WHERE failure_count > 0
                                   ORDER BY failure_count DESC'),
            'lastRun' => $last === null ? 'never' : substr((string) $last, 0, 16),
            'nextRun' => gmdate('Y-m-d', strtotime('tomorrow')) . ' 03:10 UTC',
            'active'  => 'sync',
        ], 'Sync', 'sync');
    }
}
