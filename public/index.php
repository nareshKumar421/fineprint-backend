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
use App\Controllers\CategoryController;
use App\Controllers\FeedController;
use App\Request;
use App\Router;

$router = new Router();

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

/* ---- donations ------------------------------------------------------- */
// 10 per USER per hour (the trailing true) — keying this on IP would make
// everyone behind one NAT share a single quota.
$router->post('/api/donation/create',  [DonationController::class, 'create'],
              auth: true, limit: ['donate', 10, 3600, true]);
$router->get ('/api/donation/status',  [DonationController::class, 'status'], auth: true);

// No auth: Instamojo's server calls this and the SIGNATURE is the auth.
$router->post('/api/donation/webhook', [DonationController::class, 'webhook']);

// Local sandbox payment page. 404s unless INSTAMOJO_API_KEY is empty AND
// APP_ENV is not production.
$router->get ('/api/donation/sandbox', [DonationController::class, 'sandbox']);

/* ---- account --------------------------------------------------------- */
// 5 per user per hour. Changing a password is rare; anything faster is
// somebody working through a list of guesses at the CURRENT one.
$router->post('/api/user/password', [AccountController::class, 'changePassword'],
              auth: true, limit: ['pwchange', 5, 3600, true]);

/* ---- account deletion ---------------------------------- PHASE 8 ----
$router->delete('/api/user/account', [AccountController::class, 'destroy'], auth: true);
------------------------------------------------------------------------ */

$router->dispatch(new Request());
