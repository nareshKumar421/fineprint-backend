<?php
/**
 * Response — the ONLY thing in the codebase that echoes.
 *
 * Every response is JSON, including errors. An HTML error page reaching the
 * app breaks its parser and produces a confusing crash instead of a readable
 * message. See docs/03 §6.
 */

declare(strict_types=1);

namespace App;

final class Response
{
    private static bool $sent = false;

    public static function json(mixed $data, int $status = 200): void
    {
        if (self::$sent) {
            return;             // never send two bodies
        }
        self::$sent = true;

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): void
    {
        if (!headers_sent() && $status === 429 && isset($extra['retry_after'])) {
            header('Retry-After: ' . (int) $extra['retry_after']);
        }

        self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * HTML — used ONLY by the local payment sandbox.
     *
     * The API contract is JSON everywhere (docs/03 §6); this is the one
     * deliberate exception, because it stands in for a hosted payment page
     * that a browser has to render.
     */
    public static function html(string $markup, int $status = 200): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo $markup;
    }

    public static function alreadySent(): bool
    {
        return self::$sent;
    }
}
