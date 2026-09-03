<?php
/**
 * Collects real VPS system statistics from /proc and safe, argument-free
 * shell commands. No user input ever reaches these commands — every
 * call below uses a fixed, hardcoded command string.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/filesystem.php';

function shell_supported(): bool
{
    return function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
}

function safe_shell(string $fixedCommand): ?string
{
    if (!shell_supported()) {
        return null;
    }
    // $fixedCommand is always a hardcoded literal from this file — never
    // built from user input — so no escaping of external args is needed.
    $out = @shell_exec($fixedCommand . ' 2>/dev/null');
    return $out === null ? null : trim($out);
}

function get_disk_stats(): array
{
    $root = STORAGE_ROOT;
    $total = @disk_total_space($root) ?: 0;
    $free  = @disk_free_space($root) ?: 0;
    $used  = max($total - $free, 0);
    $pct   = $total > 0 ? round(($used / $total) * 100, 1) : 0;

    return [
        'total'       => $total,
        'used'        => $used,
        'free'        => $free,
        'percent'     => $pct,
        'total_human' => format_bytes($total),
        'used_human'  => format_bytes($used),
        'free_human'  => format_bytes($free),
    ];
}

function get_cpu_info(): array
{
    $model = 'Unknown';
    $cores = 0;
    $threads = 0;

    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        if (preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $m)) {
            $model = trim($m[1]);
        }
        $threads = substr_count($cpuinfo, 'processor');
        $physIds = [];
        if (preg_match_all('/physical id\s*:\s*(\d+)/', $cpuinfo, $m)) {
            $physIds = array_unique($m[1]);
        }
        $coreCounts = [];
        if (preg_match_all('/cpu cores\s*:\s*(\d+)/', $cpuinfo, $m)) {
            $coreCounts = $m[1];
        }
        $cores = $coreCounts ? (int) max($coreCounts) * max(count($physIds), 1) : $threads;
    }

    return [
        'model'   => $model,
        'cores'   => $cores ?: $threads,
        'threads' => $threads,
    ];
}

/** CPU usage % sampled over a short interval by reading /proc/stat twice. */
function get_cpu_usage_percent(): float
{
    $read = function () {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $line = explode("\n", file_get_contents('/proc/stat'))[0];
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts); // remove "cpu"
        $parts = array_map('floatval', $parts);
        $idle = ($parts[3] ?? 0) + ($parts[4] ?? 0);
        $total = array_sum($parts);
        return [$idle, $total];
    };

    $first = $read();
    if ($first === null) {
        return 0.0;
    }
    usleep(150000);
    $second = $read();

    $idleDelta = $second[0] - $first[0];
    $totalDelta = $second[1] - $first[1];
    if ($totalDelta <= 0) {
        return 0.0;
    }
    return round((1 - ($idleDelta / $totalDelta)) * 100, 1);
}

function get_ram_info(): array
{
    $total = 0; $free = 0; $available = 0; $buffers = 0; $cached = 0;
    if (is_readable('/proc/meminfo')) {
        $mem = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $mem, $m1);
        preg_match('/MemFree:\s+(\d+)/', $mem, $m2);
        preg_match('/MemAvailable:\s+(\d+)/', $mem, $m3);
        preg_match('/Buffers:\s+(\d+)/', $mem, $m4);
        preg_match('/^Cached:\s+(\d+)/m', $mem, $m5);
        $total = isset($m1[1]) ? ((int) $m1[1]) * 1024 : 0;
        $free = isset($m2[1]) ? ((int) $m2[1]) * 1024 : 0;
        $available = isset($m3[1]) ? ((int) $m3[1]) * 1024 : $free;
        $buffers = isset($m4[1]) ? ((int) $m4[1]) * 1024 : 0;
        $cached = isset($m5[1]) ? ((int) $m5[1]) * 1024 : 0;
    }
    $used = max($total - $available, 0);
    $pct = $total > 0 ? round(($used / $total) * 100, 1) : 0;

    return [
        'total'       => $total,
        'used'        => $used,
        'available'   => $available,
        'free'        => $free,
        'buffers'     => $buffers,
        'cached'      => $cached,
        'percent'     => $pct,
        'total_human' => format_bytes($total),
        'used_human'  => format_bytes($used),
        'available_human' => format_bytes($available),
    ];
}

function get_load_average(): array
{
    if (function_exists('sys_getloadavg')) {
        $la = sys_getloadavg();
        if ($la) {
            return ['1min' => round($la[0], 2), '5min' => round($la[1], 2), '15min' => round($la[2], 2)];
        }
    }
    return ['1min' => 0, '5min' => 0, '15min' => 0];
}

function get_uptime_seconds(): int
{
    if (is_readable('/proc/uptime')) {
        $parts = explode(' ', trim(file_get_contents('/proc/uptime')));
        return (int) floatval($parts[0]);
    }
    return 0;
}

function format_uptime(int $seconds): string
{
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $out = [];
    if ($d > 0) $out[] = "{$d}d";
    if ($h > 0) $out[] = "{$h}h";
    $out[] = "{$m}m";
    return implode(' ', $out);
}

function get_os_info(): array
{
    $osName = PHP_OS;
    $prettyName = null;
    if (is_readable('/etc/os-release')) {
        $content = file_get_contents('/etc/os-release');
        if (preg_match('/PRETTY_NAME="?([^"\n]+)"?/', $content, $m)) {
            $prettyName = trim($m[1]);
        }
    }
    return [
        'name'    => $prettyName ?: $osName,
        'kernel'  => php_uname('r'),
        'arch'    => php_uname('m'),
    ];
}

function get_system_snapshot(): array
{
    $disk = get_disk_stats();
    $cpu = get_cpu_info();
    $ram = get_ram_info();
    $os = get_os_info();

    return [
        'cpu' => array_merge($cpu, ['usage_percent' => get_cpu_usage_percent()]),
        'ram' => $ram,
        'disk' => $disk,
        'os' => $os,
        'php_version' => PHP_VERSION,
        'uptime_seconds' => get_uptime_seconds(),
        'uptime_human' => format_uptime(get_uptime_seconds()),
        'load_average' => get_load_average(),
        'hostname' => gethostname() ?: 'unknown',
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? safe_shell("hostname -I | awk '{print \$1}'") ?? '127.0.0.1',
        'current_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
    ];
}
