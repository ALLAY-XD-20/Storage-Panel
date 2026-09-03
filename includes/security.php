<?php
/**
 * Cross-cutting security helpers: CSRF tokens, safe JSON responses,
 * security headers, and generic output escaping.
 */

require_once __DIR__ . '/../config.php';

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; connect-src 'self'; frame-ancestors 'none'");
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => FORCE_SECURE_COOKIE,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Idle timeout
    $timeout = (int) SESSION_TIMEOUT_SECONDS;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        $_SESSION = [];
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_verify(?string $token): bool
{
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/** Reads a CSRF token from header, POST body, or JSON body. */
function csrf_from_request(): ?string
{
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (!empty($_POST[CSRF_TOKEN_NAME])) {
        return $_POST[CSRF_TOKEN_NAME];
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json[CSRF_TOKEN_NAME])) {
            return $json[CSRF_TOKEN_NAME];
        }
    }
    return null;
}

function require_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        if (!csrf_verify(csrf_from_request())) {
            json_error('Invalid or missing security token. Please refresh the page and try again.', 403);
        }
    }
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['ok' => false, 'error' => $message], $status);
}

function json_ok(array $data = []): void
{
    json_response(array_merge(['ok' => true], $data));
}

/** Escapes a string for safe HTML output. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Very small rate limiter helper used for login attempts. */
function login_rate_limited(string $ip): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) c FROM login_attempts
        WHERE ip = :ip AND success = 0 AND created_at > datetime('now', :window)");
    $stmt->execute([':ip' => $ip, ':window' => '-' . LOGIN_LOCKOUT_SECONDS . ' seconds']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ((int) $row['c']) >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $ip, string $username, bool $success): void
{
    $stmt = db()->prepare("INSERT INTO login_attempts (ip, username, success) VALUES (:ip, :u, :s)");
    $stmt->execute([':ip' => $ip, ':u' => $username, ':s' => $success ? 1 : 0]);
}
