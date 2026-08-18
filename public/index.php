<?php
/**
 * index.php — the only web-reachable file in the backend.
 *
 * Reading this file tells you the entire surface area of the API. Keep it
 * that way: no logic here, only the route table.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\DonationController;
use App\Controllers\EventController;
use App\Controllers\CategoryController;
use App\Controllers\FeedController;
use App\Controllers\HealthController;
use App\Controllers\LegalController;
use App\Request;
use App\Router;

$router = new Router();

/* ---- status -------------------------------------------------------- */
// What uptime monitoring polls. No auth, no rate limit: a limiter would
// throttle the monitor itself and report an outage that is not happening.
// Returns 503, not 200, when the database is unreachable.
$router->get('/', [HealthController::class, 'index']);

/* ---- legal ----------------------------------------------------------- */
// Public, no auth, no rate limit. Both app stores require a reachable
// privacy-policy URL on the listing, and a store reviewer fetching it must
// never meet a 429 or a token check.
$router->get('/privacy', [LegalController::class, 'privacy']);

/* ---- auth ---------------------------------------------------------- */
// 5 attempts per IP per minute on the two endpoints worth brute-forcing.
$router->post('/api/register', [AuthController::class, 'register'], limit: ['register', 5, 60]);
$router->post('/api/login',    [AuthController::class, 'login'],    limit: ['login', 5, 60]);
$router->post('/api/logout',   [AuthController::class, 'logout'],   auth: true);

/* ---- categories ----------------------------------------------------- */
$router->get ('/api/categories',      [CategoryController::class, 'list']);
$router->get ('/api/user/categories', [CategoryController::class, 'mine'], auth: true);
$router->post('/api/user/categories', [CategoryController::class, 'save'], auth: true);

/* ---- feed ------------------------------------------------------------ */
$router->get('/api/feed', [FeedController::class, 'index'], auth: true);

/* ---- interaction events ----------------------------------------------- */
// What the feed learns from. 120 batches per USER per hour: the app flushes
// at most every 30 seconds, so a well-behaved client sends ~120 in an hour of
// continuous use and anything faster is a loop that needs stopping.
$router->post('/api/events', [EventController::class, 'store'],
              auth: true, limit: ['events', 120, 3600, true]);

// Blogs this user has muted, and the way back. The undo half matters most:
// a hide is invisible once applied, so without this a mis-tap silently
// removes a source forever with nothing to show for it.
$router->get ('/api/user/hidden-sources', [EventController::class, 'hidden'],    auth: true);
$router->post('/api/user/hidden-sources', [EventController::class, 'setHidden'], auth: true);

/* ---- donations ------------------------------------------------------- */
// 10 per USER per hour (the trailing true) — keying this on IP would make
// everyone behind one NAT share a single quota.
$router->post('/api/donation/create',  [DonationController::class, 'create'],
              auth: true, limit: ['donate', 10, 3600, true]);
$router->get ('/api/donation/status',  [DonationController::class, 'status'], auth: true);

// How to donate — gateway or UPI. No auth: it holds nothing private, and
// the Donate screen must render even if a token has just expired.
$router->get ('/api/donation/info',    [DonationController::class, 'info']);

// No auth: Instamojo's server calls this and the SIGNATURE is the auth.
$router->post('/api/donation/webhook', [DonationController::class, 'webhook']);

// Local sandbox payment page. 404s unless INSTAMOJO_API_KEY is empty AND
// APP_ENV is not production.
$router->get ('/api/donation/sandbox', [DonationController::class, 'sandbox']);

/* ---- account --------------------------------------------------------- */
// 5 per user per hour. Changing a password is rare; anything faster is
// somebody working through a list of guesses at the CURRENT one.
$router->get ('/api/user/profile',  [AccountController::class, 'profile'],       auth: true);
$router->post('/api/user/profile',  [AccountController::class, 'updateProfile'], auth: true);

$router->post('/api/user/password', [AccountController::class, 'changePassword'],
              auth: true, limit: ['pwchange', 5, 3600, true]);

/* ---- account deletion ------------------------------------------------- */
// Required by both app stores for any app with accounts. Password-confirmed
// and irreversible, so 3 per user per hour — a real deletion happens once.
$router->delete('/api/user/account', [AccountController::class, 'destroy'],
                auth: true, limit: ['delacct', 3, 3600, true]);

$router->dispatch(new Request());
