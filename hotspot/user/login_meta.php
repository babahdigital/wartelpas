<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['ok' => true]);
    exit;
}

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Metode tidak valid.']);
    exit;
}

$payload = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
$voucher_code = trim((string)($payload['voucher_code'] ?? $payload['username'] ?? $payload['user'] ?? ''));
$customer_name = trim((string)($payload['customer_name'] ?? $payload['nama'] ?? ''));
$room_name = trim((string)($payload['room'] ?? $payload['kamar'] ?? ''));
$blok_name = trim((string)($payload['blok_name'] ?? $payload['blok'] ?? ''));
$profile_name = trim((string)($payload['profile_name'] ?? $payload['profile'] ?? ''));
$price_raw = trim((string)($payload['price'] ?? $payload['harga'] ?? ''));
$price_val = is_numeric($price_raw) ? (int)$price_raw : 0;
$session_id = trim((string)($payload['session'] ?? ''));

if ($voucher_code === '' || strlen($voucher_code) < 3 || strlen($voucher_code) > 64) {
    echo json_encode(['ok' => false, 'message' => 'Kode voucher tidak valid.']);
    exit;
}
if ($customer_name !== '' && strlen($customer_name) > 80) {
    $customer_name = substr($customer_name, 0, 80);
}
if ($room_name !== '' && strlen($room_name) > 40) {
    $room_name = substr($room_name, 0, 40);
}
if ($blok_name !== '' && strlen($blok_name) > 40) {
    $blok_name = substr($blok_name, 0, 40);
}
if ($profile_name !== '' && strlen($profile_name) > 40) {
    $profile_name = substr($profile_name, 0, 40);
}
if ($price_val < 0) {
    $price_val = 0;
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($user_agent !== '' && strlen($user_agent) > 160) {
    $user_agent = substr($user_agent, 0, 160);
}

try {
    $dbDir = dirname($dbFile);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }

    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");

    $db->exec("CREATE TABLE IF NOT EXISTS login_meta_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_code TEXT,
        customer_name TEXT,
        room_name TEXT,
        blok_name TEXT,
        profile_name TEXT,
        price INTEGER,
        session_id TEXT,
        client_ip TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME,
        consumed_by TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_meta_queue_voucher ON login_meta_queue(voucher_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_meta_queue_created ON login_meta_queue(created_at)");
    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN blok_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN profile_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN price INTEGER"); } catch (Exception $e) {}

    $stmt = $db->prepare("INSERT INTO login_meta_queue (voucher_code, customer_name, room_name, blok_name, profile_name, price, session_id, client_ip, user_agent)
        VALUES (:v, :cn, :rn, :bn, :pn, :pr, :sid, :ip, :ua)");
    $stmt->execute([
        ':v' => $voucher_code,
        ':cn' => $customer_name,
        ':rn' => $room_name,
        ':bn' => $blok_name,
        ':pn' => $profile_name,
        ':pr' => $price_val,
        ':sid' => $session_id,
        ':ip' => $client_ip,
        ':ua' => $user_agent
    ]);

    try {
        $db->exec("DELETE FROM login_meta_queue WHERE created_at < datetime('now','-7 day')");
    } catch (Exception $e) {}

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan data.']);
}
