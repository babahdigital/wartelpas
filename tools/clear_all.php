<?php
// tools/clear_all.php
// Clear multiple tables at once

header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

$tables = [
    'security_log',
    'login_history',
    'login_events',
    'sales_history',
    'live_sales',
    'phone_block_daily',
    'settlement_log'
];

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($tables as $table) {
        $db->exec("DELETE FROM $table");
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'All tables cleared',
        'tables' => $tables
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}