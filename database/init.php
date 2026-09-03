<?php
/**
 * Standalone DB init script, invoked by install.sh (php database/init.php).
 * Creates the SQLite schema and the initial admin account.
 *
 * Usage: php init.php <admin_username> <admin_password> <storage_root>
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
$storageRoot = $argv[3] ?? null;

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php init.php <admin_username> <admin_password> [storage_root]\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Error: password must be at least 8 characters.\n");
    exit(1);
}

$pdo = db(); // triggers migration

// Create or update the admin user
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u");
$stmt->execute([':u' => $username]);
if ($stmt->fetch()) {
    $upd = $pdo->prepare("UPDATE users SET password_hash = :h WHERE username = :u");
    $upd->execute([':h' => $hash, ':u' => $username]);
    echo "Updated existing admin account '$username'.\n";
} else {
    $ins = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :h)");
    $ins->execute([':u' => $username, ':h' => $hash]);
    echo "Created admin account '$username'.\n";
}

if ($storageRoot) {
    setting_set('storage_root', $storageRoot);
    echo "Storage root set to: $storageRoot\n";
}

echo "Database initialized at " . DB_PATH . "\n";
