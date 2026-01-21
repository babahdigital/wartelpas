<?php
// tools/clear_logs.php
// Clears both security_log and login_history tables

header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("DELETE FROM security_log");
    $db->exec("DELETE FROM login_history");

    echo json_encode([
        'status' => 'success',
        'message' => 'security_log and login_history tables cleared'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}