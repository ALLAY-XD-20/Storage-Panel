<?php
/**
 * Chunked upload endpoint.
 *
 * The browser splits large files into CHUNK_SIZE_BYTES pieces and
 * POSTs each one with a shared upload id. Chunks are streamed to a
 * temp file with fopen/fwrite (never loaded fully into memory) and
 * assembled once the final chunk arrives.
 */
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

try {
    $destRel   = (string) ($_POST['destination'] ?? '');
    $fileName  = trim((string) ($_POST['filename'] ?? ''));
    $uploadId  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['upload_id'] ?? ''));
    $chunkIndex = (int) ($_POST['chunk_index'] ?? 0);
    $totalChunks = (int) ($_POST['total_chunks'] ?? 1);

    safe_name_check($fileName);
    if ($uploadId === '') {
        json_error('Missing upload id.', 400);
    }
    if (!isset($_FILES['chunk'])) {
        json_error('No chunk received.', 400);
    }
    if (is_blocked_extension($fileName) || !is_allowed_extension($fileName)) {
        json_error('This file type is not allowed by panel settings.', 403);
    }

    $maxMb = (int) setting_get('max_upload_mb', 2048);

    $destAbs = resolve_safe_path($destRel === '' ? '.' : $destRel, true);
    if (!is_dir($destAbs)) {
        json_error('Destination folder does not exist.', 400);
    }

    if (!is_dir(TMP_UPLOAD_DIR)) {
        mkdir(TMP_UPLOAD_DIR, 0750, true);
    }
    $tmpAssembly = TMP_UPLOAD_DIR . '/' . $uploadId . '.part';

    // Append this chunk using streaming I/O.
    $in = fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = fopen($tmpAssembly, $chunkIndex === 0 ? 'wb' : 'ab');
    if (!$in || !$out) {
        json_error('Upload failed (I/O error).', 500);
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    $sizeSoFar = filesize($tmpAssembly);
    if ($sizeSoFar > $maxMb * 1024 * 1024) {
        @unlink($tmpAssembly);
        json_error("Upload exceeds the maximum allowed size ({$maxMb} MB).", 413);
    }

    if ($chunkIndex + 1 < $totalChunks) {
        json_ok(['status' => 'chunk_received', 'chunk_index' => $chunkIndex]);
    }

    // Final chunk: move into place.
    $finalName = $fileName;
    $target = $destAbs . '/' . $finalName;
    $suffix = 1;
    $info = pathinfo($finalName);
    while (file_exists($target)) {
        $candidate = $info['filename'] . '-' . $suffix . (isset($info['extension']) ? '.' . $info['extension'] : '');
        $target = $destAbs . '/' . $candidate;
        $suffix++;
    }

    if (!rename($tmpAssembly, $target)) {
        // Cross-device fallback
        if (!copy($tmpAssembly, $target)) {
            json_error('Could not finalize upload.', 500);
        }
        @unlink($tmpAssembly);
    }
    chmod($target, 0640);

    log_activity('upload', to_relative($target));
    json_ok(['status' => 'complete', 'path' => to_relative($target), 'name' => basename($target)]);

} catch (PathSecurityException $e) {
    json_error($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('[upload.php] ' . $e->getMessage());
    json_error('Upload failed.', 500);
}
