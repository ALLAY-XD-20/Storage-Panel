<?php
/**
 * Archive management: create, extract, list.
 *
 * Extraction is hardened against Zip Slip: every entry's target path
 * is resolved and verified to remain inside the destination directory
 * (which itself must be inside STORAGE_ROOT) before it is written.
 */
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

if (!class_exists('ZipArchive')) {
    json_error('The PHP zip extension is not installed on this server.', 500);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$body = request_body();

try {
    switch ($action) {

        case 'create':
            $paths = $body['paths'] ?? [];
            $destRel = req_path($body, 'destination');
            $zipName = trim((string) ($body['zip_name'] ?? 'archive.zip'));
            safe_name_check($zipName);
            if (get_extension($zipName) !== 'zip') {
                $zipName .= '.zip';
            }
            if (empty($paths)) {
                json_error('No files selected.', 400);
            }

            $destAbs = resolve_safe_path($destRel === '' ? '.' : $destRel, true);
            $zipTarget = resolve_safe_path(($destRel !== '' ? $destRel . '/' : '') . $zipName, false);
            if (file_exists($zipTarget)) {
                json_error('A file with that name already exists.', 409);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipTarget, ZipArchive::CREATE) !== true) {
                json_error('Could not create archive.', 500);
            }

            foreach ($paths as $p) {
                $abs = resolve_safe_path((string) $p, true);
                $baseName = basename($abs);
                if (is_dir($abs)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $item) {
                        $localName = $baseName . '/' . substr($item->getPathname(), strlen($abs) + 1);
                        if ($item->isDir()) {
                            $zip->addEmptyDir($localName);
                        } else {
                            $zip->addFile($item->getPathname(), $localName);
                        }
                    }
                } else {
                    $zip->addFile($abs, $baseName);
                }
            }
            $zip->close();
            chmod($zipTarget, 0640);
            log_activity('zip_create', to_relative($zipTarget));
            json_ok(['path' => to_relative($zipTarget)]);
            break;

        case 'extract':
            $rel = req_path($body);
            $destRel = req_path($body, 'destination');
            $zipAbs = resolve_safe_path($rel, true);
            if (get_extension($zipAbs) !== 'zip') {
                json_error('Not a ZIP file.', 400);
            }
            $destAbs = resolve_safe_path($destRel === '' ? dirname($rel) : $destRel, true);
            if (!is_dir($destAbs)) {
                json_error('Destination must be a folder.', 400);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipAbs) !== true) {
                json_error('Could not open archive.', 400);
            }

            $destReal = realpath($destAbs);
            $extracted = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                // --- Zip Slip protection ---
                if ($entryName === false) continue;
                // Reject absolute paths and traversal sequences outright.
                if (strpos($entryName, "\0") !== false) {
                    continue;
                }
                $normalizedEntry = ltrim(str_replace('\\', '/', $entryName), '/');
                $segments = explode('/', $normalizedEntry);
                if (in_array('..', $segments, true)) {
                    continue; // skip malicious entry
                }

                $targetPath = $destReal . '/' . $normalizedEntry;
                $targetDir = str_ends_with($normalizedEntry, '/') ? $targetPath : dirname($targetPath);

                // Ensure the computed target stays within destination.
                $collapsedParts = [];
                foreach (explode('/', $targetPath) as $seg) {
                    if ($seg === '' || $seg === '.') continue;
                    if ($seg === '..') { array_pop($collapsedParts); continue; }
                    $collapsedParts[] = $seg;
                }
                $collapsed = '/' . implode('/', $collapsedParts);
                if (strpos($collapsed, $destReal . '/') !== 0 && $collapsed !== $destReal) {
                    continue; // skip entry that would escape destination
                }

                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0750, true);
                }
                if (!str_ends_with($normalizedEntry, '/')) {
                    $stream = $zip->getStream($entryName);
                    if ($stream) {
                        $out = fopen($collapsed, 'wb');
                        stream_copy_to_stream($stream, $out);
                        fclose($out);
                        fclose($stream);
                        chmod($collapsed, 0640);
                        $extracted++;
                    }
                }
            }
            $zip->close();
            log_activity('zip_extract', "$rel -> " . to_relative($destAbs));
            json_ok(['extracted' => $extracted]);
            break;

        default:
            json_error('Unknown action.', 400);
    }
} catch (PathSecurityException $e) {
    json_error($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('[zip.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
