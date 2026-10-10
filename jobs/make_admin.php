<?php
/**
 * make_admin.php — promote an account to administrator, or create one.
 *
 *     php jobs/make_admin.php <email> [password]
 *
 * The bootstrap path, and deliberately a CLI tool rather than a dashboard
 * feature: the first administrator cannot be created through a dashboard
 * that requires an administrator to sign in. Later admins are promoted from
 * the Users screen.
 *
 * With a password it creates the account if missing, or RESETS the password
 * if it exists. Without one it only promotes, leaving the password alone.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Services\Audit;

$email    = $argv[1] ?? '';
$password = $argv[2] ?? null;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: php jobs/make_admin.php <email> [password]\n");
    exit(1);
}

$user = Db::one('SELECT id, email, role FROM users WHERE LOWER(email) = LOWER(?)', [$email]);

if ($user === null) {
    if ($password === null) {
        fwrite(STDERR, "No account for {$email}. Pass a password to create one.\n");
        exit(1);
    }
    if (strlen($password) < 10) {
        fwrite(STDERR, "Choose a password of at least 10 characters.\n");
        exit(1);
    }

    $id = Db::scalar(
        "INSERT INTO users (email, password_hash, is_active, email_verified, role)
         VALUES (?, ?, true, true, 'admin') RETURNING id",
        [$email, password_hash($password, PASSWORD_BCRYPT)]
    );
    Audit::record(null, 'cli', 'admin.create', 'users', (string) $id, null, ['email' => $email]);
    echo "Created administrator {$email} (id {$id}).\n";
    exit(0);
}

$before = ['role' => $user['role']];

if ($password !== null) {
    if (strlen($password) < 10) {
        fwrite(STDERR, "Choose a password of at least 10 characters.\n");
        exit(1);
    }
    Db::exec('UPDATE users SET password_hash = ?, role = \'admin\', is_active = true WHERE id = ?',
             [password_hash($password, PASSWORD_BCRYPT), $user['id']]);
    echo "Promoted {$email} to administrator and reset the password.\n";
} else {
    Db::exec("UPDATE users SET role = 'admin', is_active = true WHERE id = ?", [$user['id']]);
    echo "Promoted {$email} to administrator. Password unchanged.\n";
}

Audit::record(null, 'cli', 'admin.promote', 'users', (string) $user['id'], $before, ['role' => 'admin']);
