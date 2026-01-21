<?php
// tools/delete_user.php
// Hapus data user tertentu dari database (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$secret_token = "WartelpasSecureKey";
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
if ($key === '' || !hash_equals($secret_token, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token Salah.']);
    exit;
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

$confirm = strtoupper(trim((string)($_GET['confirm'] ?? ($_POST['confirm'] ?? ''))));
if ($confirm !== 'YES') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Tambahkan confirm=YES untuk menjalankan hapus.']);
    exit;
}

$root_dir = dirname(__DIR__);
require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak terdaftar.']);
    exit;
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Hanya untuk server wartel.']);
    exit;
}

$username = trim((string)($_GET['user'] ?? ($_POST['user'] ?? '38fpc9')));
if ($username === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'User kosong.']);
    exit;
}

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB not found']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $db->beginTransaction();

    $tables = [
        'login_history' => 'username',
        'login_events' => 'username',
        'sales_history' => 'username',
        'live_sales' => 'username'
    ];

    $deleted = [];
    foreach ($tables as $table => $col) {
        try {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE {$col} = :u");
            $stmt->execute([':u' => $username]);
            $deleted[$table] = $stmt->rowCount();
        } catch (Exception $e) {
            $deleted[$table] = 0;
        }
    }

    $db->commit();

    echo json_encode([
        'ok' => true,
        'user' => $username,
        'deleted' => $deleted
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB error']);
}
