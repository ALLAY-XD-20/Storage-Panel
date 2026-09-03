<?php
/**
 * Filesystem access layer.
 *
 * EVERY function here that touches disk takes a "relative path" (as
 * seen by the browser, e.g. "websites/example.com/index.html") and
 * resolves it against STORAGE_ROOT. resolve_safe_path() is the single
 * choke point that must be used before any read/write/delete/rename
 * operation — it blocks path traversal, null bytes, absolute-path
 * injection, and symlink escapes.
 */

require_once __DIR__ . '/../config.php';

class PathSecurityException extends Exception {}

/**
 * Resolve a user-supplied relative path against STORAGE_ROOT and
 * guarantee the result is inside STORAGE_ROOT. Throws on any attempt
 * to escape the jail, including via symlinks.
 *
 * @param string $relative   Path relative to STORAGE_ROOT, using '/' separators.
 * @param bool   $mustExist  If true, throws when the resolved path does not exist.
 */
function resolve_safe_path(string $relative, bool $mustExist = true): string
{
    // Reject null bytes outright (classic null-byte injection).
    if (strpos($relative, "\0") !== false) {
        throw new PathSecurityException('Invalid path.');
    }

    // Normalize slashes, strip leading slashes so it's always relative.
    $relative = str_replace('\\', '/', $relative);
    $relative = ltrim($relative, '/');

    // Decode a single layer of URL-encoding defensively (routed input
    // should already be decoded by PHP, but this guards against
    // double-encoded traversal sequences).
    $decoded = rawurldecode($relative);
    if (strpos($decoded, "\0") !== false) {
        throw new PathSecurityException('Invalid path.');
    }

    $root = realpath(STORAGE_ROOT);
    if ($root === false) {
        throw new PathSecurityException('Storage root is not accessible.');
    }

    $candidate = $root . '/' . $decoded;

    // Collapse '.' and '..' segments manually so we can validate a
    // path that does not exist yet (realpath() returns false for
    // nonexistent paths, which we need for "new file" operations).
    $parts = [];
    foreach (explode('/', $candidate) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }
    $normalized = '/' . implode('/', $parts);

    // The normalized path must sit inside the root.
    if ($normalized !== $root && strpos($normalized, $root . '/') !== 0) {
        throw new PathSecurityException('Access outside storage root is not allowed.');
    }

    if (file_exists($normalized)) {
        // Resolve symlinks and re-verify: prevents symlink escape.
        $real = realpath($normalized);
        if ($real === false) {
            throw new PathSecurityException('Unable to resolve path.');
        }
        if ($real !== $root && strpos($real, $root . '/') !== 0) {
            throw new PathSecurityException('Symlink escapes storage root.');
        }
        return $real;
    }

    if ($mustExist) {
        throw new PathSecurityException('File or folder not found.');
    }

    // For new paths, verify the parent directory is safely inside root.
    $parentReal = realpath(dirname($normalized));
    if ($parentReal === false || ($parentReal !== $root && strpos($parentReal, $root . '/') !== 0)) {
        throw new PathSecurityException('Invalid destination path.');
    }

    return $normalized;
}

/** Converts an absolute (already-validated) path back to a root-relative display path. */
function to_relative(string $absolute): string
{
    $root = realpath(STORAGE_ROOT);
    $rel = substr($absolute, strlen($root));
    return ltrim($rel, '/');
}

function safe_name_check(string $name): void
{
    if ($name === '' || $name === '.' || $name === '..') {
        throw new PathSecurityException('Invalid name.');
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, "\0") !== false) {
        throw new PathSecurityException('Name cannot contain path separators.');
    }
}

function get_extension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function is_blocked_extension(string $filename): bool
{
    $ext = get_extension($filename);
    $blocked = array_filter(array_map('trim', explode(',', strtolower(setting_get('blocked_extensions', DEFAULT_BLOCKED_EXTENSIONS)))));
    return in_array($ext, $blocked, true);
}

function is_allowed_extension(string $filename): bool
{
    if (is_blocked_extension($filename)) {
        return false;
    }
    $allowed = trim(setting_get('allowed_extensions', DEFAULT_ALLOWED_EXTENSIONS));
    if ($allowed === '' || $allowed === '*') {
        return true;
    }
    $ext = get_extension($filename);
    $list = array_filter(array_map('trim', explode(',', strtolower($allowed))));
    return in_array($ext, $list, true);
}

