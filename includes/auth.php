<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['username']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (is_ajax_request()) {
            json_error('Not authenticated.', 401);
        }
        header('Location: login.php');
        exit;
    }
}

function is_ajax_request(): bool
{
    return (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
        || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
}

function attempt_login(string $username, string $password): bool
{
    $ip = client_ip();

    if (login_rate_limited($ip)) {
        record_login_attempt($ip, $username, false);
        return false;
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($ip, $username, false);
        usleep(300000); // constant-ish delay to slow brute force
        return false;
    }

    record_login_attempt($ip, $username, true);

    // Prevent session fixation
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['last_activity'] = time();

    log_activity('login', '', $user['username']);

    return true;
}

function do_logout(): void
{
    if (is_logged_in()) {
        log_activity('logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
