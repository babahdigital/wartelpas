<?php
session_start();
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug_mode) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    register_shutdown_function(function(){
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo '<pre style="padding:12px;background:#fff3cd;border:1px solid #f1c40f;color:#111;">';
            echo 'Fatal Error: ' . htmlspecialchars($err['message']) . "\n";
            echo 'File: ' . htmlspecialchars($err['file']) . "\n";
            echo 'Line: ' . htmlspecialchars((string)$err['line']) . "\n";
            echo '</pre>';
        }
    });
} else {
    error_reporting(0);
}

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$session = $_GET['session'] ?? '';

$root_dir = dirname(__DIR__, 2);
include($root_dir . '/include/config.php');
if ($session === '' || !isset($data[$session])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div style="padding:20px; font-family:Arial, sans-serif; color:#111;">Session tidak valid. Silakan login ulang.</div>';
    exit;
}
include($root_dir . '/include/readcfg.php');
include_once($root_dir . '/lib/routeros_api.class.php');
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once($root_dir . '/report/laporan/helpers.php');
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $session;

$mode = $_GET['mode'] ?? '';
$req_status = strtolower((string)($_GET['status'] ?? ''));
$is_usage = ($mode === 'usage' || in_array($req_status, ['used','terpakai','online','rusak','retur','ready','baik','all']));
$filter_user = trim((string)($_GET['user'] ?? ''));
$filter_blok = trim((string)($_GET['blok'] ?? ''));
$filter_profile = trim((string)($_GET['profile'] ?? ''));

$req_show = $_GET['show'] ?? '';
if ($req_show === '') {
    $req_show = $is_usage ? 'semua' : 'harian';
}
if (!in_array($req_show, ['harian','bulanan','tahunan','semua'], true)) {
    $req_show = $is_usage ? 'semua' : 'harian';
}

$block_only_all = false;

function normalize_profile_filter($profile) {
    $p = strtolower(trim((string)$profile));
    if ($p === '' || $p === 'all') return '';
    if (preg_match('/^(10|30)\b/', $p, $m)) return $m[1];
    if (preg_match('/\b(10|30)\b/', $p, $m)) return $m[1];
    return $p;
}

$filter_profile_kind = normalize_profile_filter($filter_profile);

$filter_date = $_GET['date'] ?? '';
if (!$is_usage) {
    if ($req_show === 'harian') {
        $filter_date = $filter_date ?: date('Y-m-d');
    } elseif ($req_show === 'bulanan') {
        $filter_date = $filter_date ?: date('Y-m');
    } elseif ($req_show === 'tahunan') {
        $filter_date = $filter_date ?: date('Y');
    }
}
if ($req_show === 'harian' && $filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}
if ($req_show === 'bulanan' && $filter_date !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}
if ($req_show === 'tahunan' && $filter_date !== '' && !preg_match('/^\d{4}$/', $filter_date)) {
    $filter_date = '';
}

$usage_label = 'Terpakai';
if ($req_status === 'online') $usage_label = 'Online';
elseif ($req_status === 'rusak') $usage_label = 'Rusak';
elseif ($req_status === 'retur') $usage_label = 'Retur';
elseif ($req_status === 'ready' || $req_status === 'baik') $usage_label = 'Ready';
elseif ($req_status === 'all') $usage_label = 'Semua';

function normalize_block_name_simple($blok_name) {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '') return '';
    $raw = preg_replace('/^BLOK[-_\s]*/', '', $raw);
    if (preg_match('/^([A-Z0-9]+)/', $raw, $m)) {
        $raw = $m[1];
    }
    return 'BLOK-' . $raw;
}

function extract_blok_name($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
        return 'BLOK-' . strtoupper($m[1]);
    }
    return '';
}

function extract_profile_from_comment($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bProfile\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\bProfil\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    return '';
}

