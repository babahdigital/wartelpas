<?php
session_start();
error_reporting(0);

if (empty($_SESSION['audit_csrf'])) {
    $_SESSION['audit_csrf'] = bin2hex(random_bytes(16));
}
$audit_csrf = $_SESSION['audit_csrf'];

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once($root_dir . '/include/db_helpers.php');
require_once($root_dir . '/report/laporan/helpers.php');
$pricing = $env['pricing'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$profile_price_map = $pricing['profile_prices'] ?? [];
$GLOBALS['profile_price_map'] = $profile_price_map;

$dbFile = get_stats_db_path();
$session_id = $_GET['session'] ?? '';

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
        $filter_date = date('Y-m-d');
    }
} elseif ($req_show === 'bulanan') {
    if (!preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
        $filter_date = date('Y-m');
    }
} else {
    $req_show = 'tahunan';
    if (!preg_match('/^\d{4}$/', $filter_date)) {
        $filter_date = date('Y');
    }
}


function calc_audit_adjusted_totals(array $ar) {
    $expected_qty = (int)($ar['expected_qty'] ?? 0);
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $reported_qty = (int)($ar['reported_qty'] ?? 0);
    $actual_setoran = function_exists('normalize_actual_setoran') ? normalize_actual_setoran($ar) : (int)($ar['actual_setoran'] ?? 0);
    $expense_amt = (int)($ar['expenses_amt'] ?? 0);
    $has_manual_evidence = false;
    $manual_setoran_override = false;

    $profile_qty_map = [];
    $status_count_map = [];

    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence)) {
            $has_manual_evidence = true;
            $manual_setoran_override = !empty($evidence['manual_setoran']);
            if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                $raw_map = $evidence['profile_qty'];
                if (isset($raw_map['qty_10']) || isset($raw_map['qty_30'])) {
                    $profile_qty_map['10menit'] = (int)($raw_map['qty_10'] ?? 0);
                    $profile_qty_map['30menit'] = (int)($raw_map['qty_30'] ?? 0);
                } else {
                    foreach ($raw_map as $k => $v) {
                        $key = strtolower(trim((string)$k));
                        if ($key === '') continue;
                        $profile_qty_map[$key] = (int)$v;
                    }
                }
            }
            if (!empty($evidence['users']) && is_array($evidence['users'])) {
                foreach ($evidence['users'] as $ud) {
                    $status = strtolower((string)($ud['last_status'] ?? ''));
                    $kind = strtolower((string)($ud['profile_key'] ?? $ud['profile_kind'] ?? ''));
                    if ($kind !== '' && preg_match('/^(\d+)$/', $kind, $m)) {
                        $kind = $m[1] . 'menit';
                    }
                    if ($kind === '') $kind = '10menit';
                    if (!isset($status_count_map[$kind])) {
                        $status_count_map[$kind] = ['invalid' => 0, 'retur' => 0, 'rusak' => 0];
                    }
                    if ($status === 'invalid') $status_count_map[$kind]['invalid']++;
                    elseif ($status === 'retur') $status_count_map[$kind]['retur']++;
                    elseif ($status === 'rusak') $status_count_map[$kind]['rusak']++;
                }
            }
        }
    }

    if ($has_manual_evidence) {
        $manual_display_qty = 0;
        $manual_display_setoran = 0;
        foreach ($profile_qty_map as $k => $qty) {
            $qty = (int)$qty;
            $manual_display_qty += $qty;
            $counts = $status_count_map[$k] ?? ['invalid' => 0, 'retur' => 0, 'rusak' => 0];
            $money_qty = max(0, $qty - (int)$counts['rusak'] - (int)$counts['invalid']);
            $price_val = isset($GLOBALS['profile_price_map'][$k]) ? (int)$GLOBALS['profile_price_map'][$k] : (int)resolve_price_from_profile($k);
            $manual_display_setoran += ($money_qty * $price_val);
        }
        if ($manual_setoran_override || ($actual_setoran > 0 && $actual_setoran !== $manual_display_setoran)) {
            $manual_display_setoran = $actual_setoran;
        }
        if ($manual_display_qty === 0) {
            $manual_display_qty = $reported_qty;
            $manual_display_setoran = $actual_setoran;
        }
        $expected_adj_qty = $expected_qty;
        $expected_adj_setoran = $expected_setoran;
    } else {
        $manual_display_qty = $reported_qty;
        $manual_display_setoran = $actual_setoran;
        $expected_adj_qty = $expected_qty;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_qty, $expected_adj_qty, $manual_display_setoran, $expected_adj_setoran, $expense_amt];
}

