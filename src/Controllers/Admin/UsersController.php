<?php
/**
 * UsersController — readers and administrators.
 *
 * One table, two kinds of account: `role` is 'reader' or 'admin'. Everything
 * here is about the account itself — who it is, what it has done, and the
 * three levers an administrator has over it (active, role, sessions) plus
 * deletion.
 *
 * Three rules that the API never needed, because the API only ever acts on
 * the caller's OWN account:
 *
 *   - You cannot disable, demote or delete yourself. Locking yourself out of
 *     the dashboard you are standing in is never what was meant.
 *   - The last active administrator cannot be demoted or deleted. With no
 *     admin left there is no way back in except the make_admin job on the
 *     server.
 *   - Disabling takes effect NOW. The mobile token and the dashboard session
 *     are deleted in the same transaction as the flag, so a disabled account
 *     stops working at once rather than when its tokens happen to expire.
 *     (AdminAuth re-reads is_active per request, so the dashboard would catch
 *     it anyway; the API token would not — TokenService trusts the row.)
 *
 * Booleans are bound as 'true'/'false' with an explicit ::boolean cast. With
 * emulation off, pdo_pgsql sends PHP false as '' and Postgres rejects it.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\Db;
use App\Request;
use App\Services\Audit;
use PDOException;

final class UsersController extends Page
{
    /** ORDER BY whitelist: request key => SQL column. Nothing else is accepted. */
    private const SORTS = [
        'created_at'    => 'u.created_at',
        'last_login_at' => 'u.last_login_at',
        'email'         => 'u.email',
    ];

    private const ROLES = ['reader', 'admin'];

    private const PASSWORD_MIN = 10;

    /** The columns a before/after snapshot carries. password_hash is never selected. */
    private const ROW = 'id, email, display_name, role, is_active, email_verified, created_at, last_login_at';

    /* ---------------------------------------------------------------- */
    /*  list                                                             */
    /* ---------------------------------------------------------------- */

    /** GET /users */
    public function index(Request $request): void
    {
        $this->admin();

        $q      = trim(self::qs('q'));
        $role   = self::qs('role');
        $status = self::qs('status');
        $sort   = self::qs('sort', 'created_at');
        $dir    = self::qs('dir', 'desc');

        if (!in_array($role, self::ROLES, true)) {
            $role = '';
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = '';
        }
        if (!isset(self::SORTS[$sort])) {
            $sort = 'created_at';
        }
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $where  = [];
        $params = [];
        if ($q !== '') {
            $like     = '%' . addcslashes($q, '%_\\') . '%';
            $where[]  = '(u.email ILIKE ? OR u.display_name ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($role !== '') {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }
        if ($status !== '') {
            $where[] = $status === 'active' ? 'u.is_active' : 'NOT u.is_active';
        }
        // Only whitelisted fragments above ever reach this string; every
        // value travels in $params.
        $cond = $where === [] ? 'TRUE' : implode(' AND ', $where);

        /*
         * ONE count query. The filtered total and the four tile numbers come
         * from the same scan — FILTER clauses cost nothing extra and a second
         * round trip for the tiles would.
         */
        $m = Db::one("
            SELECT count(*) FILTER (WHERE $cond)                                            AS total,
                   count(*)                                                                 AS all_users,
                   count(*) FILTER (WHERE u.is_active)                                      AS active_users,
                   count(*) FILTER (WHERE u.role = 'admin' AND u.is_active)                 AS admins,
                   count(*) FILTER (WHERE u.role = 'admin')                                 AS admins_all,
                   count(*) FILTER (WHERE u.created_at > NOW() - INTERVAL '30 days')        AS new_30d,
                   count(*) FILTER (WHERE u.last_login_at > NOW() - INTERVAL '7 days')      AS seen_7d
              FROM users u", $params) ?? [];

        $pager = new Paginator(50);
        $total = (int) ($m['total'] ?? 0);

        $rows = Db::all("
            SELECT u.id, u.email, u.display_name, u.role, u.is_active, u.created_at, u.last_login_at,
                   (SELECT count(*) FROM user_categories    c WHERE c.user_id = u.id) AS topics,
                   (SELECT count(*) FROM article_events     e WHERE e.user_id = u.id) AS events,
                   (SELECT count(*) FROM user_seen_articles s WHERE s.user_id = u.id) AS seen
              FROM users u
             WHERE $cond
             ORDER BY " . self::SORTS[$sort] . ' ' . strtoupper($dir) . ' NULLS LAST, u.id DESC
             LIMIT ? OFFSET ?',
            [...$params, $pager->perPage, $pager->offset]);

        $this->render('users/index', [
            'rows'    => $rows,
            'm'       => $m,
            'page'    => $pager->describe($total),
            'q'       => $q,
            'role'    => $role,
            'status'  => $status,
            'sort'    => $sort,
            'dir'     => $dir,
        ], 'Users', 'users');
    }

    /* ---------------------------------------------------------------- */
    /*  detail                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /users/view?id= */
    public function view(Request $request): void
    {
        $admin = $this->admin();

        $id = $this->intParam('id');
        if ($id === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }

        // Profile and every scalar count in one statement.
        $u = Db::one("
            SELECT u.id, u.email, u.display_name, u.role, u.is_active, u.email_verified,
                   u.created_at, u.last_login_at,
                   (SELECT count(*) FROM user_categories     WHERE user_id = u.id)                        AS topics,
                   (SELECT count(*) FROM user_seen_articles  WHERE user_id = u.id)                        AS seen,
                   (SELECT count(*) FROM article_events      WHERE user_id = u.id)                        AS events,
                   (SELECT count(*) FROM user_hidden_sources WHERE user_id = u.id)                        AS hidden,
                   (SELECT count(*) FROM user_tokens         WHERE user_id = u.id AND expires_at > NOW()) AS live_tokens,
                   (SELECT count(*) FROM admin_sessions      WHERE user_id = u.id AND expires_at > NOW()) AS live_sessions,
                   (SELECT count(*) FROM donations           WHERE user_id = u.id)                        AS donations,
                   (SELECT count(*) FROM donations           WHERE user_id = u.id AND status = 'success') AS donations_ok,
                   -- NUMERIC summed and rendered by Postgres. It never becomes a PHP float.
                   (SELECT coalesce(sum(amount), 0)::text FROM donations
                     WHERE user_id = u.id AND status = 'success')                                         AS donated,
                   (SELECT impressions FROM user_feed_stats WHERE user_id = u.id)                         AS impressions,
                   (SELECT good_taps   FROM user_feed_stats WHERE user_id = u.id)                         AS good_taps,
                   (SELECT max(created_at) FROM article_events WHERE user_id = u.id)                      AS last_event_at
              FROM users u
             WHERE u.id = ?", [$id]);

        if ($u === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }

        // Subscribed topics and hidden sources: two small name lists, one trip.
        $topics = [];
        $hidden = [];
        foreach (Db::all("
            SELECT 'topic' AS kind, c.name
              FROM user_categories uc JOIN category_master c ON c.id = uc.category_id
             WHERE uc.user_id = ?
            UNION ALL
            SELECT 'hidden', b.blog_name
              FROM user_hidden_sources h JOIN blog_sources b ON b.id = h.source_id
             WHERE h.user_id = ?
             ORDER BY 1, 2", [$id, $id]) as $r) {
            if ($r['kind'] === 'topic') {
                $topics[] = (string) $r['name'];
            } else {
                $hidden[] = (string) $r['name'];
            }
        }

        $byType = [];
        foreach (Db::all('
            SELECT event_type, count(*) AS n
              FROM article_events WHERE user_id = ?
             GROUP BY 1 ORDER BY 2 DESC', [$id]) as $r) {
            $byType[(string) $r['event_type']] = (int) $r['n'];
        }

        // 14 days, every day present so a quiet day reads as zero, not as
        // a missing bar.
        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $daily[gmdate('m-d', strtotime("-$i days"))] = 0;
        }
        foreach (Db::all("
            SELECT created_at::date AS d, count(*) AS n
              FROM article_events
             WHERE user_id = ? AND created_at > NOW() - INTERVAL '14 days'
             GROUP BY 1", [$id]) as $r) {
            $k = substr((string) $r['d'], 5);
            if (isset($daily[$k])) {
                $daily[$k] = (int) $r['n'];
            }
        }

        $trail = Db::all("
            SELECT action, actor_email, created_at
              FROM audit_log
             WHERE entity = 'users' AND entity_id = ?
             ORDER BY created_at DESC LIMIT 10", [(string) $id]);

        $name = trim((string) ($u['display_name'] ?? '')) ?: (string) $u['email'];

        $this->render('users/view', [
            'u'      => $u,
            'topics' => $topics,
            'hidden' => $hidden,
            'byType' => $byType,
            'daily'  => $daily,
            'trail'  => $trail,
            'isSelf' => (int) $u['id'] === (int) $admin['id'],
        ], $name, 'users');
    }

    /* ---------------------------------------------------------------- */
    /*  create                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /users/new */
    public function new(Request $request): void
    {
        $this->admin();
        $this->form(null, ['email' => '', 'display_name' => '', 'role' => 'reader']);
    }

    private function form(?string $error, array $values): void
    {
        $this->render('users/new', [
            'error'  => $error,
            'values' => $values,
            'minLen' => self::PASSWORD_MIN,
        ], 'New user', 'users');
    }

    /** POST /users/create */
    public function create(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $email    = trim(self::ps('email'));
        $password = self::ps('password');
        $name     = trim(self::ps('display_name'));
        $role     = self::ps('role', 'reader');

        // Re-rendered on error, never including the password.
        $values = ['email' => $email, 'display_name' => $name, 'role' => $role];

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->form('Enter a valid email address.', $values);
            return;
        }
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            $this->form('The password must be at least ' . self::PASSWORD_MIN . ' characters.', $values);
            return;
        }
        if (mb_strlen($name) > 80) {
            $this->form('The display name is too long (80 characters at most).', $values);
            return;
        }
        if (!in_array($role, self::ROLES, true)) {
            $this->form('Choose a role.', $values);
            return;
        }
        if (Db::scalar('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)', [$email]) !== null) {
            $this->form('An account with that email already exists.', $values);
            return;
        }

        try {
            // An account an administrator creates by hand has had its email
            // vouched for by that administrator, so it is marked verified.
            $id = (int) Db::scalar(
                "INSERT INTO users (email, password_hash, is_active, email_verified, role, display_name)
                 VALUES (?, ?, true, true, ?, ?)
                 RETURNING id",
                [$email, password_hash($password, PASSWORD_BCRYPT), $role, $name === '' ? null : $name]
            );
        } catch (PDOException $e) {
            // users_email_lower_uniq — the check above raced with another insert.
            if ($e->getCode() === '23505') {
                $this->form('An account with that email already exists.', $values);
                return;
            }
            throw $e;
        }

        $after = self::row($id);
        Audit::record($admin['id'], $admin['email'], 'user.create', 'users', (string) $id, null, $after);

        $this->redirect('/users/view?id=' . $id,
            'Created ' . ($role === 'admin' ? 'administrator ' : 'reader ') . $email . '.');
    }

    /* ---------------------------------------------------------------- */
    /*  active                                                           */
    /* ---------------------------------------------------------------- */

    /** POST /users/toggle-active {id} */
    public function toggleActive(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : self::row($id);
        if ($before === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }
        if ($id === (int) $admin['id']) {
            $this->redirect('/users/view?id=' . $id, 'You cannot disable your own account.', 'bad');
            return;
        }

        $wasActive = self::truthy($before['is_active']);

        /*
         * Disabling revokes every credential in the SAME transaction as the
         * flag. If either half failed on its own the account would be
         * disabled on paper and still signed in on a phone.
         */
        [$tokens, $sessions] = Db::transaction(function () use ($id, $wasActive): array {
            Db::exec('UPDATE users SET is_active = ?::boolean WHERE id = ?',
                     [$wasActive ? 'false' : 'true', $id]);
            if (!$wasActive) {
                return [0, 0];
            }
            return [
                Db::exec('DELETE FROM user_tokens    WHERE user_id = ?', [$id]),
                Db::exec('DELETE FROM admin_sessions WHERE user_id = ?', [$id]),
            ];
        });

        $after = array_merge($before, ['is_active' => !$wasActive]);
        Audit::record($admin['id'], $admin['email'],
            $wasActive ? 'user.disable' : 'user.enable', 'users', (string) $id,
            $before, $after + ['tokens_revoked' => $tokens, 'sessions_revoked' => $sessions]);

        $email = (string) $before['email'];
        $this->redirect('/users/view?id=' . $id, $wasActive
            ? "Disabled {$email} and revoked {$tokens} mobile token(s) and {$sessions} dashboard session(s)."
            : "Enabled {$email}. They can sign in again.",
            $wasActive ? 'warn' : 'ok');
    }

    /* ---------------------------------------------------------------- */
    /*  role                                                             */
    /* ---------------------------------------------------------------- */

    /** POST /users/role {id, role} */
    public function role(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $role   = self::ps('role');
        $before = $id === null ? null : self::row($id);
        if ($before === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }
        if (!in_array($role, self::ROLES, true)) {
            $this->redirect('/users/view?id=' . $id, 'Choose a role.', 'bad');
            return;
        }
        if ($id === (int) $admin['id']) {
            $this->redirect('/users/view?id=' . $id, 'You cannot change your own role.', 'bad');
            return;
        }
        if ($before['role'] === $role) {
            $this->redirect('/users/view?id=' . $id, $before['email'] . ' is already a ' . $role . '.', 'warn');
            return;
        }

        $demoting = $role === 'reader';

        if ($demoting && self::truthy($before['is_active'])) {
            $others = (int) Db::scalar(
                "SELECT count(*) FROM users WHERE role = 'admin' AND is_active AND id <> ?", [$id]);
            if ($others === 0) {
                $this->redirect('/users/view?id=' . $id,
                    'Cannot demote the last active administrator. Promote someone else first.', 'bad');
                return;
            }
        }

        // A demoted admin's dashboard sessions go with the role. AdminAuth
        // would refuse them on the next request anyway; deleting them means
        // "live sessions" on this page is honest immediately.
        $sessions = Db::transaction(function () use ($id, $role, $demoting): int {
            Db::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            return $demoting ? Db::exec('DELETE FROM admin_sessions WHERE user_id = ?', [$id]) : 0;
        });

        $after = array_merge($before, ['role' => $role]);
        Audit::record($admin['id'], $admin['email'],
            $demoting ? 'user.demote' : 'user.promote', 'users', (string) $id,
            $before, $after + ['sessions_revoked' => $sessions]);

        $email = (string) $before['email'];
        $this->redirect('/users/view?id=' . $id, $demoting
            ? "Demoted {$email} to reader and ended {$sessions} dashboard session(s)."
            : "Promoted {$email} to administrator. They can sign in to the dashboard now.");
    }

    /* ---------------------------------------------------------------- */
    /*  sessions                                                         */
    /* ---------------------------------------------------------------- */

    /** POST /users/revoke-sessions {id} */
    public function revokeSessions(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : self::row($id);
        if ($before === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }

        // Revoking your own sessions keeps the one you are using: ending it
        // would answer the click with the sign-in form.
        $isSelf = $id === (int) $admin['id'];

        [$tokens, $sessions] = Db::transaction(function () use ($id, $isSelf, $admin): array {
            $t = Db::exec('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
            $s = $isSelf
                ? Db::exec('DELETE FROM admin_sessions WHERE user_id = ? AND id <> ?', [$id, $admin['session_id']])
                : Db::exec('DELETE FROM admin_sessions WHERE user_id = ?', [$id]);
            return [$t, $s];
        });

        Audit::record($admin['id'], $admin['email'], 'user.revoke_sessions', 'users', (string) $id,
            $before, $before + ['tokens_revoked' => $tokens, 'sessions_revoked' => $sessions]);

        $this->redirect('/users/view?id=' . $id,
            "Revoked {$tokens} mobile token(s) and {$sessions} dashboard session(s) for {$before['email']}."
            . ($isSelf ? ' Your current session was kept.' : ''),
            $tokens + $sessions > 0 ? 'ok' : 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  delete                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /users/delete-confirm?id= */
    public function deleteConfirm(Request $request): void
    {
        $admin = $this->admin();

        $id = $this->intParam('id');
        $u  = $id === null ? null : self::row($id);
        if ($u === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }
        if ($id === (int) $admin['id']) {
            $this->redirect('/users/view?id=' . $id, 'You cannot delete your own account.', 'bad');
            return;
        }

        $this->render('users/delete', [
            'u'        => $u,
            'c'        => self::cascade($id),
            'lastAdmin' => self::isLastActiveAdmin($u),
        ], 'Delete user', 'users');
    }

    /** POST /users/delete {id, confirm=1} */
    public function delete(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : self::row($id);
        if ($before === null) {
            $this->redirect('/users', 'No such user.', 'bad');
            return;
        }
        if (self::ps('confirm') !== '1') {
            $this->redirect('/users/delete-confirm?id=' . $id, 'Tick the confirmation box to delete.', 'warn');
            return;
        }
        if ($id === (int) $admin['id']) {
            $this->redirect('/users/view?id=' . $id, 'You cannot delete your own account.', 'bad');
            return;
        }
        if (self::isLastActiveAdmin($before)) {
            $this->redirect('/users/view?id=' . $id,
                'Cannot delete the last active administrator. Promote someone else first.', 'bad');
            return;
        }

        /*
         * Counted inside the transaction, so the numbers in the audit row
         * and the flash are what actually went. One DELETE: every personal
         * table is ON DELETE CASCADE and donations / audit rows / job
         * requests are ON DELETE SET NULL, so the database enforces the
         * retention policy rather than this method remembering every table.
         */
        $c = Db::transaction(function () use ($id): array {
            $c = self::cascade($id);
            if (Db::exec('DELETE FROM users WHERE id = ?', [$id]) !== 1) {
                throw new \RuntimeException('The user row was not deleted.');
            }
            return $c;
        });

        Audit::record($admin['id'], $admin['email'], 'user.delete', 'users', (string) $id, $before, [
            'deleted'  => true,
            'cascaded' => array_intersect_key($c, array_flip(['topics', 'seen', 'events', 'hidden', 'tokens', 'sessions', 'stats'])),
            'retained' => ['donations' => $c['donations'], 'audit_rows' => $c['audit_rows'], 'jobs' => $c['jobs']],
        ]);

        $this->redirect('/users', sprintf(
            'Deleted %s — %d topic(s), %d seen, %d event(s), %d hidden source(s), %d token(s), %d session(s). %d donation(s) retained.',
            $before['email'], $c['topics'], $c['seen'], $c['events'], $c['hidden'], $c['tokens'], $c['sessions'], $c['donations']
        ));
    }

    /* ---------------------------------------------------------------- */
    /*  helpers                                                          */
    /* ---------------------------------------------------------------- */

    /** The audit-safe row: no password_hash. */
    private static function row(int $id): ?array
    {
        return Db::one('SELECT ' . self::ROW . ' FROM users WHERE id = ?', [$id]);
    }

    /** What a delete would cascade to, and what it would detach. One query. */
    private static function cascade(int $id): array
    {
        $c = Db::one("
            SELECT (SELECT count(*) FROM user_categories     WHERE user_id = ?) AS topics,
                   (SELECT count(*) FROM user_seen_articles  WHERE user_id = ?) AS seen,
                   (SELECT count(*) FROM article_events      WHERE user_id = ?) AS events,
                   (SELECT count(*) FROM user_hidden_sources WHERE user_id = ?) AS hidden,
                   (SELECT count(*) FROM user_tokens         WHERE user_id = ?) AS tokens,
                   (SELECT count(*) FROM admin_sessions      WHERE user_id = ?) AS sessions,
                   (SELECT count(*) FROM user_feed_stats     WHERE user_id = ?)
                 + (SELECT count(*) FROM user_category_stats WHERE user_id = ?)
                 + (SELECT count(*) FROM user_source_stats   WHERE user_id = ?) AS stats,
                   (SELECT count(*) FROM donations           WHERE user_id = ?) AS donations,
                   (SELECT coalesce(sum(amount), 0)::text FROM donations
                     WHERE user_id = ? AND status = 'success')                  AS donated,
                   (SELECT count(*) FROM audit_log           WHERE user_id = ?) AS audit_rows,
                   (SELECT count(*) FROM admin_jobs          WHERE requested_by = ?) AS jobs",
            array_fill(0, 13, $id)) ?? [];

        foreach ($c as $k => $v) {
            if ($k !== 'donated') {
                $c[$k] = (int) $v;
            }
        }
        return $c;
    }

    private static function isLastActiveAdmin(array $u): bool
    {
        if ($u['role'] !== 'admin' || !self::truthy($u['is_active'])) {
            return false;
        }
        return (int) Db::scalar(
            "SELECT count(*) FROM users WHERE role = 'admin' AND is_active AND id <> ?", [(int) $u['id']]) === 0;
    }

    /** A string from the query, or the default when absent or not a string. */
    private static function qs(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /** A string from POST, or the default when absent or not a string. */
    private static function ps(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        if (is_int($v)) {
            return (string) $v;
        }
        return is_string($v) ? $v : $default;
    }
}
