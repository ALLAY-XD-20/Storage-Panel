<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$body = request_body();

try {
    switch ($action) {

        case 'list':
            $rel = req_path($_GET);
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $limit = min(500, max(1, (int) ($_GET['limit'] ?? 200)));
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'asc';

            $abs = resolve_safe_path($rel === '' ? '.' : $rel, true);
            if (!is_dir($abs)) {
                json_error('Not a directory.', 400);
            }
            $result = list_directory($abs, $offset, $limit, $sort, $order);
            json_ok([
                'path' => $rel,
                'items' => $result['items'],
                'total' => $result['total'],
                'offset' => $offset,
                'limit' => $limit,
            ]);
            break;

        case 'folder_size':
            $rel = req_path($_GET);
            $abs = resolve_safe_path($rel, true);
            if (!is_dir($abs)) {
                json_error('Not a directory.', 400);
            }
            json_ok(folder_size($abs));
            break;

        case 'properties':
            $rel = req_path($_GET);
            $abs = resolve_safe_path($rel, true);
            $stat = stat($abs);
            $isDir = is_dir($abs);
            $ownerInfo = function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid']) : null;
            $groupInfo = function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid']) : null;
            json_ok([
                'name' => basename($abs),
                'path' => $rel,
                'type' => $isDir ? 'folder' : 'file',
                'size' => $isDir ? null : $stat['size'],
                'size_human' => $isDir ? null : format_bytes($stat['size']),
                'modified' => date('Y-m-d H:i:s', $stat['mtime']),
                'owner' => $ownerInfo['name'] ?? (string) $stat['uid'],
                'group' => $groupInfo['name'] ?? (string) $stat['gid'],
                'permissions_octal' => substr(sprintf('%o', fileperms($abs)), -4),
                'permissions_human' => human_perms($stat['mode']),
                'mime' => $isDir ? null : mime_for($abs),
                'extension' => $isDir ? null : get_extension($abs),
                'is_link' => is_link($abs),
            ]);
            break;

        case 'mkdir':
            $rel = req_path($body, 'parent');
            $name = trim((string) ($body['name'] ?? ''));
            safe_name_check($name);
            $parentAbs = resolve_safe_path($rel === '' ? '.' : $rel, true);
            $target = resolve_safe_path(($rel !== '' ? $rel . '/' : '') . $name, false);
            if (file_exists($target)) {
                json_error('A file or folder with that name already exists.', 409);
            }
            if (!mkdir($target, 0750)) {
                json_error('Could not create folder. Check permissions.', 500);
            }
            log_activity('mkdir', to_relative($target));
            json_ok(['path' => to_relative($target)]);
            break;

        case 'newfile':
            $rel = req_path($body, 'parent');
            $name = trim((string) ($body['name'] ?? ''));
            safe_name_check($name);
            if (is_blocked_extension($name)) {
                json_error('This file extension is not allowed.', 403);
            }
            $target = resolve_safe_path(($rel !== '' ? $rel . '/' : '') . $name, false);
            if (file_exists($target)) {
                json_error('A file with that name already exists.', 409);
            }
            if (file_put_contents($target, '') === false) {
                json_error('Could not create file. Check permissions.', 500);
            }
            chmod($target, 0640);
            log_activity('create_file', to_relative($target));
            json_ok(['path' => to_relative($target)]);
            break;

        case 'delete':
            $paths = $body['paths'] ?? [];
            if (!is_array($paths) || empty($paths)) {
                json_error('No paths specified.', 400);
            }
            $deleted = [];
            foreach ($paths as $p) {
                $abs = resolve_safe_path((string) $p, true);
                if ($abs === realpath(STORAGE_ROOT)) {
                    json_error('Cannot delete the storage root.', 403);
                }
                delete_recursive($abs);
                log_activity('delete', (string) $p);
                $deleted[] = $p;
            }
            json_ok(['deleted' => $deleted]);
            break;

        case 'delete_preview':
            // Returns counts for the confirmation dialog without deleting.
            $paths = $body['paths'] ?? [];
            $totalFiles = 0; $totalFolders = 0;
            foreach ($paths as $p) {
                $abs = resolve_safe_path((string) $p, true);
                $c = count_recursive($abs);
                $totalFiles += $c['files'];
                $totalFolders += $c['folders'];
            }
            json_ok(['files' => $totalFiles, 'folders' => $totalFolders]);
            break;

        case 'rename':
            $rel = req_path($body);
            $newName = trim((string) ($body['new_name'] ?? ''));
            safe_name_check($newName);
            $abs = resolve_safe_path($rel, true);
            $newTarget = resolve_safe_path(dirname($rel) === '.' ? $newName : dirname($rel) . '/' . $newName, false);
            if (file_exists($newTarget)) {
                json_error('A file or folder with that name already exists.', 409);
            }
            if (!rename($abs, $newTarget)) {
                json_error('Rename failed. Check permissions.', 500);
            }
            log_activity('rename', "$rel -> " . to_relative($newTarget));
            json_ok(['path' => to_relative($newTarget)]);
            break;

        case 'move':
            $sources = $body['paths'] ?? [];
            $destRel = req_path($body, 'destination');
            $destAbs = resolve_safe_path($destRel === '' ? '.' : $destRel, true);
            if (!is_dir($destAbs)) {
                json_error('Destination must be a folder.', 400);
            }
            $moved = [];
            foreach ($sources as $p) {
                $abs = resolve_safe_path((string) $p, true);
                $target = $destAbs . '/' . basename($abs);
                if (file_exists($target)) {
                    json_error('Destination already contains: ' . basename($abs), 409);
                }
                if (strpos($destAbs . '/', $abs . '/') === 0) {
                    json_error('Cannot move a folder into itself.', 400);
                }
                if (!rename($abs, $target)) {
                    json_error('Move failed for ' . basename($abs), 500);
                }
                log_activity('move', "$p -> " . to_relative($target));
                $moved[] = to_relative($target);
            }
            json_ok(['moved' => $moved]);
            break;

        case 'copy':
            $sources = $body['paths'] ?? [];
            $destRel = req_path($body, 'destination');
            $destAbs = resolve_safe_path($destRel === '' ? '.' : $destRel, true);
            if (!is_dir($destAbs)) {
                json_error('Destination must be a folder.', 400);
            }
            $copied = [];
            foreach ($sources as $p) {
                $abs = resolve_safe_path((string) $p, true);
                $target = $destAbs . '/' . basename($abs);
                $suffix = 1;
                $baseTarget = $target;
                while (file_exists($target)) {
                    $target = $baseTarget . '-copy' . ($suffix > 1 ? $suffix : '');
                    $suffix++;
                }
                copy_recursive($abs, $target);
                log_activity('copy', "$p -> " . to_relative($target));
                $copied[] = to_relative($target);
            }
            json_ok(['copied' => $copied]);
            break;

        case 'duplicate':
            $rel = req_path($body);
            $abs = resolve_safe_path($rel, true);
            $dir = dirname($abs);
            $info = pathinfo($abs);
            $suffix = 1;
            do {
                $candidateName = $info['filename'] . '-copy' . ($suffix > 1 ? $suffix : '') . (isset($info['extension']) ? '.' . $info['extension'] : '');
                $target = $dir . '/' . $candidateName;
                $suffix++;
            } while (file_exists($target));
            copy_recursive($abs, $target);
            log_activity('duplicate', "$rel -> " . to_relative($target));
            json_ok(['path' => to_relative($target)]);
            break;

        case 'chmod':
            $rel = req_path($body);
            $mode = (string) ($body['mode'] ?? '');
            if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
                json_error('Invalid permission value.', 400);
            }
            $abs = resolve_safe_path($rel, true);
            if (!chmod($abs, intval($mode, 8))) {
                json_error('Could not change permissions.', 500);
            }
            log_activity('chmod', "$rel -> $mode");
            json_ok(['path' => $rel, 'mode' => $mode]);
            break;

        case 'recent':
            // Recently modified files, shallow-scanned for performance
            // (walks up to $max entries under root, depth-limited).
            $max = 2000;
            $root = realpath(STORAGE_ROOT);
            $results = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            $iterator->setMaxDepth(3);
            $count = 0;
            foreach ($iterator as $item) {
                if ($item->isDir()) continue;
                $count++;
                if ($count > $max) break;
                $results[] = [
                    'path' => to_relative($item->getPathname()),
                    'name' => $item->getFilename(),
                    'size' => $item->getSize(),
                    'modified' => $item->getMTime(),
                ];
            }
            usort($results, fn($a, $b) => $b['modified'] <=> $a['modified']);
            json_ok(['items' => array_slice($results, 0, 25)]);
            break;

        default:
            json_error('Unknown action.', 400);
    }
} catch (PathSecurityException $e) {
    json_error($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('[files.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
