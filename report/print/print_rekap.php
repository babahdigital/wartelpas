<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include('../../include/config.php');
include('../../include/readcfg.php');

$root_dir = dirname(__DIR__, 2);
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
$pricing = $env['pricing'] ?? [];
$profiles_cfg = $env['profiles'] ?? [];
$blok_cfg = $env['blok'] ?? [];
$blok_names = $blok_cfg['names'] ?? [];
$price10 = isset($pricing['price_10']) ? (int)$pricing['price_10'] : 0;
$price30 = isset($pricing['price_30']) ? (int)$pricing['price_30'] : 0;
$profile_price_map = $pricing['profile_prices'] ?? [];
$profile_labels_map = $profiles_cfg['labels'] ?? [];
$profile_order_keys = is_array($profile_price_map) ? array_keys($profile_price_map) : [];
$GLOBALS['profile_price_map'] = $profile_price_map;
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';
$filter_blok = trim((string)($_GET['blok'] ?? ''));

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
    $filter_date = $filter_date ?: date('Y-m');
} else {
    $req_show = 'tahunan';
    $filter_date = $filter_date ?: date('Y');
}

function get_block_label($block_name, $blok_names = []) {
    $raw = strtoupper((string)$block_name);
    if (preg_match('/^BLOK-([A-Z0-9]+)/', $raw, $m)) {
        $key = $m[1];
        if (isset($blok_names[$key]) && $blok_names[$key] !== '') {
            return (string)$blok_names[$key];
        }
    }
    return (string)$block_name;
}


function detect_profile_minutes($profile) {
    $profile = resolve_profile_alias($profile);
    $profile_key = normalize_profile_key($profile);
    if ($profile_key === '') return 'OTHER';
    if (preg_match('/^\d+$/', $profile_key)) {
        return $profile_key . 'menit';
    }
    return $profile_key;
}

function format_profile_summary($map, $order_keys = []) {
    if (empty($map) || !is_array($map)) return '-';
    $parts = [];
    $keys = array_keys($map);
    if (!empty($order_keys)) {
        $keys = array_values(array_unique(array_merge($order_keys, $keys)));
    }
    foreach ($keys as $key) {
        $norm = normalize_profile_key($key);
        $val = (int)($map[$norm] ?? ($map[$key] ?? 0));
        if ($val <= 0) continue;
        $label = resolve_profile_label($norm !== '' ? $norm : $key);
        $parts[] = $label . ': ' . number_format($val, 0, ',', '.');
    }
    return !empty($parts) ? implode(' | ', $parts) : '-';
}

