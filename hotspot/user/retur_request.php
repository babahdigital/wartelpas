<?php
// --- TAMBAHKAN HEADER INI (Wajib untuk Bypass CORS) ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

// Jika browser melakukan pre-check (OPTIONS), langsung jawab OK
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$wa_cfg = $env['whatsapp'] ?? [];
$helperFile = $root_dir . '/hotspot/user/helpers.php';
if (file_exists($helperFile)) {
    require_once $helperFile;
}
$waHelper = $root_dir . '/system/whatsapp/wa_helper.php';
if (file_exists($waHelper)) {
    require_once $waHelper;
}
$system_cfg = $env['system'] ?? [];
$retur_cfg = $env['retur_request'] ?? [];
$retur_enabled = !isset($retur_cfg['enabled']) || $retur_cfg['enabled'] === true || $retur_cfg['enabled'] === 1 || $retur_cfg['enabled'] === '1';
$retur_message = trim((string)($retur_cfg['message'] ?? ''));
$retur_message = $retur_message !== '' ? $retur_message : 'Fitur retur sedang dimatikan. Silakan hubungi operator.';
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
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

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Metode tidak valid.']);
    exit;
}

if (!$retur_enabled) {
    echo json_encode(['ok' => false, 'message' => $retur_message]);
    exit;
}

$payload = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
$session_param = trim((string)($payload['session'] ?? ''));
$session_param = $session_param !== '' ? $session_param : '';
$request_type = trim((string)($payload['request_type'] ?? 'retur'));
$request_type = in_array($request_type, ['retur', 'pengembalian'], true) ? $request_type : 'retur';
$voucher_code = trim((string)($payload['voucher_code'] ?? ''));
$reason = trim((string)($payload['reason'] ?? ''));
$contact_phone = trim((string)($payload['contact_phone'] ?? ''));
$blok_name = trim((string)($payload['blok_name'] ?? ''));
$customer_name = trim((string)($payload['user_name'] ?? $payload['customer_name'] ?? ''));
$profile_name = trim((string)($payload['profile_name'] ?? $payload['profile'] ?? ''));