/** Lists a single directory (non-recursive), paginated. */
function list_directory(string $absoluteDir, int $offset = 0, int $limit = 200, string $sort = 'name', string $order = 'asc'): array
{
    $entries = [];
    $dh = opendir($absoluteDir);
    if ($dh === false) {
        throw new RuntimeException('Unable to read directory.');
    }
    while (($item = readdir($dh)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $absoluteDir . '/' . $item;
        $isDir = is_dir($full);
        $stat = @stat($full);
        $entries[] = [
            'name'      => $item,
            'type'      => $isDir ? 'folder' : 'file',
            'size'      => $isDir ? 0 : ($stat['size'] ?? 0),
            'modified'  => $stat['mtime'] ?? 0,
            'ext'       => $isDir ? '' : get_extension($item),
            'perms'     => $stat ? substr(sprintf('%o', fileperms($full)), -4) : '0000',
            'is_link'   => is_link($full),
        ];
    }
    closedir($dh);

    $sortKey = in_array($sort, ['name', 'size', 'modified', 'type'], true) ? $sort : 'name';
    usort($entries, function ($a, $b) use ($sortKey) {
        // Folders first, always
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }
        if (is_string($a[$sortKey])) {
            return strcasecmp($a[$sortKey], $b[$sortKey]);
        }
        return $a[$sortKey] <=> $b[$sortKey];
    });
    if ($order === 'desc') {
        // Keep folders-first grouping but reverse within groups
        $folders = array_values(array_filter($entries, fn($e) => $e['type'] === 'folder'));
        $files   = array_values(array_filter($entries, fn($e) => $e['type'] === 'file'));
        $entries = array_merge(array_reverse($folders), array_reverse($files));
    }

    $total = count($entries);
    $page = array_slice($entries, $offset, $limit);

    return ['items' => $page, 'total' => $total];
}

/** Recursively computes a folder's size. Callers should cache/limit use — this walks disk. */
function folder_size(string $absoluteDir, int $maxEntries = 200000): array
{
    $size = 0;
    $files = 0;
    $folders = 0;
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $count++;
        if ($count > $maxEntries) {
            break; // safety valve on huge trees
        }
        if ($item->isDir()) {
            $folders++;
        } else {
            $files++;
            $size += $item->getSize();
        }
    }
    return ['size' => $size, 'files' => $files, 'folders' => $folders, 'truncated' => $count > $maxEntries];
}

function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function human_perms(int $octal): string
{
    $perms = str_pad(decoct($octal & 0777), 3, '0', STR_PAD_LEFT);
    $map = ['0' => '---', '1' => '--x', '2' => '-w-', '3' => '-wx', '4' => 'r--', '5' => 'r-x', '6' => 'rw-', '7' => 'rwx'];
    $out = '';
    foreach (str_split($perms) as $digit) {
        $out .= $map[$digit] ?? '---';
    }
    return $out;
}

/** Copies a file or directory tree recursively, staying within STORAGE_ROOT (caller pre-validates both paths). */
function copy_recursive(string $src, string $dst): void
{
    if (is_dir($src)) {
        if (!is_dir($dst)) {
            mkdir($dst, 0750, true);
        }
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            copy_recursive($src . '/' . $item, $dst . '/' . $item);
        }
    } else {
        copy($src, $dst);
    }
}

/** Deletes a file or directory tree recursively. */
function delete_recursive(string $path): array
{
    $files = 0;
    $folders = 0;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $r = delete_recursive($path . '/' . $item);
            $files += $r['files'];
            $folders += $r['folders'];
        }
        rmdir($path);
        $folders++;
    } else {
        unlink($path);
        $files++;
    }
    return ['files' => $files, 'folders' => $folders];
}

/** Counts items in a subtree without full size computation (used for delete confirmation). */
function count_recursive(string $path): array
{
    if (!is_dir($path) || is_link($path)) {
        return ['files' => 1, 'folders' => 0];
    }
    $files = 0;
    $folders = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $folders++;
        } else {
            $files++;
        }
    }
    return ['files' => $files, 'folders' => $folders];
}
