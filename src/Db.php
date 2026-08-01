<?php
/**
 * Db — one lazily-created PDO connection per request.
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = Env::get('FEED_DB_DSN');
        if ($dsn === '') {
            throw new ApiException('SERVER_ERROR', 'Database is not configured.', 500);
        }

        try {
            self::$pdo = new PDO($dsn, Env::get('FEED_DB_USER'), Env::get('FEED_DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // With emulation ON, PDO interpolates values into the SQL string
                // itself. Real server-side prepares keep data and statement
                // genuinely separate, and make PostgreSQL type handling behave.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // The message can contain the DSN, including the password. Never
            // let it reach the client — the global handler logs it instead.
            throw new ApiException('SERVER_ERROR', 'Database unavailable.', 500, $e);
        }

        return self::$pdo;
    }

    /** Run a prepared statement and return all rows. */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a prepared statement and return the first row, or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run a prepared statement and return the first column of the first row. */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Run a prepared statement, return the number of affected rows. */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Run $fn inside a transaction, rolling back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        $db = self::conn();
        $db->beginTransaction();
        try {
            $result = $fn($db);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
