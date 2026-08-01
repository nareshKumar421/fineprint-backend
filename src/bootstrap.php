<?php
/**
 * bootstrap.php — autoload, environment, and the ONE error handler.
 *
 * Every failure path in the application converges here, which is what
 * guarantees the single error shape promised in docs/03 §3.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\ApiException;
use App\Env;
use App\Response;

Env::load();

/*
 * Never render a PHP error into the response body. The handler below is not
 * enough on its own — a parse error bypasses it entirely, which is why this
 * also belongs in production php.ini.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('UTC');

/**
 * Turn a Throwable into the standard JSON error shape.
 *
 * ApiException is expected: its code and message are written for users.
 * Anything else is a bug — log it with a reference id and tell the user
 * nothing beyond that id.
 */
set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiException) {
        Response::error($e->errorCode, $e->getMessage(), $e->status);
        return;
    }

    $id = bin2hex(random_bytes(6));
    error_log(sprintf(
        "[%s] %s: %s in %s:%d\n%s",
        $id, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));

    // The stack trace goes to the log, NEVER to the response. The user gets a
    // reference they can quote in a support message, which we can grep for.
    Response::error('SERVER_ERROR', "Something went wrong. Reference: {$id}", 500);
});

// Warnings and notices become exceptions, so they cannot half-execute a
// request and return a 200 with a broken body.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// A fatal error (out of memory, timeout) skips both handlers above. This is
// the last chance to emit valid JSON rather than a blank body or an HTML page.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (Response::alreadySent()) {
        return;
    }

    $id = bin2hex(random_bytes(6));
    error_log(sprintf("[%s] FATAL %s in %s:%d", $id, $err['message'], $err['file'], $err['line']));
    Response::error('SERVER_ERROR', "Something went wrong. Reference: {$id}", 500);
});
