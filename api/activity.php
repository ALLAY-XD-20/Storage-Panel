<?php
require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$body = request_body();

try {
    if ($action === 'list') {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
        $stmt = db()->prepare("SELECT username, action, path, ip, created_at FROM activity_log ORDER BY id DESC LIMIT :l");
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_ok(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'clear') {
        db()->exec("DELETE FROM activity_log");
        log_activity('activity_log_cleared');
        json_ok();

    } else {
        json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    error_log('[activity.php] ' . $e->getMessage());
    json_error('An unexpected error occurred.', 500);
}
