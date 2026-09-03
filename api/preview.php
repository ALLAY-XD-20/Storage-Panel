<?php
/**
 * Streams file content for inline preview (images, video, audio, pdf,
 * text). Same authentication + path-jail rules as download.php, but
 * responds inline instead of forcing a download, and truncates large
 * text previews.
 */
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/filesystem.php';

start_secure_session();
send_security_headers();
require_login();

try {
    $rel = (string) ($_GET['path'] ?? '');
    $abs = resolve_safe_path($rel, true);

    if (!is_file($abs)) {
        http_response_code(404);
        exit('File not found.');
    }

    $ext = get_extension($abs);
    $size = filesize($abs);
    $mime = mime_for($abs);

    $isMedia = in_array($ext, array_merge(PREVIEW_IMAGE_EXT, PREVIEW_VIDEO_EXT, PREVIEW_AUDIO_EXT, PREVIEW_PDF_EXT), true);

    if ($isMedia) {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($abs)) . '"');
        header('Accept-Ranges: bytes');

        $start = 0; $end = $size - 1; $isRange = false;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $isRange = true;
            if ($m[1] !== '') $start = (int) $m[1];
            if ($m[2] !== '') $end = (int) $m[2];
        }
        $length = $end - $start + 1;
        if ($isRange) {
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . $length);

        $fh = fopen($abs, 'rb');
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, min(1024 * 1024, $remaining));
            if ($chunk === false) break;
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    if (in_array($ext, PREVIEW_TEXT_EXT, true) || $ext === '') {
        $maxPreview = 500 * 1024; // 500KB text preview cap
        $content = file_get_contents($abs, false, null, 0, $maxPreview);
        json_ok([
            'type' => 'text',
            'content' => $content,
            'truncated' => $size > $maxPreview,
            'size' => $size,
        ]);
    }

    json_error('No preview available for this file type.', 415);

} catch (PathSecurityException $e) {
    http_response_code(403);
    exit('Access denied.');
} catch (Throwable $e) {
    error_log('[preview.php] ' . $e->getMessage());
    http_response_code(500);
    exit('An unexpected error occurred.');
}
