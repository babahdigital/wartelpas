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

include('../include/config.php');
include('../include/readcfg.php');
include_once('../lib/routeros_api.class.php');
include_once('../lib/formatbytesbites.php');

function uptime_to_seconds($uptime) {
    if (empty($uptime)) return 0;
    if ($uptime === '0s') return 0;
    $total = 0;
    if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $uptime, $m, PREG_SET_ORDER)) {
        foreach ($m as $part) {
            $val = (int)$part[1];
            switch (strtolower($part[2])) {
                case 'w': $total += $val * 7 * 24 * 3600; break;
                case 'd': $total += $val * 24 * 3600; break;
                case 'h': $total += $val * 3600; break;
                case 'm': $total += $val * 60; break;
                case 's': $total += $val; break;
            }
        }
    }
    return $total;
}

function seconds_to_uptime($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0s';
    $parts = [];
    $weeks = intdiv($seconds, 7 * 24 * 3600); $seconds %= 7 * 24 * 3600;
    $days = intdiv($seconds, 24 * 3600); $seconds %= 24 * 3600;
    $hours = intdiv($seconds, 3600); $seconds %= 3600;
    $mins = intdiv($seconds, 60); $seconds %= 60;
    if ($weeks) $parts[] = $weeks . 'w';
    if ($days) $parts[] = $days . 'd';
    if ($hours) $parts[] = $hours . 'h';
    if ($mins) $parts[] = $mins . 'm';
    if ($seconds || empty($parts)) $parts[] = $seconds . 's';
    return implode('', $parts);
}

function resolve_rusak_limits($profile) {
    $p = strtolower((string)$profile);
    $limits = ['uptime' => 300, 'bytes' => 5 * 1024 * 1024, 'uptime_label' => '5 menit', 'bytes_label' => '5MB'];
    if (preg_match('/\b10\s*(menit|m)\b|10menit/i', $p)) {
        $limits['uptime'] = 180;
        $limits['uptime_label'] = '3 menit';
    }
    return $limits;
}

function extract_blok_name($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]*\s*([A-Za-z0-9]+)(?:\s*[-_]*\s*([0-9]+))?/i', $comment, $m)) {
        $raw = strtoupper($m[1] . ($m[2] ?? ''));
        $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
        $raw = preg_replace('/^BLOK/', '', $raw);
        if (preg_match('/^([A-Z]+)/', $raw, $mx)) {
            $raw = $mx[1];
        }
        if ($raw !== '') return 'BLOK-' . $raw;
    }
    return '';
}

function normalize_blok_label($blok) {
    $raw = strtoupper(trim((string)$blok));
    if ($raw === '') return '';
    $raw = preg_replace('/[^A-Z0-9]/', '', $raw);
    $raw = preg_replace('/^BLOK/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        return $m[1];
    }
    return $raw;
}

function normalize_profile_label($profile) {
    $p = trim((string)$profile);
    if ($p === '') return '';
    if (preg_match('/\b(10|30)\s*(menit|m)\b/i', $p, $m)) {
        return $m[1] . ' Menit';
    }
    $p = preg_replace('/\s*menit\b/i', ' Menit', $p);
    return $p;
}

function extract_ip_mac_from_comment($comment) {
    $ip = '';
    $mac = '';
    if (!empty($comment)) {
        if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) {
            $ip = trim($m[1]);
        }
        if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) {
            $mac = trim($m[1]);
        }
    }
    return ['ip' => $ip, 'mac' => $mac];
}

function format_dmy($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i:s', $ts);
}

function format_dmy_date($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

function normalize_dt($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return '';
    return date('Y-m-d H:i:s', $ts);
}

// --- DATABASE ---
$dbDir = dirname(__DIR__) . '/db_data';
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

function get_user_history($db, $name) {
    if (!$db) return null;
    try {
        $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_status, first_login_real, last_login_real, last_uptime, last_bytes, raw_comment FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function get_cumulative_uptime_from_events($db, $username, $date_key = '', $fallback_logout = '') {
    if (!$db || empty($username)) return 0;
    $params = [':u' => $username];
    $where = "username = :u";
    if (!empty($date_key)) {
        $where .= " AND date_key = :d";
        $params[':d'] = $date_key;
    }
    try {
        $stmt = $db->prepare("SELECT login_time, logout_time FROM login_events WHERE $where ORDER BY seq ASC, id ASC");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $total = 0;
        $fallback_ts = !empty($fallback_logout) ? strtotime($fallback_logout) : 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $login_time = $row['login_time'] ?? '';
            $logout_time = $row['logout_time'] ?? '';
            if (empty($login_time)) continue;
            $login_ts = strtotime($login_time);
            if (!$login_ts) continue;
            $logout_ts = !empty($logout_time) ? strtotime($logout_time) : 0;
            if (!$logout_ts && $fallback_ts && $fallback_ts >= $login_ts) {
                $logout_ts = $fallback_ts;
            }
            if ($logout_ts && $logout_ts >= $login_ts) {
                $total += ($logout_ts - $login_ts);
            }
        }
        return (int)$total;
    } catch (Exception $e) {
        return 0;
    }
}

function get_relogin_events($db, $username, $date_key = '') {
    if (!$db || empty($username) || empty($date_key)) return [];
    try {
        $stmt = $db->prepare("SELECT login_time, logout_time, seq FROM login_events WHERE username = :u AND date_key = :d ORDER BY seq ASC, id ASC");
        $stmt->execute([':u' => $username, ':d' => $date_key]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
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

$hist = get_user_history($db, $user);
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

$total_uptime_sec = get_cumulative_uptime_from_events($db, $user, $date_key, $logout_time_real);
$relogin_events = get_relogin_events($db, $user, $date_key);

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
$relogin_date_label = $date_key ? format_dmy_date($date_key) : '';

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
        <button class="btn" onclick="window.print()">Download PDF</button>
    </div>
    <h3>Cek Kelayakan Rusak</h3>
    <div style="margin:6px 0 10px 0;font-size:12px;color:#444;">
        <strong>Kelayakan Untuk Rusak:</strong> <span class="<?= $ok ? 'status-ok' : 'status-bad' ?>"><?= $status_label ?></span>
    </div>

    <div class="section-title">Detail User</div>
    <table>
        <thead>
            <tr><th>Info</th><th>Deskripsi</th></tr>
        </thead>
        <tbody>
            <?php foreach ($detail_rows as $row): ?>
                <tr><td><?= $row[0] ?></td><td><?= $row[1] ?></td></tr>
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
        <div class="section-title">Rincian Relogin<?= $relogin_date_label ? ' (Tanggal ' . $relogin_date_label . ')' : '' ?></div>
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

    <script>
        // manual print via toolbar
    </script>
</body>
</html>
