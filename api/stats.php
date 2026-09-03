<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/stats.php';

$snapshot = get_system_snapshot();

// Top-level folder count / file count for the dashboard cards.
// Uses a shallow scan of the root only (cheap) — deep recursive counts
// are available via api/files.php?action=folder_size for a specific path.
$root = realpath(STORAGE_ROOT);
$fileCount = 0;
$folderCount = 0;
if ($root && is_dir($root)) {
    $dh = opendir($root);
    if ($dh) {
        while (($item = readdir($dh)) !== false) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($root . '/' . $item)) {
                $folderCount++;
            } else {
                $fileCount++;
            }
        }
        closedir($dh);
    }
}

json_ok([
    'system' => $snapshot,
    'storage_root_summary' => [
        'files' => $fileCount,
        'folders' => $folderCount,
    ],
]);
