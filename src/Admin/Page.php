<?php
/**
 * Page — the base every dashboard controller extends.
 *
 * Holds the four things that must never be re-implemented per module, because
 * each is a security property when done right and a hole when done wrong:
 * rendering through the shared layout, the admin gate, the CSRF check, and
 * the redirect-with-message that every form posts back through.
 *
 * Conventions for modules built on this:
 *   - Every GET handler starts with $this->admin().
 *   - Every POST handler starts with $this->admin() and $this->csrf().
 *   - Detail pages use ?id= — the Router is exact-match and the dashboard
 *     does not justify adding path parameters to it.
 *   - Templates escape EVERYTHING with e(). The only raw echo is $body in
 *     the layout, which is itself the output of an escaped template.
 */

declare(strict_types=1);

namespace App\Admin;

use App\ApiException;
use App\Env;
use App\Response;
use App\Services\AdminAuth;

abstract class Page
{
    protected const VIEWS = __DIR__ . '/../Views/admin/';

    /** CSRF token travels in a second, readable cookie. See AdminController. */
    public const CSRF_COOKIE = 'fp_admin_csrf';

    /** The signed-in admin, or a 403 that the layout turns into the sign-in page. */
    protected function admin(): array
    {
        return AdminAuth::require();
    }

    /** Reject a state-changing request without a matching CSRF token. */
    protected function csrf(): void
    {
        AdminAuth::requireCsrf($_POST['csrf'] ?? null);
    }

    protected function render(string $view, array $vars, string $title, string $active = ''): void
    {
        $vars['admin']  = AdminAuth::current();
        $vars['csrf']   = $_COOKIE[self::CSRF_COOKIE] ?? '';
        $vars['active'] = $active;
        $vars['flash']  = $this->takeFlash();

        extract($vars, EXTR_SKIP);
        ob_start();
        require self::VIEWS . $view . '.php';
        $body = ob_get_clean();

        ob_start();
        require self::VIEWS . 'layout.php';
        Response::html(ob_get_clean());
    }

    /**
     * Redirect, with an optional message shown once on the next page.
     *
     * The message rides in a short-lived cookie rather than the query string
     * so it cannot be bookmarked, shared, or replayed — a "Deleted X" banner
     * reappearing on every refresh of a shared link is confusing at best.
     */
    protected function redirect(string $to, ?string $message = null, string $tone = 'ok'): void
    {
        if ($message !== null && !headers_sent()) {
            setcookie('fp_flash', $tone . '|' . $message, [
                'expires' => time() + 60, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure'  => str_starts_with(Env::get('APP_URL', ''), 'https://'),
            ]);
        }
        if (!headers_sent()) {
            header('Location: ' . $to, true, 302);
        }
        // 302 must be passed through: Response::html defaults to 200 and would
        // overwrite the status set above.
        Response::html('<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to) . '">', 302);
    }

    /** @return array{tone: string, text: string}|null */
    private function takeFlash(): ?array
    {
        $raw = $_COOKIE['fp_flash'] ?? '';
        if ($raw === '') {
            return null;
        }
        if (!headers_sent()) {
            setcookie('fp_flash', '', ['expires' => time() - 3600, 'path' => '/']);
        }
        [$tone, $text] = array_pad(explode('|', $raw, 2), 2, '');
        return ['tone' => in_array($tone, ['ok', 'bad', 'warn'], true) ? $tone : 'ok', 'text' => $text];
    }

    /** A positive integer from the query string, or null. */
    protected function intParam(string $key, array $from = null): ?int
    {
        $from ??= $_GET;
        $v = $from[$key] ?? null;
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** A trimmed string from POST, or throw a validation error. */
    protected function requireString(string $key, int $max = 500, int $min = 1): string
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v)) {
            throw new ApiException('VALIDATION_ERROR', "$key is required.", 400);
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min) {
            throw new ApiException('VALIDATION_ERROR', "$key is required.", 400);
        }
        if ($len > $max) {
            throw new ApiException('VALIDATION_ERROR', "$key is too long (max $max).", 400);
        }
        return $v;
    }

    /**
     * pdo_pgsql has reported booleans as bool, 't'/'f' and '1'/'' across
     * versions. Reading one wrong silently inverts an is_active flag.
     */
    public static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 't', 'true', 'y'], true);
    }
}
