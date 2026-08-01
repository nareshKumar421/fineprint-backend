<?php
/**
 * Auth — turn a bearer token into a user id, or throw.
 *
 * No route handler ever deals with a missing or invalid user: by the time a
 * controller runs, $request->userId is guaranteed to be set.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\ApiException;
use App\Request;
use App\Services\TokenService;

final class Auth
{
    public static function authenticate(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new ApiException('TOKEN_INVALID', 'Please log in again.', 401);
        }

        $result = TokenService::verify($token);

        if ($result === null) {
            throw new ApiException('TOKEN_INVALID', 'Please log in again.', 401);
        }

        if (isset($result['expired'])) {
            // A distinct code so the app can tell "your session ended" from
            // "something is wrong with this token".
            throw new ApiException('TOKEN_EXPIRED', 'Please log in again.', 401);
        }

        $request->userId = $result['user_id'];
    }
}
