<?php
/**
 * Request — what the client sent.
 */

declare(strict_types=1);

namespace App;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    private ?array $json = null;

    /** Set by the Auth middleware once a token resolves to a user. */
    public ?int $userId = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
    }

    /** Decoded JSON body. Malformed JSON is a 400, not a 500. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return $this->json = [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException('VALIDATION_ERROR', 'Request body must be valid JSON.', 400);
        }

        return $this->json = $decoded;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // Some server configurations strip Authorization before PHP sees it.
        // If auth mysteriously fails in production but works locally, add
        //   fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        // to the nginx config. See docs/08 §3.
        if (strcasecmp($name, 'Authorization') === 0) {
            if (function_exists('apache_request_headers')) {
                foreach (apache_request_headers() as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) {
                        return $v;
                    }
                }
            }
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }

        return null;
    }

    /** The bearer token, or null. */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Client IP, used only for rate limiting.
     *
     * REMOTE_ADDR is the only value a client cannot forge. X-Forwarded-For is
     * trusted ONLY when TRUSTED_PROXY is set, because otherwise anyone can
     * send that header and sidestep the rate limit entirely.
     */
    public function ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (Env::get('TRUSTED_PROXY') !== '' && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    /** Throws unless the request is authenticated. */
    public function requireUserId(): int
    {
        if ($this->userId === null) {
            throw new ApiException('TOKEN_INVALID', 'Please log in again.', 401);
        }
        return $this->userId;
    }
}
