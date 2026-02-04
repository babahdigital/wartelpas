<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
}

$session = $_GET['session'] ?? '';
$user = trim((string)($_GET['user'] ?? ''));

if ($session === '' || $user === '') {
    echo "Parameter tidak lengkap.";
    exit;
}

include(__DIR__ . '/../../include/config.php');
if ($session === '' || !isset($data[$session])) {
    echo "Session tidak valid.";
    exit;
}
include(__DIR__ . '/../../include/readcfg.php');
include_once(__DIR__ . '/../../lib/routeros_api.class.php');
include_once(__DIR__ . '/../../lib/formatbytesbites.php');
include_once(__DIR__ . '/../../hotspot/user/helpers.php');
require_once(__DIR__ . '/../../include/db_helpers.php');

// --- DATABASE ---
$dbFile = get_stats_db_path();
$db = null;
if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_TIMEOUT, 2);
        $db->exec("PRAGMA busy_timeout=2000;");
    } catch (Exception $e) {
        $db = null;
    }
}


// --- ROUTEROS ---
$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;
$API->attempts = 1;
$api_connected = $API->connect($iphost, $userhost, decrypt($passwdhost));

$hotspot_server = $hotspot_server ?? 'wartel';

$uinfo = [];
$ainfo = [];
if ($api_connected) {
    $uinfo = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $user,
        '.proplist' => 'name,comment,profile,disabled,bytes-in,bytes-out,uptime'
    ]);
    $ainfo = $API->comm('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $user,
        '.proplist' => 'user,uptime,bytes-in,bytes-out'
    ]);
}

$urow = $uinfo[0] ?? [];
$arow = $ainfo[0] ?? [];
$comment = $urow['comment'] ?? '';
$profile = $urow['profile'] ?? '';

$hist = get_user_history_from_db($db, $user);
$meta = function_exists('get_voucher_meta_info') ? get_voucher_meta_info($db, $user) : [];
$blok = $hist['blok_name'] ?? '';
if ($blok === '') {
    $blok = extract_blok_name($comment);
}
$blok_label = normalize_blok_label($blok ?: extract_blok_name($comment));
$profile_label = normalize_profile_label($profile);
$room_name = (string)($meta['room_name'] ?? '');
$customer_name = (string)($meta['customer_name'] ?? '');
$hist_ip = $hist['ip_address'] ?? '';
$hist_mac = $hist['mac_address'] ?? '';
$c_ipmac = extract_ip_mac_from_comment($comment);
$ip_addr = $hist_ip ?: ($c_ipmac['ip'] ?? '');
$mac_addr = $hist_mac ?: ($c_ipmac['mac'] ?? '');

$normalize_room_label = function($room) {
    $room = trim((string)$room);
    if ($room === '' || $room === '-') return '-';
    return trim(preg_replace('/^\s*(kamar|kmr|room)\s+/i', '', $room));
};

$bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
$bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
$bytes_hist = (int)($hist['last_bytes'] ?? 0);
$bytes = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);

$uptime_user = $urow['uptime'] ?? '';
$uptime_active = $arow['uptime'] ?? '';
$uptime_hist = $hist['last_uptime'] ?? '';

$sec_user = uptime_to_seconds($uptime_user);
$sec_active = uptime_to_seconds($uptime_active);
$sec_hist = uptime_to_seconds($uptime_hist);
$max_sec = max($sec_user, $sec_active, $sec_hist);

if ($max_sec == $sec_active && $sec_active > 0) {
    $uptime = $uptime_active;
} elseif ($max_sec == $sec_user && $sec_user > 0) {
    $uptime = $uptime_user;
} elseif ($max_sec == $sec_hist && $sec_hist > 0) {
    $uptime = $uptime_hist;
} else {
    $uptime = '0s';
}

if ($profile_label === '' || $profile_label === '-') {
    if ($max_sec >= 590 && $max_sec <= 610) $profile_label = '10 Menit';
    elseif ($max_sec >= 1790 && $max_sec <= 1810) $profile_label = '30 Menit';
    elseif (!empty($hist['raw_comment']) && preg_match('/\b(10|30)\s*(menit|m)\b/i', $hist['raw_comment'], $m)) {
        $profile_label = $m[1] . ' Menit';
    }
}
if ($profile_label === '' || $profile_label === '-') {
    $profile_hint = resolve_profile_from_history($hist['raw_comment'] ?? $comment, '', $uptime);
    $profile_label = normalize_profile_label($profile_hint);
}
if ($profile_label === '' || $profile_label === '-') {
    $kind = detect_profile_kind_unified($profile, $comment, $blok, $uptime);
    if (in_array($kind, ['10', '30'], true)) {
        $profile_label = $kind . ' Menit';
    }
}

$is_active = isset($arow['user']);

$first_login_real = $hist['first_login_real'] ?? '';
$login_time_real = $hist['login_time_real'] ?? ($hist['last_login_real'] ?? '');
$logout_time_real = $hist['logout_time_real'] ?? '';
$last_status = $hist['last_status'] ?? '';

$date_key = '';
if (!empty($first_login_real)) {
    $date_key = date('Y-m-d', strtotime($first_login_real));
}

$max_session_seconds = 0;
if ($profile_label !== '' && $profile_label !== '-') {
    if (preg_match('/\b10\b/i', $profile_label)) $max_session_seconds = 10 * 60;
    elseif (preg_match('/\b30\b/i', $profile_label)) $max_session_seconds = 30 * 60;
} else {
    $kind = detect_profile_kind_unified($profile, $comment, $blok, $uptime);
    if ($kind === '10') $max_session_seconds = 10 * 60;
    elseif ($kind === '30') $max_session_seconds = 30 * 60;
}

