<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

start_secure_session();
send_security_headers();

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (login_rate_limited(client_ip())) {
            $error = 'Too many failed attempts. Please try again in a few minutes.';
        } elseif ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } elseif (attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Storage Panel</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-logo">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" stroke="currentColor" stroke-width="1.6"/></svg>
      <h1>Storage Panel</h1>
    </div>
    <p class="login-sub">Sign in to manage your VPS files</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="off">
      <input type="hidden" name="<?= h(CSRF_TOKEN_NAME) ?>" value="<?= h($token) ?>">
      <label>Username
        <input type="text" name="username" required autofocus autocomplete="username">
      </label>
      <label>Password
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
  </div>
</body>
</html>
