<?php
/**
 * purge_sessions.php — drop expired dashboard sessions.
 *
 * Expired rows are already rejected at login (the lookup filters on
 * expires_at), so this is not a security fix — it is housekeeping. Without
 * it the table grows by one row per sign-in forever, and the index behind
 * every dashboard request grows with it.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Services\AdminAuth;

printf("expired admin sessions removed: %d\n", AdminAuth::purgeExpired());