$total_uptime_sec = get_cumulative_uptime_from_events_db($db, $user, $date_key, $logout_time_real, $max_session_seconds);
if ($max_session_seconds > 0 && $total_uptime_sec > $max_session_seconds) {
    $total_uptime_sec = $max_session_seconds;
}
$relogin_events = get_relogin_events_db($db, $user, $date_key);

$first_login_norm = normalize_dt($first_login_real);
$login_norm = normalize_dt($login_time_real);
$logout_norm = normalize_dt($logout_time_real);
$relogin_events_filtered = [];
foreach ($relogin_events as $ev) {
    $ev_login = normalize_dt($ev['login_time'] ?? '');
    $ev_logout = normalize_dt($ev['logout_time'] ?? '');
    $is_same = false;
    if ($ev_login !== '' && $ev_logout !== '') {
        if (($ev_login === $first_login_norm || $ev_login === $login_norm) && $ev_logout === $logout_norm) {
            $is_same = true;
        }
    }
    if (!$is_same) {
        $relogin_events_filtered[] = $ev;
    }
}
$relogin_events = $relogin_events_filtered;
$relogin_date_label = $date_key ? format_dmy_date($date_key) : '';
$relogin_date_label_safe = htmlspecialchars($relogin_date_label, ENT_QUOTES);

$detail_rows = [
    ['User', $user],
    ['Nama', $customer_name !== '' ? $customer_name : '-'],
    ['Blok', $blok_label !== '' ? $blok_label : '-'],
    ['Kamar', $normalize_room_label($room_name !== '' ? $room_name : '-')],
    ['Profile', $profile_label !== '' ? $profile_label : '-'],
    ['IP', $ip_addr ?: '-'],
    ['MAC', $mac_addr ?: '-'],
    ['Status', $is_active ? 'ONLINE' : (strtoupper((string)$last_status) ?: 'OFFLINE')],
    ['First Login', format_dmy($first_login_real)],
    ['Login', format_dmy($login_time_real)],
    ['Logout', format_dmy($logout_time_real)],
    ['Bytes', function_exists('formatBytes') ? formatBytes($bytes, 2) : (string)$bytes],
    ['Uptime', $uptime ?: '0s'],
    ['Total Uptime', seconds_to_uptime($total_uptime_sec)]
];

if ($api_connected) {
    $API->disconnect();
}

$stuck_info = null;
if ($db) {
    $log_file = __DIR__ . '/../../logs/stuck_kick_' . date('Y-m-d') . '.log';
    if (is_file($log_file)) {
        $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (!empty($lines)) {
            foreach (array_reverse($lines) as $line) {
                $item = json_decode($line, true);
                if (!is_array($item)) continue;
                if (strcasecmp((string)($item['user'] ?? ''), $user) !== 0) continue;
                $stuck_info = $item;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Rincian Used</title>
    <style>
        body{font-family:Arial,sans-serif;color:#111;margin:20px;}
        h3{margin:0 0 6px 0;}
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        .section-title{margin:12px 0 6px 0;font-weight:700;font-size:13px;}
        table{width:100%;border-collapse:collapse;font-size:12px;}
        th,td{border:1px solid #444;padding:6px 8px;text-align:left;}
        th{background:#f0f0f0;}
        @media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;} .toolbar{display:none;}}
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
    </div>

    <h3>Detail User</h3>

    <div class="section-title">Detail User</div>
    <table>
        <thead>
            <tr><th>Info</th><th>Deskripsi</th></tr>
        </thead>
        <tbody>
            <?php foreach ($detail_rows as $row): ?>
                <tr><td><?= htmlspecialchars($row[0], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$row[1], ENT_QUOTES) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($relogin_events) > 1): ?>
        <div class="section-title">Rincian Relogin<?= $relogin_date_label_safe ? ' (Tanggal ' . $relogin_date_label_safe . ')' : '' ?></div>
        <table>
            <thead>
                <tr><th>#</th><th>Login</th><th>Logout</th></tr>
            </thead>
            <tbody>
                <?php foreach ($relogin_events as $idx => $ev): ?>
                    <tr>
                        <td>#<?= (int)($ev['seq'] ?? ($idx + 1)) ?></td>
                        <td><?= htmlspecialchars(format_dmy($ev['login_time'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(format_dmy($ev['logout_time'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($stuck_info)): ?>
        <div class="section-title">Log Stuck Kick (Hari Ini)</div>
        <table>
            <thead>
                <tr><th>Info</th><th>Nilai</th></tr>
            </thead>
            <tbody>
                <tr><td>Waktu</td><td><?= htmlspecialchars((string)($stuck_info['ts'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>Reason</td><td><?= htmlspecialchars((string)($stuck_info['reason'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>Uptime</td><td><?= htmlspecialchars((string)($stuck_info['uptime'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>Bytes In</td><td><?= htmlspecialchars(function_exists('formatBytes') ? formatBytes((int)($stuck_info['bytes_in'] ?? 0), 2) : (string)($stuck_info['bytes_in'] ?? 0), ENT_QUOTES) ?></td></tr>
                <tr><td>Bytes Out</td><td><?= htmlspecialchars(function_exists('formatBytes') ? formatBytes((int)($stuck_info['bytes_out'] ?? 0), 2) : (string)($stuck_info['bytes_out'] ?? 0), ENT_QUOTES) ?></td></tr>
                <tr><td>IP</td><td><?= htmlspecialchars((string)($stuck_info['ip'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>MAC</td><td><?= htmlspecialchars((string)($stuck_info['mac'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>Profil</td><td><?= htmlspecialchars((string)($stuck_info['profile'] ?? '-'), ENT_QUOTES) ?></td></tr>
                <tr><td>Server</td><td><?= htmlspecialchars((string)($stuck_info['server'] ?? '-'), ENT_QUOTES) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
