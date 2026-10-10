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
use App\Controllers\Admin;
use App\Controllers\AdminController;
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

/* ---- admin dashboard ------------------------------------------------- */
// The dashboard owns '/'. Signed out it renders the sign-in form; signed in,
// the overview. Everything here is HTML, not JSON — a browser handed the
// API's error shape would render it as text. Every controller extends
// App\Admin\Page, which supplies the admin gate and the CSRF check.
$router->get ('/',       [AdminController::class, 'index']);
$router->post('/login',  [AdminController::class, 'login'], limit: ['adminlogin', 10, 300]);
$router->post('/logout', [AdminController::class, 'logout']);

// Articles — the 17k-row table, browsable at last.
$router->get ('/articles',                 [Admin\ArticlesController::class, 'index']);
$router->get ('/articles/view',            [Admin\ArticlesController::class, 'view']);
$router->get ('/articles/delete-confirm',  [Admin\ArticlesController::class, 'deleteConfirm']);
$router->post('/articles/delete',          [Admin\ArticlesController::class, 'delete']);
$router->post('/articles/bulk-delete',     [Admin\ArticlesController::class, 'bulkDelete']);

// Sources — full lifecycle of a feed, with live validation before saving.
$router->get ('/sources',                  [Admin\SourcesController::class, 'index']);
$router->get ('/sources/view',             [Admin\SourcesController::class, 'view']);
$router->get ('/sources/new',              [Admin\SourcesController::class, 'newForm']);
$router->post('/sources/create',           [Admin\SourcesController::class, 'create']);
$router->get ('/sources/edit',             [Admin\SourcesController::class, 'edit']);
$router->post('/sources/update',           [Admin\SourcesController::class, 'update']);
$router->post('/sources/toggle',           [Admin\SourcesController::class, 'toggle']);
$router->get ('/sources/delete-confirm',   [Admin\SourcesController::class, 'deleteConfirm']);
$router->post('/sources/delete',           [Admin\SourcesController::class, 'delete']);
$router->get ('/sources/validate',         [Admin\SourcesController::class, 'validate']);

// Categories.
$router->get ('/categories',               [Admin\CategoriesController::class, 'index']);
$router->get ('/categories/view',          [Admin\CategoriesController::class, 'view']);
$router->get ('/categories/new',           [Admin\CategoriesController::class, 'newForm']);
$router->get ('/categories/edit',          [Admin\CategoriesController::class, 'editForm']);
$router->post('/categories/create',        [Admin\CategoriesController::class, 'create']);
$router->post('/categories/update',        [Admin\CategoriesController::class, 'update']);
$router->post('/categories/toggle',        [Admin\CategoriesController::class, 'toggle']);
$router->post('/categories/reorder',       [Admin\CategoriesController::class, 'reorder']);
$router->get ('/categories/delete-confirm',[Admin\CategoriesController::class, 'deleteConfirm']);
$router->post('/categories/delete',        [Admin\CategoriesController::class, 'delete']);

// Users — readers and administrators.
$router->get ('/users',                    [Admin\UsersController::class, 'index']);
$router->get ('/users/view',               [Admin\UsersController::class, 'view']);
$router->get ('/users/new',                [Admin\UsersController::class, 'new']);
$router->post('/users/create',             [Admin\UsersController::class, 'create']);
$router->get ('/users/edit',               [Admin\UsersController::class, 'edit']);
$router->post('/users/update',             [Admin\UsersController::class, 'update']);
$router->post('/users/toggle-active',      [Admin\UsersController::class, 'toggleActive']);
$router->post('/users/role',               [Admin\UsersController::class, 'role']);
$router->post('/users/revoke-sessions',    [Admin\UsersController::class, 'revokeSessions']);
$router->get ('/users/delete-confirm',     [Admin\UsersController::class, 'deleteConfirm']);
$router->post('/users/delete',             [Admin\UsersController::class, 'delete']);

// Jobs — operations that outlast a request run through admin_jobs and a
// detached worker (jobs/run_job.php). Sync pages live here too.
$router->get ('/jobs',                     [Admin\JobsController::class, 'index']);
$router->post('/jobs/run',                 [Admin\JobsController::class, 'run']);
$router->get ('/jobs/view',                [Admin\JobsController::class, 'view']);
$router->post('/jobs/retry',               [Admin\JobsController::class, 'retry']);
$router->post('/jobs/cancel',              [Admin\JobsController::class, 'cancel']);
$router->get ('/sync',                     [Admin\JobsController::class, 'sync']);
$router->get ('/sync/view',                [Admin\JobsController::class, 'syncView']);

// System — settings, the audit trail, and a status page.
$router->get ('/settings',                 [Admin\SystemController::class, 'settings']);
$router->post('/settings/save',            [Admin\SystemController::class, 'saveSetting']);
$router->get ('/audit',                    [Admin\SystemController::class, 'audit']);
$router->get ('/system',                   [Admin\SystemController::class, 'system']);

/* ---- status -------------------------------------------------------- */
// Moved off '/' when the dashboard took it. What uptime monitoring polls —
// no auth, no rate limit: a limiter would throttle the monitor itself and
// report an outage that is not happening. Returns 503, not 200, when the
// database is unreachable.
//
// The container healthcheck, deploy.sh and nginx all point here now. If you
// move it again, move those three with it.
$router->get('/health', [HealthController::class, 'index']);

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
