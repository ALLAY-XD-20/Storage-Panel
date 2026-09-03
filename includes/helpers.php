<?php
require_once __DIR__ . '/../config.php';

function mime_for(string $absolutePath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $absolutePath);
        finfo_close($finfo);
        if ($mime) {
            return $mime;
        }
    }
    return 'application/octet-stream';
}

function bootstrap_api(): void
{
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/security.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/filesystem.php';

    start_secure_session();
    send_security_headers();
    require_login();
    require_csrf();

    header('Content-Type: application/json; charset=utf-8');
}

/** Reads and decodes a JSON request body, falling back to $_POST. */
function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return $_POST;
}

function req_path(array $body, string $key = 'path'): string
{
    return isset($body[$key]) ? (string) $body[$key] : '';
}
