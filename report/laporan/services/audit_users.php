<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/report/laporan/helpers.php';

$pricing = $env['pricing'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$profile_price_map = $pricing['profile_prices'] ?? [];
$GLOBALS['price10'] = $price10;
$GLOBALS['price30'] = $price30;
$GLOBALS['profile_price_map'] = $profile_price_map;

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

$date = trim($_GET['date'] ?? '');
$blok_raw = trim($_GET['blok'] ?? '');
if ($date === '' || $blok_raw === '') {
    echo json_encode(['ok' => false, 'message' => 'Tanggal dan blok wajib diisi.']);
    exit;
}

$blok_norm = normalize_block_name($blok_raw);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $rows = [];
    if (table_exists($db, 'sales_history')) {
        $stmt = $db->prepare("SELECT sale_date, sale_time, sale_datetime, username, profile, profile_snapshot, price, price_snapshot, sprice_snapshot, validity, comment, blok_name, status, is_invalid, is_retur, is_rusak FROM sales_history WHERE sale_date = :d");
        $stmt->execute([':d' => $date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if (table_exists($db, 'live_sales')) {
        $stmt = $db->prepare("SELECT sale_date, sale_time, sale_datetime, username, profile, profile_snapshot, price, price_snapshot, sprice_snapshot, validity, comment, blok_name, status, is_invalid, is_retur, is_rusak FROM live_sales WHERE sale_date = :d AND sync_status = 'pending'");
        $stmt->execute([':d' => $date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    $user_latest = [];
    foreach ($rows as $r) {
        $uname = trim((string)($r['username'] ?? ''));
        if ($uname === '') continue;
        if (is_vip_comment($r['comment'] ?? '')) continue;
        $dt = (string)($r['sale_datetime'] ?? '');
        if ($dt === '') {
            $sd = (string)($r['sale_date'] ?? $date);
            $st = (string)($r['sale_time'] ?? '');
            $dt = trim($sd . ' ' . $st);
        }
        $ts = strtotime($dt);
        if (!isset($user_latest[$uname]) || ($ts && $ts > ($user_latest[$uname]['_ts'] ?? 0))) {
            $r['_ts'] = $ts ?: 0;
            $user_latest[$uname] = $r;
        }
    }

    $usernames = array_keys($user_latest);
    $login_map = [];
    if (!empty($usernames) && table_exists($db, 'login_history')) {
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        $stmt = $db->prepare("SELECT username, last_uptime, last_bytes, last_status, validity, raw_comment FROM login_history WHERE username IN ($placeholders) ORDER BY id DESC");
        $stmt->execute($usernames);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lh) {
            $u = (string)($lh['username'] ?? '');
            if ($u !== '' && !isset($login_map[$u])) {
                if (!is_vip_comment($lh['raw_comment'] ?? '')) {
                    $login_map[$u] = $lh;
                }
            }
        }
    }

    if (table_exists($db, 'login_history')) {
        $stmtLH = $db->prepare("SELECT username, last_uptime, last_bytes, last_status, validity, raw_comment,
            COALESCE(NULLIF(login_time_real,''), NULLIF(last_login_real,''), NULLIF(logout_time_real,''), NULLIF(updated_at,'')) AS dt
            FROM login_history
            WHERE username != ''
                AND (
                    substr(login_time_real,1,10) = :d OR
                    substr(last_login_real,1,10) = :d OR
                    substr(logout_time_real,1,10) = :d OR
                    substr(updated_at,1,10) = :d OR
                    login_date = :d
                )
                AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'");
        $stmtLH->execute([':d' => $date]);
        foreach ($stmtLH->fetchAll(PDO::FETCH_ASSOC) as $lh) {
            $uname = trim((string)($lh['username'] ?? ''));
            if ($uname === '') continue;
            $lh_comment = (string)($lh['raw_comment'] ?? '');
            if (is_vip_comment($lh_comment)) continue;
            $blok = normalize_block_name('', $lh_comment);
            if ($blok !== $blok_norm) continue;
            if (isset($user_latest[$uname])) continue;
            $dt = (string)($lh['dt'] ?? '');
            $ts = strtotime($dt);
            $user_latest[$uname] = [
                'username' => $uname,
                'comment' => $lh_comment,
                'blok_name' => $blok_norm,
                'status' => (string)($lh['last_status'] ?? ''),
                'is_invalid' => 0,
                'is_retur' => 0,
                'is_rusak' => 0,
                'sale_date' => $date,
                'sale_time' => $dt !== '' ? substr($dt, 11, 8) : '',
                'sale_datetime' => $dt,
                '_ts' => $ts ?: 0,
                'profile' => (string)($lh['validity'] ?? ''),
                'profile_snapshot' => (string)($lh['validity'] ?? ''),
                'validity' => (string)($lh['validity'] ?? ''),
                'price' => 0,
                'price_snapshot' => 0,
                'sprice_snapshot' => 0
            ];
            if (!isset($login_map[$uname])) {
                $login_map[$uname] = $lh;
            }
        }
    }

    $users = [];
    $retur_total = 0;
    $retur_count = 0;
    $retur_users = [];
    foreach ($user_latest as $uname => $r) {
        $lh = $login_map[$uname] ?? [];
        $comment = (string)($r['comment'] ?? '');
        $lh_comment = (string)($lh['raw_comment'] ?? '');
        if (is_vip_comment($comment) || is_vip_comment($lh_comment)) continue;
        $blok = normalize_block_name($r['blok_name'] ?? '', $comment);
        if ($blok !== $blok_norm && $lh_comment !== '') {
            $blok = normalize_block_name($r['blok_name'] ?? '', $lh_comment);
        }
        if ($blok !== $blok_norm) continue;

        $lh_status = $lh['last_status'] ?? '';
        $status = resolve_status_from_sources($r['status'] ?? '', $r['is_invalid'] ?? 0, $r['is_retur'] ?? 0, $r['is_rusak'] ?? 0, ($comment !== '' ? $comment : $lh_comment), $lh_status);
        if (in_array($status, ['rusak', 'invalid'], true)) continue;

        $profile_src = (string)($r['profile_snapshot'] ?? $r['profile'] ?? $r['validity'] ?? '');
        if ($profile_src === '') $profile_src = (string)($lh['validity'] ?? '');
        if ($profile_src === '') $profile_src = extract_profile_from_comment(($lh_comment !== '' ? $lh_comment : $comment));
        $profile_key = normalize_profile_key($profile_src);
        if ($profile_key !== '' && preg_match('/^\d+$/', $profile_key)) {
            $profile_key = $profile_key . 'menit';
        }

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
        if ($price <= 0 && $profile_key !== '') {
            $price = (int)resolve_price_from_profile($profile_key);
        }

        $uptime = trim((string)($lh['last_uptime'] ?? ''));
        $bytes = (int)($lh['last_bytes'] ?? 0);

        if ($status === 'retur') {
            if (!isset($retur_users[$uname])) {
                $retur_users[$uname] = true;
                $retur_total += $price;
                $retur_count += 1;
            }
            continue;
        }

        $users[] = [
            'username' => $uname,
            'profile_key' => $profile_key,
            'price' => $price,
            'status' => strtoupper((string)$status),
            'uptime' => $uptime !== '' ? $uptime : '-',
            'bytes' => format_bytes_short($bytes)
        ];
    }

    echo json_encode(['ok' => true, 'users' => $users, 'retur_total' => $retur_total, 'retur_count' => $retur_count]);
    exit;
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal membaca data.']);
    exit;
}
