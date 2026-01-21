<?php
// tools/cleanup_non_wartel_login_history.php
header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $patterns = [
        '%00%',
        '%/%',
        '%.%',
        '%@%',
        '%vlan%',
        '%hotspot%',
        '%@%',
        '%HS%',
        '%IP%',
        '%VM%',
        '%UEFI%',
        '%intern%',
        '%local%',
        '%localhost%',
        '%mikrotik%',
        '%admin%',
        '%operator%',
        '%scheduler%',
        '%user@%',
        '%test%',
        '%trial%',
        '%debug%',
        '%system%',
        '%pppoe%',
        '%cctv%',
        '%wifi%',
        '%wa%',
        '%download%',
        '%upload%',
        '%wa-',
        '%wash%',
        '%beta%',
        '%backup%',
        '%_tmp%'
    ];

    $clauses = [];
    $params = [];
    foreach ($patterns as $pattern) {
        $clauses[] = "username LIKE ?";
        $params[] = $pattern;
    }

    $where = implode(' OR ', $clauses);

    $stmt = $db->prepare("SELECT COUNT(1) FROM login_history WHERE $where");
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();

    $del = $db->prepare("DELETE FROM login_history WHERE $where");
    $del->execute($params);
    $deleted = $del->rowCount();

    echo json_encode([
        'status' => 'success',
        'matched' => $count,
        'deleted' => $deleted
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}