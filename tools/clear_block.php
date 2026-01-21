<?php
// tools/clear_block.php
// Clears phone_block_daily table

header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("DELETE FROM phone_block_daily");

    echo json_encode([
        'status' => 'success',
        'message' => 'phone_block_daily table cleared'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}