function format_date_ddmmyyyy($dateStr) {
    $ts = strtotime((string)$dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

function normalize_status_value($status) {
    $status = strtolower(trim((string)$status));
    if ($status === '') return '';
    if (strpos($status, 'rusak') !== false) return 'rusak';
    if (strpos($status, 'retur') !== false) return 'retur';
    if (strpos($status, 'invalid') !== false) return 'invalid';
    if (strpos($status, 'online') !== false) return 'online';
    if (strpos($status, 'terpakai') !== false) return 'terpakai';
    if (strpos($status, 'ready') !== false) return 'ready';
    return $status;
}

// Helper untuk membuat tabel bersarang di kolom audit
function generate_nested_table($items, $align = 'left') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; margin:0; padding:0; background:transparent;">';
    $count = count($items);
    foreach ($items as $i => $val) {
        $border = ($i < $count - 1) ? 'border-bottom:1px solid #999;' : ''; 
        $html .= '<tr><td style="border:none; padding:4px 2px; '.$border.' text-align: center; vertical-align:middle; line-height:1.2; word-wrap:break-word;">'.htmlspecialchars($val).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// Helper khusus untuk username audit (warna rusak/normal)
function generate_nested_table_user($items, $align = 'left') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; margin:0; padding:0; background:transparent;">';
    $count = count($items);
    foreach ($items as $i => $item) {
        $label = is_array($item) ? ($item['label'] ?? '-') : (string)$item;
        $status = is_array($item) ? normalize_status_value($item['status'] ?? '') : '';
        
        // Logika Warna:
        // Merah = Rusak
        // Hijau = Retur
        // Kuning = Tidak Terlapor (Default evidence tapi tidak rusak/retur)
        // Transparan = Jika labelnya "-" atau kosong
        if ($label === '-' || trim($label) === '') {
            $bg = 'transparent';
        } elseif ($status === 'rusak') {
            $bg = '#fecaca'; // Merah
        } elseif ($status === 'retur') {
            $bg = '#dcfce7'; // Hijau
        } else {
            $bg = '#fef3c7'; // Kuning
        }

        $border = ($i < $count - 1) ? 'border-bottom:1px solid #999;' : '';
        $html .= '<tr><td style="border:none; padding:4px 2px; '.$border.' text-align:'.$align.'; vertical-align:middle; line-height:1.2; word-wrap:break-word; background:'.$bg.';">'.htmlspecialchars($label).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

function generate_audit_cell($items, $key = 'label', $align = 'left') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; margin:0; padding:0; background:transparent;">';
    $count = count($items);
    foreach ($items as $i => $item) {
        $text = is_array($item) ? (string)($item[$key] ?? '-') : (string)$item;
        $status = normalize_status_value(is_array($item) ? ($item['status'] ?? '') : '');
        if ($status !== '' && !in_array($status, ['rusak', 'retur', 'invalid', 'normal'], true)) {
            $status = 'anomaly';
        }

        if ($status === 'rusak' || $status === 'invalid') {
            $bg = '#fee2e2';
        } elseif ($status === 'retur') {
            $bg = '#dcfce7';
        } elseif ($status === 'anomaly') {
            $bg = '#fef3c7';
        } else {
            $bg = 'transparent';
        }

        $border = ($i < $count - 1) ? 'border-bottom:1px solid #999;' : '';
        $html .= '<tr><td style="border:none; padding:4px 2px; '.$border.' text-align:'.$align.'; vertical-align:middle; line-height:1.2; word-wrap:break-word; background:'.$bg.';">'.htmlspecialchars($text).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

$rows = [];
$hp_total_units = 0;
$hp_active_units = 0;
$hp_rusak_units = 0;
$hp_spam_units = 0;
$hp_wartel_units = 0;
$hp_kamtib_units = 0;
$audit_rows = [];
$audit_total_expected_qty = 0;
$audit_total_reported_qty = 0;
$audit_total_expected_setoran = 0;
$audit_total_actual_setoran = 0;
$audit_total_selisih_qty = 0;
$audit_total_selisih_setoran = 0;
$audit_expected_setoran_adj_total = 0;
$audit_selisih_setoran_adj_total = 0;
$has_audit_adjusted = false;
$total_audit_expense = 0;
$daily_note_alert = '';
$hp_active_by_block = [];
$hp_stats_by_block = [];
$hp_units_by_block = [];
$block_summaries = [];
$valid_blocks = [];

try {
    if (file_exists($dbFile)) {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $res = $db->query("SELECT 
            sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
            sh.username, sh.profile, sh.profile_snapshot,
            sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
            sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
            sh.full_raw_data, lh.last_status, lh.last_bytes, lh.last_uptime, lh.raw_comment
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
            ls.full_raw_data, lh2.last_status, lh2.last_bytes, lh2.last_uptime, lh2.raw_comment
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);

        // Tambahkan data dari login_history untuk status rusak/retur/invalid
        try {
            $lhWhere = "(substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d OR login_date = :d)";
            if ($req_show === 'bulanan') {
                $lhWhere = "(substr(login_time_real,1,7) = :d OR substr(last_login_real,1,7) = :d OR substr(logout_time_real,1,7) = :d OR substr(updated_at,1,7) = :d OR substr(login_date,1,7) = :d)";
            } elseif ($req_show === 'tahunan') {
                $lhWhere = "(substr(login_time_real,1,4) = :d OR substr(last_login_real,1,4) = :d OR substr(logout_time_real,1,4) = :d OR substr(updated_at,1,4) = :d OR substr(login_date,1,4) = :d)";
            }
            $stmtLh = $db->prepare("SELECT
                '' AS raw_date,
                '' AS raw_time,
                COALESCE(NULLIF(substr(login_time_real,1,10),''), NULLIF(substr(last_login_real,1,10),''), NULLIF(substr(logout_time_real,1,10),''), NULLIF(substr(updated_at,1,10),''), login_date) AS sale_date,
                COALESCE(NULLIF(substr(login_time_real,12,8),''), NULLIF(substr(last_login_real,12,8),''), NULLIF(substr(logout_time_real,12,8),''), NULLIF(substr(updated_at,12,8),''), login_time) AS sale_time,
                COALESCE(NULLIF(login_time_real,''), NULLIF(last_login_real,''), NULLIF(logout_time_real,''), NULLIF(updated_at,'')) AS sale_datetime,
                username,
                COALESCE(NULLIF(validity,''), '-') AS profile,
                COALESCE(NULLIF(validity,''), '-') AS profile_snapshot,
                CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price,
                CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price_snapshot,
                CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS sprice_snapshot,
                validity,
                raw_comment AS comment,
                blok_name,
                COALESCE(NULLIF(last_status,''), '') AS status,
                0 AS is_rusak,
                0 AS is_retur,
                0 AS is_invalid,
                1 AS qty,
                '' AS full_raw_data,
                last_status,
                                last_bytes,
                                last_uptime
              FROM login_history
              WHERE username != ''
                AND $lhWhere
                                AND (
                                        instr(lower(COALESCE(NULLIF(last_status,''), '')), 'rusak') > 0
                                        OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'retur') > 0
                                        OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'invalid') > 0
                                )");
            $stmtLh->execute([':d' => $filter_date]);
            $lhRows = $stmtLh->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($lhRows)) {
                $rows = array_merge($lhRows, $rows);
            }
        } catch (Exception $e) {}

        if ($req_show === 'harian') {
            try {
                $stmtN = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
                $stmtN->execute([':d' => $filter_date]);
                $daily_note_alert = $stmtN->fetchColumn() ?: '';
            } catch (Exception $e) {
                $daily_note_alert = '';
            }

            $stmtHp = $db->prepare("SELECT
                    SUM(total_units) AS total_units,
                    SUM(active_units) AS active_units,
                    SUM(rusak_units) AS rusak_units,
                    SUM(spam_units) AS spam_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'");
            $stmtHp->execute([':d' => $filter_date]);
            $hp = $stmtHp->fetch(PDO::FETCH_ASSOC) ?: [];
            $hp_total_units = (int)($hp['total_units'] ?? 0);
            $hp_active_units = (int)($hp['active_units'] ?? 0);
            $hp_rusak_units = (int)($hp['rusak_units'] ?? 0);
            $hp_spam_units = (int)($hp['spam_units'] ?? 0);

            $stmtHpBlock = $db->prepare("SELECT blok_name, SUM(active_units) AS active_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'
                GROUP BY blok_name");
            $stmtHpBlock->execute([':d' => $filter_date]);
            $hpBlockRows = $stmtHpBlock->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpBlockRows as $hb) {
                $blk = normalize_block_name($hb['blok_name'] ?? '');
                $hp_active_by_block[$blk] = (int)($hb['active_units'] ?? 0);
                if ($blk !== '') $valid_blocks[$blk] = true;
            }

            $stmtHpStats = $db->prepare("SELECT blok_name,
                    SUM(total_units) AS total_units,
                    SUM(active_units) AS active_units,
                    SUM(rusak_units) AS rusak_units,
                    SUM(spam_units) AS spam_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'
                GROUP BY blok_name");
            $stmtHpStats->execute([':d' => $filter_date]);
            $hpStatsRows = $stmtHpStats->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpStatsRows as $hs) {
                $blk = normalize_block_name($hs['blok_name'] ?? '');
                $hp_stats_by_block[$blk] = [
                    'total' => (int)($hs['total_units'] ?? 0),
                    'active' => (int)($hs['active_units'] ?? 0),
                    'rusak' => (int)($hs['rusak_units'] ?? 0),
                    'spam' => (int)($hs['spam_units'] ?? 0)
                ];
            }

            $stmtHpUnitsBlock = $db->prepare("SELECT blok_name, unit_type, SUM(total_units) AS total_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
                GROUP BY blok_name, unit_type");
            $stmtHpUnitsBlock->execute([':d' => $filter_date]);
            $hpUnitRows = $stmtHpUnitsBlock->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpUnitRows as $hu) {
                $blk = normalize_block_name($hu['blok_name'] ?? '');
                if (!isset($hp_units_by_block[$blk])) $hp_units_by_block[$blk] = ['WARTEL' => 0, 'KAMTIB' => 0];
                $ut = strtoupper((string)($hu['unit_type'] ?? ''));
                if ($ut === 'WARTEL') $hp_units_by_block[$blk]['WARTEL'] = (int)($hu['total_units'] ?? 0);
                if ($ut === 'KAMTIB') $hp_units_by_block[$blk]['KAMTIB'] = (int)($hu['total_units'] ?? 0);
            }

            $stmtHp2 = $db->prepare("SELECT unit_type, SUM(total_units) AS total_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
                GROUP BY unit_type");
            $stmtHp2->execute([':d' => $filter_date]);
            $hpRows = $stmtHp2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpRows as $hr) {
                $ut = strtoupper((string)($hr['unit_type'] ?? ''));
                if ($ut === 'WARTEL') $hp_wartel_units = (int)($hr['total_units'] ?? 0);
                if ($ut === 'KAMTIB') $hp_kamtib_units = (int)($hr['total_units'] ?? 0);
            }

            $stmtAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name");
            $stmtAudit->execute([':d' => $filter_date]);
            $audit_rows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
            foreach ($audit_rows as $ar) {
                $audit_total_expected_qty += (int)($ar['expected_qty'] ?? 0);
                $audit_total_reported_qty += (int)($ar['reported_qty'] ?? 0);
                $audit_total_expected_setoran += (int)($ar['expected_setoran'] ?? 0);
                $audit_total_actual_setoran += (int)($ar['actual_setoran'] ?? 0);
                $total_audit_expense += (int)($ar['expenses_amt'] ?? 0);
                $audit_total_selisih_qty += (int)($ar['selisih_qty'] ?? 0);
                $audit_total_selisih_setoran += (int)($ar['selisih_setoran'] ?? 0);
                [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($ar);
                $audit_expected_setoran_adj_total += (int)$expected_adj_setoran;
                $audit_selisih_setoran_adj_total += (int)$manual_setoran - (int)$expected_adj_setoran;
                $has_audit_adjusted = true;
            }
        }
    }
} catch (Exception $e) {
    $rows = [];
}

$total_gross = 0;
$total_rusak = 0;
$total_invalid = 0;
$total_net = 0;
$total_qty = 0;
$total_qty_retur = 0;
$total_qty_rusak = 0;
$total_qty_invalid = 0;
$total_qty_laku = 0;
$rusak_by_profile = [];
$total_qty_units = 0;
$total_net_units = 0;
$total_bandwidth = 0;

$seen_sales = [];
$seen_user_day = [];
$unique_laku_users = [];
$system_incidents_by_block = [];
$rusak_user_map = [];
$retur_ref_map = [];

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    $sale_time = $r['sale_time'] ?? ($r['raw_time'] ?? '');
    $match = false;
    if ($req_show === 'harian') $match = ($sale_date === $filter_date);
    elseif ($req_show === 'bulanan') $match = (strpos((string)$sale_date, $filter_date) === 0);
    else $match = (strpos((string)$sale_date, $filter_date) === 0);
    if (!$match) continue;

    $comment = (string)($r['comment'] ?? '');
    $raw_comment = (string)($r['raw_comment'] ?? '');
    if ($raw_comment !== '') {
        $raw_low = strtolower($raw_comment);
        $cmt_low = strtolower($comment);
        if ((strpos($raw_low, 'retur') !== false || strpos($raw_low, 'rusak') !== false) &&
            !(strpos($cmt_low, 'retur') !== false || strpos($cmt_low, 'rusak') !== false)) {
            $comment = $raw_comment;
        }
    }
    $blok_row = (string)($r['blok_name'] ?? '');
    if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $comment)) {
        continue;
    }
    $block = normalize_block_name($r['blok_name'] ?? '', $comment);

    $status_db = normalize_status_value($r['status'] ?? '');
    $lh_status = normalize_status_value($r['last_status'] ?? '');
    $cmt_low = strtolower($comment);
    $status = 'normal';
    if (
        $status_db === 'invalid' || $lh_status === 'invalid' ||
        strpos($cmt_low, 'invalid') !== false || (int)($r['is_invalid'] ?? 0) === 1
    ) {
        $status = 'invalid';
    } elseif (
        $status_db === 'retur' || $lh_status === 'retur' ||
        strpos($cmt_low, 'retur') !== false || (int)($r['is_retur'] ?? 0) === 1
    ) {
        $status = 'retur';
    } elseif (
        $status_db === 'rusak' || $lh_status === 'rusak' ||
        strpos($cmt_low, 'rusak') !== false || (int)($r['is_rusak'] ?? 0) === 1
    ) {
        $status = 'rusak';
    } elseif (in_array($status_db, ['online', 'terpakai', 'ready'], true)) {
        $status = $status_db;
    }

    if ($status === 'retur') {
        $ref_user = extract_retur_user_from_ref($comment);
        if ($ref_user !== '') {
            if (!isset($retur_ref_map[$block])) $retur_ref_map[$block] = [];
            $retur_ref_map[$block][strtolower($ref_user)] = true;
        }
    }
    if ($status === 'rusak') {
        $username = strtolower((string)($r['username'] ?? ''));
        if ($username !== '') {
            if (!isset($rusak_user_map[$block])) $rusak_user_map[$block] = [];
            $rusak_user_map[$block][$username] = true;
        }
    }
}

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    $sale_time = $r['sale_time'] ?? ($r['raw_time'] ?? '');
    $match = false;
    if ($req_show === 'harian') $match = ($sale_date === $filter_date);
    elseif ($req_show === 'bulanan') $match = (strpos((string)$sale_date, $filter_date) === 0);
    else $match = (strpos((string)$sale_date, $filter_date) === 0);
    if (!$match) continue;

    $username = $r['username'] ?? '';
    if ($username !== '' && $sale_date !== '') {
        $user_day_key = $username . '|' . $sale_date;
        if (isset($seen_user_day[$user_day_key])) continue;
        $seen_user_day[$user_day_key] = true;
    }
    $raw_key = trim((string)($r['full_raw_data'] ?? ''));
    $unique_key = '';
    if ($raw_key !== '') {
        $unique_key = 'raw|' . $raw_key;
    } elseif ($username !== '' && $sale_date !== '') {
        $unique_key = $username . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($sale_time ?? '')));
        if ($unique_key === $username . '|') {
            $unique_key = $username . '|' . $sale_date . '|' . ($sale_time ?? '');
        }
    } elseif ($sale_date !== '') {
        $unique_key = 'date|' . $sale_date . '|' . ($sale_time ?? '');
    }
    if ($unique_key !== '') {
        if (isset($seen_sales[$unique_key])) continue;
        $seen_sales[$unique_key] = true;
    }

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    if ($price <= 0) {
        $price = (int)($r['sprice_snapshot'] ?? 0);
    }
    $qty = (int)($r['qty'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $line_price = $price * $qty;
    $comment = (string)($r['comment'] ?? '');
    $blok_row = (string)($r['blok_name'] ?? '');
    if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $comment)) {
        continue;
    }
    $block = normalize_block_name($r['blok_name'] ?? '', $comment);
    $status_db = normalize_status_value($r['status'] ?? '');
    $lh_status = normalize_status_value($r['last_status'] ?? '');
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    if ($profile === '' || $profile === '-') {
        $profile_from_comment = extract_profile_from_comment($comment);
        if ($profile_from_comment !== '') {
            $profile = $profile_from_comment;
        }
    }
    $cmt_low = strtolower($comment);
    $bytes = (int)($r['last_bytes'] ?? 0);
    if ($bytes < 0) $bytes = 0;

    $status = 'normal';
    if (
        $status_db === 'invalid' || $lh_status === 'invalid' ||
        strpos($cmt_low, 'invalid') !== false || (int)($r['is_invalid'] ?? 0) === 1
    ) {
        $status = 'invalid';
    } elseif (
        $status_db === 'retur' || $lh_status === 'retur' ||
        (int)($r['is_retur'] ?? 0) === 1
    ) {
        $status = 'retur';
    } elseif (
        $status_db === 'rusak' || $lh_status === 'rusak' ||
        (int)($r['is_rusak'] ?? 0) === 1
    ) {
        $status = 'rusak';
    } elseif (in_array($status_db, ['online', 'terpakai', 'ready'], true)) {
        $status = $status_db;
    }

    if ($status !== 'invalid') {
        if (strpos($cmt_low, 'retur') !== false) {
            $status = 'retur';
        } elseif (strpos($cmt_low, 'rusak') !== false) {
            $status = 'rusak';
        }
    }

    if ($price <= 0) {
        $price = resolve_price_from_profile($profile);
        $line_price = $price * $qty;
    }

    if (in_array($status, ['rusak', 'retur', 'invalid'], true) && $username !== '') {
        $kind = detect_profile_minutes($profile);
        $inc_key = $username . '|' . $kind . '|' . $status;
        if (!isset($system_incidents_by_block[$block])) $system_incidents_by_block[$block] = [];
        if (!isset($system_incidents_by_block[$block][$inc_key])) {
            $system_incidents_by_block[$block][$inc_key] = [
                'username' => $username,
                'status' => $status,
                'profile_kind' => $kind,
                'last_uptime' => trim((string)($r['last_uptime'] ?? '')),
                'last_bytes' => $bytes,
                'price' => $price
            ];
        }
    }

    $gross_add = 0;
    $loss_rusak = 0;
    $loss_invalid = 0;
    $net_add = 0;
    $rusak_recovered = false;
    if ($status === 'rusak' && $username !== '' && isset($retur_ref_map[$block][strtolower($username)])) {
        $rusak_recovered = true;
    }

    if ($status === 'invalid') {
        $gross_add = 0;
        $net_add = 0;
    } elseif ($status === 'retur') {
        $gross_add = 0;
        $net_add = $line_price;
    } elseif ($status === 'rusak') {
        $gross_add = $line_price;
        $loss_rusak = $rusak_recovered ? 0 : $line_price;
        $net_add = 0;
    } else {
        $gross_add = $line_price;
        $net_add = $line_price;
    }

    if (empty($valid_blocks) || isset($valid_blocks[$block])) {
        $total_bandwidth += $bytes;
    }

    $is_laku = !in_array($status, ['rusak', 'invalid'], true);
    if ($is_laku && $username !== '') {
        $unique_laku_users[$username] = true;
    }

    if ($req_show === 'harian') {
        $qty_count = 1;
        $gross_line = 0;
        $net_line = 0;

        if ($status === 'invalid') {
            $gross_line = 0;
            $net_line = 0;
        } elseif ($status === 'retur') {
            $gross_line = 0;
            $net_line = $line_price;
        } elseif ($status === 'rusak') {
            $gross_line = $line_price;
            $net_line = 0;
        } else {
            $gross_line = $line_price;
            $net_line = $line_price;
        }

        if ($status !== 'invalid' && $status !== 'retur') {
            $total_qty_units += $qty_count;
        }
        $total_net_units += $net_line;

        $bucket = detect_profile_minutes($profile);
        if (!isset($block_summaries[$block])) {
            $block_summaries[$block] = [
                'total_qty' => 0,
                'total_amount' => 0,
                'total_bw' => 0,
                'profile_qty' => [],
                'profile_amt' => [],
                'profile_rs' => [],
                'profile_rt' => [],
                'rs_total' => 0,
                'rt_total' => 0
            ];
        }
        $bw_line = $bytes;
        $bucket_key = normalize_profile_key($bucket);
        if ($bucket_key === '') $bucket_key = 'other';
        if ($status !== 'invalid' && $status !== 'retur') {
            if (!isset($block_summaries[$block]['profile_qty'][$bucket_key])) $block_summaries[$block]['profile_qty'][$bucket_key] = 0;
            $block_summaries[$block]['profile_qty'][$bucket_key] += $qty_count;
        }
        if ($is_laku || $status === 'retur') {
            if (!isset($block_summaries[$block]['profile_amt'][$bucket_key])) $block_summaries[$block]['profile_amt'][$bucket_key] = 0;
            $block_summaries[$block]['profile_amt'][$bucket_key] += $net_line;
        }
        if ($status === 'rusak' && !$rusak_recovered) {
            if (!isset($block_summaries[$block]['profile_rs'][$bucket_key])) $block_summaries[$block]['profile_rs'][$bucket_key] = 0;
            $block_summaries[$block]['profile_rs'][$bucket_key] += $qty_count;
        }
        if ($status === 'retur') {
            if (!isset($block_summaries[$block]['profile_rt'][$bucket_key])) $block_summaries[$block]['profile_rt'][$bucket_key] = 0;
            $block_summaries[$block]['profile_rt'][$bucket_key] += $qty_count;
        }
        if ($status !== 'invalid' && $status !== 'retur') {
            $block_summaries[$block]['total_qty'] += $qty_count;
            $block_summaries[$block]['total_bw'] += $bw_line;
        }
        if ($is_laku || $status === 'retur') {
            $block_summaries[$block]['total_amount'] += $net_line;
        }
        if ($status === 'rusak' && !$rusak_recovered) $block_summaries[$block]['rs_total'] += $qty_count;
        if ($status === 'retur') $block_summaries[$block]['rt_total'] += $qty_count;
    }

    $total_qty++;
    $laku_add = ($req_show === 'harian') ? ($qty_count ?? 1) : 1;
    if ($is_laku) $total_qty_laku += $laku_add;
    if ($status === 'retur') $total_qty_retur++;
    if ($status === 'rusak' && !$rusak_recovered) {
        $total_qty_rusak++;
        $rusak_key = normalize_profile_key(detect_profile_minutes($profile));
        if ($rusak_key === '') $rusak_key = 'other';
        if (!isset($rusak_by_profile[$rusak_key])) $rusak_by_profile[$rusak_key] = 0;
        $rusak_by_profile[$rusak_key]++;
    }
    if ($status === 'invalid') $total_qty_invalid++;

    $total_gross += $gross_add;
    $total_rusak += $loss_rusak;
    $total_invalid += $loss_invalid;
    $total_net += $net_add;
}

