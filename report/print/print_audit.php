<?php
session_start();
error_reporting(0);

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}
require_once($root_dir . '/report/laporan/helpers.php');
$pricing = $env['pricing'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$profile_price_map = $pricing['profile_prices'] ?? [];
$GLOBALS['profile_price_map'] = $profile_price_map;

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
$session_id = $_GET['session'] ?? '';

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
  $filter_date = $filter_date ?: date('Y-m');
  if (strlen($filter_date) > 7) $filter_date = substr($filter_date, 0, 7);
} else {
    $req_show = 'tahunan';
  $filter_date = $filter_date ?: date('Y');
  if (strlen($filter_date) > 4) $filter_date = substr($filter_date, 0, 4);
}


function calc_audit_adjusted_totals(array $ar) {
  $expected_qty = (int)($ar['expected_qty'] ?? 0);
  $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
  $reported_qty = (int)($ar['reported_qty'] ?? 0);
  $actual_setoran = (int)($ar['actual_setoran'] ?? 0);
  $expense_amt = (int)($ar['expenses_amt'] ?? 0);
  $has_manual_evidence = false;

  $profile_qty_map = [];
  $status_count_map = [];

  $manual_setoran_override = false;
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

$sales_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
    'pending' => 0,
];
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
];
$daily_note_audit = '';

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
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending' AND $dateFilter";
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
                WHERE sync_status='pending' AND $dateFilter";
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
          $auditSql = "SELECT expected_qty, expected_setoran, reported_qty, actual_setoran, expenses_amt, expenses_desc, user_evidence
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
            $daily_note_audit = $stmtN->fetchColumn() ?: '';
          } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Audit Penjualan & Voucher</title>
