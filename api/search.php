<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

try {
    $rel = req_path($_GET);
    $query = trim((string) ($_GET['q'] ?? ''));
    $ext = trim((string) ($_GET['ext'] ?? ''));
    $recursive = ($_GET['recursive'] ?? '1') === '1';
    $sort = $_GET['sort'] ?? 'name';
    $order = $_GET['order'] ?? 'asc';
    $maxResults = 500;

    if ($query === '' && $ext === '') {
        json_error('Provide a search term or extension.', 400);
    }

    $abs = resolve_safe_path($rel === '' ? '.' : $rel, true);
    if (!is_dir($abs)) {
        json_error('Not a directory.', 400);
    }

    $results = [];
    $queryLower = mb_strtolower($query);
    $extLower = ltrim(mb_strtolower($ext), '.');

    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } else {
        $iterator = new DirectoryIterator($abs);
    }

    $count = 0;
    foreach ($iterator as $item) {
        if ($item->isDot()) continue;
        $count++;
        if ($count > 100000) break; // safety valve

        $name = $item->getFilename();
        $nameLower = mb_strtolower($name);

        if ($query !== '' && strpos($nameLower, $queryLower) === false) {
            continue;
        }
        if ($extLower !== '') {
            $itemExt = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($itemExt !== $extLower) {
                continue;
            }
        }

        $itemPath = $item->getPathname();
        $results[] = [
            'name' => $name,
            'type' => $item->isDir() ? 'folder' : 'file',
            'size' => $item->isDir() ? 0 : $item->getSize(),
            'path' => to_relative($itemPath),
            'modified' => $item->getMTime(),
        ];

        if (count($results) >= $maxResults) break;
    }

    usort($results, function ($a, $b) use ($sort) {
        $key = in_array($sort, ['name', 'size', 'modified'], true) ? $sort : 'name';
        if (is_string($a[$key])) {
            return strcasecmp($a[$key], $b[$key]);
        }
        return $a[$key] <=> $b[$key];
    });
    if ($order === 'desc') {
        $results = array_reverse($results);
    }

    json_ok(['items' => $results, 'total' => count($results), 'truncated' => $count > 100000]);

} catch (PathSecurityException $e) {
    json_error($e->getMessage(), 403);
} catch (Throwable $e) {
    error_log('[search.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
