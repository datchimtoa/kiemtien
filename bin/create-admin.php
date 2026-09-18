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
App\Database::run(
    'INSERT INTO admin_users(username, password_hash, status, created_at) VALUES(?,?,?,?)
     ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash',
    [$username, $hash, 'active', now()]
);
echo "Admin '{$username}' created/updated.\n";