$db = null;
$sales_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
    'pending' => 0,
];
$security_logs = [];
$sales_status_rows = [];
$pending_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
];
$pending_range_label = '';
$audit_manual_summary = [
    'rows' => 0,
    'manual_qty' => 0,
    'expected_qty' => 0,
    'manual_setoran' => 0,
    'expected_setoran' => 0,
    'selisih_qty' => 0,
    'selisih_setoran' => 0,
    'total_rusak_rp' => 0,
    'total_expenses' => 0,
    'total_refund' => 0,
    'total_kurang_bayar' => 0,
];
$audit_refund_notes = [];
$audit_kurang_bayar_notes = [];
$daily_note_alert = '';
$rebuild_message = '';
$audit_warnings = [];
$sync_status_message = '';

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dateFilter = '';
        $dateParam = [];
        $auditDateFilter = '';
        $auditDateParam = [];
        if ($req_show === 'harian') {
            $dateFilter = '(sale_date = :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3 OR raw_date LIKE :raw4 OR raw_date LIKE :raw5)';
            $dateParam[':d'] = $filter_date;
            $dateParam[':raw1'] = $filter_date . '%';
            $dateParam[':raw2'] = date('m/d/Y', strtotime($filter_date)) . '%';
            $dateParam[':raw3'] = date('d/m/Y', strtotime($filter_date)) . '%';
            $dateParam[':raw4'] = date('M/d/Y', strtotime($filter_date)) . '%';
            $dateParam[':raw5'] = substr($filter_date, 0, 10) . '%';
            $auditDateFilter = 'report_date = :d';
            $auditDateParam[':d'] = $filter_date;
        } elseif ($req_show === 'bulanan') {
            $ym = $filter_date;
            $year = substr($ym, 0, 4);
            $month = substr($ym, 5, 2);
            $monthShort = date('M', strtotime($ym . '-01'));
            $dateFilter = '(sale_date LIKE :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3)';
            $dateParam[':d'] = $ym . '%';
            $dateParam[':raw1'] = $month . '/%/' . $year;
            $dateParam[':raw2'] = '%/' . $month . '/' . $year;
            $dateParam[':raw3'] = $monthShort . '/%/' . $year;
            $auditDateFilter = 'report_date LIKE :d';
            $auditDateParam[':d'] = $ym . '%';
        } else {
            $year = $filter_date;
            $dateFilter = '(sale_date LIKE :d OR raw_date LIKE :raw1)';
            $dateParam[':d'] = $year . '%';
            $dateParam[':raw1'] = '%/' . $year;
            $auditDateFilter = 'report_date LIKE :d';
            $auditDateParam[':d'] = $year . '%';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rebuild_audit_expected') {
            $token_ok = isset($_POST['audit_csrf']) && hash_equals($audit_csrf, (string)$_POST['audit_csrf']);
            if (!$token_ok) {
                $rebuild_message = 'Token tidak valid. Silakan refresh halaman.';
            } elseif ($req_show !== 'harian') {
                $rebuild_message = 'Rebuild hanya tersedia untuk rekap harian.';
            } else {
                try {
                    $updated = rebuild_audit_expected_for_date($db, $filter_date);
                    $rebuild_message = $updated > 0 ? ('Rebuild sukses: ' . $updated . ' blok diperbarui.') : 'Tidak ada blok audit yang bisa diperbarui.';
                } catch (Exception $e) {
                    $rebuild_message = 'Rebuild gagal. Coba lagi.';
                }
            }
        }

        $has_sales_history = table_exists($db, 'sales_history');
        if ($has_sales_history) {
            $sumSql = "SELECT
                SUM(CASE WHEN eff_status='invalid' THEN eff_price * eff_qty ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN eff_status='rusak' THEN eff_price * eff_qty ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN eff_status='retur' THEN eff_price * eff_qty ELSE 0 END) AS retur_sum,
                SUM(eff_price * eff_qty) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM (
                    SELECT
                        CASE
                            WHEN COALESCE(sh.is_retur,0)=1
                                OR LOWER(COALESCE(sh.status,''))='retur'
                                OR LOWER(COALESCE(sh.comment,'')) LIKE '%retur%'
                                THEN 'retur'
                            WHEN COALESCE(sh.is_rusak,0)=1
                                OR LOWER(COALESCE(sh.status,''))='rusak'
                                OR LOWER(COALESCE(sh.comment,'')) LIKE '%rusak%'
                                OR LOWER(COALESCE(lh.last_status,''))='rusak'
                                THEN 'rusak'
                            WHEN COALESCE(sh.is_invalid,0)=1
                                OR LOWER(COALESCE(sh.status,''))='invalid'
                                OR LOWER(COALESCE(sh.comment,'')) LIKE '%invalid%'
                                THEN 'invalid'
                            ELSE 'normal'
                        END AS eff_status,
                        COALESCE(sh.price_snapshot, sh.price, 0) AS eff_price,
                        COALESCE(sh.qty,1) AS eff_qty
                                        FROM sales_history sh
                                        LEFT JOIN login_history lh ON lh.username = sh.username
                                        WHERE $dateFilter
                                            AND instr(lower(COALESCE(sh.comment,'')), 'vip') = 0
                                            AND instr(lower(COALESCE(lh.raw_comment,'')), 'vip') = 0
                ) t";
            $stmt = $db->prepare($sumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sumRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $raw_gross = (int)($sumRow['gross_sum'] ?? 0);
            $invalid_rp = (int)($sumRow['invalid_sum'] ?? 0);
            $rusak_rp = (int)($sumRow['rusak_sum'] ?? 0);
            $retur_rp = (int)($sumRow['retur_sum'] ?? 0);

            $sales_summary['invalid'] = $invalid_rp;
            $sales_summary['rusak'] = $rusak_rp;
            $sales_summary['retur'] = $retur_rp;
            $sales_summary['gross'] = $raw_gross - $retur_rp;
            $sales_summary['total'] = (int)($sumRow['total_cnt'] ?? 0);
            $sales_summary['net'] = $raw_gross - $invalid_rp - $rusak_rp;
        }

        if (table_exists($db, 'login_history')) {
            try {
                $lhWhere = "(substr(login_time_real,1,10) = :d_lh OR substr(last_login_real,1,10) = :d_lh OR substr(logout_time_real,1,10) = :d_lh OR substr(updated_at,1,10) = :d_lh OR login_date = :d_lh)";
                if ($req_show === 'bulanan') {
                    $lhWhere = "(substr(login_time_real,1,7) = :d_lh OR substr(last_login_real,1,7) = :d_lh OR substr(logout_time_real,1,7) = :d_lh OR substr(updated_at,1,7) = :d_lh OR substr(login_date,1,7) = :d_lh)";
                } elseif ($req_show === 'tahunan') {
                    $lhWhere = "(substr(login_time_real,1,4) = :d_lh OR substr(last_login_real,1,4) = :d_lh OR substr(logout_time_real,1,4) = :d_lh OR substr(updated_at,1,4) = :d_lh OR substr(login_date,1,4) = :d_lh)";
                }

                $lhNotExists = '';
                if ($has_sales_history) {
                    $lhNotExists = "AND NOT EXISTS (SELECT 1 FROM sales_history sh WHERE sh.username = lh.username AND $dateFilter)";
                }

                $lhSql = "SELECT username, price, validity, raw_comment, last_status
                    FROM login_history lh
                    WHERE username != ''
                        AND $lhWhere
                        AND instr(lower(COALESCE(raw_comment,'')), 'vip') = 0
                        AND (
                            instr(lower(COALESCE(NULLIF(last_status,''), '')), 'rusak') > 0
                            OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'retur') > 0
                            OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'invalid') > 0
                        )
                        $lhNotExists";
                $stmt = $db->prepare($lhSql);
                foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
                $stmt->bindValue(':d_lh', $filter_date);
                $stmt->execute();
                $lhRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($lhRows)) {
                    $lh_total = 0;
                    $lh_raw_gross = 0;
                    $lh_invalid = 0;
                    $lh_rusak = 0;
                    $lh_retur = 0;
                    foreach ($lhRows as $row) {
                        $status = strtolower(trim((string)($row['last_status'] ?? '')));
                        if (strpos($status, 'retur') !== false) $status = 'retur';
                        elseif (strpos($status, 'rusak') !== false) $status = 'rusak';
                        elseif (strpos($status, 'invalid') !== false) $status = 'invalid';
                        else $status = '';

                        $price = (int)($row['price'] ?? 0);
                        if ($price <= 0) {
                            $profile = (string)($row['validity'] ?? '');
                            if ($profile === '') {
                                $profile = extract_profile_from_comment($row['raw_comment'] ?? '');
                            }
                            $price = (int)resolve_price_from_profile($profile);
                        }
                        if ($price <= 0) continue;

                        $lh_total++;
                        $lh_raw_gross += $price;
                        if ($status === 'invalid') $lh_invalid += $price;
                        elseif ($status === 'rusak') $lh_rusak += $price;
                        elseif ($status === 'retur') $lh_retur += $price;
                    }
                    if ($lh_total > 0) {
                        $sales_summary['total'] += $lh_total;
                        $sales_summary['gross'] += ($lh_raw_gross - $lh_retur);
                        $sales_summary['rusak'] += $lh_rusak;
                        $sales_summary['retur'] += $lh_retur;
                        $sales_summary['invalid'] += $lh_invalid;
                        $sales_summary['net'] += ($lh_raw_gross - $lh_invalid - $lh_rusak);
                    }
                }
            } catch (Exception $e) {}
        }

        if (table_exists($db, 'live_sales')) {
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending' AND $dateFilter AND instr(lower(COALESCE(comment,'')), 'vip') = 0";
            $stmt = $db->prepare($pendingSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sales_summary['pending'] = (int)($stmt->fetchColumn() ?: 0);

            $pendingSumSql = "SELECT
                SUM(CASE WHEN eff_status='invalid' THEN eff_price * eff_qty ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN eff_status='rusak' THEN eff_price * eff_qty ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN eff_status='retur' THEN eff_price * eff_qty ELSE 0 END) AS retur_sum,
                SUM(eff_price * eff_qty) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM (
                    SELECT
                        CASE
                            WHEN COALESCE(ls.is_retur,0)=1
                                OR LOWER(COALESCE(ls.status,''))='retur'
                                OR LOWER(COALESCE(ls.comment,'')) LIKE '%retur%'
                                THEN 'retur'
                            WHEN COALESCE(ls.is_rusak,0)=1
                                OR LOWER(COALESCE(ls.status,''))='rusak'
                                OR LOWER(COALESCE(ls.comment,'')) LIKE '%rusak%'
                                OR LOWER(COALESCE(lh2.last_status,''))='rusak'
                                THEN 'rusak'
                            WHEN COALESCE(ls.is_invalid,0)=1
                                OR LOWER(COALESCE(ls.status,''))='invalid'
                                OR LOWER(COALESCE(ls.comment,'')) LIKE '%invalid%'
                                THEN 'invalid'
                            ELSE 'normal'
                        END AS eff_status,
                        COALESCE(ls.price_snapshot, ls.price, 0) AS eff_price,
                        COALESCE(ls.qty,1) AS eff_qty
                                        FROM live_sales ls
                                        LEFT JOIN login_history lh2 ON lh2.username = ls.username
                                        WHERE ls.sync_status='pending' AND $dateFilter
                                            AND instr(lower(COALESCE(ls.comment,'')), 'vip') = 0
                                            AND instr(lower(COALESCE(lh2.raw_comment,'')), 'vip') = 0
                ) t";
            $stmt = $db->prepare($pendingSumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $raw_gross = (int)($p['gross_sum'] ?? 0);
            $invalid_rp = (int)($p['invalid_sum'] ?? 0);
            $rusak_rp = (int)($p['rusak_sum'] ?? 0);
            $retur_rp = (int)($p['retur_sum'] ?? 0);

            $pending_summary['invalid'] = $invalid_rp;
            $pending_summary['rusak'] = $rusak_rp;
            $pending_summary['retur'] = $retur_rp;
            $pending_summary['gross'] = $raw_gross - $retur_rp;
            $pending_summary['total'] = (int)($p['total_cnt'] ?? 0);
            $pending_summary['net'] = $raw_gross - $invalid_rp - $rusak_rp;

            if ($sales_summary['pending'] > 0) {
                $rangeSql = "SELECT
                    MIN(COALESCE(NULLIF(sale_date,''),'')) AS min_sale,
                    MAX(COALESCE(NULLIF(sale_date,''),'')) AS max_sale,
                    MIN(COALESCE(NULLIF(raw_date,''),'')) AS min_raw,
                    MAX(COALESCE(NULLIF(raw_date,''),'')) AS max_raw
                    FROM live_sales
                    WHERE sync_status='pending' AND $dateFilter AND instr(lower(COALESCE(comment,'')), 'vip') = 0";
                $stmt = $db->prepare($rangeSql);
                foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
                $stmt->execute();
                $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $minDate = (string)($r['min_sale'] ?? '');
                $maxDate = (string)($r['max_sale'] ?? '');
                if ($minDate === '' || $maxDate === '') {
                    $minDate = (string)($r['min_raw'] ?? $minDate);
                    $maxDate = (string)($r['max_raw'] ?? $maxDate);
                }
                if ($minDate !== '' && $maxDate !== '') {
                    $minLabel = format_date_dmy($minDate);
                    $maxLabel = format_date_dmy($maxDate);
                    if ($minLabel === $maxLabel) {
                        $pending_range_label = 'Tanggal Pending: ' . $minLabel;
                    } else {
                        $pending_range_label = 'Rentang Pending: ' . $minLabel . ' - ' . $maxLabel;
                    }
                }
            }
        }

        if (table_exists($db, 'audit_rekap_manual')) {
            $auditSql = "SELECT blok_name, expected_qty, expected_setoran, reported_qty, actual_setoran, selisih_setoran, refund_amt, refund_desc, kurang_bayar_amt, kurang_bayar_desc, expenses_amt, expenses_desc, user_evidence
                FROM audit_rekap_manual WHERE $auditDateFilter";
            $stmt = $db->prepare($auditSql);
            foreach ($auditDateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $audit_manual_summary['rows'] = count($audit_rows);
            $audit_manual_summary['total_rusak_rp'] = 0;
            foreach ($audit_rows as $ar) {
                [$manual_qty, $expected_qty, $manual_setoran, $expected_setoran, $expense_amt] = calc_audit_adjusted_totals($ar);
                $audit_manual_summary['manual_qty'] += (int)$manual_qty;
                $audit_manual_summary['expected_qty'] += (int)$expected_qty;
                $audit_manual_summary['manual_setoran'] += (int)$manual_setoran;
                $audit_manual_summary['expected_setoran'] += (int)$expected_setoran;
                $audit_manual_summary['total_expenses'] += (int)$expense_amt;
                $refund_amt = (int)($ar['refund_amt'] ?? 0);
                $refund_desc = trim((string)($ar['refund_desc'] ?? ''));
                $kurang_bayar_amt = (int)($ar['kurang_bayar_amt'] ?? 0);
                $kurang_bayar_desc = trim((string)($ar['kurang_bayar_desc'] ?? ''));
                $audit_manual_summary['total_refund'] += $refund_amt;
                $audit_manual_summary['total_kurang_bayar'] += $kurang_bayar_amt;

                if ($refund_amt > 0) {
                    $blok_name = trim((string)($ar['blok_name'] ?? ''));
                    $row_selisih = (int)$manual_setoran - (int)$expected_setoran;
                    $row_selisih_adj = $row_selisih - $refund_amt + $kurang_bayar_amt;
                    $audit_refund_notes[] = [
                        'blok' => $blok_name !== '' ? $blok_name : 'Tanpa Blok',
                        'refund_amt' => $refund_amt,
                        'refund_desc' => $refund_desc,
                        'selisih_adj' => $row_selisih_adj,
                    ];
                }
                if ($kurang_bayar_amt > 0) {
                    $blok_name = trim((string)($ar['blok_name'] ?? ''));
                    $row_selisih = (int)$manual_setoran - (int)$expected_setoran;
                    $row_selisih_adj = $row_selisih - $refund_amt + $kurang_bayar_amt;
                    $audit_kurang_bayar_notes[] = [
                        'blok' => $blok_name !== '' ? $blok_name : 'Tanpa Blok',
                        'kurang_bayar_amt' => $kurang_bayar_amt,
                        'kurang_bayar_desc' => $kurang_bayar_desc,
                        'selisih_adj' => $row_selisih_adj,
                    ];
                }

                $curr_rusak_rp = 0;
                if (!empty($ar['user_evidence'])) {
                    $ev = json_decode((string)$ar['user_evidence'], true);
                    if (is_array($ev) && !empty($ev['users'])) {
                        foreach ($ev['users'] as $u) {
                            $st = strtolower((string)($u['last_status'] ?? ''));
                            $k = strtolower((string)($u['profile_key'] ?? $u['profile_kind'] ?? ''));
                            if ($k !== '' && preg_match('/^(\d+)$/', $k, $m)) {
                                $k = $m[1] . 'menit';
                            }
                            if ($k === '') $k = '10menit';
                            if ($st === 'rusak' || $st === 'invalid') {
                                $price = isset($GLOBALS['profile_price_map'][$k])
                                    ? (int)$GLOBALS['profile_price_map'][$k]
                                    : (int)resolve_price_from_profile($k);
                                $curr_rusak_rp += $price;
                            }
                        }
                    }
                }
                $audit_manual_summary['total_rusak_rp'] += $curr_rusak_rp;
            }
            $audit_manual_summary['selisih_qty'] = (int)$audit_manual_summary['manual_qty'] - (int)$audit_manual_summary['expected_qty'];
            $audit_manual_summary['selisih_setoran'] = (int)$audit_manual_summary['manual_setoran'] - (int)$audit_manual_summary['expected_setoran'];
        }

        if ($req_show === 'harian') {
            try {
                $stmtN = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
                $stmtN->execute([':d' => $filter_date]);
                $daily_note_alert = $stmtN->fetchColumn() ?: '';
            } catch (Exception $e) {}
        }
        if ($req_show === 'harian') {
            $audit_warnings = fetch_audit_warnings($db, $filter_date);
            if (!empty($audit_warnings)) {
                $audit_warnings = array_values(array_filter($audit_warnings, function($w) {
                    return (string)($w['type'] ?? '') !== 'sync_status';
                }));
            }
        }

        if ($sales_summary['pending'] > 0) {
            $sync_status_message = 'Belum disinkronisasi. Masih ada ' . number_format($sales_summary['pending'],0,',','.') . ' transaksi pending. Setelah settlement, klik Rebuild Target Sistem.';
        } else {
            $sync_status_message = 'Sistem sudah disinkronisasi.';
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>

<style>
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .card-body { padding: 16px; }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 10px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 10px; margin-bottom: 14px; }
    .summary-card { background:#22272b;border:1px solid #3a4046;border-radius:6px;padding:12px; }
    .summary-title { font-size: 11px; color: var(--txt-muted); text-transform: uppercase; letter-spacing: .5px; }
    .summary-value { font-size: 18px; font-weight: 700; margin-top: 4px; }
    .pill { display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700; }
    .pill-ok { background:#1f7a3f;color:#fff; }
    .pill-warn { background:#f39c12;color:#fff; }
    .pill-bad { background:#c0392b;color:#fff; }
    .section-title { font-weight:700; margin:14px 0 8px; font-size:14px; margin-left: 1px;}
    .toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom: 10px; }
    .toolbar-left { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .toolbar-right { display:flex; gap:8px; align-items:center; }
    .toolbar select, .toolbar input { background:#343a40; color:#fff; border:1px solid var(--border-col); height:36px; padding:0 10px; border-radius:4px; }
    .toolbar select:focus, .toolbar input:focus { outline:none; box-shadow:none; border-color: var(--border-col); }
    .btn-solid { background:#2d8cff;color:#fff;border:none;padding:6px 10px;border-radius:4px; cursor:pointer; }
    .muted { color: var(--txt-muted); }
    .print-title { display: none; }
</style>

<div class="card card-solid">
    <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-shield"></i> Audit Keuangan & Voucher</h3>
        <div style="display:flex;gap:8px;align-items:center;">
            <form method="POST" action="?report=audit_session&session=<?= urlencode($session_id) ?>&show=<?= urlencode($req_show) ?>&date=<?= urlencode($filter_date) ?>" style="margin:0;">
                <input type="hidden" name="action" value="rebuild_audit_expected">
                <input type="hidden" name="audit_csrf" value="<?= htmlspecialchars($audit_csrf) ?>">
                <button type="submit" class="btn-solid" style="background:#8e44ad;"><i class="fa fa-refresh"></i> Rebuild Target Sistem</button>
            </form>
            <a class="btn-solid" style="text-decoration:none;" target="_blank" href="report/print/print_audit.php?session=<?= urlencode($session_id) ?>&show=<?= urlencode($req_show) ?>&date=<?= urlencode($filter_date) ?>"><i class="fa fa-print"></i> Print Laporan Keuangan</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($rebuild_message)): ?>
            <div style="background:#e8f0fe;border:1px solid #c6dafc;border-left:5px solid #4c8bf5;padding:12px;border-radius:4px;margin-bottom:14px;color:#1a3c78;">
                <?= htmlspecialchars($rebuild_message) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($sync_status_message)): ?>
            <div style="background:#eef7ff;border:1px solid #cde7ff;border-left:5px solid #5aa2ff;padding:12px;border-radius:4px;margin-bottom:14px;color:#1a3c78;">
                <?= htmlspecialchars($sync_status_message) ?>
            </div>
        <?php endif; ?>
        <form method="GET" class="toolbar" action="?">
            <input type="hidden" name="report" value="audit_session">
            <input type="hidden" name="session" value="<?= htmlspecialchars($session_id) ?>">
            <div class="toolbar-left">
                <select name="show" onchange="this.form.submit()">
                    <option value="harian" <?= $req_show==='harian'?'selected':''; ?>>Harian</option>
                    <option value="bulanan" <?= $req_show==='bulanan'?'selected':''; ?>>Bulanan</option>
                    <option value="tahunan" <?= $req_show==='tahunan'?'selected':''; ?>>Tahunan</option>
                </select>
                <?php if ($req_show === 'harian'): ?>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php elseif ($req_show === 'bulanan'): ?>
                    <input type="month" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php else: ?>
                    <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="width:120px;">
                <?php endif; ?>
            </div>
            <div class="toolbar-right muted">
                Filter: <?= htmlspecialchars(format_date_dmy($filter_date)) ?>
            </div>
        </form>

        <div class="section-title" style="margin-top:0;">Ringkasan Keuangan (Audit Manual vs Sistem)</div>

        <div class="summary-card" style="border:1px dashed #3a4046;background:#1f2327;margin-bottom:14px;">
            <div class="summary-title" style="color:#f39c12;"><i class="fa fa-info-circle"></i> SOP Input Audit (Wajib)</div>
            <div style="font-size:12px;color:#cbd5e1;line-height:1.6;margin-top:6px;">
                1) Total Qty fisik (termasuk retur sebagai pengganti).<br>
                2) Total uang fisik di laci (sebelum pengeluaran).<br>
                3) Pengeluaran opsional (jika ada, wajib isi keterangan).<br>
                4) Evidence: tandai user rusak/retur agar selisih bisa dijelaskan.
            </div>
        </div>

        <?php if (!empty($daily_note_alert)): ?>
            <div style="background:#fff3cd; border:1px solid #ffeeba; border-left:5px solid #ffc107; padding:15px; border-radius:4px; margin-bottom:20px; color:#856404;">
                <div style="font-weight:bold; font-size:12px; text-transform:uppercase; margin-bottom:5px;">
                    <i class="fa fa-commenting-o"></i> Catatan Operasional / Insiden:
                </div>
                <div style="font-size:14px; line-height:1.5; font-style:italic;">
                    "<?= nl2br(htmlspecialchars($daily_note_alert)) ?>"
                </div>
            </div>
        <?php endif; ?>

        <?php if ($audit_manual_summary['rows'] === 0): ?>
            <?php
                $use_pending_stats = ($sales_summary['total'] === 0 && $sales_summary['pending'] > 0);
                $target_est = $use_pending_stats ? $pending_summary['net'] : $sales_summary['net'];
            ?>
            <div class="summary-card" style="border:1px solid #3a4046;background:#1f2327;text-align:center;padding:20px;">
                <div style="color:#f39c12;font-weight:bold;"><i class="fa fa-exclamation-triangle"></i> Belum ada data Audit Manual yang diinput pada periode ini.</div>
                <div style="margin-top:10px;font-size:13px;color:#bbb;">Silakan input fisik uang/voucher di Laporan Penjualan untuk melihat selisih.</div>
                <div style="margin-top:12px;font-size:14px;color:#fff;">Target Sistem (Estimasi): <b>Rp <?= number_format($target_est,0,',','.') ?></b></div>
            </div>
        <?php else: ?>
            <?php
                $selisih = $audit_manual_summary['selisih_setoran'];
                $selisih_adj = $selisih - (int)($audit_manual_summary['total_refund'] ?? 0) + (int)($audit_manual_summary['total_kurang_bayar'] ?? 0);
                $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih_adj);
                $color_status = $selisih_adj < 0 ? '#c0392b' : ($selisih_adj > 0 ? '#2ecc71' : '#3498db');
                $text_status = $selisih_adj < 0 ? 'KURANG SETOR (LOSS)' : ($selisih_adj > 0 ? 'LEBIH SETOR' : 'AMAN / SESUAI');
                $bg_status = $selisih_adj < 0 ? '#381818' : ($selisih_adj > 0 ? '#1b3a24' : '#1e2a36');
                $system_net_total = (int)$sales_summary['net'] + (int)$pending_summary['net'];
            ?>
            <div style="background:<?= $bg_status ?>;border:1px solid <?= $color_status ?>;padding:15px;border-radius:6px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <div style="font-size:12px;color:#aaa;text-transform:uppercase;">Status Keuangan</div>
                    <div style="font-size:22px;font-weight:bold;color:<?= $color_status ?>;"><?= $text_status ?></div>
                    <?php if ($selisih_adj != 0): ?>
                        <div style="font-size:16px;color:#fff;">Selisih: Rp <?= number_format($selisih_adj,0,',','.') ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ghost_hint)): ?>
                    <div style="text-align:right;max-width:50%;">
                        <div style="font-size:11px;color:#fca5a5;font-weight:bold;text-transform:uppercase;">Ghost Hunter (Deteksi Otomatis)</div>
                        <div style="font-size:13px;color:#fff;"><?= htmlspecialchars($ghost_hint) ?></div>
                        <div style="font-size:10px;color:#aaa;">*Kemungkinan voucher lupa diinput atau hilang.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-title">Setoran Bersih (Cash)</div>
                    <div class="summary-value">Rp <?= number_format(max(0, $audit_manual_summary['manual_setoran'] - $audit_manual_summary['total_expenses'] - $audit_manual_summary['total_refund'] + $audit_manual_summary['total_kurang_bayar']),0,',','.') ?></div>
                </div>
                <?php if ($audit_manual_summary['total_expenses'] > 0): ?>
                    <div class="summary-card" style="border-color:#f39c12;">
                        <div class="summary-title" style="color:#f39c12;">Pengeluaran Ops.</div>
                        <div class="summary-value" style="color:#f39c12;">Rp <?= number_format($audit_manual_summary['total_expenses'],0,',','.') ?></div>
                        <div style="font-size:10px;color:#d35400;">(Bon/Belanja)</div>
                    </div>
                <?php endif; ?>
                <?php if ($audit_manual_summary['total_refund'] > 0): ?>
                    <div class="summary-card" style="border-color:#6c5ce7;">
                        <div class="summary-title" style="color:#6c5ce7;">Pengembalian</div>
                        <div class="summary-value" style="color:#6c5ce7;">Rp <?= number_format($audit_manual_summary['total_refund'],0,',','.') ?></div>
                        <div style="font-size:10px;color:#6c5ce7;">(Lebih setor)</div>
                    </div>
                <?php endif; ?>
                <?php if ($audit_manual_summary['total_kurang_bayar'] > 0): ?>
                    <div class="summary-card" style="border-color:#2ecc71;">
                        <div class="summary-title" style="color:#2ecc71;">Piutang</div>
                        <div class="summary-value" style="color:#2ecc71;">Rp <?= number_format($audit_manual_summary['total_kurang_bayar'],0,',','.') ?></div>
                        <div style="font-size:10px;color:#2ecc71;">(Tagih susulan)</div>
                    </div>
                <?php endif; ?>
                <div class="summary-card">
                    <div class="summary-title">Target Sistem (Audit)</div>
                    <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card" style="border-color:#3f4b57;">
                    <div class="summary-title">Target Sistem (Global)</div>
                    <div class="summary-value">Rp <?= number_format($system_net_total,0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Selisih Qty</div>
                    <div class="summary-value" style="color:<?= $audit_manual_summary['selisih_qty'] != 0 ? '#f39c12' : '#2ecc71' ?>;">
                        <?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?> Lembar
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-title">Statistik Keuangan & Insiden (Real-Time)</div>
        <?php
            $stat_total = (int)$sales_summary['total'] + (int)$sales_summary['pending'];
            $stat_gross = (int)$sales_summary['gross'] + (int)$pending_summary['gross'];
            $stat_rusak_system = (int)$sales_summary['rusak'] + (int)$sales_summary['invalid']
                + (int)$pending_summary['rusak'] + (int)$pending_summary['invalid'];
            $stat_rusak_manual = (int)($audit_manual_summary['total_rusak_rp'] ?? 0);
            $stat_retur_system = (int)$sales_summary['retur'] + (int)$pending_summary['retur'];
            $expected_net = (int)($audit_manual_summary['expected_setoran'] ?? 0);
            $total_loss_real = $stat_rusak_system > 0 ? $stat_rusak_system : $stat_rusak_manual;
        ?>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title">Total Transaksi</div>
                <div class="summary-value"><?= number_format($stat_total,0,',','.') ?></div>
                <div style="font-size:10px;color:#888;">(Final + Live)</div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Pendapatan Kotor (Gross)</div>
                <div class="summary-value">Rp <?= number_format($stat_gross,0,',','.') ?></div>
            </div>
            <div class="summary-card" style="border-color:#fca5a5;">
                <div class="summary-title" style="color:#c0392b;">Total Voucher Rusak/Invalid</div>
                <div class="summary-value" style="color:#c0392b;">Rp <?= number_format($total_loss_real,0,',','.') ?></div>
                <div style="font-size:10px;color:#b91c1c;">(Mengurangi Setoran)</div>
            </div>
            <?php if ($sales_summary['pending'] > 0): ?>
                <div class="summary-card" style="border-color:#ffeeba;">
                    <div class="summary-title" style="color:#856404;">Status Data</div>
                    <div class="summary-value" style="font-size:14px;color:#856404;">
                        <?= number_format($sales_summary['pending'],0,',','.') ?> Transaksi Belum Settlement
                    </div>
                    <div style="font-size:10px;color:#856404;">(Data Live Sales)</div>
                    <?php $show_pending_date = ($req_show === 'harian' && $filter_date !== date('Y-m-d')); ?>
                    <?php if ($show_pending_date && !empty($pending_range_label)): ?>
                        <div style="font-size:10px;color:#856404;margin-top:4px;"><?= htmlspecialchars($pending_range_label) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($audit_manual_summary['total_refund'] > 0): ?>
            <div class="summary-card" style="border:1px solid #3a4046;background:#1f2327;margin-top:10px;">
                <div style="font-size:12px;color:#cbd5f5;">
                    <strong>Catatan Refund:</strong> Total refund tercatat Rp <?= number_format($audit_manual_summary['total_refund'],0,',','.') ?>.
                </div>
                <?php if (!empty($audit_refund_notes) && $selisih_adj != 0): ?>
                    <div style="margin-top:8px;">
                        <div style="font-size:12px;color:#cbd5f5;font-weight:bold;">Catatan Selisih (setelah refund/piutang):</div>
                        <ul style="margin:6px 0 0 16px; padding:0; color:#e2e8f0; font-size:12px;">
                            <?php foreach ($audit_refund_notes as $rn): ?>
                                <?php if ((int)$rn['selisih_adj'] === 0) continue; ?>
                                <li>
                                    Blok <?= htmlspecialchars((string)$rn['blok']) ?>: Refund Rp <?= number_format((int)$rn['refund_amt'],0,',','.') ?>
                                    <?php if (!empty($rn['refund_desc'])): ?>
                                        (<?= htmlspecialchars($rn['refund_desc']) ?>)
                                    <?php endif; ?>
                                    — Selisih Rp <?= number_format((int)$rn['selisih_adj'],0,',','.') ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($audit_manual_summary['total_kurang_bayar'] > 0): ?>
            <div class="summary-card" style="border:1px solid #3a4046;background:#1f2327;margin-top:10px;">
                <div style="font-size:12px;color:#cbd5f5;">
                    <strong>Catatan Piutang:</strong> Total piutang tercatat Rp <?= number_format($audit_manual_summary['total_kurang_bayar'],0,',','.') ?>.
                </div>
                <?php if (!empty($audit_kurang_bayar_notes) && $selisih_adj != 0): ?>
                    <div style="margin-top:8px;">
                        <div style="font-size:12px;color:#cbd5f5;font-weight:bold;">Catatan Selisih (setelah refund/piutang):</div>
                        <ul style="margin:6px 0 0 16px; padding:0; color:#e2e8f0; font-size:12px;">
                            <?php foreach ($audit_kurang_bayar_notes as $rn): ?>
                                <?php if ((int)$rn['selisih_adj'] === 0) continue; ?>
                                <li>
                                    Blok <?= htmlspecialchars((string)$rn['blok']) ?>: Piutang Rp <?= number_format((int)$rn['kurang_bayar_amt'],0,',','.') ?>
                                    <?php if (!empty($rn['kurang_bayar_desc'])): ?>
                                        (<?= htmlspecialchars($rn['kurang_bayar_desc']) ?>)
                                    <?php endif; ?>
                                    — Selisih Rp <?= number_format((int)$rn['selisih_adj'],0,',','.') ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sales_summary['pending'] > 0): ?>
            <div class="summary-card" style="border:1px solid #ffeeba;background:#fff3cd;margin-top:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="fa fa-info-circle" style="color:#856404;font-size:18px;"></i>
                    <div>
                        <div style="font-size:13px;font-weight:bold;color:#856404;">Info Sinkronisasi</div>
                        <div style="font-size:12px;color:#856404;">
                            Terdapat <b><?= number_format($sales_summary['pending']) ?> transaksi baru</b> (Live) yang belum masuk database final.
                            Total angka di atas sudah mencakup data ini.
                        </div>
                        <?php if ($show_pending_date && !empty($pending_range_label)): ?>
                            <div style="font-size:11px;color:#856404;margin-top:4px;"><?= htmlspecialchars($pending_range_label) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>