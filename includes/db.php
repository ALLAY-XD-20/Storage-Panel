<?php
/**
 * SQLite database bootstrap. Used only for: admin account, settings,
 * activity log, login-attempt tracking. Uploaded files are NEVER
 * stored here — only on the real filesystem under STORAGE_ROOT.
 */

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($isNew) {
        @chmod(DB_PATH, 0640);
    }

    db_migrate($pdo);

    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        action TEXT NOT NULL,
        path TEXT,
        ip TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        username TEXT,
        success INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log(created_at)");

    // Seed default settings if missing
    $defaults = [
        'storage_root'          => STORAGE_ROOT,
        'max_upload_mb'         => '2048',
        'allowed_extensions'    => DEFAULT_ALLOWED_EXTENSIONS,
        'blocked_extensions'    => DEFAULT_BLOCKED_EXTENSIONS,
        'session_timeout'       => (string) SESSION_TIMEOUT_SECONDS,
        'theme'                 => 'dark',
        'auto_refresh_interval' => '5',
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => $v]);
    }
}

function setting_get(string $key, $default = null)
{
    $stmt = db()->prepare("SELECT value FROM settings WHERE key = :k");
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare("INSERT INTO settings (key, value) VALUES (:k, :v)
        ON CONFLICT(key) DO UPDATE SET value = :v");
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function log_activity(string $action, string $path = '', ?string $username = null): void
{
    $username = $username ?? ($_SESSION['username'] ?? 'unknown');
    $stmt = db()->prepare("INSERT INTO activity_log (username, action, path, ip) VALUES (:u, :a, :p, :ip)");
    $stmt->execute([
        ':u'  => $username,
        ':a'  => $action,
        ':p'  => $path,
        ':ip' => client_ip(),
    ]);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
