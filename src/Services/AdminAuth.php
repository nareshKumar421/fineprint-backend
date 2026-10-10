<?php
/**
 * AdminAuth — browser sessions for the dashboard.
 *
 * DELIBERATELY SEPARATE from TokenService, which serves the mobile app.
 *
 * They solve different problems and sharing one would make both worse. A
 * mobile token is a long-lived bearer credential in a header, with no
 * concept of a browser or a same-site boundary. A dashboard session is a
 * short-lived cookie that rides along on every request the browser makes —
 * including ones another site caused — so it needs CSRF protection the API
 * does not, and must be revocable without logging anybody off their phone.
 *
 * The session id is stored HASHED, the same rule the API follows: a database
 * leak must not hand over live sessions.
 */

declare(strict_types=1);

namespace App\Services;

use App\ApiException;
use App\Db;
use App\Env;

final class AdminAuth
{
    public const COOKIE = 'fp_admin';

    /** 32 bytes of entropy, hex-encoded. */
    private const ID_BYTES = 32;

    private static ?array $current = null;

    /** Session lifetime, from app_settings, falling back to 12 hours. */
    private static function lifetimeHours(): int
    {
        $v = Settings::get('admin.session_hours');
        return $v !== null && (int) $v > 0 ? (int) $v : 12;
    }

    /**
     * Verify an email/password pair and open a session.
     *
     * Returns the raw session id and CSRF token — the only time either is
     * ever in plaintext. Both are stored hashed.
     *
     * @return array{session: string, csrf: string, user: array}
     */
    public static function login(string $email, string $password, string $ip, string $agent): array
    {
        $identifier = trim($email);

        /*
         * Email OR display name.
         *
         * Accounts are keyed by email, but an administrator signing in at a
         * keyboard expects to type a name. Matching on display_name is only
         * safe because it is restricted to ADMINS and required to be
         * unambiguous below — display_name has no unique constraint, and two
         * readers sharing one must never make a login non-deterministic.
         */
        $candidates = Db::all(
            "SELECT id, email, display_name, password_hash, role, is_active
               FROM users
              WHERE LOWER(email) = LOWER(?)
                 OR (role = 'admin' AND LOWER(display_name) = LOWER(?))",
            [$identifier, $identifier]
        );

        // An ambiguous name is treated as no match at all. Picking one would
        // make which account you reach depend on row order.
        $user = count($candidates) === 1 ? $candidates[0] : null;

        /*
         * One message for every failure below, and the hash is verified even
         * when the row is missing.
         *
         * Distinguishing "no such account" from "wrong password" turns the
         * login form into an account-enumeration oracle, and returning early
         * on a missing row makes the two cases measurably different in time
         * even when the text is identical.
         */
        $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok   = password_verify($password, $hash);

        if ($user === null || !$ok) {
            throw new ApiException('INVALID_CREDENTIALS', 'Email or password is incorrect.', 401);
        }
        if (!self::truthy($user['is_active'])) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }
        if ($user['role'] !== 'admin') {
            // Same 403 for a real reader as for a disabled admin. A reader
            // probing the dashboard learns nothing about who is an admin.
            throw new ApiException('FORBIDDEN', 'This account cannot access the dashboard.', 403);
        }

        $session = bin2hex(random_bytes(self::ID_BYTES));
        $csrf    = bin2hex(random_bytes(self::ID_BYTES));

        Db::exec(
            'INSERT INTO admin_sessions
                 (user_id, session_hash, csrf_hash, ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + (? || \' hours\')::interval)',
            [
                $user['id'],
                hash('sha256', $session),
                hash('sha256', $csrf),
                $ip,
                mb_substr($agent, 0, 500),
                self::lifetimeHours(),
            ]
        );

        Db::exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

        Audit::record((int) $user['id'], $user['email'], 'admin.login', 'users', (string) $user['id'], null, null, $ip);

        return [
            'session' => $session,
            'csrf'    => $csrf,
            'user'    => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'name'  => $user['display_name'],
            ],
        ];
    }

