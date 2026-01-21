<?php
// tools/cleanup_duplicate_sales.php

header('Content-Type: application/json');
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Find duplicate rows by username + login_time
    $dupRows = $db->query("
        SELECT username, login_time_real, COUNT(*) AS cnt
        FROM sales_history
        GROUP BY username, login_time_real
        HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $deleted = 0;
    foreach ($dupRows as $row) {
        $username = $row['username'];
        $loginTime = $row['login_time_real'];

        // keep one row, delete others
        $stmt = $db->prepare("
            DELETE FROM sales_history
            WHERE rowid IN (
                SELECT rowid FROM sales_history
                WHERE username = ? AND login_time_real = ?
                LIMIT ?
            )
        ");
        // delete count-1 rows
        $toDelete = max(0, (int)$row['cnt'] - 1);
        if ($toDelete > 0) {
            $stmt->execute([$username, $loginTime, $toDelete]);
            $deleted += $toDelete;
        }
    }

    echo json_encode([
        'status' => 'success',
        'duplicates' => $dupRows,
        'deleted_rows' => $deleted
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}