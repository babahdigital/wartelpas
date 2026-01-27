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

// --- DATABASE ---
$dbDir = __DIR__ . '/../../db_data';
$dbFile = $dbDir . '/mikhmon_stats.db';
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
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "Tidak bisa konek ke router.";
    exit;
}

$hotspot_server = $hotspot_server ?? 'wartel';

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

$urow = $uinfo[0] ?? [];
$arow = $ainfo[0] ?? [];
$comment = $urow['comment'] ?? '';
$profile = $urow['profile'] ?? '';
$disabled = $urow['disabled'] ?? 'false';

$hist = get_user_history_from_db($db, $user);
$blok = $hist['blok_name'] ?? '';
if ($blok === '') {
    $blok = extract_blok_name($comment);
}
$blok_label = normalize_blok_label($blok ?: extract_blok_name($comment));
$profile_label = normalize_profile_label($profile);
$hist_ip = $hist['ip_address'] ?? '';
$hist_mac = $hist['mac_address'] ?? '';
$c_ipmac = extract_ip_mac_from_comment($comment);
$ip_addr = $hist_ip ?: ($c_ipmac['ip'] ?? '');
$mac_addr = $hist_mac ?: ($c_ipmac['mac'] ?? '');

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
$uptime_sec = $max_sec;

if ($profile_label === '' || $profile_label === '-') {
    if ($uptime_sec >= 590 && $uptime_sec <= 610) $profile_label = '10 Menit';
    elseif ($uptime_sec >= 1790 && $uptime_sec <= 1810) $profile_label = '30 Menit';
}

$is_active = isset($arow['user']);
$limits = resolve_rusak_limits($profile);

$first_login_real = $hist['first_login_real'] ?? '';
$login_time_real = $hist['login_time_real'] ?? ($hist['last_login_real'] ?? '');
$logout_time_real = $hist['logout_time_real'] ?? '';
$last_status = $hist['last_status'] ?? '';

$date_key = '';
if (!empty($first_login_real)) {
    $date_key = date('Y-m-d', strtotime($first_login_real));
}

$total_uptime_sec = get_cumulative_uptime_from_events_db($db, $user, $date_key, $logout_time_real);
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

$criteria = [
    'offline' => !$is_active,
    'bytes_ok' => $bytes <= $limits['bytes'],
    'first_login_ok' => !empty($first_login_real)
];
$ok = $criteria['offline'] && $criteria['bytes_ok'] && $criteria['first_login_ok'];

$status_label = $ok ? 'Terpenuhi' : 'Belum';
$status_label_safe = htmlspecialchars($status_label, ENT_QUOTES);
$relogin_date_label = $date_key ? format_dmy_date($date_key) : '';
$relogin_date_label_safe = htmlspecialchars($relogin_date_label, ENT_QUOTES);

$detail_rows = [
    ['User', htmlspecialchars($user)],
    ['Blok', htmlspecialchars($blok_label !== '' ? $blok_label : '-')],
    ['Profile', htmlspecialchars($profile_label !== '' ? $profile_label : '-')],
    ['IP', htmlspecialchars($ip_addr ?: '-')],
    ['MAC', htmlspecialchars($mac_addr ?: '-')],
    ['Status', $is_active ? 'ONLINE' : (strtoupper((string)$last_status) ?: 'OFFLINE')],
    ['First Login', format_dmy($first_login_real)],
    ['Login', format_dmy($login_time_real)],
    ['Logout', format_dmy($logout_time_real)],
    ['Bytes', function_exists('formatBytes') ? formatBytes($bytes, 2) : (string)$bytes],
    ['Uptime', $uptime ?: '0s'],
    ['Total Uptime', seconds_to_uptime($total_uptime_sec)]
];

$criteria_rows = [
    ['Offline (tidak sedang online)', $criteria['offline'], $is_active ? 'Ya' : 'Tidak'],
    ['Bytes maksimal ' . ($limits['bytes_label'] ?? '-'), $criteria['bytes_ok'], function_exists('formatBytes') ? formatBytes($bytes, 2) : (string)$bytes],
    ['Uptime (informasi)', true, seconds_to_uptime($total_uptime_sec)],
    ['Pernah login (first login ada)', $criteria['first_login_ok'], $first_login_real ? format_dmy($first_login_real) : '-']
];

$API->disconnect();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Cek Kelayakan Rusak</title>
    <style>
        body{font-family:Arial,sans-serif;color:#111;margin:20px;}
        h3{margin:0 0 6px 0;}
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        .section-title{margin:12px 0 6px 0;font-weight:700;font-size:13px;}
        table{width:100%;border-collapse:collapse;font-size:12px;}
        th,td{border:1px solid #444;padding:6px 8px;text-align:left;}
        th{background:#f0f0f0;}
        .status-ok{color:#0b7a0b !important;font-weight:700;}
        .status-bad{color:#c62828 !important;font-weight:700;}
        .row-ok td{background:#e8f5e9 !important;}
        .row-bad td{background:#ffebee !important;}
        @media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;} .toolbar{display:none;}}
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
    </div>
    <h3>Cek Kelayakan Rusak</h3>
    <div style="margin:6px 0 10px 0;font-size:12px;color:#444;">
        <strong>Kelayakan Untuk Rusak:</strong> <span class="<?= $ok ? 'status-ok' : 'status-bad' ?>"><?= $status_label_safe ?></span>
    </div>

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

    <div class="section-title">Cek Kelayakan Rusak</div>
    <table>
        <thead>
            <tr><th>Kriteria</th><th>Nilai</th><th>Status</th></tr>
        </thead>
        <tbody>
            <?php foreach ($criteria_rows as $row): ?>
                <?php $rowClass = $row[1] ? 'row-ok' : 'row-bad'; ?>
                <?php $statusClass = $row[1] ? 'status-ok' : 'status-bad'; ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= htmlspecialchars($row[0]) ?></td>
                    <td><?= htmlspecialchars($row[2]) ?></td>
                    <td class="<?= $statusClass ?>"><?= $row[1] ? 'OK' : 'TIDAK' ?></td>
                </tr>
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
</body>
</html>
