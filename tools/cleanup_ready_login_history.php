<?php
// tools/cleanup_ready_login_history.php
header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("DELETE FROM login_history WHERE username = ?");
    $stmt->execute(['READY']);

    echo json_encode([
        'status' => 'success',
        'deleted' => $stmt->rowCount()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}