    /**
     * The signed-in admin for this request, or null.
     *
     * Cached per request: a dashboard page checks authorisation more than
     * once, and each check would otherwise be a round trip to a database in
     * another region.
     */
    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current['id'] === null ? null : self::$current;
        }

        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '' || !ctype_xdigit($raw)) {
            self::$current = ['id' => null];
            return null;
        }

        $row = Db::one(
            'SELECT s.id AS session_id, s.csrf_hash, s.expires_at,
                    u.id, u.email, u.display_name, u.role, u.is_active
               FROM admin_sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.session_hash = ? AND s.expires_at > NOW()',
            [hash('sha256', $raw)]
        );

        // Role and is_active are re-read on EVERY request, never trusted from
        // the cookie. Demoting or disabling an admin has to take effect at
        // once, not when their session happens to expire.
        if ($row === null || $row['role'] !== 'admin' || !self::truthy($row['is_active'])) {
            self::$current = ['id' => null];
            return null;
        }

        // Cheap liveness, useful for "who is signed in". Not on every hit —
        // one write per request to a remote database is a real cost.
        if (random_int(1, 10) === 1) {
            Db::exec('UPDATE admin_sessions SET last_seen_at = NOW() WHERE id = ?', [$row['session_id']]);
        }

        return self::$current = [
            'id'         => (int) $row['id'],
            'email'      => $row['email'],
            'name'       => $row['display_name'],
            'session_id' => (int) $row['session_id'],
            'csrf_hash'  => $row['csrf_hash'],
        ];
    }

    /** Throw unless an admin is signed in. */
    public static function require(): array
    {
        $admin = self::current();
        if ($admin === null) {
            throw new ApiException('FORBIDDEN', 'Please sign in.', 403);
        }
        return $admin;
    }

    /**
     * Reject a state-changing request whose CSRF token does not match.
     *
     * The cookie alone is not proof of intent: a browser attaches it to a
     * request another site caused just as readily as one the admin made. The
     * token is compared with hash_equals so a mismatch cannot be narrowed
     * down by timing.
     */
    public static function requireCsrf(?string $submitted): void
    {
        $admin = self::require();

        if ($submitted === null || $submitted === ''
            || !hash_equals($admin['csrf_hash'], hash('sha256', $submitted))) {
            // 403, not 419. 419 is a Laravel convention with no standard
            // status line; Apache's mod_php substitutes 500 for codes it does
            // not know, so the rejection worked in development (php -S) and
            // reported as a server error in production.
            throw new ApiException('CSRF_INVALID',
                'That form expired. Reload the page and try again.', 403);
        }
    }

    public static function logout(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw !== '' && ctype_xdigit($raw)) {
            $admin = self::current();
            if ($admin !== null) {
                Audit::record($admin['id'], $admin['email'], 'admin.logout', 'users', (string) $admin['id']);
            }
            Db::exec('DELETE FROM admin_sessions WHERE session_hash = ?', [hash('sha256', $raw)]);
        }
        self::$current = ['id' => null];
        self::clearCookie();
    }

    /** Remove every session for a user — used when disabling or demoting. */
    public static function revokeAllFor(int $userId): int
    {
        return Db::exec('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
    }

    public static function setCookie(string $session): void
    {
        // Guarded exactly as Response::json does. Without it, any stray
        // output before this point turns a logout into a 500 — and the
        // session would already have been deleted, leaving the admin with
        // an error page and a cookie pointing at nothing.
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $session, [
            'expires'  => time() + self::lifetimeHours() * 3600,
            'path'     => '/',
            'httponly' => true,                      // JavaScript must never read it
            'samesite' => 'Lax',                     // blocks the cross-site POST
            // Secure is conditional ONLY so the dashboard is usable over
            // http://localhost during development. In production APP_URL is
            // https and the flag is on.
            'secure'   => str_starts_with(Env::get('APP_URL', ''), 'https://'),
        ]);
    }

    public static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /** Housekeeping, called from the nightly job. */
    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM admin_sessions WHERE expires_at < NOW()');
    }

    private static function truthy(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 't', 'true', 'y'], true);
    }
}
