<?php
session_start();
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug_mode) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    error_reporting(0);
}

if (!isset($_SESSION["mikhmon"])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div style="padding:20px; font-family:Arial, sans-serif; color:#111;">Sesi login tidak ditemukan. Silakan login ulang.</div>';
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
require_once($root_dir . '/report/laporan/helpers.php');

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
$cur = isset($currency) ? $currency : 'Rp';

$mode = $_GET['mode'] ?? '';
$req_status = strtolower((string)($_GET['status'] ?? ''));
$is_usage = ($mode === 'usage' || in_array($req_status, ['used','terpakai','online','rusak','retur','ready','baik','all'], true));
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

function table_exists_local(PDO $db, $table) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function format_time_only_local($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('H:i:s', $ts);
}

function format_date_only_local($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

function format_date_time_local($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i:s', $ts);
}

function format_month_label_local($dateStr) {
    $ts = strtotime((string)$dateStr . '-01');
    if ($ts === false) $ts = strtotime((string)$dateStr);
    if ($ts === false) return (string)$dateStr;
    $months = [
        'Januari','Februari','Maret','April','Mei','Juni',
        'Juli','Agustus','September','Oktober','November','Desember'
    ];
    $m = (int)date('n', $ts);
    $month = $months[$m - 1] ?? date('m', $ts);
    return $month . ' ' . date('Y', $ts);
}

function format_day_label_local($dateStr) {
    $ts = strtotime((string)$dateStr);
    if ($ts === false) return '';
    $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $idx = (int)date('w', $ts);
    return $days[$idx] ?? '';
}

function build_rincian_title_local($req_show, $filter_date) {
    $stamp = date('His');
    if ($req_show === 'bulanan') {
        $month_label = format_month_label_local($filter_date);
        return 'LaporanRincianBulanan-' . str_replace(' ', '-', $month_label) . '-' . $stamp;
    }
    if ($req_show === 'tahunan') {
        $year = preg_match('/^\d{4}/', (string)$filter_date, $m) ? $m[0] : (string)$filter_date;
        return 'LaporanRincianTahunan-' . $year . '-' . $stamp;
    }
    $day_label = format_day_label_local($filter_date);
    $date_label = format_date_only_local($filter_date);
    $suffix = $day_label !== '' ? ($day_label . '-' . $date_label) : $date_label;
    return 'LaporanRincianHarian-' . $suffix . '-' . $stamp;
}

function format_bytes_short_local($bytes) {
    $b = (int)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = (float)$b;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($n, $dec, '.', '') . ' ' . $units[$i];
}

function normalize_profile_label_local($profile) {
    $p = trim((string)$profile);
    if ($p === '') return '-';
    if (preg_match('/\b(10|30)\s*(menit|m|min)\b/i', $p, $m)) {
        return $m[1] . ' Menit';
    }
    return $p;
}

function format_blok_short_local($blok) {
    $raw = strtoupper(trim((string)$blok));
    if ($raw === '') return '-';
    $raw = preg_replace('/^BLOK[-_\s]*/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^([A-Z0-9]+)/', $raw, $m)) {
        return $m[1];
    }
    return $raw;
}

function format_room_short_local($room) {
    $raw = trim((string)$room);
    if ($raw === '') return '-';
    if (preg_match('/(\d+)/', $raw, $m)) {
        return $m[1];
    }
    return $raw;
}

$usage_list = [];
$tx_list = [];
$meta_queue_map = [];
$debug_info = [];
$tx_total_qty = 0;
$tx_total_amount = 0;
$tx_v10_qty = 0;
$tx_v10_amount = 0;
$tx_v30_qty = 0;
$tx_v30_amount = 0;
$tx_rusak_qty = 0;
$tx_rusak_amount = 0;
$login_name_map = [];

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (table_exists_local($db, 'login_meta_queue')) {
            $stmtMeta = $db->query("SELECT voucher_code, customer_name, room_name FROM login_meta_queue WHERE voucher_code != '' ORDER BY created_at DESC");
            while ($row = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
                $vc = strtolower(trim((string)($row['voucher_code'] ?? '')));
                if ($vc === '' || isset($meta_queue_map[$vc])) continue;
                $meta_queue_map[$vc] = [
                    'customer_name' => trim((string)($row['customer_name'] ?? '')),
                    'room_name' => trim((string)($row['room_name'] ?? ''))
                ];
            }
        }

        if (table_exists_local($db, 'login_history')) {
            $stmtLn = $db->query("SELECT username, customer_name, room_name FROM login_history WHERE username != ''");
            while ($row = $stmtLn->fetch(PDO::FETCH_ASSOC)) {
                $u = strtolower(trim((string)($row['username'] ?? '')));
                if ($u === '' || isset($login_name_map[$u])) continue;
                $login_name_map[$u] = [
                    'customer_name' => trim((string)($row['customer_name'] ?? '')),
                    'room_name' => trim((string)($row['room_name'] ?? ''))
                ];
            }
        }

        if ($is_usage && table_exists_local($db, 'login_history')) {
            $stmt = $db->query("SELECT username, customer_name, room_name, validity, blok_name, last_status, last_uptime, last_bytes, login_time_real, logout_time_real, first_login_real, updated_at FROM login_history WHERE username != ''");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uname = (string)($row['username'] ?? '');
                if ($uname === '') continue;
                if ($filter_user !== '' && $uname !== $filter_user) continue;

                $status = strtolower((string)($row['last_status'] ?? 'ready'));
                if ($status === 'used') $status = 'terpakai';
                if (!in_array($status, ['ready','online','terpakai','rusak','retur','invalid'], true)) {
                    $status = 'ready';
                }

                if ($req_status !== '' && $req_status !== 'all') {
                    if ($req_status === 'used' && !in_array($status, ['terpakai','retur'], true)) continue;
                    if ($req_status === 'terpakai' && $status !== 'terpakai') continue;
                    if ($req_status === 'online' && $status !== 'online') continue;
                    if ($req_status === 'rusak' && $status !== 'rusak') continue;
                    if ($req_status === 'retur' && $status !== 'retur') continue;
                    if (in_array($req_status, ['ready','baik'], true) && $status !== 'ready') continue;
                }

                if ($req_show !== 'semua' && $filter_date !== '') {
                    $ref = (string)($row['logout_time_real'] ?? $row['login_time_real'] ?? $row['first_login_real'] ?? $row['updated_at'] ?? '');
                    if ($ref !== '') {
                        $key = normalize_date_key($ref, $req_show);
                        if ($key !== $filter_date && !in_array($status, ['ready'], true)) continue;
                    }
                }

                $customer_name = (string)($row['customer_name'] ?? '');
                $room_name = (string)($row['room_name'] ?? '');
                if ($customer_name === '' || $room_name === '') {
                    $u_key = strtolower($uname);
                    $ln = $login_name_map[$u_key] ?? null;
                    if ($ln) {
                        if ($customer_name === '' && $ln['customer_name'] !== '') $customer_name = $ln['customer_name'];
                        if ($room_name === '' && $ln['room_name'] !== '') $room_name = $ln['room_name'];
                    }
                    if ($customer_name === '' || $room_name === '') {
                        $meta = $meta_queue_map[$u_key] ?? null;
                        if ($meta) {
                            if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
                            if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
                        }
                    }
                }

                $usage_list[] = [
                    'username' => $uname,
                    'customer_name' => $customer_name,
                    'room_name' => $room_name,
                    'profile' => normalize_profile_label_local($row['validity'] ?? ''),
                    'blok' => (string)($row['blok_name'] ?? '-'),
                    'login' => (string)($row['login_time_real'] ?? ''),
                    'logout' => (string)($row['logout_time_real'] ?? ''),
                    'uptime' => (string)($row['last_uptime'] ?? ''),
                    'bytes' => (int)($row['last_bytes'] ?? 0),
                    'status' => $status
                ];
            }
        }

        if (!$is_usage && $filter_date !== '' && (table_exists_local($db, 'sales_history') || table_exists_local($db, 'live_sales'))) {
            $whereExpr = 'sale_date = :d';
            $dateParam = $filter_date;
            if ($req_show === 'bulanan') {
                $whereExpr = 'sale_date LIKE :d';
                $dateParam = $filter_date . '%';
            } elseif ($req_show === 'tahunan') {
                $whereExpr = 'sale_date LIKE :d';
                $dateParam = $filter_date . '%';
            }
            $selects = [];
            if (table_exists_local($db, 'sales_history')) {
                $selects[] = "SELECT sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                    sh.username, sh.profile, sh.profile_snapshot, sh.validity,
                    sh.comment, sh.blok_name, sh.status, sh.price, sh.price_snapshot, sh.sprice_snapshot,
                    sh.qty, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.full_raw_data,
                    lh.customer_name, lh.room_name, lh.last_bytes, lh.last_status, lh.first_login_real
                FROM sales_history sh
                LEFT JOIN login_history lh ON lh.username = sh.username
                WHERE $whereExpr";
            }
            if (table_exists_local($db, 'live_sales')) {
                $selects[] = "SELECT ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                    ls.username, ls.profile, ls.profile_snapshot, ls.validity,
                    ls.comment, ls.blok_name, ls.status, ls.price, ls.price_snapshot, ls.sprice_snapshot,
                    ls.qty, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.full_raw_data,
                    lh2.customer_name, lh2.room_name, lh2.last_bytes, lh2.last_status, lh2.first_login_real
                FROM live_sales ls
                LEFT JOIN login_history lh2 ON lh2.username = ls.username
                WHERE $whereExpr AND ls.sync_status = 'pending'";
            }
            $sql = implode(" UNION ALL ", $selects) . " ORDER BY sale_time DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([':d' => $dateParam]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $retur_ref_map = [];
            foreach ($rows as $rr) {
                $ref_u = extract_retur_user_from_ref($rr['comment'] ?? '');
                if ($ref_u !== '') $retur_ref_map[strtolower($ref_u)] = true;
            }
            $seen_sales = [];
            $seen_user_day = [];
            $unique_laku_users = [];
            foreach ($rows as $r) {
                $sale_date = (string)($r['sale_date'] ?? '');
                $sale_time = (string)($r['sale_time'] ?? '');
                $uname = (string)($r['username'] ?? '-');
                $comment = (string)($r['comment'] ?? '');
                if ($uname !== '-' && isset($retur_ref_map[strtolower($uname)])) {
                    continue;
                }
                if ($uname !== '' && $sale_date !== '') {
                    $user_day_key = $uname . '|' . $sale_date;
                    if (isset($seen_user_day[$user_day_key])) {
                        continue;
                    }
                    $seen_user_day[$user_day_key] = true;
                }

                $raw_key = trim((string)($r['full_raw_data'] ?? ''));
                $unique_key = '';
                if ($raw_key !== '') {
                    $unique_key = 'raw|' . $raw_key;
                } elseif ($uname !== '' && $sale_date !== '') {
                    $unique_key = $uname . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($sale_time ?? '')));
                    if ($unique_key === $uname . '|') {
                        $unique_key = $uname . '|' . $sale_date . '|' . ($sale_time ?? '');
                    }
                } elseif ($sale_date !== '') {
                    $unique_key = 'date|' . $sale_date . '|' . ($sale_time ?? '');
                }
                if ($unique_key !== '') {
                    if (isset($seen_sales[$unique_key])) continue;
                    $seen_sales[$unique_key] = true;
                }

                $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
                if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
                $qty = (int)($r['qty'] ?? 0);
                if ($qty <= 0) $qty = 1;

                $raw_comment = (string)($r['comment'] ?? '');
                $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
                if ($profile === '' || $profile === '-') {
                    $hint = (string)($r['validity'] ?? '') . ' ' . $raw_comment;
                    if (preg_match('/\b30\s*(menit|m)\b|30menit|profile\s*[:=]?\s*30\b|\b30m\b/i', $hint)) {
                        $profile = '30 Menit';
                    } elseif (preg_match('/\b10\s*(menit|m)\b|10menit|profile\s*[:=]?\s*10\b|\b10m\b/i', $hint)) {
                        $profile = '10 Menit';
                    }
                }
                if ($price <= 0) {
                    $guess_profile = infer_profile_from_comment($raw_comment);
                    if ($guess_profile !== '') {
                        $profile = $guess_profile;
                        $price = resolve_price_from_profile($profile);
                    }
                }
                if ($price <= 0 && $profile !== '' && $profile !== '-') {
                    $price = resolve_price_from_profile($profile);
                }
                $line_price = $price * $qty;
                $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);

                $status = strtolower((string)($r['status'] ?? ''));
                $lh_status = strtolower((string)($r['last_status'] ?? ''));
                if ($status !== '') {
                    if (strpos($status, 'rusak') !== false) $status = 'rusak';
                    elseif (strpos($status, 'retur') !== false) $status = 'retur';
                    elseif (strpos($status, 'invalid') !== false) $status = 'invalid';
                    elseif (strpos($status, 'online') !== false) $status = 'online';
                    elseif (strpos($status, 'terpakai') !== false) $status = 'terpakai';
                    elseif (strpos($status, 'ready') !== false) $status = 'ready';
                }
                if ($lh_status !== '') {
                    if (strpos($lh_status, 'rusak') !== false) $lh_status = 'rusak';
                    elseif (strpos($lh_status, 'retur') !== false) $lh_status = 'retur';
                    elseif (strpos($lh_status, 'invalid') !== false) $lh_status = 'invalid';
                }
                $cmt_low = strtolower($raw_comment);
                if ($status === '' || $status === 'normal') {
                    if ((int)($r['is_invalid'] ?? 0) === 1) $status = 'invalid';
                    elseif ((int)($r['is_retur'] ?? 0) === 1) $status = 'retur';
                    elseif ((int)($r['is_rusak'] ?? 0) === 1) $status = 'rusak';
                    elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
                    elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';
                    elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
                    else $status = 'normal';
                }

                $blok_row = (string)($r['blok_name'] ?? '');
                $has_block_hint = ($blok_row !== '' || preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $raw_comment));
                if (!$has_block_hint && !in_array($status, ['rusak', 'retur', 'invalid'], true)) {
                    continue;
                }

                $net_add = 0;
                if ($status === 'invalid') {
                    $net_add = 0;
                } elseif ($status === 'retur') {
                    $net_add = $line_price;
                } elseif ($status === 'rusak') {
                    $net_add = 0;
                } else {
                    $net_add = $line_price;
                }

                if ($status === 'rusak') {
                    $tx_rusak_qty += 1;
                    $tx_rusak_amount += $line_price;
                }

                if (!in_array($status, ['rusak','invalid'], true) && $uname !== '-') {
                    $unique_laku_users[$uname] = true;
                }

                $customer_name = (string)($r['customer_name'] ?? '');
                $room_name = (string)($r['room_name'] ?? '');
                if ($customer_name === '' || $room_name === '') {
                    $u_key = strtolower($uname);
                    $ln = $login_name_map[$u_key] ?? null;
                    if ($ln) {
                        if ($customer_name === '' && $ln['customer_name'] !== '') $customer_name = $ln['customer_name'];
                        if ($room_name === '' && $ln['room_name'] !== '') $room_name = $ln['room_name'];
                    }
                    if ($customer_name === '' || $room_name === '') {
                        $meta = $meta_queue_map[$u_key] ?? null;
                        if ($meta) {
                            if ($customer_name === '' && $meta['customer_name'] !== '') $customer_name = $meta['customer_name'];
                            if ($room_name === '' && $meta['room_name'] !== '') $room_name = $meta['room_name'];
                        }
                    }
                }

                $profile_label = resolve_profile_label($profile);
                if ($profile_label === '') $profile_label = $profile;

                $tx_list[] = [
                    'time' => $sale_time,
                    'username' => $uname,
                    'customer_name' => $customer_name,
                    'room_name' => $room_name,
                    'profile' => $profile_label,
                    'blok' => $blok,
                    'comment' => $comment,
                    'status' => $status,
                    'price' => $line_price,
                    'bytes' => (int)($r['last_bytes'] ?? 0)
                ];

                $tx_total_amount += $net_add;
                $profile_key = strtolower((string)$profile_label);
                if (preg_match('/\b10\b/', $profile_key)) {
                    if ($net_add > 0) $tx_v10_qty += 1;
                    $tx_v10_amount += $net_add;
                } elseif (preg_match('/\b30\b/', $profile_key)) {
                    if ($net_add > 0) $tx_v30_qty += 1;
                    $tx_v30_amount += $net_add;
                }
            }
            $tx_total_qty = count($unique_laku_users);
        }

        if ($debug_mode) {
            $debug_info['meta_queue_count'] = count($meta_queue_map);
            $debug_info['usage_count'] = count($usage_list);
            $debug_info['tx_count'] = count($tx_list);
        }
    } catch (Exception $e) {
        if ($debug_mode) {
            $debug_info['db_error'] = $e->getMessage();
        }
    }
}

