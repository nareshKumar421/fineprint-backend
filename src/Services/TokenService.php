<?php
/**
 * TokenService — issue, verify and revoke auth tokens.
 *
 * THE RULE: we store the SHA-256 hash of the token, never the token.
 * A leaked table of raw tokens is a leaked set of live sessions — an attacker
 * could replay every one of them without knowing a single password.
 *
 * The raw token exists in exactly two places: the response that created it,
 * and the phone's Keychain / EncryptedSharedPreferences.
 */

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Env;
use DateTimeImmutable;
use DateTimeZone;

final class TokenService
{
    /** 32 random bytes, hex-encoded → 64 characters. */
    private const TOKEN_BYTES = 32;

    /**
     * Create a token for a user.
     *
     * @return array{token: string, expires_at: string} the RAW token — this is
     *         the only moment it exists in plaintext on the server.
     */
    public static function issue(int $userId, ?string $device = null): array
    {
        // random_bytes() is cryptographically secure. rand(), mt_rand() and
        // uniqid() are NOT and must never appear in this file.
        $raw  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = hash('sha256', $raw);

        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . Env::int('TOKEN_TTL_DAYS', 90) . ' days');

        Db::exec(
            'INSERT INTO user_tokens (user_id, token_hash, device_info, expires_at)
             VALUES (?, ?, ?, ?)',
            [$userId, $hash, $device, $expires->format(DATE_ATOM)]
        );

        return [
            'token'      => $raw,
            'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Resolve a raw token to a user id.
     *
     * Returns ['user_id' => int] on success, or ['expired' => true] when the
     * token exists but has lapsed — the app shows a different message for that.
     */
    public static function verify(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);

        // Expiry is enforced in SQL, not in PHP: a clock or timezone mistake in
        // application code must not be able to extend a session.
        $row = Db::one(
            'SELECT user_id, (expires_at <= NOW()) AS expired
               FROM user_tokens
              WHERE token_hash = ?',
            [$hash]
        );

        if ($row === null) {
            return null;
        }

        if ($row['expired'] === true || $row['expired'] === 't' || $row['expired'] === 1) {
            return ['expired' => true];
        }

        return ['user_id' => (int) $row['user_id']];
    }

    /** Delete one token — logout on this device only. */
    public static function revoke(string $rawToken): int
    {
        return Db::exec(
            'DELETE FROM user_tokens WHERE token_hash = ?',
            [hash('sha256', $rawToken)]
        );
    }

    /** Delete every token for a user — logout everywhere. */
    public static function revokeAllFor(int $userId): int
    {
        return Db::exec('DELETE FROM user_tokens WHERE user_id = ?', [$userId]);
    }

    /** Housekeeping; also run nightly by cleanup.sql. */
    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM user_tokens WHERE expires_at < NOW()');
    }
}
