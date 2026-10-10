<?php
/**
 * Audit — who changed what, and when.
 *
 * Written on every administrative mutation. The question it exists to answer
 * is "who disabled that feed three weeks ago", which application logs have
 * never once managed: they are rotated, unindexed, and record the request
 * rather than the change.
 *
 * NEVER throws. An audit failure must not roll back the operation it was
 * describing — losing the record of a change is bad, failing the change
 * because the record could not be written is worse, and leaves the
 * administrator with an error for an action that may have half-happened.
 */

declare(strict_types=1);

namespace App\Services;

use App\Db;
use Throwable;

final class Audit
{
    public static function record(
        ?int $userId,
        ?string $actorEmail,
        string $action,
        ?string $entity = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
    ): void {
        try {
            Db::exec(
                'INSERT INTO audit_log
                     (user_id, actor_email, action, entity, entity_id,
                      before_json, after_json, ip)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)',
                [
                    $userId,
                    $actorEmail,
                    $action,
                    $entity,
                    $entityId,
                    $before !== null ? json_encode(self::scrub($before)) : null,
                    $after  !== null ? json_encode(self::scrub($after))  : null,
                    $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                ]
            );
        } catch (Throwable $e) {
            error_log('Audit::record failed: ' . $e->getMessage());
        }
    }

    /**
     * Strip anything that must never be written to a log.
     *
     * before/after snapshots are taken by selecting a whole row, so a
     * password hash or token lands in the audit table by accident unless it
     * is removed here. An audit log is read far more casually than the users
     * table and is often exported.
     */
    private static function scrub(array $row): array
    {
        foreach (['password_hash', 'password', 'token', 'token_hash',
                  'session_hash', 'csrf_hash', 'api_key', 'secret'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = '[redacted]';
            }
        }
        return $row;
    }

    /** @return array<int, array> */
    public static function recent(int $limit = 50, ?string $entity = null): array
    {
        if ($entity !== null) {
            return Db::all(
                'SELECT * FROM audit_log WHERE entity = ? ORDER BY created_at DESC LIMIT ?',
                [$entity, $limit]
            );
        }
        return Db::all('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
}
