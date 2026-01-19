<?php
/**
 * Endpoint untuk save logout time via Ajax
 * Dipanggil saat Ajax mendeteksi user logout
 */
session_start();
if (!isset($_SESSION["mikhmon"])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

require '../include/readcfg.php';

// Koneksi database
$db = null;
try {
    $db = new PDO("sqlite:../db_data/mikhmon_stats.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => 'Database error']));
}

// Validasi input
if (!isset($_POST['username']) || empty($_POST['username'])) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Username required']));
}

$username = $_POST['username'];
$logout_time = date('Y-m-d H:i:s');

try {
    // Update logout time di database
    $stmt = $db->prepare("UPDATE login_history SET logout_time_real = :lor, last_status = 'terpakai', updated_at = :upd WHERE username = :u");
    $stmt->execute([
        ':lor' => $logout_time,
        ':upd' => $logout_time,
        ':u' => $username
    ]);
    
    echo json_encode([
        'success' => true,
        'username' => $username,
        'logout_time' => $logout_time,
        'formatted' => date('d-m-Y H:i:s', strtotime($logout_time))
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
