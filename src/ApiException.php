<?php
/**
 * ApiException — the only way application code reports a failure.
 *
 * Controllers throw; they never format errors themselves. That is what
 * guarantees the single error shape promised in docs/03.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
