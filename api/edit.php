<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'load');
$body = request_body();

// 10MB cap on what the browser-based editor will open/save — large
// binary or huge log files should be downloaded instead.
const EDIT_MAX_BYTES = 10 * 1024 * 1024;

try {
    if ($action === 'load') {
        $rel = req_path($_GET);
        $abs = resolve_safe_path($rel, true);

        if (!is_file($abs)) {
            json_error('File not found.', 404);
        }
        $ext = get_extension($abs);
        if (!in_array($ext, EDITABLE_EXTENSIONS, true) && $ext !== '') {
            json_error('This file type cannot be opened in the editor.', 415);
        }
        if (filesize($abs) > EDIT_MAX_BYTES) {
            json_error('File is too large to edit in the browser (10MB limit). Please download it instead.', 413);
        }
        // Reject binary content defensively even if extension looked ok.
        $sample = file_get_contents($abs, false, null, 0, 8192);
        if ($sample !== false && strpos($sample, "\0") !== false) {
            json_error('This appears to be a binary file and cannot be edited.', 415);
        }

        json_ok([
            'path' => $rel,
            'content' => file_get_contents($abs),
            'extension' => $ext,
            'size' => filesize($abs),
            'modified' => filemtime($abs),
        ]);

    } elseif ($action === 'save') {
        $rel = req_path($body);
        $content = (string) ($body['content'] ?? '');
        $abs = resolve_safe_path($rel, true);

        if (!is_file($abs)) {
            json_error('File not found.', 404);
        }
        $ext = get_extension($abs);
        if (!in_array($ext, EDITABLE_EXTENSIONS, true) && $ext !== '') {
            json_error('This file type cannot be edited.', 415);
        }
        if (strlen($content) > EDIT_MAX_BYTES) {
            json_error('Content exceeds the 10MB editor limit.', 413);
        }

        if (file_put_contents($abs, $content, LOCK_EX) === false) {
            json_error('Save failed. Check file permissions.', 500);
        }
        log_activity('edit', $rel);
        json_ok(['path' => $rel, 'saved_at' => date('Y-m-d H:i:s')]);

    } else {
        json_error('Unknown action.', 400);
    }
} catch (PathSecurityException $e) {
    json_error($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('[edit.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
