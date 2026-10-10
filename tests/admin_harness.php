<?php
/**
 * admin_harness.php — run one dashboard controller method from the CLI.
 *
 *     php tests/admin_harness.php <Controller> <method> <HTTP> <path> [query] [post-json]
 *
 *     php tests/admin_harness.php Admin\\ArticlesController index GET /articles 'q=rocket&page=2'
 *     php tests/admin_harness.php Admin\\SourcesController toggle POST /sources/toggle '' '{"id":"12"}'
 *
 * Signs in a throwaway admin, sets the session and CSRF cookies exactly as a
 * browser would, injects the CSRF token into every POST, calls the method,
 * and prints the HTTP status, the Location header if any, and the body.
 *
 * It exists so that a dashboard page can be TESTED rather than only read.
 * Against whatever database .env points at — which for development is the
 * retired Neon copy, safe to mutate.
 *
 * Exit code is the HTTP status class: 0 for 2xx/3xx, 1 otherwise.
 */

declare(strict_types=1);

[$_, $class, $method, $http, $path] = array_pad($argv, 5, null);
$query = $argv[5] ?? '';
$post  = $argv[6] ?? '';

if ($class === null || $method === null || $http === null || $path === null) {
    fwrite(STDERR, "usage: php tests/admin_harness.php <Controller> <method> <GET|POST> <path> [query] [post-json]\n");
    exit(2);
}

$_SERVER['REQUEST_METHOD'] = strtoupper($http);
$_SERVER['REQUEST_URI']    = $path . ($query !== '' ? '?' . $query : '');
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'admin-harness';
parse_str($query, $_GET);
$_POST = $post !== '' ? (json_decode($post, true) ?? []) : [];

require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Request;
use App\Services\AdminAuth;

// A dedicated harness admin, created on first use.
$email = 'harness@fineprint.local';
$pass  = 'harness-only-' . substr(hash('sha256', (string) getenv('FEED_DB_DSN')), 0, 12);
if (Db::one('SELECT 1 FROM users WHERE email = ?', [$email]) === null) {
    Db::exec("INSERT INTO users (email, password_hash, is_active, email_verified, role, display_name)
              VALUES (?, ?, true, true, 'admin', 'harness')", [$email, password_hash($pass, PASSWORD_BCRYPT)]);
} else {
    Db::exec("UPDATE users SET password_hash = ?, role = 'admin', is_active = true WHERE email = ?",
             [password_hash($pass, PASSWORD_BCRYPT), $email]);
}

$session = AdminAuth::login($email, $pass, '127.0.0.1', 'admin-harness');
$_COOKIE[AdminAuth::COOKIE]      = $session['session'];
$_COOKIE['fp_admin_csrf']        = $session['csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['csrf'] = $_POST['csrf'] ?? $session['csrf'];
}

$fqcn = str_starts_with($class, 'App\\') ? $class : 'App\\Controllers\\' . $class;
if (!class_exists($fqcn)) {
    fwrite(STDERR, "no such controller: $fqcn\n");
    exit(2);
}

ob_start();
$status = 200;
try {
    (new $fqcn())->$method(new Request());
    $status = http_response_code() ?: 200;
} catch (\App\ApiException $e) {
    $status = $e->status;
    echo "ApiException {$e->errorCode}: {$e->getMessage()}\n";
} catch (\Throwable $e) {
    $status = 500;
    echo get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
$body = ob_get_clean();

$location = '';
foreach (headers_list() as $h) {
    if (stripos($h, 'Location:') === 0) {
        $location = trim(substr($h, 9));
    }
}

fwrite(STDERR, "HTTP $status" . ($location !== '' ? "  -> $location" : '') . "  (" . strlen($body) . " bytes)\n");
echo $body;
exit($status < 400 ? 0 : 1);
