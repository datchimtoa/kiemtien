<?php
/** Usage: php bin/create-admin.php <username> <password> */
require dirname(__DIR__) . '/app/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> <password>\n");
    exit(1);
}
$username = trim($argv[1]);
$password = (string)$argv[2];
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}
App\Database::migrate();
$hash = hash_password($password);
// Upsert tương thích SQLite + MySQL.
App\Database::upsert(
    'admin_users',
    ['username' => $username, 'password_hash' => $hash, 'status' => 'active', 'created_at' => now()],
    'username',
    ['password_hash']
);
echo "Admin '{$username}' created/updated.\n";