if (!function_exists('normalize_profile_label')) {
    function normalize_profile_label($profile) {
        $p = trim((string)$profile);
        if ($p === '') return '-';
        if (preg_match('/\b(10|30)\s*(menit|m|min)\b/i', $p, $m)) {
            return $m[1] . ' Menit';
        }
        return $p;
    }
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

function is_wartel_client($comment, $hist_blok = '') {
    if (!empty($hist_blok)) return true;
    $blok = extract_blok_name($comment);
    if (!empty($blok)) return true;
    if (!empty($comment) && stripos($comment, 'blok-') !== false) return true;
    return false;
}

if (!function_exists('uptime_to_seconds')) {
    function uptime_to_seconds($uptime) {
        if (empty($uptime) || $uptime === '0s') return 0;
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
}

if (!function_exists('seconds_to_uptime')) {
    function seconds_to_uptime($seconds) {
        $seconds = (int)$seconds;
        if ($seconds <= 0) return '0s';
        $parts = [];
        $w = intdiv($seconds, 604800);
        if ($w > 0) { $parts[] = $w . 'w'; $seconds %= 604800; }
        $d = intdiv($seconds, 86400);
        if ($d > 0) { $parts[] = $d . 'd'; $seconds %= 86400; }
        $h = intdiv($seconds, 3600);
        if ($h > 0) { $parts[] = $h . 'h'; $seconds %= 3600; }
        $m = intdiv($seconds, 60);
        if ($m > 0) { $parts[] = $m . 'm'; $seconds %= 60; }
        if ($seconds > 0) { $parts[] = $seconds . 's'; }
        return implode('', $parts);
    }
}

if (!function_exists('extract_datetime_from_comment')) {
    function extract_datetime_from_comment($comment) {
        if (empty($comment)) return '';
        $first = trim(explode('|', $comment)[0] ?? '');
        if ($first === '') return '';
        $ts = strtotime($first);
        if ($ts === false) return '';
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('format_date_indo')) {
    function format_date_indo($dateStr) {
        if (empty($dateStr) || $dateStr === '-') return '-';
        $ts = strtotime($dateStr);
        if ($ts === false) return $dateStr;
        return date('d-m-Y H:i:s', $ts);
    }
}

function format_date_long_indo($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    $months = [
        'Januari','Februari','Maret','April','Mei','Juni',
        'Juli','Agustus','September','Oktober','November','Desember'
    ];
    $m = (int)date('n', $ts);
    $month = $months[$m - 1] ?? date('m', $ts);
    return date('d', $ts) . ' ' . $month . ' ' . date('Y', $ts);
}

function normalize_date_key($dateStr, $mode) {
    $ts = strtotime((string)$dateStr);
    if ($ts === false) return '';
    if ($mode === 'bulanan') return date('Y-m', $ts);
    if ($mode === 'tahunan') return date('Y', $ts);
    return date('Y-m-d', $ts);
}

function format_filter_date_label($dateStr, $mode) {
    if ($mode === 'tahunan') {
        if (preg_match('/^(\d{4})/', (string)$dateStr, $m)) return $m[1];
        return $dateStr;
    }
    if ($mode === 'bulanan') {
        $ts = strtotime((string)$dateStr . '-01');
        if ($ts === false) $ts = strtotime((string)$dateStr);
        if ($ts === false) return $dateStr;
        $months = [
            'Januari','Februari','Maret','April','Mei','Juni',
            'Juli','Agustus','September','Oktober','November','Desember'
        ];
        $m = (int)date('n', $ts);
        $month = $months[$m - 1] ?? date('m', $ts);
        return $month . ' ' . date('Y', $ts);
    }
    return format_date_long_indo($dateStr);
}

function format_time_only($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('H:i:s', $ts);
}

function strip_blok_prefix($blok) {
    $raw = trim((string)$blok);
    if ($raw === '') return '-';
    return preg_replace('/^BLOK-?/i', '', $raw);
}

function normalize_blok_key($blok) {
    $raw = trim((string)$blok);
    if ($raw === '') return '';
    $raw = preg_replace('/^BLOK-?/i', '', strtoupper($raw));
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^([A-Z0-9]+)/', $raw, $m)) {
        return strtoupper($m[1]);
    }
    return strtoupper($raw);
}

function format_date_only_indo($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

$rows = [];
$retur_ref_map = [];
$list = [];
$usage_list = [];
$total_gross = 0;
$total_net = 0;
$total_qty_rusak = 0;
$total_qty_retur = 0;
$unique_laku_users = [];
$bytes_by_user = [];
$audit_net = null;
$only_wartel = true;
if (isset($_GET['only_wartel']) && $_GET['only_wartel'] === '0') {
    $only_wartel = false;
}

$meta_queue_map = [];

try {
    if (file_exists($dbFile)) {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $res = $db->query("SELECT 
                sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                sh.username, sh.profile, sh.profile_snapshot,
                sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
                sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
            sh.full_raw_data, lh.last_status, lh.last_bytes, lh.customer_name, lh.room_name
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
            ls.full_raw_data, lh2.last_status, lh2.last_bytes, lh2.customer_name, lh2.room_name
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
        try {
            $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_rekap_manual' LIMIT 1");
            $hasAudit = (bool)$stmtChk->fetchColumn();
            if ($hasAudit) {
                $stmtAudit = $db->prepare("SELECT SUM(actual_setoran) FROM audit_rekap_manual WHERE report_date = :d");
                $stmtAudit->execute([':d' => $filter_date]);
                $audit_net = (int)$stmtAudit->fetchColumn();
            }
        } catch (Exception $e) {
            $audit_net = null;
        }

        try {
            $stmtMeta = $db->query("SELECT voucher_code, customer_name, room_name FROM login_meta_queue WHERE voucher_code != '' ORDER BY created_at DESC");
            while ($row = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
                $vc = strtolower(trim((string)($row['voucher_code'] ?? '')));
                if ($vc === '' || isset($meta_queue_map[$vc])) continue;
                $meta_queue_map[$vc] = [
                    'customer_name' => trim((string)($row['customer_name'] ?? '')),
                    'room_name' => trim((string)($row['room_name'] ?? ''))
                ];
            }
        } catch (Exception $e) {}
    }
} catch (Exception $e) {
    $rows = [];
}

// Scan referensi Retur dari DB (agar voucher asal bisa ditandai meski belum login)
if (isset($db) && $db instanceof PDO) {
    try {
        $stmtLive = $db->query("SELECT comment FROM live_sales WHERE comment LIKE '%Retur%'");
        while ($row = $stmtLive->fetch(PDO::FETCH_ASSOC)) {
            $ref_u = extract_retur_user_from_ref($row['comment'] ?? '');
            if ($ref_u !== '') $retur_ref_map[strtolower($ref_u)] = true;
        }
        $stmtHist = $db->prepare("SELECT comment FROM sales_history WHERE comment LIKE '%Retur%' AND sale_date = :d");
        $stmtHist->execute([':d' => $filter_date]);
        while ($row = $stmtHist->fetch(PDO::FETCH_ASSOC)) {
            $ref_u = extract_retur_user_from_ref($row['comment'] ?? '');
            if ($ref_u !== '') $retur_ref_map[strtolower($ref_u)] = true;
        }
    } catch (Exception $e) {}
}

// Lengkapi referensi retur dari RouterOS agar voucher asal bisa disembunyikan
$API = null;
if (!$is_usage) {
    try {
        $API = $API ?? new RouterosAPI();
        $API->debug = false;
        $API->timeout = 5;
        $API->attempts = 1;
        if (!$API->connected && $API->connect($iphost, $userhost, decrypt($passwdhost))) {
            $router_users = $API->comm("/ip/hotspot/user/print", array(
                "?server" => $hotspot_server,
                ".proplist" => "comment"
            ));
            if (is_array($router_users)) {
                foreach ($router_users as $u_src) {
                    $ref_u = extract_retur_user_from_ref($u_src['comment'] ?? '');
                    if ($ref_u !== '') {
                        $retur_ref_map[strtolower($ref_u)] = true;
                    }
                }
            }
        }
    } catch (Exception $e) {}
    if ($API instanceof RouterosAPI && $API->connected) {
        $API->disconnect();
    }
}

if ($is_usage && file_exists($dbFile)) {
    $histMap = [];
    try {
        $db = $db ?? new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("SELECT username, customer_name, room_name, blok_name, ip_address, mac_address, last_uptime, last_bytes, login_time_real, logout_time_real, raw_comment, last_status, login_count, first_login_real, last_login_real, updated_at, validity FROM login_history");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uname = $row['username'] ?? '';
            if ($uname !== '') {
                $raw_comment = (string)($row['raw_comment'] ?? '');
                $has_retur = (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false);
                if ($has_retur) {
                    $row['last_status'] = 'retur';
                }
                $histMap[$uname] = $row;
            }
            $ref_user = extract_retur_user_from_ref($row['raw_comment'] ?? '');
            if ($ref_user !== '') $retur_ref_map[strtolower($ref_user)] = true;
        }
    } catch (Exception $e) {
        $histMap = [];
    }

    $API = $API ?? new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;
    $system_cfg = $env['system'] ?? [];
    $expected_hotspot = $system_cfg['hotspot_server'] ?? 'wartel';
    $hotspot_server = $hotspot_server ?? $expected_hotspot;
    $connected = $API->connected ? true : $API->connect($iphost, $userhost, decrypt($passwdhost));
    $all_users = [];
    $active = [];
    if ($connected) {
        $all_users = $API->comm("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            ".proplist" => ".id,name,comment,profile,disabled,bytes-in,bytes-out,uptime"
        ));
        $active = $API->comm("/ip/hotspot/active/print", array(
            "?server" => $hotspot_server,
            ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
        ));
        $API->disconnect();
    } else {
        foreach ($histMap as $uname => $row) {
            $all_users[] = [
                'name' => $uname,
                'comment' => (string)($row['raw_comment'] ?? ''),
                'profile' => (string)($row['validity'] ?? ''),
                'disabled' => (strtolower((string)($row['last_status'] ?? '')) === 'rusak') ? 'true' : 'false',
                'bytes-in' => 0,
                'bytes-out' => 0,
                'uptime' => (string)($row['last_uptime'] ?? '0s')
            ];
        }
    }

    $activeMap = [];
    foreach ($active as $a) {
        if (isset($a['user'])) $activeMap[$a['user']] = $a;
    }

    $seen_users = [];
    foreach ($all_users as $u) {
            $name = $u['name'] ?? '';
            if ($name === '') continue;
            if ($filter_user !== '' && $name !== $filter_user) continue;

            $comment = (string)($u['comment'] ?? '');
            $disabled = $u['disabled'] ?? 'false';
            $is_active = isset($activeMap[$name]);

            $f_blok = extract_blok_name($comment);
            $hist = $histMap[$name] ?? null;
            if ($f_blok === '' && $hist && !empty($hist['blok_name'])) {
                $f_blok = normalize_block_name_simple($hist['blok_name']);
            }
            if ($only_wartel && !is_wartel_client($comment, $hist['blok_name'] ?? '')) {
                continue;
            }
            if ($filter_blok !== '') {
                $target_blok = normalize_blok_key($filter_blok);
                $f_blok_key = normalize_blok_key($f_blok);
                if ($f_blok_key === '' || strcasecmp($f_blok_key, $target_blok) !== 0) continue;
            }

            $f_ip = $is_active ? ($activeMap[$name]['address'] ?? '-') : '-';
            $f_mac = $is_active ? ($activeMap[$name]['mac-address'] ?? '-') : '-';
            if ($f_ip == '-' || $f_mac == '-') {
                $cm = extract_ip_mac_from_comment($comment);
                if ($f_ip == '-' && !empty($cm['ip'])) $f_ip = $cm['ip'];
                if ($f_mac == '-' && !empty($cm['mac'])) $f_mac = $cm['mac'];
            }
            if (!$is_active && $hist) {
                if ($f_ip == '-' && !empty($hist['ip_address'])) $f_ip = $hist['ip_address'];
                if ($f_mac == '-' && !empty($hist['mac_address'])) $f_mac = $hist['mac_address'];
            }

            $bytes_total = ($u['bytes-in'] ?? 0) + ($u['bytes-out'] ?? 0);
            $bytes_active = 0;
            if ($is_active) {
                $bytes_active = ($activeMap[$name]['bytes-in'] ?? 0) + ($activeMap[$name]['bytes-out'] ?? 0);
            }
            $bytes_hist = (int)($hist['last_bytes'] ?? 0);
            $bytes = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);

            $uptime_user = $u['uptime'] ?? '';
            $uptime_hist = $hist['last_uptime'] ?? '';
            $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
            $uptime = $uptime_active != '' ? $uptime_active : ($uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s'));

            $is_rusak = stripos($comment, 'RUSAK') !== false;
            $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
            $hist_status = strtolower((string)($hist['last_status'] ?? ''));
            $hist_comment = (string)($hist['raw_comment'] ?? '');
            $hist_is_retur = (stripos($hist_comment, '(Retur)') !== false) || (stripos($hist_comment, 'Retur Ref:') !== false) || ($hist_status === 'retur');
            if ($hist_status === 'rusak') $is_rusak = true;
            if ($hist_status === 'retur' || $hist_is_retur) $is_retur = true;
            if ($disabled === 'true') $is_rusak = true;
            if ($is_retur) $is_rusak = false;

            $hist_used = $hist && (
                in_array($hist_status, ['online','terpakai','rusak','retur']) ||
                !empty($hist['login_time_real']) ||
                !empty($hist['logout_time_real']) ||
                (!empty($hist['last_uptime']) && $hist['last_uptime'] != '0s') ||
                (int)($hist['last_bytes'] ?? 0) > 0
            );
            $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
                (!$is_active && ($bytes > 50 || $uptime != '0s' || $hist_used));

            $status = 'READY';
            if ($is_active) $status = 'ONLINE';
            elseif ($is_rusak) $status = 'RUSAK';
            elseif ($disabled === 'true') $status = 'RUSAK';
            elseif ($is_retur) $status = 'RETUR';
            elseif ($is_used) $status = 'TERPAKAI';

            $status_match = true;
            if ($req_status === 'online') $status_match = ($status === 'ONLINE');
            elseif ($req_status === 'rusak') $status_match = ($status === 'RUSAK');
            elseif ($req_status === 'retur') $status_match = ($status === 'RETUR');
            elseif ($req_status === 'ready' || $req_status === 'baik') $status_match = ($status === 'READY');
            elseif ($req_status === 'used' || $req_status === 'terpakai') $status_match = in_array($status, ['TERPAKAI','RETUR']);
            elseif ($req_status === 'all') {
                $allowed = ['READY','ONLINE','RUSAK','RETUR','TERPAKAI'];
                if ($block_only_all) {
                    $allowed = ['ONLINE','RUSAK','RETUR','TERPAKAI'];
                }
                $status_match = in_array($status, $allowed);
            }
            else $status_match = ($status === 'TERPAKAI');

            if (!$status_match) continue;
            $profile_label = normalize_profile_label($u['profile'] ?? '');
            if ($profile_label === '-' || $profile_label === '') {
                $profile_label = normalize_profile_label(extract_profile_from_comment($comment));
            }
            if (($profile_label === '-' || $profile_label === '') && $hist && !empty($hist['raw_comment'])) {
                $profile_label = normalize_profile_label(extract_profile_from_comment($hist['raw_comment'] ?? ''));
            }
            if (($profile_label === '-' || $profile_label === '') && $hist && !empty($hist['validity'])) {
                $profile_label = normalize_profile_label($hist['validity'] ?? '');
            }
            if ($profile_label === '' || $profile_label === '-') $profile_label = '-';
            if ($filter_profile_kind !== '') {
                $kind = detect_profile_kind_from_label($profile_label);
                if ($kind !== $filter_profile_kind) {
                    continue;
                }
            }

            $seen_users[$name] = true;

            $login_time = $hist['login_time_real'] ?? '';
            $logout_time = $hist['logout_time_real'] ?? '';
            if ($logout_time === '') {
                $logout_time = extract_datetime_from_comment($comment);
            }
            if ($status === 'ONLINE') {
                $logout_time = '-';
                // gunakan waktu login dari DB agar presisi sama dengan users.php
                $u_sec_active = uptime_to_seconds($uptime_active);
                if ($u_sec_active > 0) {
                    $login_time = date('Y-m-d H:i:s', time() - $u_sec_active);
                } elseif ($login_time === '') {
                    $u_sec = uptime_to_seconds($uptime);
                    if ($u_sec > 0) {
                        $login_time = date('Y-m-d H:i:s', time() - $u_sec);
                    }
                }
            }
            if ($login_time === '' && $logout_time !== '' && $logout_time !== '-') {
                $u_sec = uptime_to_seconds($uptime);
                if ($u_sec > 0) {
                    $login_time = date('Y-m-d H:i:s', strtotime($logout_time) - $u_sec);
                } else {
                    $login_time = $logout_time;
                }
            }
            if ($login_time === '') $login_time = '-';
            if ($logout_time === '') $logout_time = '-';

            $uptime_display = $uptime;
            if ($status === 'ONLINE' && $u_sec_active > 0) {
                $uptime_display = seconds_to_uptime($u_sec_active);
            } elseif ($login_time !== '-' && $logout_time !== '-') {
                $diff = strtotime($logout_time) - strtotime($login_time);
                if ($diff > 0) {
                    $diff = normalize_uptime_diff($diff, 2);
                    $uptime_display = seconds_to_uptime($diff);
                }
            } elseif ($status === 'ONLINE' && $login_time !== '-') {
                $diff = time() - strtotime($login_time);
                if ($diff > 0) {
                    $diff = normalize_uptime_diff($diff, 2);
                    $uptime_display = seconds_to_uptime($diff);
                }
            }

            $relogin = ((int)($hist['login_count'] ?? 0) > 1);
            if ($req_status === 'rusak') {
                $relogin = false;
            }
            $first_login = $hist['first_login_real'] ?? $login_time;
            $customer_name = (string)($hist['customer_name'] ?? '');
            $room_name = (string)($hist['room_name'] ?? '');
            if ($customer_name === '' || $room_name === '') {
                $meta = $meta_queue_map[strtolower($name)] ?? null;
                if ($meta) {
                    if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
                    if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
                }
            }
            $usage_list[] = [
                'first_login' => $first_login,
                'login' => $login_time,
                'logout' => $logout_time,
                'username' => $name,
                'customer_name' => $customer_name,
                'room_name' => $room_name,
                'profile' => $profile_label,
                'blok' => (normalize_blok_key($f_blok) ?: '-'),
                'ip' => $f_ip,
                'mac' => $f_mac,
                'uptime' => $uptime_display,
                'bytes' => $bytes,
                'status' => strtolower($status),
                'comment' => $comment,
                'relogin' => $relogin,
                'updated_at' => $hist['updated_at'] ?? null
            ];
        }

        // Tambahkan data history-only jika user sudah hilang dari Mikrotik
        foreach ($histMap as $uname => $row) {
            if (isset($seen_users[$uname])) continue;
            if ($filter_user !== '' && $uname !== $filter_user) continue;
            $hist_status = strtolower((string)($row['last_status'] ?? ''));

            if ($req_status === 'rusak') {
                $is_retur_hist = (stripos($row['raw_comment'] ?? '', '(Retur)') !== false) || (stripos($row['raw_comment'] ?? '', 'Retur Ref:') !== false) || ($hist_status === 'retur') || isset($retur_ref_map[strtolower($uname)]);
                if ($is_retur_hist) continue;
            }

            $comment = (string)($row['raw_comment'] ?? '');
            $profile_label = normalize_profile_label(extract_profile_from_comment($comment));
            if (($profile_label === '' || $profile_label === '-') && !empty($row['validity'])) {
                $profile_label = normalize_profile_label($row['validity'] ?? '');
            }
            if ($profile_label === '' || $profile_label === '-') $profile_label = '-';
            if ($filter_profile_kind !== '') {
                $kind = detect_profile_kind_from_label($profile_label);
                if ($kind !== $filter_profile_kind) {
                    continue;
                }
            }
            $f_blok = normalize_block_name_simple($row['blok_name'] ?? '') ?: extract_blok_name($comment);
            if ($only_wartel && !is_wartel_client($comment, $row['blok_name'] ?? '')) {
                continue;
            }
            if ($filter_blok !== '') {
                $target_blok = normalize_blok_key($filter_blok);
                $f_blok_key = normalize_blok_key($f_blok);
                if ($f_blok_key === '' || strcasecmp($f_blok_key, $target_blok) !== 0) continue;
            }

            $login_time = $row['login_time_real'] ?? '-';
            $logout_time = $row['logout_time_real'] ?? '-';
            $uptime_display = $row['last_uptime'] ?? '-';
            if ($login_time !== '-' && $logout_time !== '-') {
                $diff = strtotime($logout_time) - strtotime($login_time);
                if ($diff > 0) {
                    $diff = normalize_uptime_diff($diff, 2);
                    $uptime_display = seconds_to_uptime($diff);
                }
            }
            $relogin = ((int)($row['login_count'] ?? 0) > 1);
            if ($req_status === 'rusak') {
                $relogin = false;
            }
            $st = strtolower((string)($row['last_status'] ?? ''));
            $bytes_hist = (int)($row['last_bytes'] ?? 0);
            $uptime_hist = (string)($row['last_uptime'] ?? '');
            $ip_hist = (string)($row['ip_address'] ?? '');
            $cm = extract_ip_mac_from_comment($comment);
            $ip_use = $ip_hist !== '' && $ip_hist !== '-' ? $ip_hist : ($cm['ip'] ?? '');
            $h_is_rusak = $st === 'rusak' || preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment) || (stripos($comment, 'RUSAK') !== false);
            $h_is_retur = $st === 'retur' || (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $comment);
            $h_is_used = (!$h_is_rusak && !$h_is_retur) && (
                $bytes_hist > 50 ||
                ($uptime_hist !== '' && $uptime_hist !== '0s') ||
                ($ip_use !== '' && $ip_use !== '-')
            );
            $status = 'READY';
            if ($st === 'retur' || $h_is_retur) {
                $status = 'RETUR';
            } elseif ($st === 'rusak' || $h_is_rusak) {
                $status = 'RUSAK';
            } elseif ($st === 'terpakai' || $st === 'used' || $h_is_used) {
                $status = 'TERPAKAI';
            } elseif ($st === 'online') {
                $status = 'ONLINE';
            }
            if ($status === 'READY' && !in_array($req_status, ['ready','baik','all'], true)) continue;
            if ($req_status === 'online' && $status !== 'ONLINE') continue;
            if ($req_status === 'rusak' && $status !== 'RUSAK') continue;
            if ($req_status === 'retur' && $status !== 'RETUR') continue;
            if (($req_status === 'ready' || $req_status === 'baik') && $status !== 'READY') continue;
            if ($req_status === 'used' && !in_array($status, ['TERPAKAI','RETUR'])) continue;
            if ($req_status === 'all') {
                $allowed = ['READY','ONLINE','RUSAK','RETUR','TERPAKAI'];
                if ($block_only_all) {
                    $allowed = ['ONLINE','RUSAK','RETUR','TERPAKAI'];
                }
                if (!in_array($status, $allowed)) continue;
            }
            $customer_name = (string)($row['customer_name'] ?? '');
            $room_name = (string)($row['room_name'] ?? '');
            if ($customer_name === '' || $room_name === '') {
                $meta = $meta_queue_map[strtolower($uname)] ?? null;
                if ($meta) {
                    if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
                    if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
                }
            }
            $usage_list[] = [
                'first_login' => $row['first_login_real'] ?? $login_time,
                'login' => $login_time,
                'logout' => $logout_time,
                'username' => $uname,
                'customer_name' => $customer_name,
                'room_name' => $room_name,
                'profile' => $profile_label,
                'blok' => (normalize_blok_key($f_blok) ?: '-'),
                'ip' => $row['ip_address'] ?? '-',
                'mac' => $row['mac_address'] ?? '-',
                'uptime' => $uptime_display,
                'bytes' => (int)($row['last_bytes'] ?? 0),
                'status' => strtolower($status),
                'comment' => $comment,
                'relogin' => $relogin,
                'updated_at' => $row['updated_at'] ?? null
            ];
        }
    }

if ($is_usage && $req_show !== 'semua' && !empty($filter_date)) {
    $usage_list = array_values(array_filter($usage_list, function($it) use ($filter_date, $req_show, $req_status) {
        $status = strtolower((string)($it['status'] ?? ''));
        if ($status === 'baik') $status = 'ready';
        if ($status === 'ready') {
            if ($req_status === 'ready' || $req_status === 'all') {
                return true;
            }
            return false;
        }
        $ref = '';
        if (in_array($status, ['rusak','retur'], true)) {
            $ref = $it['updated_at'] ?? '';
        }
        if ($ref === '' || $ref === '-') $ref = $it['logout'] ?? '';
        if ($ref === '' || $ref === '-') $ref = $it['login'] ?? '';
        if ($ref === '' || $ref === '-') $ref = $it['first_login'] ?? '';
        $date_key = normalize_date_key($ref, $req_show);
        return ($date_key !== '' && $date_key === $filter_date);
    }));
}

foreach ($rows as $r) {
    $ref_user = extract_retur_user_from_ref($r['comment'] ?? '');
    if ($ref_user !== '') {
        $retur_ref_map[strtolower($ref_user)] = true;
    }
}

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date !== $filter_date) continue;

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    $comment = (string)($r['comment'] ?? '');
    $status = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    $cmt_low = strtolower($comment);

    if ($status === '' || $status === 'normal') {
        if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
        elseif (strpos($cmt_low, 'retur') !== false || $lh_status === 'retur') $status = 'retur';
        elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
        else $status = 'normal';
    }
    $retur_user = extract_retur_user_from_ref($comment);
    if ($retur_user !== '') {
        $status = 'retur';
    }
    $username_check = strtolower((string)($r['username'] ?? ''));
    $is_retur_source = ($username_check !== '' && isset($retur_ref_map[$username_check]));

    $gross_add = 0;
    $net_add = 0;

    if ($status === 'invalid') {
        $gross_add = 0;
        $net_add = 0;
    } elseif ($status === 'retur') {
        $gross_add = 0;
        $net_add = $price;
    } elseif ($status === 'rusak') {
        $gross_add = $price;
        $net_add = 0;
    } else {
        $gross_add = $price;
        $net_add = $price;
    }
    $bytes = (int)($r['last_bytes'] ?? 0);

    if (!in_array($status, ['rusak','invalid'], true) && !empty($r['username'])) {
        $unique_laku_users[$r['username']] = true;
    }
    $total_gross += $gross_add;
    $total_net += $net_add;
    if ($status === 'rusak') $total_qty_rusak += 1;
    if ($status === 'retur') $total_qty_retur += 1;
    if (!empty($r['username'])) {
        $u = $r['username'];
        $bytes_by_user[$u] = max((int)($bytes_by_user[$u] ?? 0), $bytes);
    }

    if ($status === 'rusak' && $is_retur_source) {
        continue;
    }

    $comment_display = $comment;
    if ($retur_user !== '') {
        $comment_display = 'Retur Ref: ' . $retur_user;
    }

    $customer_name = (string)($r['customer_name'] ?? '');
    $room_name = (string)($r['room_name'] ?? '');
    if ($customer_name === '' || $room_name === '') {
        $meta = $meta_queue_map[strtolower((string)($r['username'] ?? ''))] ?? null;
        if ($meta) {
            if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
            if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
        }
    }

    $list[] = [
        'time' => $r['sale_time'] ?: ($r['raw_time'] ?? ''),
        'username' => $r['username'] ?? '-',
        'customer_name' => $customer_name,
        'room_name' => $room_name,
        'profile' => $profile,
        'blok' => $blok,
        'comment' => $comment_display,
        'status' => $status,
        'is_retur_source' => $is_retur_source,
        'price' => $price,
        'gross' => $gross_add,
        'net' => $net_add,
        'bytes' => $bytes
    ];
}