$usage_label = 'Terpakai';
if ($req_status === 'online') $usage_label = 'Online';
elseif ($req_status === 'rusak') $usage_label = 'Rusak';
elseif ($req_status === 'retur') $usage_label = 'Retur';
elseif ($req_status === 'ready' || $req_status === 'baik') $usage_label = 'Ready';
elseif ($req_status === 'all') $usage_label = 'Semua';

$rincian_title = build_rincian_title_local($req_show, $filter_date);
$rincian_heading = 'Rincian Transaksi Harian';
$rincian_date_label = 'Tanggal: ' . format_date_only_local($filter_date);
if ($req_show === 'bulanan') {
    $rincian_heading = 'Rincian Transaksi Bulanan';
    $rincian_date_label = 'Bulan: ' . format_month_label_local($filter_date);
} elseif ($req_show === 'tahunan') {
    $year = preg_match('/^\d{4}/', (string)$filter_date, $m) ? $m[0] : (string)$filter_date;
    $rincian_heading = 'Rincian Transaksi Tahunan';
    $rincian_date_label = 'Tahun: ' . $year;
}

?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_usage ? 'Print List' : htmlspecialchars($rincian_title) ?></title>
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; }
        .meta { font-size:12px; color:#374151; margin-bottom:10px; margin-top: -10px; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        .summary-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .summary-pill { border:1px solid #999; border-radius:6px; padding:6px 10px; background:#f8f8f8; font-size:12px; }
        .row-rusak { background:#f8d7da; }
        .row-retur { background:#d1e7dd; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #d1d5db; padding:6px 8px; }
        th { background:#f3f4f6; text-align:center; }
        .text-right { text-align:right; }
        .text-center { text-align:center; }
        @media print { .toolbar { display:none; } }
    </style>
</head>
<body>
<div class="toolbar" style="margin-bottom:10px;">
    <button class="btn" onclick="window.print()">Print / Download PDF</button>
</div>

<?php if ($debug_mode && !empty($debug_info)): ?>
    <pre style="background:#fff3cd;border:1px solid #f1c40f;padding:10px; font-size:12px;">DEBUG: <?= htmlspecialchars(json_encode($debug_info, JSON_PRETTY_PRINT)) ?></pre>
<?php endif; ?>

<?php if ($is_usage): ?>
    <h2>List Pemakaian</h2>
    <div class="meta">
        Status: <strong><?= htmlspecialchars($usage_label) ?></strong> |
        Tanggal: <strong><?= htmlspecialchars($filter_date !== '' ? $filter_date : 'Semua') ?></strong>
    </div>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>User</th>
                <th>Nama</th>
                <th>Profile</th>
                <th>Blok</th>
                <th>Kamar</th>
                <th>Uptime</th>
                <th>Bytes</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($usage_list)): ?>
                <tr><td colspan="9" class="text-center">Tidak ada data</td></tr>
            <?php else: foreach ($usage_list as $it): ?>
                <?php
                    $st_row = strtolower((string)($it['status'] ?? ''));
                    $row_cls = '';
                    if ($st_row === 'rusak') $row_cls = 'row-rusak';
                    elseif ($st_row === 'retur') $row_cls = 'row-retur';
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="text-center"><?= htmlspecialchars(format_time_only_local($it['login'] ?: $it['logout'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['username']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['customer_name'] !== '' ? $it['customer_name'] : '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['profile'] ?: '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars(format_blok_short_local($it['blok'] ?? '')) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['room_name'] !== '' ? format_room_short_local($it['room_name']) : '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['uptime'] ?: '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars(format_bytes_short_local($it['bytes'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars(strtoupper($it['status'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
<?php else: ?>
    <h2><?= htmlspecialchars($rincian_heading) ?></h2>
    <div class="meta"><?= htmlspecialchars($rincian_date_label) ?></div>
    <table>
        <thead>
            <tr>
                <th>Jam</th>
                <th>User</th>
                <th>Profile</th>
                <th>Nama</th>
                <th>Blok</th>
                <th>Kamar</th>
                <th class="text-right">Bandwidth</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tx_list)): ?>
                <tr><td colspan="8" class="text-center">Tidak ada data</td></tr>
            <?php else: foreach ($tx_list as $it): ?>
                <?php
                    $st_row = strtolower((string)($it['status'] ?? ''));
                    $row_cls = '';
                    if ($st_row === 'rusak') $row_cls = 'row-rusak';
                    elseif ($st_row === 'retur') $row_cls = 'row-retur';
                ?>
                <tr class="<?= $row_cls ?>">
                    <td class="text-center"><?= htmlspecialchars($it['time'] ?: '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['username']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['profile'] ?: '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['customer_name'] !== '' ? $it['customer_name'] : '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars(format_blok_short_local($it['blok'] ?? '')) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['room_name'] !== '' ? format_room_short_local($it['room_name']) : '-') ?></td>
                    <td class="text-right"><?= htmlspecialchars(format_bytes_short_local((int)$it['bytes'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars(strtoupper($it['status'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="summary-row">
        <div class="summary-pill"><strong>Total QTY:</strong> <?= number_format((int)$tx_total_qty,0,',','.') ?></div>
        <div class="summary-pill"><strong>Total Omset:</strong> <?= $cur ?> <?= number_format((int)$tx_total_amount,0,',','.') ?></div>
        <div class="summary-pill"><strong>V10:</strong> <?= number_format((int)$tx_v10_qty,0,',','.') ?> | <strong>Omset:</strong> <?= $cur ?> <?= number_format((int)$tx_v10_amount,0,',','.') ?></div>
        <div class="summary-pill"><strong>V30:</strong> <?= number_format((int)$tx_v30_qty,0,',','.') ?> | <strong>Omset:</strong> <?= $cur ?> <?= number_format((int)$tx_v30_amount,0,',','.') ?></div>
        <div class="summary-pill"><strong>Total Rusak:</strong> <?= number_format((int)$tx_rusak_qty,0,',','.') ?> | <strong>Nilai:</strong> <?= $cur ?> <?= number_format((int)$tx_rusak_amount,0,',','.') ?></div>
    </div>
<?php endif; ?>
</body>
</html>
