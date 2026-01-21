<?php
// tools/migrate_sales_reporting.php
header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS sales_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        price INTEGER,
        profile TEXT,
        status TEXT,
        login_time_real TEXT,
        logout_time_real TEXT,
        created_at TEXT,
        updated_at TEXT,
        uptime TEXT,
        bytes_in INTEGER,
        bytes_out INTEGER,
        raw_comment TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS live_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        price INTEGER,
        profile TEXT,
        status TEXT,
        login_time_real TEXT,
        logout_time_real TEXT,
        created_at TEXT,
        updated_at TEXT,
        uptime TEXT,
        bytes_in INTEGER,
        bytes_out INTEGER,
        raw_comment TEXT
    )");

    echo json_encode([
        'status' => 'success',
        'message' => 'sales_history and live_sales tables are ready'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}