$total_qty_laku = count($unique_laku_users);
$net_system_display = (int)$total_net;
$voucher_loss_display = (int)$total_rusak + (int)$total_invalid;
$setoran_loss_display = ($audit_selisih_setoran_adj_total < 0) ? abs((int)$audit_selisih_setoran_adj_total) : 0;
$kerugian_display = $voucher_loss_display + $setoran_loss_display;
$waterfall_tech_loss = $voucher_loss_display;
$waterfall_target = $net_system_display;
$waterfall_actual = ($req_show === 'harian') ? (int)$audit_total_actual_setoran : 0;
$waterfall_variance = $waterfall_actual - $waterfall_target;
$period_label = $req_show === 'harian' ? 'Harian' : ($req_show === 'bulanan' ? 'Bulanan' : 'Tahunan');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Rekap Laporan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .card { border:1px solid #ddd; padding:10px; border-radius:6px; }
        .label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
        .value { font-size:18px; font-weight:700; margin-top:4px; }
        .small { font-size:12px; color:#555; margin-top:4px; }
        .rekap-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:2%; }
        .rekap-table th, .rekap-table td { border:1px solid #000; padding:6px; vertical-align:middle; }
        .rekap-table th { background:#f0f3f7; text-align:center; vertical-align:middle; }
        .rekap-detail { width:100%; border-collapse:collapse; font-size:12px; }
        .rekap-detail th, .rekap-detail td { border:1px solid #000; padding:5px; }
        .rekap-detail th { background:#e9eef5; text-align:center; }
        .rekap-detail td { vertical-align: middle; }
        .rekap-detail tr:nth-child(even) td { background:#fbfbfd; }
        .rekap-total { background:#d7dee8; font-weight:700; }
        .rekap-subtotal { background:#e8edf4; font-weight:700; }
        .rekap-hp { text-align:center; vertical-align:middle; font-weight:700; }
        /* Style untuk nested table audit */
        .nested-cell-table td { border-bottom: 1px solid #ccc; }
        .nested-cell-table td:last-child { border-bottom: none; }
        /* Style untuk Summary Audit Box */
        .audit-summary-box { margin-top: 25px; border: 1px solid #000; padding: 15px; border-radius: 4px; background-color: #fdfdfd; }
        .audit-summary-header { font-weight: bold; font-size: 14px; margin-bottom: 10px; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
        .audit-item { font-size: 12px; margin-bottom: 8px; line-height: 1.5; }
        .audit-item strong { color: #000; }
        .audit-details-list { margin: 4px 0 0 15px; padding: 0; list-style-type: none; color: #444; }
        .audit-details-list li::before { content: "- "; font-weight: bold; }
        
        @page { margin: 10mm; }
        @media print { 
            .toolbar { display:none; } 
            .audit-summary-box { page-break-inside: avoid; }
            .dul-gap { margin-bottom: 20% !important; }
            body { zoom: 0.9; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

    <h2>Rekap Laporan Penjualan</h2>
    <div class="meta">Periode: <?= htmlspecialchars($period_label) ?> | Tanggal: <?= htmlspecialchars(format_date_ddmmyyyy($filter_date)) ?> | Blok: <?= htmlspecialchars($filter_blok !== '' ? strtoupper($filter_blok) : 'Semua') ?> | Dicetak: <?= date('d-m-Y H:i:s') ?></div>

    <div class="grid">
        <div class="card">
            <div class="label">Terjual</div>
            <div class="value"><?= number_format($total_qty_laku,0,',','.') ?></div>
            <div class="small">Bandwith: <?= htmlspecialchars(format_bytes_short($total_bandwidth)) ?></div>
        </div>
        <div class="card">
            <div class="label">Rusak</div>
            <div class="value"><?= number_format($total_qty_rusak,0,',','.') ?></div>
            <div class="small"><?= htmlspecialchars(format_profile_summary($rusak_by_profile, $profile_order_keys)) ?></div>
        </div>
        <?php if ($req_show === 'harian'): ?>
        <div class="card">
            <div class="label">Device</div>
            <div class="value"><?= number_format($hp_total_units,0,',','.') ?></div>
            <div class="small">WARTEL: <?= number_format($hp_wartel_units,0,',','.') ?> | KAMTIB: <?= number_format($hp_kamtib_units,0,',','.') ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($req_show === 'harian'): ?>
    <div class="card" style="margin-top:12px;">
        <div class="label" style="margin-bottom:6px;">Waterfall Pendapatan</div>
        <div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;">
            <div>
                <div class="small">Gross (Omzet)</div>
                <div class="value" style="font-size:16px;">
                    <?= $cur ?> <?= number_format((int)$total_gross,0,',','.') ?>
                </div>
            </div>
            <div>
                <div class="small">Loss (Rusak)</div>
                <div class="value" style="font-size:16px;color:#c0392b;">
                    - <?= $cur ?> <?= number_format((int)$waterfall_tech_loss,0,',','.') ?>
                </div>
            </div>
            <div style="background:#e8f5e9;border-radius:4px;padding:0 4px;">
                <div class="small" style="color:#1b5e20;">Target Net (Sistem)</div>
                <div class="value" style="font-size:16px;color:#1b5e20;">
                    <?= $cur ?> <?= number_format((int)$waterfall_target,0,',','.') ?>
                </div>
                <div style="font-size:9px;color:#666;">(Termasuk Pemulihan Retur)</div>
            </div>
            <div style="background:#e3f2fd;border-radius:4px;padding:0 4px;">
                <div class="small" style="color:#0d47a1;">Fisik (Audit)</div>
                <div class="value" style="font-size:16px;color:#0d47a1;">
                    <?= $cur ?> <?= number_format((int)$waterfall_actual,0,',','.') ?>
                </div>
            </div>
            <div>
                <div class="small">Variance</div>
                <div class="value" style="font-size:16px;color:<?= $waterfall_variance < 0 ? '#c0392b' : '#2ecc71'; ?>;">
                    <?= $cur ?> <?= number_format((int)$waterfall_variance,0,',','.') ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($req_show === 'harian'): ?>
        <?php
            if (!empty($block_summaries)) {
                foreach ($block_summaries as $blk => $bdata) {
                    $has_profile = !empty($bdata['profile_qty']) || !empty($bdata['profile_rs']) || !empty($bdata['profile_rt']) || !empty($bdata['profile_amt']);
                    $has_data = ((int)($bdata['total_qty'] ?? 0) > 0)
                        || ((int)($bdata['total_amount'] ?? 0) > 0)
                        || ((int)($bdata['total_bw'] ?? 0) > 0)
                        || $has_profile
                        || ((int)($bdata['rs_total'] ?? 0) > 0)
                        || ((int)($bdata['rt_total'] ?? 0) > 0);
                    if (!$has_data) {
                        unset($block_summaries[$blk]);
                    }
                }
            }
            ksort($block_summaries);
            $profile_keys_ordered = array_values(array_unique($profile_order_keys));
            $profile_key_1 = $profile_keys_ordered[0] ?? '';
            $profile_key_2 = $profile_keys_ordered[1] ?? '';
            $profile_label_1 = $profile_key_1 !== '' ? resolve_profile_label($profile_key_1) : 'Profil 1';
            $profile_label_2 = $profile_key_2 !== '' ? resolve_profile_label($profile_key_2) : 'Profil 2';
        ?>
        <table class="rekap-table">
            <thead>
                <tr>
                    <th>Rincian Penjualan</th>
                    <th style="width:60px;">QTY</th>
                    <th style="width:80px;">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <table class="rekap-detail">
                            <thead>
                                <tr>
                                    <th rowspan="2" style="width:140px;">BLOK</th>
                                    <th colspan="3" style="width:210px;">Voucher <?= htmlspecialchars($profile_label_1) ?></th>
                                    <th colspan="3" style="width:210px;">Voucher <?= htmlspecialchars($profile_label_2) ?></th>
                                    <th colspan="3" style="width:210px;">Pendapatan</th>
                                    <th colspan="3" style="width:210px;">Device</th>
                                    <th rowspan="2" style="width:70px;">Aktif</th>
                                </tr>
                                <tr>
                                    <th style="width:70px;">Total</th>
                                    <th style="width:70px;">Rusak</th>
                                    <th style="width:70px;">Retur</th>
                                    <th style="width:70px;">Total</th>
                                    <th style="width:70px;">Rusak</th>
                                    <th style="width:70px;">Retur</th>
                                    <th style="width:80px;">V10</th>
                                    <th style="width:80px;">V30</th>
                                    <th style="width:80px;">Total</th>
                                    <th style="width:70px;">Total</th>
                                    <th style="width:70px;">RS</th>
                                    <th style="width:70px;">SP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($block_summaries)): ?>
                                    <tr><td colspan="14" style="text-align:center;">Tidak ada data</td></tr>
                                <?php else: ?>
                                    <?php foreach ($block_summaries as $blk => $bdata): ?>
                                        <?php
                                            $blk_label = get_block_label($blk, $blok_names);
                                            $hp_stat = $hp_stats_by_block[$blk] ?? ['total' => 0, 'active' => 0, 'rusak' => 0, 'spam' => 0];
                                            $p1_qty = (int)($bdata['profile_qty'][$profile_key_1] ?? 0);
                                            $p1_rs = (int)($bdata['profile_rs'][$profile_key_1] ?? 0);
                                            $p1_rt = (int)($bdata['profile_rt'][$profile_key_1] ?? 0);
                                            $p1_amt = (int)($bdata['profile_amt'][$profile_key_1] ?? 0);
                                            $p2_qty = (int)($bdata['profile_qty'][$profile_key_2] ?? 0);
                                            $p2_rs = (int)($bdata['profile_rs'][$profile_key_2] ?? 0);
                                            $p2_rt = (int)($bdata['profile_rt'][$profile_key_2] ?? 0);
                                            $p2_amt = (int)($bdata['profile_amt'][$profile_key_2] ?? 0);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($blk_label) ?></td>
                                            <td style="text-align:center;"><?= number_format($p1_qty,0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format($p1_rs,0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format($p1_rt,0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format($p2_qty,0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format($p2_rs,0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format($p2_rt,0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format($p1_amt,0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format($p2_amt,0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format((int)$bdata['total_amount'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$hp_stat['total'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$hp_stat['rusak'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$hp_stat['spam'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$hp_stat['active'],0,',','.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                    <td style="text-align:center; font-weight:700; font-size:11px;"><?= number_format((int)$total_qty_units,0,',','.') ?></td>
                    <td style="text-align:right; font-weight:700; font-size:11px;"><?= number_format((int)$total_net_units,0,',','.') ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:8px; font-size:11px; color:#444;">
            Keterangan: RS = Rusak, RT = Retur, SP = Spam, WR = Wartel, KM = Kamtib.
        </div>
        <div class="dul-gap" style="margin-top:4px; font-size:11px; color:#444;">
            Catatan: Data rekap adalah acuan resmi untuk keuangan karena berasal dari transaksi.
        </div> 

        <?php if ($req_show === 'harian' && !empty($daily_note_alert)): ?>
            <div style="line-height: 25px; margin-top:2%; padding:10px; border:1px solid #ffcdd2; background:#ffebee; border-radius:4px; color:#b71c1c;">
                <strong style="margin-bottom: 15px;"><i class="fa fa-exclamation-circle"></i> CATATAN PENTING HARI INI:</strong><br>
                <?= nl2br(htmlspecialchars($daily_note_alert)) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($audit_rows)): ?>
            <?php 
                // Array untuk menampung data summary
                $audit_summary_report = []; 
                $price10 = (int)$price10;
                $price30 = (int)$price30;
                $profile_keys_ordered = array_values(array_unique($profile_order_keys));
                $profile_key_1 = $profile_key_1 ?? ($profile_keys_ordered[0] ?? '');
                $profile_key_2 = $profile_key_2 ?? ($profile_keys_ordered[1] ?? '');
                $profile_label_1 = $profile_label_1 ?? ($profile_key_1 !== '' ? resolve_profile_label($profile_key_1) : 'Profil 1');
                $profile_label_2 = $profile_label_2 ?? ($profile_key_2 !== '' ? resolve_profile_label($profile_key_2) : 'Profil 2');
            ?>
            <h2 style="margin-top:25px;">Rekap Audit Penjualan Lapangan</h2>
            <div class="meta">Periode: <?= htmlspecialchars($period_label) ?> | Tanggal: <?= htmlspecialchars(format_date_ddmmyyyy($filter_date)) ?></div>

            <table class="rekap-table" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th colspan="15">Audit Manual Rekap Harian</th>
                    </tr>
                    <tr>
                        <th rowspan="2" style="width:90px;">Blok</th>
                        <th colspan="3">Voucher</th>
                        <th colspan="3">Setoran</th>
                        <th colspan="4">Profil <?= htmlspecialchars($profile_label_1) ?></th>
                        <th colspan="4">Profil <?= htmlspecialchars($profile_label_2) ?></th>
                    </tr>
                    <tr>
                        <th style="width:70px;">Sistem</th>
                        <th style="width:70px;">Aktual</th>
                        <th style="width:70px;">Selisih</th>
                        <th style="width:90px;">Sistem</th>
                        <th style="width:90px;">Aktual</th>
                        <th style="width:80px;">Selisih</th>
                        <th style="width:90px;">User</th>
                        <th style="width:70px;">Up</th>
                        <th style="width:70px;">Byte</th>
                        <th style="width:70px;">QTY</th>
                        <th style="width:90px;">User</th>
                        <th style="width:70px;">Up</th>
                        <th style="width:70px;">Byte</th>
                        <th style="width:70px;">QTY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $audit_total_profile_qty_1 = 0;
                        $audit_total_profile_qty_2 = 0;
                        $audit_total_expected_qty_adj = 0;
                        $audit_total_reported_qty_adj = 0;
                        $audit_total_selisih_qty_adj = 0;
                        $audit_total_expected_setoran_adj = 0;
                        $audit_total_actual_setoran_adj = 0;
                        $audit_total_selisih_setoran_adj = 0;
                    ?>
                    <?php foreach ($audit_rows as $idx => $ar): ?>
                        <?php
                            $evidence = [];
                            $profile_qty = [];
                            $profile_items = [];
                            $profile_display_items = [];
                            $profile_items_by_key = [];
                            $profile_display_by_key = [];
                            $profile_qty_map = [];
                            $cnt_rusak = [];
                            $cnt_unreported = [];
                            $cnt_retur = [];
                            $cnt_invalid = [];
                            $has_manual_evidence = false;
                            $manual_users_map = [];
                            $audit_block_key = normalize_block_name($ar['blok_name'] ?? '', (string)($ar['comment'] ?? ''));
                            $system_incidents = $system_incidents_by_block[$audit_block_key] ?? [];
                            $system_status_map = [];
                            if (!empty($system_incidents)) {
                                foreach ($system_incidents as $inc) {
                                    $inc_user = trim((string)($inc['username'] ?? ''));
                                    if ($inc_user === '') continue;
                                    $system_status_map[strtolower($inc_user)] = normalize_status_value($inc['status'] ?? '');
                                }
                            }

                            if (!empty($ar['user_evidence'])) {
                                $evidence = json_decode((string)$ar['user_evidence'], true);
                                if (is_array($evidence)) {
                                    if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                                        $profile_qty = [];
                                        foreach ($evidence['profile_qty'] as $k => $v) {
                                            $key_raw = (string)$k;
                                            if (strpos($key_raw, 'qty_') === 0) {
                                                $key_raw = substr($key_raw, 4);
                                            }
                                            $key_norm = normalize_profile_key($key_raw);
                                            if ($key_norm !== '' && preg_match('/^\d+$/', $key_norm)) {
                                                $key_norm = $key_norm . 'menit';
                                            }
                                            if ($key_norm === '') continue;
                                            $profile_qty[$key_norm] = (int)$v;
                                        }
                                    }
                                    if (!empty($evidence['users']) && is_array($evidence['users'])) {
                                        $has_manual_evidence = true;
                                        foreach ($evidence['users'] as $uname => $ud) {
                                            $uname = trim((string)$uname);
                                            if ($uname === '') continue;
                                            $cnt = isset($ud['events']) && is_array($ud['events']) ? count($ud['events']) : 0;
                                            $upt = trim((string)($ud['last_uptime'] ?? ''));
                                            $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                            $price_val = (int)($ud['price'] ?? 0);
                                            $upt = $upt !== '' ? $upt : '-';
                                            $profile_key = normalize_profile_key($ud['profile_key'] ?? ($ud['profile_kind'] ?? ($ud['profile'] ?? '')));
                                            if ($profile_key !== '' && preg_match('/^\d+$/', $profile_key)) {
                                                $profile_key = $profile_key . 'menit';
                                            }
                                            if ($profile_key === '') $profile_key = 'other';
                                            if ($price_val <= 0) {
                                                $price_val = resolve_price_from_profile($profile_key);
                                            }
                                            // Collecting data
                                            $u_status = normalize_status_value($ud['last_status'] ?? '');
                                            $uname_key = strtolower($uname);
                                            if (isset($system_status_map[$uname_key]) && $system_status_map[$uname_key] !== '') {
                                                $u_status = $system_status_map[$uname_key];
                                            }
                                            if ($u_status === 'rusak' && isset($retur_ref_map[$audit_block_key][$uname_key])) {
                                                continue;
                                            }
                                            if (!in_array($u_status, ['rusak', 'retur', 'invalid'], true)) {
                                                $u_status = 'anomaly';
                                            }
                                            $is_unreported = ($u_status === 'anomaly');
                                            $item = [
                                                'label' => $uname,
                                                'status' => $u_status,
                                                'uptime' => $upt,
                                                'bytes' => $lb
                                            ];

                                            $manual_users_map[$uname_key] = true;

                                            $profile_items[] = $item;
                                            $profile_display_items[] = $item;
                                            if (!isset($profile_items_by_key[$profile_key])) $profile_items_by_key[$profile_key] = [];
                                            if (!isset($profile_display_by_key[$profile_key])) $profile_display_by_key[$profile_key] = [];
                                            $profile_items_by_key[$profile_key][] = $item;
                                            $profile_display_by_key[$profile_key][] = $item;
                                            if (!isset($profile_qty_map[$profile_key])) $profile_qty_map[$profile_key] = 0;
                                            $profile_qty_map[$profile_key]++;
                                            if ($u_status === 'rusak') {
                                                if (!isset($cnt_rusak[$profile_key])) $cnt_rusak[$profile_key] = 0;
                                                $cnt_rusak[$profile_key]++;
                                            }
                                            if ($u_status === 'retur') {
                                                if (!isset($cnt_retur[$profile_key])) $cnt_retur[$profile_key] = 0;
                                                $cnt_retur[$profile_key]++;
                                            }
                                            if ($u_status === 'invalid') {
                                                if (!isset($cnt_invalid[$profile_key])) $cnt_invalid[$profile_key] = 0;
                                                $cnt_invalid[$profile_key]++;
                                            }
                                            if ($is_unreported) {
                                                if (!isset($cnt_unreported[$profile_key])) $cnt_unreported[$profile_key] = 0;
                                                $cnt_unreported[$profile_key]++;
                                            }
                                        }
                                    } elseif (!empty($evidence['events'])) {
                                        // Fallback legacy format
                                        $cnt = isset($evidence['events']) && is_array($evidence['events']) ? count($evidence['events']) : 0;
                                        $upt = trim((string)($evidence['last_uptime'] ?? ''));
                                        $lb = format_bytes_short((int)($evidence['last_bytes'] ?? 0));
                                        $price_val = (int)($evidence['price'] ?? 0);
                                        $upt = $upt !== '' ? $upt : '-';
                                        $item = [
                                            'label' => '-',
                                            'status' => 'normal',
                                            'uptime' => $upt,
                                            'bytes' => $lb
                                        ];
                                        $profile_items[] = $item;
                                        if (!isset($profile_items_by_key['other'])) $profile_items_by_key['other'] = [];
                                        if (!isset($profile_display_by_key['other'])) $profile_display_by_key['other'] = [];
                                        $profile_items_by_key['other'][] = $item;
                                        $profile_display_by_key['other'][] = $item;
                                        if (!isset($profile_qty_map['other'])) $profile_qty_map['other'] = 0;
                                        $profile_qty_map['other']++;
                                    }
                                }
                            }

                            if (!empty($system_incidents)) {
                                foreach ($system_incidents as $inc) {
                                    $uname = trim((string)($inc['username'] ?? ''));
                                    if ($uname === '') continue;
                                    if (isset($manual_users_map[strtolower($uname)])) continue;

                                    $u_status = normalize_status_value($inc['status'] ?? '');
                                    $uname_key = strtolower($uname);
                                    if ($u_status === 'rusak' && isset($retur_ref_map[$audit_block_key][$uname_key])) {
                                        continue;
                                    }
                                    $profile_key = normalize_profile_key($inc['profile_key'] ?? ($inc['profile_kind'] ?? ($inc['profile'] ?? '')));
                                    if ($profile_key !== '' && preg_match('/^\d+$/', $profile_key)) {
                                        $profile_key = $profile_key . 'menit';
                                    }
                                    if ($profile_key === '') $profile_key = 'other';
                                    $upt = trim((string)($inc['last_uptime'] ?? ''));
                                    $lb = format_bytes_short((int)($inc['last_bytes'] ?? 0));
                                    $price_val = (int)($inc['price'] ?? 0);
                                    $upt = $upt !== '' ? $upt : '-';
                                    $item = [
                                        'label' => $uname,
                                        'status' => $u_status,
                                        'uptime' => $upt,
                                        'bytes' => $lb
                                    ];

                                    $profile_items[] = $item;
                                    $profile_display_items[] = $item;
                                    if (!isset($profile_items_by_key[$profile_key])) $profile_items_by_key[$profile_key] = [];
                                    if (!isset($profile_display_by_key[$profile_key])) $profile_display_by_key[$profile_key] = [];
                                    $profile_items_by_key[$profile_key][] = $item;
                                    $profile_display_by_key[$profile_key][] = $item;
                                    if (!isset($profile_qty_map[$profile_key])) $profile_qty_map[$profile_key] = 0;
                                    $profile_qty_map[$profile_key]++;
                                    if ($u_status === 'rusak') {
                                        if (!isset($cnt_rusak[$profile_key])) $cnt_rusak[$profile_key] = 0;
                                        $cnt_rusak[$profile_key]++;
                                    }
                                    if ($u_status === 'retur') {
                                        if (!isset($cnt_retur[$profile_key])) $cnt_retur[$profile_key] = 0;
                                        $cnt_retur[$profile_key]++;
                                    }
                                    if ($u_status === 'invalid') {
                                        if (!isset($cnt_invalid[$profile_key])) $cnt_invalid[$profile_key] = 0;
                                        $cnt_invalid[$profile_key]++;
                                    }
                                }
                            }
                            // Generate HTML Tables using helper (per profil)
                            $p1_items = $profile_display_by_key[$profile_key_1] ?? [];
                            $p2_items = $profile_display_by_key[$profile_key_2] ?? [];
                            $p1_us = generate_audit_cell($p1_items, 'label', 'center');
                            $p1_up = generate_audit_cell($p1_items, 'uptime', 'center');
                            $p1_bt = generate_audit_cell($p1_items, 'bytes', 'center');
                            $p2_us = generate_audit_cell($p2_items, 'label', 'center');
                            $p2_up = generate_audit_cell($p2_items, 'uptime', 'center');
                            $p2_bt = generate_audit_cell($p2_items, 'bytes', 'center');

                            $profile_qty_display = $profile_qty;
                            if ($has_manual_evidence && !empty($profile_qty_map)) {
                                $profile_qty_display = $profile_qty_map;
                            } elseif (empty($profile_qty_display) && !empty($profile_qty_map)) {
                                $profile_qty_display = $profile_qty_map;
                            }
                            $manual_display_qty = $has_manual_evidence ? array_sum($profile_qty_display) : (int)($ar['reported_qty'] ?? 0);

                            $manual_display_setoran = 0;
                            if ($has_manual_evidence) {
                                foreach ($profile_qty_display as $pkey => $qty_val) {
                                    $qty_val = (int)$qty_val;
                                    $price_val = resolve_price_from_profile($pkey);
                                    $rusak_val = (int)($cnt_rusak[$pkey] ?? 0);
                                    $invalid_val = (int)($cnt_invalid[$pkey] ?? 0);
                                    $retur_val = (int)($cnt_retur[$pkey] ?? 0);
                                    $net_qty = max(0, $qty_val - $rusak_val - $invalid_val + $retur_val);
                                    $manual_display_setoran += ($net_qty * $price_val);
                                }
                            } else {
                                $manual_display_setoran = (int)($ar['actual_setoran'] ?? 0);
                            }

                            $p1_qty = (int)($profile_qty_display[$profile_key_1] ?? 0);
                            $p2_qty = (int)($profile_qty_display[$profile_key_2] ?? 0);
                            if ($has_manual_evidence && !empty($p1_items)) {
                                $p1_qty = count($profile_items_by_key[$profile_key_1] ?? $p1_items);
                            } elseif ($p1_qty <= 0 && !empty($p1_items)) {
                                $p1_qty = count($profile_items_by_key[$profile_key_1] ?? $p1_items);
                            }
                            if ($has_manual_evidence && !empty($p2_items)) {
                                $p2_qty = count($profile_items_by_key[$profile_key_2] ?? $p2_items);
                            } elseif ($p2_qty <= 0 && !empty($p2_items)) {
                                $p2_qty = count($profile_items_by_key[$profile_key_2] ?? $p2_items);
                            }
                            $p1_qty_display = $p1_qty > 0 ? number_format($p1_qty,0,',','.') : '-';
                            $p2_qty_display = $p2_qty > 0 ? number_format($p2_qty,0,',','.') : '-';
                            $audit_total_profile_qty_1 += $p1_qty;
                            $audit_total_profile_qty_2 += $p2_qty;

                            $expected_qty = (int)($ar['expected_qty'] ?? 0);
                            $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                            if (!empty($rows)) {
                                $expected_calc = calc_expected_for_block($rows, $filter_date, $audit_block_key);
                                if (!empty($expected_calc)) {
                                    $expected_qty = (int)($expected_calc['raw_qty'] ?? $expected_qty);
                                    $expected_setoran = (int)($expected_calc['net'] ?? $expected_setoran);
                                }
                            }
                            $expected_adj_qty = $expected_qty;
                            $expected_adj_setoran = $expected_setoran;
                            if ($has_manual_evidence) {
                                $expected_adj_qty = $expected_qty;
                                $adjustment = 0;
                                $all_keys = array_unique(array_merge(
                                    array_keys($cnt_rusak),
                                    array_keys($cnt_invalid),
                                    array_keys($cnt_retur)
                                ));
                                foreach ($all_keys as $pkey) {
                                    $price_val = resolve_price_from_profile($pkey);
                                    $adjustment -= ((int)($cnt_rusak[$pkey] ?? 0) + (int)($cnt_invalid[$pkey] ?? 0)) * $price_val;
                                    $adjustment += ((int)($cnt_retur[$pkey] ?? 0)) * $price_val;
                                }
                                $expected_adj_setoran = max(0, $expected_setoran + $adjustment);
                            }

                            $selisih_qty = $manual_display_qty - $expected_adj_qty;
                            $selisih_setoran = $manual_display_setoran - $expected_adj_setoran;
                            
                            // === LOGIKA DETEKSI GHOST HUNTER (PENYEMPURNAAN) ===
                            $db_selisih_qty = (int)$selisih_qty;
                            $db_selisih_rp  = (int)$selisih_setoran;

                            $ghost_10 = 0;
                            $ghost_30 = 0;

                            if ($db_selisih_qty < 0) {
                                $target_qty = abs($db_selisih_qty);
                                $target_rp  = abs($db_selisih_rp);

                                if ($target_rp > 0) {
                                    $numerator = $target_rp - ($target_qty * $price10);
                                    $divisor = $price30 - $price10;

                                    if ($divisor != 0) {
                                        $y = $numerator / $divisor;
                                        if ($y >= 0 && $y <= $target_qty && abs($y - round($y)) < 0.00001) {
                                            $ghost_30 = (int)round($y);
                                            $ghost_10 = $target_qty - $ghost_30;
                                        } elseif ($numerator == 0) {
                                            $ghost_10 = $target_qty;
                                        }
                                    }

                                    if ($ghost_10 == 0 && $ghost_30 == 0) {
                                        if ($target_rp == ($target_qty * $price30)) {
                                            $ghost_30 = $target_qty;
                                        } elseif ($target_rp == ($target_qty * $price10)) {
                                            $ghost_10 = $target_qty;
                                        }
                                    }
                                }
                            }
                            // ===============================================

                            // Capture data for summary
                            $audit_summary_report[] = [
                                'blok' => get_block_label(normalize_block_name($ar['blok_name'] ?? '-', (string)($ar['comment'] ?? '')), $blok_names),
                                'selisih_setoran' => (int)$selisih_setoran,
                                'profile_summary' => $profile_qty_summary,
                                'unreported_total' => (int)array_sum($cnt_unreported),
                                'unreported_summary' => format_profile_summary($cnt_unreported, $profile_order_keys),
                                'ghost_10' => (int)$ghost_10,
                                'ghost_30' => (int)$ghost_30,
                                'rusak_total' => (int)array_sum($cnt_rusak),
                                'retur_total' => (int)array_sum($cnt_retur),
                                'rusak_summary' => format_profile_summary($cnt_rusak, $profile_order_keys),
                                'retur_summary' => format_profile_summary($cnt_retur, $profile_order_keys)
                            ];
                        ?>
                        <?php $audit_blk_label = get_block_label(normalize_block_name($ar['blok_name'] ?? '-', (string)($ar['comment'] ?? '')), $blok_names); ?>
                        <tr>
                            <td style="text-align: left;"><?= htmlspecialchars($audit_blk_label) ?></td>
                            <td style="text-align:center;"><?= number_format((int)$expected_adj_qty,0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$manual_display_qty,0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$selisih_qty,0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$expected_adj_setoran,0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$manual_display_setoran,0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$selisih_setoran,0,',','.') ?></td>
                            
                            <td style="padding:0; text-align: center;"><?= $p1_us ?></td>
                            <td style="padding:0; text-align: center;"><?= $p1_up ?></td>
                            <td style="padding:0; text-align: center;"><?= $p1_bt ?></td>
                            <td style="text-align: center; font-weight:bold;"><?= $p1_qty_display ?></td>
                            <td style="padding:0; text-align: center;"><?= $p2_us ?></td>
                            <td style="padding:0; text-align: center;"><?= $p2_up ?></td>
                            <td style="padding:0; text-align: center;"><?= $p2_bt ?></td>
                            <td style="text-align: center; font-weight:bold;"><?= $p2_qty_display ?></td>
                        </tr>
                        <?php
                            $audit_total_expected_qty_adj += (int)$expected_adj_qty;
                            $audit_total_reported_qty_adj += (int)$manual_display_qty;
                            $audit_total_selisih_qty_adj += (int)$selisih_qty;
                            $audit_total_expected_setoran_adj += (int)$expected_adj_setoran;
                            $audit_total_actual_setoran_adj += (int)$manual_display_setoran;
                            $audit_total_selisih_setoran_adj += (int)$selisih_setoran;
                        ?>
                    <?php endforeach; ?>
                    <tr>
                        <td style="text-align:center;"><b>Total</b></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_expected_qty_adj,0,',','.') ?></b></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_reported_qty_adj,0,',','.') ?></b></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_selisih_qty_adj,0,',','.') ?></b></td>
                        <td style="text-align:right;"><b><?= number_format($audit_total_expected_setoran_adj,0,',','.') ?></b></td>
                        <td style="text-align:right;"><b><?= number_format($audit_total_actual_setoran_adj,0,',','.') ?></b></td>
                        <td style="text-align:right;"><b><?= number_format($audit_total_selisih_setoran_adj,0,',','.') ?></b></td>
                        <td colspan="3" style="background:#eee;"></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_profile_qty_1,0,',','.') ?></b></td>
                        <td colspan="3" style="background:#eee;"></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_profile_qty_2,0,',','.') ?></b></td>
                    </tr>
                </tbody>
            </table>

            <?php
                if ($total_audit_expense > 0) {
                    $total_cash_on_hand = $audit_total_actual_setoran_adj - $total_audit_expense;
            ?>
            <div style="margin-top:10px; display:flex; justify-content:flex-end;">
                <table style="width:300px; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <td style="padding:4px; text-align:right; color:#666;">Total Nilai Audit:</td>
                        <td style="padding:4px; text-align:right; font-weight:bold; border-bottom:1px solid #ddd;">
                            Rp <?= number_format((int)$audit_total_actual_setoran_adj,0,',','.') ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:4px; text-align:right; color:#d35400;">(-) Pengeluaran Ops:</td>
                        <td style="padding:4px; text-align:right; color:#d35400; border-bottom:1px solid #000;">
                            Rp <?= number_format((int)$total_audit_expense,0,',','.') ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 4px; text-align:right; font-weight:bold;">SETORAN TUNAI:</td>
                        <td style="padding:6px 4px; text-align:right; font-weight:bold; font-size:13px;">
                            Rp <?= number_format((int)$total_cash_on_hand,0,',','.') ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php } ?>

<div class="audit-summary-box" style="margin-top: 25px; border: 1px solid #000; padding: 15px; border-radius: 4px; background-color: #fdfdfd;">
                <div class="audit-summary-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #ddd; padding-bottom: 5px; margin-bottom: 10px;">
                    <span style="font-weight: bold; font-size: 14px;">KESIMPULAN AUDIT HARIAN</span>
                    <span style="font-size:11px; font-weight:normal; color:#555;">(Ringkasan Keuangan & Insiden)</span>
                </div>

                <div style="margin-bottom:12px; padding:8px 10px; background:#fff; border:1px solid #ddd; border-radius:4px; font-size:10px; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <strong style="margin-right:4px;">KETERANGAN STATUS:</strong>
                    
                    <span style="display:flex; align-items:center;">
                        <span style="width:12px; height:12px; background:#dbeafe; border:1px solid #93c5fd; margin-right:4px; border-radius:3px;"></span> 
                        <span style="color:#1e3a8a;">Aman / Sesuai</span>
                    </span>

                    <span style="display:flex; align-items:center;">
                        <span style="width:12px; height:12px; background:#fee2e2; border:1px solid #fca5a5; margin-right:4px; border-radius:3px;"></span> 
                        <span style="color:#991b1b;">Kurang Setor / Rugi (Loss)</span>
                    </span>

                    <span style="display:flex; align-items:center;">
                        <span style="width:12px; height:12px; background:#fef3c7; border:1px solid #fcd34d; margin-right:4px; border-radius:3px;"></span> 
                        <span style="color:#92400e;">Warning / Ada Insiden Tapi Uang Pas</span>
                    </span>

                    <span style="display:flex; align-items:center;">
                        <span style="width:12px; height:12px; background:#dcfce7; border:1px solid #86efac; margin-right:4px; border-radius:3px;"></span> 
                        <span style="color:#166534;">Lebih Setor / Retur (Aman)</span>
                    </span>
                </div>

                <div style="background:#f8fafc; padding:8px; border-radius:4px; margin-bottom:12px; font-size:11px; display:flex; justify-content:space-between; align-items:center; border:1px solid #e2e8f0;">
                    <div>
                        <strong>Total Insiden Hari Ini:</strong>
                        <span style="margin-left:8px; color:<?= $total_qty_rusak > 0 ? '#c0392b' : '#444' ?>;">Rusak: <b><?= number_format((int)$total_qty_rusak,0,',','.') ?></b></span>
                        <span style="margin-left:8px; color:<?= $total_qty_retur > 0 ? '#166534' : '#444' ?>;">Retur: <b><?= number_format((int)$total_qty_retur,0,',','.') ?></b></span>
                        <span style="margin-left:8px; color:<?= $total_qty_invalid > 0 ? '#c0392b' : '#444' ?>;">Invalid: <b><?= number_format((int)$total_qty_invalid,0,',','.') ?></b></span>
                    </div>
                    <div style="font-style:italic; color:#666; font-size:10px;">
                        *Data berdasarkan input manual lapangan
                    </div>
                </div>
                
                <hr style="border:0; border-top:1px dashed #ccc; margin:10px 0;">

                <?php if (!empty($audit_summary_report)): ?>
                    <?php foreach ($audit_summary_report as $idx => $rep): ?>
                        <div class="audit-item" style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #eee;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <div style="font-size:13px;">
                                    <strong><?= ($idx + 1) ?>. Blok <?= htmlspecialchars($rep['blok']) ?></strong>
                                </div>
                                <div style="font-size:12px; font-weight:bold;">
                                    <?php 
                                        $rusak_total = (int)($rep['rusak_total'] ?? 0);
                                        $unreported_total = (int)($rep['unreported_total'] ?? 0);
                                        
                                        // LOGIKA STATUS
                                        if ($rep['selisih_setoran'] < 0) {
                                            echo "<span style='color:#991b1b; background:#fee2e2; padding:3px 8px; border-radius:4px; border:1px solid #fca5a5;'>KURANG SETOR: Rp " . number_format(abs($rep['selisih_setoran']), 0, ',', '.') . "</span>";
                                        } elseif ($rep['selisih_setoran'] > 0) {
                                            echo "<span style='color:#166534; background:#dcfce7; padding:3px 8px; border-radius:4px; border:1px solid #86efac;'>LEBIH SETOR: Rp " . number_format($rep['selisih_setoran'], 0, ',', '.') . "</span>";
                                        } elseif ($rusak_total > 0) {
                                            echo "<span style='color:#92400e; background:#fef3c7; padding:3px 8px; border-radius:4px; border:1px solid #fcd34d;'>SETORAN SESUAI (ADA RUSAK)</span>";
                                        } else {
                                            echo "<span style='color:#1e3a8a; background:#dbeafe; padding:3px 8px; border-radius:4px; border:1px solid #93c5fd;'>STATUS: AMAN</span>";
                                        }
                                    ?>
                                </div>
                            </div>

                            <div style="margin-left:15px; font-size:11px; color:#444; line-height:1.4;">
                                <?php if (!empty($rep['profile_summary'])): ?>
                                    <div>• Profil: <b><?= htmlspecialchars($rep['profile_summary']) ?></b></div>
                                <?php endif; ?>
                            </div>

                            <?php if ($rusak_total > 0 || !empty($rep['retur_total']) || !empty($rep['unreported_total']) || !empty($rep['ghost_10']) || !empty($rep['ghost_30'])): ?>
                                <div style="margin-top:8px; margin-left:15px; background:#fff; border:1px solid #ddd; border-left: 3px solid #ccc; border-radius:2px; padding:6px 8px;">
                                    <div style="font-size:10px; font-weight:bold; color:#555; margin-bottom:4px; text-transform:uppercase; border-bottom:1px solid #eee; padding-bottom:2px;">Rincian Masalah / Insiden:</div>
                                    
                                    <?php if ($rusak_total > 0): ?>
                                        <div style="color:#b91c1c; font-size:11px; margin-bottom:2px;">
                                            <i class="fa fa-times-circle"></i> <b>Voucher Rusak (Kerugian):</b>
                                            <?= htmlspecialchars($rep['rusak_summary'] ?? '-') ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($rep['retur_total'])): ?>
                                        <div style="color:#15803d; font-size:11px; margin-bottom:2px;">
                                            <i class="fa fa-refresh"></i> <b>Voucher Retur (Diganti Baru):</b>
                                            <?= htmlspecialchars($rep['retur_summary'] ?? '-') ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($rep['unreported_total'])): ?>
                                        <div style="color:#b45309; font-size:11px; margin-bottom:2px;">
                                            <i class="fa fa-exclamation-triangle"></i> <b>User Aktif Tidak Terlapor:</b>
                                            <?= htmlspecialchars($rep['unreported_summary'] ?? '-') ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                        $ghost_10_rem = (int)$rep['ghost_10'];
                                        $ghost_30_rem = (int)$rep['ghost_30'];
                                        $ghost_parts = [];
                                        if ($ghost_10_rem > 0) $ghost_parts[] = $ghost_10_rem . ' unit (10m)';
                                        if ($ghost_30_rem > 0) $ghost_parts[] = $ghost_30_rem . ' unit (30m)';
                                    ?>
                                    <?php if (!empty($ghost_parts)): ?>
                                        <div style="color:#c2410c; font-size:11px; margin-top:2px; font-style:italic;">
                                            <i class="fa fa-search"></i> <b>Indikasi Sisa Selisih (Auto-Detect):</b>
                                            <?= implode(', ', $ghost_parts) ?> hilang/belum input.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="audit-item" style="text-align:center; padding:20px; color:#666;">
                        Belum ada data audit manual yang diinput untuk tanggal ini.
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
    <?php endif; ?>

<script>
function shareReport(){
    if (navigator.share) {
        navigator.share({
            title: 'Rekap Laporan Penjualan',
            url: window.location.href
        });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}

function setUniquePrintTitle(){
    var now = new Date();
    var pad = function(n){ return String(n).padStart(2, '0'); };
    var dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    var reportDateStr = <?= json_encode((string)$filter_date) ?>;
    var dateParts = reportDateStr.split('-');
    var reportDate = (dateParts.length === 3)
        ? new Date(Number(dateParts[0]), Number(dateParts[1]) - 1, Number(dateParts[2]))
        : now;
    var dayLabel = dayNames[reportDate.getDay()];
    var dateLabel = pad(reportDate.getDate()) + '-' + pad(reportDate.getMonth() + 1) + '-' + reportDate.getFullYear();
    var timeLabel = pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    document.title = 'LaporanHarian-' + dayLabel + '-' + dateLabel + '-' + timeLabel;
}

window.addEventListener('beforeprint', setUniquePrintTitle);

</script>
</body>
</html>