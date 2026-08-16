<?php
/**
 * AccountController — things a user does to their own account.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Db;
use App\Request;
use App\Response;

final class AccountController
{
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD = 200;
    private const MAX_NAME     = 80;

    /**
     * GET /api/user/profile
     *
     * The app calls this on launch. It doubles as a token check: a stale
     * token 401s here and the client logs itself out, rather than the user
     * discovering it later on some unrelated screen.
     */
    public function profile(Request $request): void
    {
        $userId = $request->requireUserId();

        $row = Db::one('SELECT id, email, display_name FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            throw new ApiException('TOKEN_INVALID', 'Please log in again.', 401);
        }

        Response::json([
            'id'           => (int) $row['id'],
            'email'        => $row['email'],
            'display_name' => $row['display_name'],
        ]);
    }

    /**
     * POST /api/user/profile — set or clear the display name.
     *
     * An empty string is stored as NULL. Two values that mean "not set" but
     * behave differently in queries is a bug waiting to happen.
     */
    public function updateProfile(Request $request): void
    {
        $userId = $request->requireUserId();
        $raw    = $request->input('display_name');

        if ($raw !== null && !is_string($raw)) {
            throw new ApiException('VALIDATION_ERROR', 'Name must be text.', 400);
        }

        $name = $raw === null ? null : trim($raw);

        if ($name !== null && $name !== '') {
            if (mb_strlen($name) > self::MAX_NAME) {
                throw new ApiException('VALIDATION_ERROR',
                    'Name must be ' . self::MAX_NAME . ' characters or fewer.', 400);
            }
            // Control characters would let someone put line breaks or
            // right-to-left overrides into a name that other people see.
            if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new ApiException('VALIDATION_ERROR', 'That name contains invalid characters.', 400);
            }
        }

        $store = ($name === null || $name === '') ? null : $name;

        Db::exec('UPDATE users SET display_name = ? WHERE id = ?', [$store, $userId]);

        Response::json(['success' => true, 'display_name' => $store]);
    }

    /**
     * POST /api/user/password
     *
     * Changing a password REVOKES every other session.
     *
     * That is the whole point of the feature. People change their password
     * because they think someone else has it — leaving that person's phone
     * logged in would make the change pointless. The device doing the change
     * keeps its own token so the user is not thrown out mid-action.
     */
    public function changePassword(Request $request): void
    {
        $userId  = $request->requireUserId();
        $current = $request->input('current_password');
        $new     = $request->input('new_password');

        if (!is_string($current) || !is_string($new)) {
            throw new ApiException('VALIDATION_ERROR', 'Both passwords are required.', 400);
        }
        if ($current === '' || $new === '') {
            throw new ApiException('VALIDATION_ERROR', 'Both passwords are required.', 400);
        }
        if (strlen($new) < self::MIN_PASSWORD) {
            throw new ApiException('VALIDATION_ERROR',
                'Your new password must be at least ' . self::MIN_PASSWORD . ' characters.', 400);
        }
        if (strlen($new) > self::MAX_PASSWORD) {
            throw new ApiException('VALIDATION_ERROR', 'That password is too long.', 400);
        }

        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            throw new ApiException('TOKEN_INVALID', 'Please log in again.', 401);
        }

        // Verifying the CURRENT password is what stops a stolen phone — or a
        // stolen token — from locking the real owner out of their account.
        if (!password_verify($current, $row['password_hash'])) {
            throw new ApiException('INVALID_CREDENTIALS', 'Your current password is incorrect.', 401);
        }

        if (password_verify($new, $row['password_hash'])) {
            throw new ApiException('VALIDATION_ERROR',
                'Your new password must be different from the current one.', 400);
        }

        $hash    = password_hash($new, PASSWORD_BCRYPT);
        $keep    = hash('sha256', (string) $request->bearerToken());
        $revoked = 0;

        Db::transaction(static function ($db) use ($userId, $hash, $keep, &$revoked): void {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([$hash, $userId]);

            $stmt = $db->prepare('DELETE FROM user_tokens WHERE user_id = ? AND token_hash <> ?');
            $stmt->execute([$userId, $keep]);
            $revoked = $stmt->rowCount();
        });

        Response::json([
            'success'         => true,
            'sessions_ended'  => $revoked,
        ]);
    }
}
