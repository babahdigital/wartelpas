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
$meta_key = trim((string)($system_cfg['api_key'] ?? $system_cfg['meta_key'] ?? ''));
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

function table_exists_local(PDO $db, $table) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function table_has_column_local(PDO $db, $table, $column) {
    try {
        $stmt = $db->query("PRAGMA table_info(" . $table . ")");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (strtolower((string)($col['name'] ?? '')) === strtolower($column)) return true;
            }
        }
    } catch (Exception $e) {}
    return false;
}

$payload = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
$req_key = trim((string)($payload['key'] ?? ''));
if ($meta_key !== '' && $req_key !== $meta_key) {
    echo json_encode(['ok' => false, 'message' => 'Kunci tidak valid.']);
    exit;
}
$voucher_code = trim((string)($payload['voucher_code'] ?? $payload['username'] ?? $payload['user'] ?? ''));
$customer_name = trim((string)($payload['customer_name'] ?? $payload['nama'] ?? ''));
$room_name = trim((string)($payload['room'] ?? $payload['kamar'] ?? ''));
$blok_name = trim((string)($payload['blok_name'] ?? $payload['blok'] ?? ''));
$profile_name = trim((string)($payload['profile_name'] ?? $payload['profile'] ?? ''));
$price_raw = trim((string)($payload['price'] ?? $payload['harga'] ?? ''));
$price_val = is_numeric($price_raw) ? (int)$price_raw : 0;
$session_id = trim((string)($payload['session'] ?? ''));

if ($session_id === '') {
    $configFile = $root_dir . '/include/config.php';
    if (file_exists($configFile)) {
        require $configFile;
        if (isset($data) && is_array($data)) {
            foreach ($data as $k => $_v) {
                if ($k !== 'mikhmon') {
                    $session_id = $k;
                    break;
                }
            }
        }
    }
}

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

    if (table_exists_local($db, 'login_history')) {
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_login_history_username ON login_history(username)");
        } catch (Exception $e) {}
    }
    if (table_exists_local($db, 'sales_history')) {
        if (table_has_column_local($db, 'sales_history', 'username') && table_has_column_local($db, 'sales_history', 'sale_date')) {
            try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_history_user_date ON sales_history(username, sale_date)"); } catch (Exception $e) {}
        }
    }
    if (table_exists_local($db, 'live_sales')) {
        if (table_has_column_local($db, 'live_sales', 'username') && table_has_column_local($db, 'live_sales', 'sale_date')) {
            try { $db->exec("CREATE INDEX IF NOT EXISTS idx_live_sales_user_date ON live_sales(username, sale_date)"); } catch (Exception $e) {}
        }
    }

    if (($customer_name !== '' || $room_name !== '') && table_exists_local($db, 'login_history')) {
        $has_customer = table_has_column_local($db, 'login_history', 'customer_name');
        $has_room = table_has_column_local($db, 'login_history', 'room_name');
        $has_blok = table_has_column_local($db, 'login_history', 'blok_name');
        $has_updated = table_has_column_local($db, 'login_history', 'updated_at');
        if ($has_customer || $has_room || $has_blok) {
            $cols = ['username'];
            $vals = [':v'];
            $updates = [];
            if ($has_customer) {
                $cols[] = 'customer_name';
                $vals[] = ':cn';
                $updates[] = "customer_name = CASE WHEN :cn != '' THEN :cn ELSE customer_name END";
            }
            if ($has_room) {
                $cols[] = 'room_name';
                $vals[] = ':rn';
                $updates[] = "room_name = CASE WHEN :rn != '' THEN :rn ELSE room_name END";
            }
            if ($has_blok) {
                $cols[] = 'blok_name';
                $vals[] = ':bn';
                $updates[] = "blok_name = CASE WHEN :bn != '' THEN :bn ELSE blok_name END";
            }
            if ($has_updated) {
                $cols[] = 'updated_at';
                $vals[] = "datetime('now')";
                $updates[] = "updated_at = datetime('now')";
            }
            $sql = "INSERT INTO login_history (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")
                ON CONFLICT(username) DO UPDATE SET " . implode(', ', $updates);
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':v' => $voucher_code,
                ':cn' => $customer_name,
                ':rn' => $room_name,
                ':bn' => $blok_name
            ]);
        }
    }

    if (($customer_name !== '' || $room_name !== '') && table_exists_local($db, 'sales_history')) {
        if (table_has_column_local($db, 'sales_history', 'customer_name') && table_has_column_local($db, 'sales_history', 'room_name')) {
            $stmt = $db->prepare("UPDATE sales_history SET
                customer_name = CASE WHEN :cn != '' THEN :cn ELSE customer_name END,
                room_name = CASE WHEN :rn != '' THEN :rn ELSE room_name END
                WHERE username = :v");
            $stmt->execute([
                ':cn' => $customer_name,
                ':rn' => $room_name,
                ':v' => $voucher_code
            ]);
        }
    }

    if (($customer_name !== '' || $room_name !== '') && table_exists_local($db, 'live_sales')) {
        if (table_has_column_local($db, 'live_sales', 'customer_name') && table_has_column_local($db, 'live_sales', 'room_name')) {
            $stmt = $db->prepare("UPDATE live_sales SET
                customer_name = CASE WHEN :cn != '' THEN :cn ELSE customer_name END,
                room_name = CASE WHEN :rn != '' THEN :rn ELSE room_name END
                WHERE username = :v");
            $stmt->execute([
                ':cn' => $customer_name,
                ':rn' => $room_name,
                ':v' => $voucher_code
            ]);
        }
    }

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan data.']);
}
