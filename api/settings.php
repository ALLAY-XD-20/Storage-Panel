<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$body = request_body();

$editableKeys = ['max_upload_mb', 'allowed_extensions', 'blocked_extensions', 'session_timeout', 'theme', 'auto_refresh_interval'];

try {
    if ($action === 'get') {
        $settings = [];
        foreach (array_merge(['storage_root'], $editableKeys) as $key) {
            $settings[$key] = setting_get($key);
        }
        $settings['php'] = [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'max_execution_time'  => ini_get('max_execution_time'),
            'memory_limit'        => ini_get('memory_limit'),
        ];
        json_ok(['settings' => $settings]);

    } elseif ($action === 'update') {
        foreach ($editableKeys as $key) {
            if (isset($body[$key])) {
                $value = (string) $body[$key];
                if ($key === 'max_upload_mb' && (!is_numeric($value) || (int) $value <= 0)) {
                    json_error('Max upload size must be a positive number.', 400);
                }
                if ($key === 'session_timeout' && (!is_numeric($value) || (int) $value < 60)) {
                    json_error('Session timeout must be at least 60 seconds.', 400);
                }
                setting_set($key, $value);
            }
        }
        log_activity('settings_update');
        json_ok();

    } else {
        json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    error_log('[settings.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