<style>
    body { font-family: Arial, sans-serif; color: #111; margin: 0; padding: 12mm; }
    h1 { margin: 0 0 4mm 0; font-size: 16px; }
    .sub { font-size: 11px; color: #666; margin-bottom: 6mm; }
    .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 8mm; }
    .summary-card { border: 1px solid #ddd; border-radius: 4px; padding: 6px; }
    .summary-title { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: .4px; }
    .summary-value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .section { margin-bottom: 6mm; }
    .section-title { font-weight: 700; font-size: 12px; margin: 4mm 0 2mm; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #ddd; padding: 5px 6px; text-align: left; }
    th { background: #f0f0f0; }
    .text-right { text-align: right; }
    .text-red { color: #c0392b; }
    .muted { color: #666; }
    .toolbar { margin-bottom: 10px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
    @page { margin: 8mm; }
    @media print {
      .toolbar { display: none; }
    }
</style>
</head>
<body>
  <div class="toolbar">
      <button class="btn" onclick="window.print()">Print / Download PDF</button>
      <button class="btn" onclick="shareReport()">Share</button>
  </div>

    <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
      <h1 style="margin:0;">Laporan Audit Keuangan</h1>
      <div class="sub" style="margin-top:5px;">
        <?php
          $mode_label = strtoupper((string)$req_show);
          if ($req_show === 'tahunan') {
            $period_label = htmlspecialchars((string)$filter_date);
          } elseif ($req_show === 'bulanan') {
            $period_label = htmlspecialchars(month_label_id($filter_date) . ' ' . substr((string)$filter_date, 0, 4));
          } else {
            $period_label = htmlspecialchars(date('d-m-Y', strtotime((string)$filter_date)));
          }
        ?>
        Periode: <?= $period_label ?> | Mode: <?= $mode_label ?>
      </div>
    </div>

    <?php if (!empty($daily_note_audit)): ?>
      <div style="border: 1px solid #e0e0e0; background-color: #fff9c4; padding: 12px; margin-bottom: 20px; border-radius: 4px; font-size: 12px; color: #5d4037;">
        <strong><i class="fa fa-info-circle"></i> CATATAN / INSIDEN HARI INI:</strong><br>
        <div style="margin-top:4px; font-style:italic;">
          <?= nl2br(htmlspecialchars($daily_note_audit)) ?>
        </div>
      </div>
    <?php endif; ?>

      <?php
        $selisih = $audit_manual_summary['selisih_setoran'];
      $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
      $bg_status = $selisih < 0 ? '#fee2e2' : ($selisih > 0 ? '#dcfce7' : '#f3f4f6');
      $border_status = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#ccc');
      $text_color = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#333');
      $label_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'SETORAN SESUAI / AMAN');
  ?>

    <?php if ($audit_manual_summary['rows'] === 0): ?>
      <?php
        $use_pending_stats = ($sales_summary['total'] === 0 && $sales_summary['pending'] > 0);
        $target_est = $use_pending_stats ? $pending_summary['net'] : $sales_summary['net'];
      ?>
      <div style="border: 2px dashed #ccc; background-color: #fafafa; padding: 20px; border-radius: 4px; margin-bottom: 20px; text-align:center;">
        <h3 style="margin:0 0 10px 0; color:#555;">BELUM ADA AUDIT MANUAL</h3>
        <p style="margin:0; font-size:12px; color:#666;">Operator belum melakukan input fisik uang dan voucher.</p>
        <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
          <div style="font-size:11px; text-transform:uppercase; color:#888;">Target Setoran Sistem (Estimasi)</div>
          <div style="font-size:24px; font-weight:bold; color:#333;">Rp <?= number_format($target_est, 0, ',', '.') ?></div>
        </div>
      </div>
    <?php else: ?>
  <div style="border: 2px solid <?= $border_status ?>; background-color: <?= $bg_status ?>; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
      <table style="width:100%; border:none;">
          <tr>
              <td style="border:none; padding:0;">
                  <div style="font-size:10px; color:#555; text-transform:uppercase;">Status Audit</div>
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      <?= $label_status ?>
                  </div>
              </td>
              <td style="border:none; padding:0; text-align:right;">
                  <div style="font-size:10px; color:#555; text-transform:uppercase;">Total Selisih</div>
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      Rp <?= number_format($selisih, 0, ',', '.') ?>
                  </div>
              </td>
          </tr>
      </table>

        <?php if (!empty($ghost_hint)): ?>
          <div style="margin-top:10px; padding-top:10px; border-top:1px dashed <?= $border_status ?>; font-size:12px; color:<?= $text_color ?>;">
            <strong>Indikasi Anomali (Deteksi Otomatis):</strong> <?= htmlspecialchars($ghost_hint) ?>
          </div>
        <?php endif; ?>
  </div>

    <?php
      $system_gross = (int)($sales_summary['gross'] ?? 0) + (int)($pending_summary['gross'] ?? 0);
      $system_net = (int)($sales_summary['net'] ?? 0) + (int)($pending_summary['net'] ?? 0);
      $system_loss = (int)($sales_summary['rusak'] ?? 0) + (int)($sales_summary['invalid'] ?? 0)
        + (int)($pending_summary['rusak'] ?? 0) + (int)($pending_summary['invalid'] ?? 0);
      $system_retur = (int)($sales_summary['retur'] ?? 0) + (int)($pending_summary['retur'] ?? 0);
      $system_net_display = $system_net;
      $system_loss_display = $system_loss;
      $actual_cash = (int)($audit_manual_summary['manual_setoran'] ?? 0);
      $actual_exp = (int)($audit_manual_summary['total_expenses'] ?? 0);
    ?>

    <div class="section-title">Rekonsiliasi Pendapatan (Sistem vs Fisik)</div>
    <table>
      <thead>
        <tr>
          <th style="width:40%;">Keterangan</th>
          <th style="width:30%;">Sistem (Target)</th>
          <th style="width:30%;">Fisik (Aktual)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Omzet Penjualan (Gross)</td>
          <td class="text-right">Rp <?= number_format($system_gross,0,',','.') ?></td>
          <td class="text-center" style="background:#eee;">-</td>
        </tr>
        <tr>
          <td>(-) Potongan (Rusak/Invalid)</td>
          <td class="text-right text-red">(Rp <?= number_format($system_loss_display,0,',','.') ?>)</td>
          <td class="text-center" style="background:#eee;">-</td>
        </tr>
        <?php if ($system_retur > 0): ?>
        <tr>
          <td>(+) Pemulihan (Retur/Ganti)</td>
          <td class="text-right" style="color:#16a34a;">Rp <?= number_format($system_retur,0,',','.') ?></td>
          <td class="text-center" style="background:#eee;">-</td>
        </tr>
        <?php endif; ?>

        <tr class="bold" style="background:#f9f9f9;">
          <td>(=) Pendapatan Bersih (Net)</td>
          <td class="text-right">Rp <?= number_format($system_net_display,0,',','.') ?></td>
          <td class="text-right" style="border:2px solid #000;">Rp <?= number_format($actual_cash,0,',','.') ?></td>
        </tr>
        <tr>
          <td>(-) Pengeluaran Operasional</td>
          <td class="text-center" style="background:#eee;">-</td>
          <td class="text-right text-red">(Rp <?= number_format($actual_exp,0,',','.') ?>)</td>
        </tr>
        <tr class="bold">
          <td>(=) Total Uang Disetor</td>
          <td class="text-center" style="background:#eee;">-</td>
          <td class="text-right">Rp <?= number_format(max(0, $actual_cash - $actual_exp),0,',','.') ?></td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Rincian Perhitungan</div>
    <div class="summary-grid">
    <div class="summary-card">
      <div class="summary-title">Setoran Bersih (Cash)</div>
      <div class="summary-value">Rp <?= number_format(max(0, $audit_manual_summary['manual_setoran'] - $audit_manual_summary['total_expenses']),0,',','.') ?></div>
    </div>
    <?php if ($audit_manual_summary['total_expenses'] > 0): ?>
      <div class="summary-card">
        <div class="summary-title" style="color:#f39c12;">Pengeluaran Ops.</div>
        <div class="summary-value" style="color:#f39c12;">Rp <?= number_format($audit_manual_summary['total_expenses'],0,',','.') ?></div>
        <div style="font-size:10px;color:#d35400;">(Bon/Belanja)</div>
      </div>
    <?php endif; ?>
    <div class="summary-card">
      <div class="summary-title">Target Sistem (Net)</div>
      <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
    </div>
    <div class="summary-card">
      <div class="summary-title">Selisih Uang</div>
      <div class="summary-value" style="color:<?= $text_color ?>;">Rp <?= number_format($selisih,0,',','.') ?></div>
    </div>
    <div class="summary-card">
      <div class="summary-title">Selisih Qty</div>
      <div class="summary-value" style="color:<?= $text_color ?>;"><?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?></div>
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
    <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
      <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($stat_total,0,',','.') ?></div><div style="font-size:10px;color:#888;">(Final + Live)</div></div>
      <div class="summary-card"><div class="summary-title">Pendapatan Kotor (Gross)</div><div class="summary-value">Rp <?= number_format($stat_gross,0,',','.') ?></div></div>
      <div class="summary-card"><div class="summary-title" style="color:#c0392b;">Total Voucher Rusak/Invalid</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($total_loss_real,0,',','.') ?></div><div style="font-size:10px;color:#b91c1c;">(Mengurangi Setoran)</div></div>
    </div>
    <?php if ($sales_summary['pending'] > 0): ?>
        <?php $show_pending_date = ($req_show === 'harian' && $filter_date !== date('Y-m-d')); ?>
        <div class="summary-grid" style="grid-template-columns: repeat(1, 1fr); margin-top:6px;">
            <div class="summary-card" style="border-color:#ffeeba;">
                <div class="summary-title" style="color:#856404;">Status Data</div>
                <div class="summary-value" style="font-size:14px;color:#856404;">
                    <?= number_format($sales_summary['pending'],0,',','.') ?> Transaksi Belum Settlement
                </div>
                <div style="font-size:10px;color:#856404;">(Data Live Sales)</div>
                <?php if ($show_pending_date && !empty($pending_range_label)): ?>
                    <div style="font-size:10px;color:#856404;margin-top:4px;"><?= htmlspecialchars($pending_range_label) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($sales_summary['pending'] > 0): ?>
      <div style="margin-top:10px; padding:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:11px; color:#64748b;">
          <strong>Catatan Sistem:</strong> Angka di atas mencakup <?= number_format($sales_summary['pending']) ?> transaksi Live (Pending) yang belum dilakukan settlement.
          <?php $show_pending_date = ($req_show === 'harian' && $filter_date !== date('Y-m-d')); ?>
          <?php if ($show_pending_date && !empty($pending_range_label)): ?>
            <div style="margin-top:4px;"><?= htmlspecialchars($pending_range_label) ?></div>
          <?php endif; ?>
      </div>
    <?php endif; ?>

  <div style="margin-top:10px; padding:10px; border:1px dashed #ccc; background:#f9f9f9; font-size:11px; color:#555;">
      <strong>Cara Membaca Rekonsiliasi:</strong><br>
      1. <b>Omzet (Gross)</b>: Total nilai transaksi yang sempat terjadi (termasuk voucher Rusak/Invalid).<br>
      2. <b>(-) Potongan</b>: Nilai uang yang hilang karena voucher Rusak/Invalid.<br>
      3. <b>(+) Pemulihan</b>: Nilai uang yang kembali sah karena diganti voucher Retur.<br>
      4. <b>(=) Net</b>: Uang yang wajib ada di laci.
  </div>

    <div style="margin-top:30px; display:flex; justify-content:space-between; gap:16px;">
      <div style="width:30%; text-align:center;">
        Dibuat Oleh (Operator),
        <div style="margin-top:120px; border-bottom:1px solid #000; margin-bottom:20px;"></div>
      </div>
      <div style="width:30%; text-align:center;">
        Diperiksa Oleh (Admin),
        <div style="margin-top:120px; border-bottom:1px solid #000; margin-bottom:20px;"></div>
      </div>
      <div style="width:30%; text-align:center;">
        Mengetahui (Owner),
        <div style="margin-top:120px; border-bottom:1px solid #000; margin-bottom:20px;"></div>
      </div>
    </div>

    <div style="margin-top:20px; font-size:10px; color:#999; text-align:center;">
      Dicetak oleh Sistem Wartelpas pada <?= date('d-m-Y H:i:s') ?>
  </div>

  <script>
    function shareReport(){
        if (navigator.share) {
            navigator.share({ title: 'Laporan Audit Keuangan', url: window.location.href });
        } else {
            window.prompt('Salin link laporan:', window.location.href);
        }
    }
  </script>
</body>
</html>
