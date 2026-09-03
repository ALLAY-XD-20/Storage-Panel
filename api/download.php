<?php
/**
 * Authenticated file download / streaming endpoint.
 *
 * This is the ONLY way to retrieve a file's contents. STORAGE_ROOT is
 * never exposed directly by the web server (see apache/.htaccess and
 * nginx examples) — every download must pass through the checks below.
 */
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/filesystem.php';

start_secure_session();
send_security_headers();
require_login(); // redirects to login for a plain browser GET

try {
    $rel = (string) ($_GET['path'] ?? '');
    $asZip = isset($_GET['zip']); // multi-file download is handled by zip.php; this checks single-file only

    $abs = resolve_safe_path($rel, true);

    if (!is_file($abs)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }

    log_activity('download', $rel);

    $size = filesize($abs);
    $mime = mime_for($abs);
    $filename = basename($abs);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');

    $start = 0;
    $end = $size - 1;
    $isRange = false;

    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $isRange = true;
            if ($m[1] !== '') $start = (int) $m[1];
            if ($m[2] !== '') $end = (int) $m[2];
            if ($start > $end || $end >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header("Content-Range: bytes */$size");
                exit;
            }
        }
    }

    $length = $end - $start + 1;

    if ($isRange) {
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . $length);

    $fh = fopen($abs, 'rb');
    if ($fh === false) {
        http_response_code(500);
        exit;
    }
    fseek($fh, $start);
    $bufferSize = 1024 * 1024; // stream 1MB at a time — never load whole file
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min($bufferSize, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;

} catch (PathSecurityException $e) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
} catch (Throwable $e) {
    error_log('[download.php] ' . $e->getMessage());
    http_response_code(500);
    echo 'An unexpected error occurred.';
    exit;
}
