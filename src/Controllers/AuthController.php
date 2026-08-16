<?php
/**
 * AuthController — register, login, logout.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Db;
use App\Request;
use App\Response;
use App\Services\TokenService;
use PDOException;

final class AuthController
{
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD = 200;   // bcrypt truncates past 72 bytes; reject long input outright
    private const MAX_EMAIL    = 255;

    /**
     * A valid bcrypt hash of a value nobody knows.
     *
     * When the email is unknown we still run password_verify() against this,
     * so an unknown address and a wrong password take the same time. Without
     * it, response timing reveals which addresses are registered — the exact
     * thing the identical error message exists to hide.
     */
    private const DUMMY_HASH = '$2y$10$usesomesillystringforeseeidiotforcedtimingsafetyabcdefghi';

    public function register(Request $request): void
    {
        [$email, $password] = $this->credentials($request);

        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $userId = (int) Db::scalar(
                'INSERT INTO users (email, password_hash) VALUES (?, ?) RETURNING id',
                [$email, $hash]
            );
        } catch (PDOException $e) {
            // users_email_lower_uniq is a UNIQUE INDEX on LOWER(email), so
            // Test@x.com and TEST@X.COM collide. Let the database decide rather
            // than doing a SELECT first, which would race between two signups.
            if ($e->getCode() === '23505') {
                throw new ApiException('EMAIL_TAKEN', 'That email is already registered.', 409, $e);
            }
            throw $e;
        }

        $token = TokenService::issue($userId, $request->userAgent());

        // Registration returns a token, so there is no separate login step.
        Response::json([
            'token'      => $token['token'],
            'user'       => ['id' => $userId, 'email' => $email, 'display_name' => null],
            'expires_at' => $token['expires_at'],
        ], 201);
    }

    public function login(Request $request): void
    {
        [$email, $password] = $this->credentials($request);

        // LOWER(email) so the query can use users_email_lower_uniq.
        $user = Db::one(
            'SELECT id, password_hash, is_active, display_name FROM users WHERE LOWER(email) = LOWER(?)',
            [$email]
        );

        $ok = $user !== null
            && password_verify($password, $user['password_hash'])
            && ($user['is_active'] === true || $user['is_active'] === 't');

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);   // constant-ish time
        }

        if (!$ok) {
            // Deliberately vague, and IDENTICAL for an unknown email and a
            // wrong password. "No such email" confirms to an attacker which
            // addresses are registered.
            throw new ApiException('INVALID_CREDENTIALS', 'Email or password is incorrect.', 401);
        }

        $userId = (int) $user['id'];
        Db::exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);

        $token = TokenService::issue($userId, $request->userAgent());

        Response::json([
            'token'      => $token['token'],
            'user'       => [
                'id'           => $userId,
                'email'        => $email,
                'display_name' => $user['display_name'],
            ],
            'expires_at' => $token['expires_at'],
        ], 200);
    }

    public function logout(Request $request): void
    {
        // Auth middleware has already proven this token is valid.
        $token = $request->bearerToken();
        if ($token !== null) {
            TokenService::revoke($token);       // this device only
        }

        Response::json(['success' => true]);
    }

    /**
     * Validate and normalise the email/password pair.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(Request $request): array
    {
        $email    = $request->input('email');
        $password = $request->input('password');

        if (!is_string($email) || !is_string($password)) {
            throw new ApiException('VALIDATION_ERROR', 'Email and password are required.', 400);
        }

        $email = trim($email);

        if ($email === '' || $password === '') {
            throw new ApiException('VALIDATION_ERROR', 'Email and password are required.', 400);
        }
        if (strlen($email) > self::MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('VALIDATION_ERROR', 'Enter a valid email address.', 400);
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new ApiException('VALIDATION_ERROR',
                'Password must be at least ' . self::MIN_PASSWORD . ' characters.', 400);
        }
        if (strlen($password) > self::MAX_PASSWORD) {
            throw new ApiException('VALIDATION_ERROR', 'Password is too long.', 400);
        }

        return [$email, $password];
    }
}
