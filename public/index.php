<?php
/**
 * index.php — the only web-reachable file in the backend.
 *
 * Reading this file tells you the entire surface area of the API. Keep it
 * that way: no logic here, only the route table.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\AuthController;
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

/* ---- donations ----------------------------------------- PHASE 7 ----
$router->post('/api/donation/create',  [DonationController::class, 'create'], auth: true);
$router->get ('/api/donation/status',  [DonationController::class, 'status'], auth: true);
$router->post('/api/donation/webhook', [DonationController::class, 'webhook']);
------------------------------------------------------------------------ */

/* ---- account ------------------------------------------- PHASE 8 ----
$router->delete('/api/user/account', [AccountController::class, 'destroy'], auth: true);
------------------------------------------------------------------------ */

$router->dispatch(new Request());