if (!$is_usage && empty($list) && $filter_date !== '' && isset($db) && $db instanceof PDO && table_exists($db, 'sales_history')) {
    try {
        $stmtFallback = $db->prepare("SELECT
            sh.sale_time, sh.username, sh.profile, sh.profile_snapshot, sh.validity,
            sh.comment, sh.blok_name, sh.status, sh.price, sh.price_snapshot, sh.sprice_snapshot,
            lh.customer_name, lh.room_name, lh.last_bytes
          FROM sales_history sh
          LEFT JOIN login_history lh ON lh.username = sh.username
          WHERE sh.sale_date = :d
          ORDER BY sh.sale_time DESC");
        $stmtFallback->execute([':d' => $filter_date]);
        foreach ($stmtFallback->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $comment = (string)($r['comment'] ?? '');
            $status = strtolower((string)($r['status'] ?? ''));
            if ($status !== '') {
                if (strpos($status, 'rusak') !== false) $status = 'rusak';
                elseif (strpos($status, 'retur') !== false) $status = 'retur';
                elseif (strpos($status, 'invalid') !== false) $status = 'invalid';
                else $status = 'normal';
            } else {
                $status = 'normal';
            }
            $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
            if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
            $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
            if (($profile === '' || $profile === '-') && !empty($r['validity'])) {
                $profile = $r['validity'];
            }
            $blok = normalize_block_name_simple($r['blok_name'] ?? '') ?: extract_blok_name($comment);
            $retur_user = extract_retur_user_from_ref($comment);
            $comment_display = $retur_user !== '' ? ('Retur Ref: ' . $retur_user) : $comment;
            $customer_name = (string)($r['customer_name'] ?? '');
            $room_name = (string)($r['room_name'] ?? '');
            if ($customer_name === '' || $room_name === '') {
                $meta = $meta_queue_map[strtolower((string)($r['username'] ?? ''))] ?? null;
                if ($meta) {
                    if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
                    if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
                }
            }
            $list[] = [
                'time' => $r['sale_time'] ?? '',
                'username' => $r['username'] ?? '-',
                'customer_name' => $customer_name,
                'room_name' => $room_name,
                'profile' => $profile,
                'blok' => $blok,
                'comment' => $comment_display,
                'status' => $status,
                'is_retur_source' => false,
                'price' => $price,
                'gross' => $price,
                'net' => $price,
                'bytes' => (int)($r['last_bytes'] ?? 0)
            ];
        }
    } catch (Exception $e) {}
}

function esc($s){ return htmlspecialchars((string)$s); }

function normalize_uptime_diff($diff, $snap = 2) {
    $d = (int)$diff;
    if ($d <= 0) return 0;
    $mod = $d % 60;
    if ($mod <= $snap) {
        $d -= $mod;
    } elseif ($mod >= (60 - $snap)) {
        $d += (60 - $mod);
    }
    return $d;
}

$usage_status_totals = [
    'ready' => 0,
    'online' => 0,
    'terpakai' => 0,
    'rusak' => 0,
    'retur' => 0
];
$usage_block_totals = [];
$usage_profile_totals = [];
$usage_bytes_total = 0;

if ($is_usage) {
    foreach ($usage_list as $it) {
        $st = strtolower((string)($it['status'] ?? ''));
        if ($st === 'baik') $st = 'ready';
        if ($block_only_all && $st === 'ready') {
            continue;
        }
        if (!isset($usage_status_totals[$st])) $st = 'terpakai';
        $usage_status_totals[$st]++;
        $usage_bytes_total += (int)($it['bytes'] ?? 0);

        $blk = (string)($it['blok'] ?? '-');
        if ($blk === '') $blk = '-';
        if (!isset($usage_block_totals[$blk])) {
            $usage_block_totals[$blk] = [
                'ready' => 0, 'online' => 0, 'terpakai' => 0, 'rusak' => 0, 'retur' => 0, 'total' => 0
            ];
        }
        $usage_block_totals[$blk][$st]++;
        $usage_block_totals[$blk]['total']++;

        $pf = normalize_profile_label($it['profile'] ?? '-');
        if ($pf === '') $pf = '-';
        if (!isset($usage_profile_totals[$pf])) {
            $usage_profile_totals[$pf] = [
                'ready' => 0, 'online' => 0, 'terpakai' => 0, 'rusak' => 0, 'retur' => 0, 'total' => 0
            ];
        }
        $usage_profile_totals[$pf][$st]++;
        $usage_profile_totals[$pf]['total']++;
    }

    if (!empty($usage_block_totals)) {
        ksort($usage_block_totals, SORT_NATURAL | SORT_FLAG_CASE);
    }
    if (!empty($usage_profile_totals)) {
        ksort($usage_profile_totals, SORT_NATURAL | SORT_FLAG_CASE);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $is_usage ? 'Print List' : 'Print Rincian Harian' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #ddd; padding:6px; text-align:left; vertical-align:top; }
        th { background:#f5f5f5; }
        .usage-table th { text-align:center; vertical-align:middle; }
        .usage-table td { text-align:center; vertical-align:middle; }
        .usage-table td.col-uptime, .usage-table td.col-bytes { text-align:right; }
        .status-normal { color:#0a7f2e; font-weight:700; }
        .status-rusak { color:#d35400; font-weight:700; }
        .status-retur { color:#7f8c8d; font-weight:700; }
        .status-invalid { color:#c0392b; font-weight:700; }
        .status-online { color:#1976d2; font-weight:700; }
        .status-terpakai { color:#0a7f2e; font-weight:700; }
        .status-ready { color:#0a7f2e; font-weight:700; }
        .status-relogin { color:#fff; background:#6f42c1; font-weight:700; padding:2px 6px; border-radius:4px; display:inline-block; }
        .summary-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .summary-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:6px; border:1px solid #999; background:#f8f8f8; font-size:12px; }
        .summary-badge .label { font-weight:700; color:#333; }
        .summary-badge .value { font-weight:700; color:#111; }
        @media print {
            .toolbar { display:none; }
            .status-relogin { color:#fff !important; background:#6f42c1 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

        <?php if ($is_usage): ?>
                <?php
                    $is_ready_view = in_array($req_status, ['ready','baik'], true);
                    $usage_title = 'Print List';
                    if ($req_status === 'online') {
                        $usage_title = 'List Pemakaian Online';
                    } elseif ($req_status === 'used' || $req_status === 'terpakai') {
                        $usage_title = 'List Pemakaian Voucher';
                    } elseif ($req_status === 'rusak') {
                        $usage_title = 'List Voucher Rusak';
                    } elseif ($req_status === 'retur') {
                        $usage_title = 'List Voucher Yang Retur';
                    } elseif ($is_ready_view) {
                        $usage_title = 'List Username Ready';
                    }
                ?>
                <h2><?= $usage_title ?></h2>
            <div class="meta">
        <?php
            $header_blok = '';
            if ($filter_blok !== '') {
                $header_blok = strip_blok_prefix($filter_blok);
            }
            $header_profile = '';
            if ($filter_profile_kind !== '') {
                $header_profile = $filter_profile_kind . ' Menit';
            } elseif ($filter_profile !== '' && strtolower($filter_profile) !== 'all') {
                $header_profile = normalize_profile_label($filter_profile);
            }
        ?>
        <?php
            $date_label = 'Semua Periode';
            if ($filter_date !== '') {
                $date_label = format_filter_date_label($filter_date, $req_show);
            }
        ?>
        <?php if ($filter_user !== ''): ?>User: <?= esc($filter_user) ?> | <?php endif; ?>
        <?php if ($header_blok !== ''): ?>Blok: <?= esc($header_blok) ?> | <?php endif; ?>
        <?php if ($header_profile !== ''): ?>Profile: <?= esc($header_profile) ?> | <?php endif; ?>
                Status: <?= esc($usage_label) ?> | 
                Tanggal: <?= esc($date_label) ?> | Jam Cetak: <?= esc(format_time_only(date('Y-m-d H:i:s'))) ?>
      </div>

    <table class="usage-table">
          <thead>
              <tr>
                  <?php if (!$is_ready_view): ?>
                      <th>Waktu</th>
                  <?php endif; ?>
                  <th>Username</th>
                  <th>Nama</th>
                  <th>Profile</th>
                  <th>Blok</th>
                  <th>Kamar</th>
                  <?php if (!$is_ready_view): ?>
                      <th>Uptime</th>
                      <th>Bytes</th>
                  <?php endif; ?>
                  <th>Status</th>
              </tr>
          </thead>
          <tbody>
              <?php if (empty($usage_list)): ?>
                  <tr><td colspan="<?= $is_ready_view ? 6 : 9 ?>" style="text-align:center;">Tidak ada data</td></tr>
              <?php else: ?>
                  <?php foreach ($usage_list as $it): ?>
                  <tr>
                      <?php if (!$is_ready_view): ?>
                          <td><?= esc(format_time_only($it['first_login'] ?? '-')) ?></td>
                      <?php endif; ?>
                      <td><?= esc($it['username']) ?></td>
                      <td><?= esc(($it['customer_name'] ?? '') !== '' ? $it['customer_name'] : '-') ?></td>
                      <td><?= esc($it['profile'] ?? '-') ?></td>
                      <td><?= esc(strip_blok_prefix($it['blok'])) ?></td>
                      <td><?= esc(($it['room_name'] ?? '') !== '' ? $it['room_name'] : '-') ?></td>
                      <?php if (!$is_ready_view): ?>
                          <td class="col-uptime"><?= esc($it['uptime']) ?></td>
                          <td class="col-bytes"><?= esc(format_bytes_short($it['bytes'])) ?></td>
                      <?php endif; ?>
                      <?php
                          $st = strtolower((string)($it['status'] ?? ''));
                          $st_label = '-';
                          $st_class = '';
                          if ($st === 'online') { $st_label = 'Online'; $st_class = 'status-online'; }
                          elseif ($st === 'ready') { $st_label = 'Ready'; $st_class = 'status-ready'; }
                          elseif ($st === 'retur') { $st_label = 'Retur'; $st_class = 'status-retur'; }
                          elseif ($st === 'rusak') { $st_label = 'Rusak'; $st_class = 'status-rusak'; }
                          elseif ($st === 'terpakai') { $st_label = 'Terpakai'; $st_class = 'status-terpakai'; }
                          if (!empty($it['relogin'])) { $st_label = 'Relogin'; $st_class = 'status-relogin'; }
                      ?>
                      <td><span class="<?= esc($st_class) ?>"><?= esc($st_label) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
              <?php endif; ?>
          </tbody>
      </table>
      <div class="summary-row">
          <div class="summary-badge"><span class="label">Total Voucher Login</span> <span class="value"><?= number_format((int)count($usage_list),0,',','.') ?></span></div>
          <div class="summary-badge"><span class="label">Byte</span> <span class="value"><?= esc(format_bytes_short((int)$usage_bytes_total)) ?></span></div>
      </div>

      <?php if ($req_status === 'all'): ?>
          <div class="summary-row">
              <div class="summary-badge"><span class="label">Total</span> <span class="value"><?= number_format(array_sum($usage_status_totals),0,',','.') ?></span></div>
              <?php if (!$block_only_all): ?>
                  <div class="summary-badge"><span class="label">Ready</span> <span class="value"><?= number_format((int)$usage_status_totals['ready'],0,',','.') ?></span></div>
              <?php endif; ?>
              <div class="summary-badge"><span class="label">Online</span> <span class="value"><?= number_format((int)$usage_status_totals['online'],0,',','.') ?></span></div>
              <div class="summary-badge"><span class="label">Terpakai</span> <span class="value"><?= number_format((int)$usage_status_totals['terpakai'],0,',','.') ?></span></div>
              <div class="summary-badge"><span class="label">Rusak</span> <span class="value"><?= number_format((int)$usage_status_totals['rusak'],0,',','.') ?></span></div>
              <div class="summary-badge"><span class="label">Retur</span> <span class="value"><?= number_format((int)$usage_status_totals['retur'],0,',','.') ?></span></div>
              <?php if (!is_null($audit_net)): ?>
                  <div class="summary-badge"><span class="label">Net Audit</span> <span class="value"><?= $cur ?> <?= number_format((int)$audit_net,0,',','.') ?></span></div>
              <?php endif; ?>
              <div class="summary-badge"><span class="label">Byte</span> <span class="value"><?= esc(format_bytes_short((int)$usage_bytes_total)) ?></span></div>
          </div>
          <?php if (!$block_only_all): ?>
              <h3 style="margin-top:18px;">Rekap Per Status</h3>
              <table>
                  <thead>
                      <tr>
                          <th>Status</th>
                          <th>Total</th>
                      </tr>
                  </thead>
                  <tbody>
                      <tr><td>Ready</td><td><?= number_format((int)$usage_status_totals['ready'],0,',','.') ?></td></tr>
                      <tr><td>Online</td><td><?= number_format((int)$usage_status_totals['online'],0,',','.') ?></td></tr>
                      <tr><td>Terpakai</td><td><?= number_format((int)$usage_status_totals['terpakai'],0,',','.') ?></td></tr>
                      <tr><td>Rusak</td><td><?= number_format((int)$usage_status_totals['rusak'],0,',','.') ?></td></tr>
                      <tr><td>Retur</td><td><?= number_format((int)$usage_status_totals['retur'],0,',','.') ?></td></tr>
                  </tbody>
              </table>

              <h3 style="margin-top:18px;">Rekap Per Blok</h3>
              <table>
                  <thead>
                      <tr>
                          <th>Blok</th>
                          <th>Ready</th>
                          <th>Online</th>
                          <th>Terpakai</th>
                          <th>Rusak</th>
                          <th>Retur</th>
                          <th>Total</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if (empty($usage_block_totals)): ?>
                          <tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>
                      <?php else: ?>
                          <?php foreach ($usage_block_totals as $blk => $val): ?>
                              <tr>
                                  <td><?= esc(strip_blok_prefix($blk)) ?></td>
                                  <td><?= number_format((int)$val['ready'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['online'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['terpakai'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['rusak'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['retur'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['total'],0,',','.') ?></td>
                              </tr>
                          <?php endforeach; ?>
                      <?php endif; ?>
                  </tbody>
              </table>

              <h3 style="margin-top:18px;">Rekap Per Profile</h3>
              <table>
                  <thead>
                      <tr>
                          <th>Profile</th>
                          <th>Ready</th>
                          <th>Online</th>
                          <th>Terpakai</th>
                          <th>Rusak</th>
                          <th>Retur</th>
                          <th>Total</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if (empty($usage_profile_totals)): ?>
                          <tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>
                      <?php else: ?>
                          <?php foreach ($usage_profile_totals as $pf => $val): ?>
                              <tr>
                                  <td><?= esc($pf) ?></td>
                                  <td><?= number_format((int)$val['ready'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['online'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['terpakai'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['rusak'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['retur'],0,',','.') ?></td>
                                  <td><?= number_format((int)$val['total'],0,',','.') ?></td>
                              </tr>
                          <?php endforeach; ?>
                      <?php endif; ?>
                  </tbody>
              </table>
          <?php endif; ?>
      <?php endif; ?>
    <?php else: ?>
    <h2>Rincian Transaksi Harian</h2>
    <div class="meta">Tanggal: <?= esc(format_date_only_indo($filter_date)) ?></div>

      <table>
          <thead>
              <tr>
                  <th>Jam</th>
                  <th>Username</th>
                  <th>Nama</th>
                  <th>Profile</th>
                  <th>Blok</th>
                  <th>Kamar</th>
                  <th>Catatan</th>
                  <th>Status</th>
              </tr>
          </thead>
          <tbody>
              <?php if (empty($list)): ?>
                  <tr><td colspan="8" style="text-align:center;">Tidak ada data</td></tr>
              <?php else: ?>
                  <?php foreach ($list as $it): ?>
                  <tr>
                      <td><?= esc($it['time']) ?></td>
                      <td><?= esc($it['username']) ?></td>
                      <td><?= esc(($it['customer_name'] ?? '') !== '' ? $it['customer_name'] : '-') ?></td>
                      <td><?= esc($it['profile']) ?></td>
                      <td><?= esc(strip_blok_prefix($it['blok'] ?? '-')) ?></td>
                      <td><?= esc(($it['room_name'] ?? '') !== '' ? $it['room_name'] : '-') ?></td>
                                            <td><?= nl2br(esc($it['comment'])) ?></td>
                                            <?php
                                                $st = strtolower((string)($it['status'] ?? ''));
                                                $st_label = strtoupper($st);
                                                $st_class = 'status-' . $st;
                                                if ($st === 'rusak' && !empty($it['is_retur_source'])) {
                                                    $st_label = 'RUSAK (DIGANTI)';
                                                    $st_class = 'status-retur';
                                                }
                                                if ($st === 'retur') {
                                                    $st_label = 'RETUR (PENGGANTI)';
                                                }
                                            ?>
                                            <td class="<?= esc($st_class) ?>"><?= esc($st_label) ?></td>
                  </tr>
                  <?php endforeach; ?>
              <?php endif; ?>
          </tbody>
      </table>
      <?php
          $total_voucher_laku = count($unique_laku_users);
          $total_bytes = array_sum($bytes_by_user);
      ?>
      <div class="summary-row">
          <div class="summary-badge"><span class="label">Terjual</span> <span class="value"><?= number_format((int)$total_voucher_laku,0,',','.') ?></span></div>
          <?php if (!empty($total_qty_rusak)): ?>
              <div class="summary-badge"><span class="label">Rusak</span> <span class="value"><?= number_format((int)$total_qty_rusak,0,',','.') ?></span></div>
          <?php endif; ?>
          <?php if (!empty($total_qty_retur)): ?>
              <div class="summary-badge"><span class="label">Retur</span> <span class="value"><?= number_format((int)$total_qty_retur,0,',','.') ?></span></div>
          <?php endif; ?>
          <?php if (!is_null($audit_net)): ?>
              <div class="summary-badge"><span class="label">Net Audit</span> <span class="value"><?= $cur ?> <?= number_format((int)$audit_net,0,',','.') ?></span></div>
          <?php endif; ?>
          <div class="summary-badge"><span class="label">Net Income</span> <span class="value"><?= $cur ?> <?= number_format((int)$total_net,0,',','.') ?></span></div>
          <div class="summary-badge"><span class="label">Byte</span> <span class="value"><?= esc(format_bytes_short((int)$total_bytes)) ?></span></div>
            </div>
        <?php endif; ?>

<script>
function shareReport(){
    const title = <?= $is_usage ? "'Print List'" : "'Rincian Transaksi Harian'" ?>;
    if (navigator.share) {
        navigator.share({
            title: title,
            url: window.location.href
        });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}
</script>
</body>
</html>