if ($session_param === '') {
    $configFile = $root_dir . '/include/config.php';
    if (file_exists($configFile)) {
        require $configFile;
        if (isset($data) && is_array($data)) {
            foreach ($data as $k => $_v) {
                if ($k !== 'mikhmon') {
                    $session_param = $k;
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
if ($reason === '' || strlen($reason) < 5 || strlen($reason) > 200) {
    echo json_encode(['ok' => false, 'message' => 'Alasan minimal 5 karakter.']);
    exit;
}
if ($request_type === 'pengembalian' && (strlen($customer_name) < 2 || strlen($customer_name) > 80)) {
    echo json_encode(['ok' => false, 'message' => 'Nama lengkap minimal 2 karakter.']);
    exit;
}
if ($contact_phone !== '' && strlen($contact_phone) > 20) {
    $contact_phone = substr($contact_phone, 0, 20);
}
if ($blok_name !== '' && strlen($blok_name) > 30) {
    $blok_name = substr($blok_name, 0, 30);
}
if ($customer_name !== '' && strlen($customer_name) > 80) {
    $customer_name = substr($customer_name, 0, 80);
}
if ($profile_name !== '' && strlen($profile_name) > 40) {
    $profile_name = substr($profile_name, 0, 40);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS retur_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        request_date TEXT,
        voucher_code TEXT,
        blok_name TEXT,
        request_type TEXT DEFAULT 'retur',
        customer_name TEXT,
        reason TEXT,
        contact_phone TEXT,
        status TEXT DEFAULT 'pending',
        reviewed_by TEXT,
        reviewed_at DATETIME,
        review_note TEXT,
        router_name TEXT,
        source TEXT DEFAULT 'portal'
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_retur_requests_status_date ON retur_requests(status, request_date)");

    $cols = $db->query("PRAGMA table_info(retur_requests)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $col_names = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    if (!in_array('request_type', $col_names, true)) {
        $db->exec("ALTER TABLE retur_requests ADD COLUMN request_type TEXT DEFAULT 'retur'");
    }
    if (!in_array('customer_name', $col_names, true)) {
        $db->exec("ALTER TABLE retur_requests ADD COLUMN customer_name TEXT");
    }

    $today = date('Y-m-d');
    $voucher_date = '';
    try {
        if (table_exists_local($db, 'sales_history')) {
            $stmt = $db->prepare("SELECT sale_date, raw_date FROM sales_history WHERE username = :u ORDER BY sale_date DESC, raw_date DESC LIMIT 1");
            $stmt->execute([':u' => $voucher_code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $voucher_date = norm_date_from_raw_report($row['sale_date'] ?? '');
                if ($voucher_date === '') $voucher_date = norm_date_from_raw_report($row['raw_date'] ?? '');
            }
        }
        if ($voucher_date === '' && table_exists_local($db, 'live_sales')) {
            $stmt = $db->prepare("SELECT sale_date, raw_date FROM live_sales WHERE username = :u ORDER BY sale_date DESC, raw_date DESC LIMIT 1");
            $stmt->execute([':u' => $voucher_code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $voucher_date = norm_date_from_raw_report($row['sale_date'] ?? '');
                if ($voucher_date === '') $voucher_date = norm_date_from_raw_report($row['raw_date'] ?? '');
            }
        }
        if ($voucher_date === '' && table_exists_local($db, 'login_history')) {
            $stmt = $db->prepare("SELECT login_date, login_time_real, last_login_real, logout_time_real, updated_at FROM login_history WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $voucher_code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $voucher_date = norm_date_from_raw_report($row['login_date'] ?? '');
                if ($voucher_date === '' && !empty($row['login_time_real'])) $voucher_date = substr((string)$row['login_time_real'], 0, 10);
                if ($voucher_date === '' && !empty($row['last_login_real'])) $voucher_date = substr((string)$row['last_login_real'], 0, 10);
                if ($voucher_date === '' && !empty($row['logout_time_real'])) $voucher_date = substr((string)$row['logout_time_real'], 0, 10);
                if ($voucher_date === '' && !empty($row['updated_at'])) $voucher_date = substr((string)$row['updated_at'], 0, 10);
            }
        }
    } catch (Exception $e) {}

    if ($voucher_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucher_date) && $voucher_date < $today) {
        echo json_encode(['ok' => false, 'message' => 'Tidak berlaku untuk voucher yang lama. Silahkan ajukan voucher pada hari yang sama.']);
        exit;
    }

    $hist = null;
    try {
        $stmt = $db->prepare("SELECT blok_name, raw_comment, validity FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $voucher_code]);
        $hist = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}

    $raw_comment = $hist['raw_comment'] ?? '';
    if ($blok_name === '' && !empty($hist['blok_name'])) {
        $blok_name = (string)$hist['blok_name'];
    }
    if ($blok_name === '' && $raw_comment !== '' && function_exists('extract_blok_name')) {
        $blok_name = extract_blok_name($raw_comment);
    }
    $blok_short = '';
    if ($blok_name !== '' && function_exists('normalize_blok_label')) {
        $blok_short = normalize_blok_label($blok_name);
    }
    if ($blok_short === '' && $blok_name !== '') {
        $blok_short = strtoupper(preg_replace('/[^A-Z0-9]/', '', $blok_name));
        $blok_short = preg_replace('/^BLOK/', '', $blok_short);
    }

    $profile_label = '';
    if (!empty($hist['validity']) && function_exists('normalize_profile_label')) {
        $profile_label = normalize_profile_label($hist['validity']);
    }
    if ($profile_label === '' && $raw_comment !== '' && preg_match('/\bprofile\s*:\s*([^|]+)/i', $raw_comment, $m)) {
        $profile_label = function_exists('normalize_profile_label') ? normalize_profile_label($m[1]) : trim((string)$m[1]);
    }
    if ($profile_label === '' && !empty($hist['profile_name']) && function_exists('normalize_profile_label')) {
        $profile_label = normalize_profile_label($hist['profile_name']);
    }

    if ($blok_short === '' || $profile_label === '') {
        $router_user = null;
        $configFile = $root_dir . '/include/config.php';
        $apiFile = $root_dir . '/lib/routeros_api.class.php';
        if (file_exists($configFile) && file_exists($apiFile)) {
            require_once $configFile;
            require_once $apiFile;
            $session_id = $session_param;
            if ($session_id === '' && isset($data) && is_array($data)) {
                foreach ($data as $k => $v) {
                    if ($k !== 'mikhmon') {
                        $session_id = $k;
                        break;
                    }
                }
            }
            if ($session_id !== '' && isset($data[$session_id])) {
                $iphost = explode('!', $data[$session_id][1])[1] ?? '';
                $userhost = explode('@|@', $data[$session_id][2])[1] ?? '';
                $passwdhost = explode('#|#', $data[$session_id][3])[1] ?? '';
                $hotspot_server = isset($data[$session_id][12]) ? explode('~', $data[$session_id][12])[1] : 'wartel';
                if (!empty($env['system']['hotspot_server'])) {
                    $hotspot_server = (string)$env['system']['hotspot_server'];
                }
                if ($iphost !== '' && $userhost !== '' && $passwdhost !== '') {
                    $API = new RouterosAPI();
                    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
                        $params = ['?name' => $voucher_code];
                        if ($hotspot_server !== '') {
                            $params['?server'] = $hotspot_server;
                        }
                        $res = $API->comm('/ip/hotspot/user/print', $params);
                        if (is_array($res) && count($res) > 0) {
                            $router_user = $res[0];
                        }
                        $API->disconnect();
                    }
                }
            }
        }

        if ($router_user) {
            $router_comment = trim((string)($router_user['comment'] ?? ''));
            if ($blok_name === '' && $router_comment !== '' && function_exists('extract_blok_name')) {
                $blok_name = extract_blok_name($router_comment);
            }
            if ($blok_short === '' && $blok_name !== '' && function_exists('normalize_blok_label')) {
                $blok_short = normalize_blok_label($blok_name);
            }
            if ($profile_label === '' && !empty($router_user['profile']) && function_exists('normalize_profile_label')) {
                $profile_label = normalize_profile_label($router_user['profile']);
            }
        }
    }

    if ($blok_short === '' || $profile_label === '') {
        echo json_encode(['ok' => false, 'message' => 'Blok/Profil tidak ditemukan. Pastikan voucher terdaftar di sistem.']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO retur_requests (request_date, voucher_code, blok_name, request_type, customer_name, reason, contact_phone, status, source)
        VALUES (:d, :v, :b, :t, :n, :r, :p, 'pending', 'portal')");
    $stmt->execute([
        ':d' => date('Y-m-d'),
        ':v' => $voucher_code,
        ':b' => $blok_name,
        ':t' => $request_type,
        ':n' => $customer_name,
        ':r' => $reason,
        ':p' => $contact_phone
    ]);

    if (function_exists('wa_send_text')) {
        $type_label = $request_type === 'pengembalian' ? 'REFUND' : 'RETUR';
        $notify_all = !isset($wa_cfg['notify_request_enabled']) || $wa_cfg['notify_request_enabled'] === true || $wa_cfg['notify_request_enabled'] === 1 || $wa_cfg['notify_request_enabled'] === '1';
        $notify_refund = !isset($wa_cfg['notify_refund_enabled']) || $wa_cfg['notify_refund_enabled'] === true || $wa_cfg['notify_refund_enabled'] === 1 || $wa_cfg['notify_refund_enabled'] === '1';
        $notify_retur = !isset($wa_cfg['notify_retur_enabled']) || $wa_cfg['notify_retur_enabled'] === true || $wa_cfg['notify_retur_enabled'] === 1 || $wa_cfg['notify_retur_enabled'] === '1';
        $should_send = $notify_all && (($type_label === 'REFUND') ? $notify_refund : $notify_retur);
        if ($should_send) {
            $name_label = $customer_name !== '' ? $customer_name : '-';
            $contact_label = $contact_phone !== '' ? $contact_phone : '-';
            $reason_msg = str_replace('"', "'", $reason);
            $line = '────────────';
            $msg = "🔔 *PERMINTAAN " . $type_label . " BARU*\n" .
                $line . "\n" .
                "Status: ⏳ *PENDING*\n\n" .
                "👤 *Data Pengguna*\n" .
                "• Nama : " . $name_label . "\n" .
                "• Blok : " . $blok_short . "\n" .
                "• Profil : " . $profile_label . "\n\n" .
                "🎫 *Detail Tiket*\n" .
                "• Voucher : *`" . $voucher_code . "`*\n" .
                "• Alasan : _\"" . $reason_msg . "\"_\n\n" .
                $line . "\n" .
                "Mohon segera diverifikasi melalui dashboard admin.";
            wa_send_text($msg, '', 'retur');
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Permintaan retur berhasil dikirim.']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan permintaan.']);
}