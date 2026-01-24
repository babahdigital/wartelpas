<?php
session_start();
error_reporting(0);

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
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

function table_exists($db, $name) {
    try {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :n LIMIT 1");
        $stmt->execute([':n' => $name]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
}

function format_date_dmy($date) {
    if (!$date) return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $date, $m)) {
        return $m[2] . '-' . $m[1];
    }
    return $date;
}

function format_blok_label($blok) {
    $blok = (string)$blok;
    if ($blok === '') return '';
    return preg_replace('/^BLOK-?/i', '', $blok);
}

function infer_profile_from_blok($blok) {
    $blok = strtoupper((string)$blok);
    if (preg_match('/(10|30)\b/', $blok, $m)) {
        return $m[1] . ' Menit';
    }
    return '';
}

function extract_profile_from_comment($comment) {
    $comment = (string)$comment;
    if (preg_match('/\bProfile\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    return '';
}

function build_ghost_hint($selisih_qty, $selisih_rp) {
    $ghost_qty = abs((int)$selisih_qty);
    $ghost_rp = abs((int)$selisih_rp);
    if ($ghost_qty <= 0 || $ghost_rp <= 0) return '';

    $price10 = 5000;
    $price30 = 20000;
    $divisor = $price30 - $price10;
    $ghost_10 = 0;
    $ghost_30 = 0;

    if ($ghost_rp >= ($ghost_qty * $price10) && $divisor > 0) {
        $numerator = $ghost_rp - ($ghost_qty * $price10);
        if ($numerator % $divisor === 0) {
            $ghost_30 = (int)($numerator / $divisor);
            $ghost_10 = $ghost_qty - $ghost_30;
        }
    }

    if ($ghost_10 < 0 || $ghost_30 < 0) {
        $ghost_10 = 0;
        $ghost_30 = 0;
    }

    if ($ghost_10 === 0 && $ghost_30 === 0) {
        if ($ghost_rp === ($price30 * $ghost_qty)) {
            $ghost_30 = $ghost_qty;
        } elseif ($ghost_rp === ($price10 * $ghost_qty)) {
            $ghost_10 = $ghost_qty;
        }
    }

    if ($ghost_10 <= 0 && $ghost_30 <= 0) return '';
    $parts = [];
    if ($ghost_10 > 0) $parts[] = number_format($ghost_10, 0, ',', '.') . ' unit 10 menit';
    if ($ghost_30 > 0) $parts[] = number_format($ghost_30, 0, ',', '.') . ' unit 30 menit';
    $total_ghost = $ghost_10 + $ghost_30;
    return 'Analisa dari selisih ' . number_format($total_ghost, 0, ',', '.') . ' lembar: ' . implode(' + ', $parts);
}

function calc_audit_adjusted_totals(array $ar) {
    $price10 = 5000;
    $price30 = 20000;
    $expected_qty = (int)($ar['expected_qty'] ?? 0);
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $reported_qty = (int)($ar['reported_qty'] ?? 0);
    $actual_setoran = (int)($ar['actual_setoran'] ?? 0);

    $p10_qty = 0;
    $p30_qty = 0;
    $cnt_rusak_10 = 0;
    $cnt_rusak_30 = 0;
    $cnt_retur_10 = 0;
    $cnt_retur_30 = 0;
    $cnt_invalid_10 = 0;
    $cnt_invalid_30 = 0;
    $profile10_users = 0;
    $profile30_users = 0;
    $has_manual_evidence = false;

    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence)) {
            $has_manual_evidence = true;
            if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
            }
            if (!empty($evidence['users']) && is_array($evidence['users'])) {
                foreach ($evidence['users'] as $ud) {
                    $kind = (string)($ud['profile_kind'] ?? '10');
                    $status = strtolower((string)($ud['last_status'] ?? ''));
                    if ($kind === '30') {
                        $profile30_users++;
                        if ($status === 'invalid') $cnt_invalid_30++;
                        elseif ($status === 'retur') $cnt_retur_30++;
                        elseif ($status === 'rusak') $cnt_rusak_30++;
                    } else {
                        $profile10_users++;
                        if ($status === 'invalid') $cnt_invalid_10++;
                        elseif ($status === 'retur') $cnt_retur_10++;
                        elseif ($status === 'rusak') $cnt_rusak_10++;
                    }
                }
            }
        }
    }

    if ($p10_qty <= 0) $p10_qty = $profile10_users;
    if ($p30_qty <= 0) $p30_qty = $profile30_users;

    if ($has_manual_evidence) {
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
        $manual_display_qty = $manual_net_qty_10 + $manual_net_qty_30;
        $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        $expected_adj_qty = $expected_qty;
        $expected_adj_setoran = $expected_setoran;
    } else {
        $manual_display_qty = $reported_qty;
        $manual_display_setoran = $actual_setoran;
        $expected_adj_qty = $expected_qty;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_qty, $expected_adj_qty, $manual_display_setoran, $expected_adj_setoran];
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
$audit_manual_summary = [
    'rows' => 0,
    'manual_qty' => 0,
    'expected_qty' => 0,
    'manual_setoran' => 0,
    'expected_setoran' => 0,
    'selisih_qty' => 0,
    'selisih_setoran' => 0,
    'total_rusak_rp' => 0,
];

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dateFilter = '';
        $dateParam = [];
        $auditDateFilter = '';
        $auditDateParam = [];
        if ($req_show === 'harian') {
            $dateFilter = '(sale_date = :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3 OR raw_date LIKE :raw4)';
            $dateParam[':d'] = $filter_date;
            $dateParam[':raw1'] = $filter_date . '%';
            $dateParam[':raw2'] = date('m/d/Y', strtotime($filter_date)) . '%';
            $dateParam[':raw3'] = date('d/m/Y', strtotime($filter_date)) . '%';
            $dateParam[':raw4'] = date('M/d/Y', strtotime($filter_date)) . '%';
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

        if (table_exists($db, 'sales_history')) {
            $sumSql = "SELECT
                SUM(CASE WHEN COALESCE(is_invalid,0)=1
                    OR LOWER(COALESCE(status,''))='invalid'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%invalid%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN COALESCE(is_rusak,0)=1
                    OR LOWER(COALESCE(status,''))='rusak'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%rusak%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN COALESCE(is_retur,0)=1
                    OR LOWER(COALESCE(status,''))='retur'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%retur%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
                SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1)) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM sales_history
                WHERE $dateFilter";
            $stmt = $db->prepare($sumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sumRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $sales_summary['invalid'] = (int)($sumRow['invalid_sum'] ?? 0);
            $sales_summary['rusak'] = (int)($sumRow['rusak_sum'] ?? 0);
            $sales_summary['retur'] = (int)($sumRow['retur_sum'] ?? 0);
            $sales_summary['gross'] = (int)($sumRow['gross_sum'] ?? 0);
            $sales_summary['total'] = (int)($sumRow['total_cnt'] ?? 0);
            $sales_summary['net'] = $sales_summary['gross'] - $sales_summary['rusak'] - $sales_summary['invalid'];
        }

        if (table_exists($db, 'live_sales')) {
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending' AND $dateFilter";
            $stmt = $db->prepare($pendingSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sales_summary['pending'] = (int)($stmt->fetchColumn() ?: 0);

            $pendingSumSql = "SELECT
                SUM(CASE WHEN COALESCE(is_invalid,0)=1
                    OR LOWER(COALESCE(status,''))='invalid'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%invalid%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN COALESCE(is_rusak,0)=1
                    OR LOWER(COALESCE(status,''))='rusak'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%rusak%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN COALESCE(is_retur,0)=1
                    OR LOWER(COALESCE(status,''))='retur'
                    OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%retur%')
                THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
                SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1)) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM live_sales
                WHERE sync_status='pending' AND $dateFilter";
            $stmt = $db->prepare($pendingSumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pending_summary['invalid'] = (int)($p['invalid_sum'] ?? 0);
            $pending_summary['rusak'] = (int)($p['rusak_sum'] ?? 0);
            $pending_summary['retur'] = (int)($p['retur_sum'] ?? 0);
            $pending_summary['gross'] = (int)($p['gross_sum'] ?? 0);
            $pending_summary['total'] = (int)($p['total_cnt'] ?? 0);
            $pending_summary['net'] = $pending_summary['gross'] - $pending_summary['rusak'] - $pending_summary['invalid'];
        }

        if (table_exists($db, 'audit_rekap_manual')) {
            $auditSql = "SELECT expected_qty, expected_setoran, reported_qty, actual_setoran, user_evidence
                FROM audit_rekap_manual WHERE $auditDateFilter";
            $stmt = $db->prepare($auditSql);
            foreach ($auditDateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $audit_manual_summary['rows'] = count($audit_rows);
            $audit_manual_summary['total_rusak_rp'] = 0;
            foreach ($audit_rows as $ar) {
                [$manual_qty, $expected_qty, $manual_setoran, $expected_setoran] = calc_audit_adjusted_totals($ar);
                $audit_manual_summary['manual_qty'] += (int)$manual_qty;
                $audit_manual_summary['expected_qty'] += (int)$expected_qty;
                $audit_manual_summary['manual_setoran'] += (int)$manual_setoran;
                $audit_manual_summary['expected_setoran'] += (int)$expected_setoran;

                $curr_rusak_rp = 0;
                if (!empty($ar['user_evidence'])) {
                    $ev = json_decode((string)$ar['user_evidence'], true);
                    if (is_array($ev) && !empty($ev['users'])) {
                        foreach ($ev['users'] as $u) {
                            $st = strtolower((string)($u['last_status'] ?? ''));
                            $k = (string)($u['profile_kind'] ?? '10');
                            if ($st === 'rusak' || $st === 'invalid') {
                                $price = ($k === '30') ? 20000 : 5000;
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
    .section-title { font-weight:700; margin:14px 0 8px; font-size:14px; }
    .toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
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
        <a class="btn-solid" style="text-decoration:none;" target="_blank" href="report/print_audit.php?session=<?= urlencode($session_id) ?>&show=<?= urlencode($req_show) ?>&date=<?= urlencode($filter_date) ?>"><i class="fa fa-print"></i> Print Laporan Keuangan</a>
    </div>
    <div class="card-body">
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
                $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
                $color_status = $selisih < 0 ? '#c0392b' : ($selisih > 0 ? '#2ecc71' : '#3498db');
                $text_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'AMAN / SESUAI');
                $bg_status = $selisih < 0 ? '#381818' : ($selisih > 0 ? '#1b3a24' : '#1e2a36');
            ?>
            <div style="background:<?= $bg_status ?>;border:1px solid <?= $color_status ?>;padding:15px;border-radius:6px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <div style="font-size:12px;color:#aaa;text-transform:uppercase;">Status Keuangan</div>
                    <div style="font-size:22px;font-weight:bold;color:<?= $color_status ?>;"><?= $text_status ?></div>
                    <?php if ($selisih != 0): ?>
                        <div style="font-size:16px;color:#fff;">Selisih: Rp <?= number_format($selisih,0,',','.') ?></div>
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
                    <div class="summary-title">Uang Fisik (Manual)</div>
                    <div class="summary-value">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Target Sistem (Net)</div>
                    <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Selisih Qty</div>
                    <div class="summary-value" style="color:<?= $audit_manual_summary['selisih_qty'] != 0 ? '#f39c12' : '#2ecc71' ?>;">
                        <?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?> Lembar
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-title">Statistik Keuangan & Insiden</div>
        <?php
            $use_pending_stats = ($sales_summary['total'] === 0 && $sales_summary['pending'] > 0);
            $stat_total = $use_pending_stats ? $pending_summary['total'] : $sales_summary['total'];
            $stat_rusak = $use_pending_stats ? $pending_summary['rusak'] : $sales_summary['rusak'];
            $total_loss_real = (int)$stat_rusak + (int)($audit_manual_summary['total_rusak_rp'] ?? 0);
        ?>
        <div class="summary-grid">
            <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($stat_total,0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Pendapatan Kotor (Gross)</div><div class="summary-value">Rp <?= number_format(($use_pending_stats ? $pending_summary['gross'] : $sales_summary['gross']),0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title" style="color:#c0392b;">Total Voucher Rusak</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($total_loss_real,0,',','.') ?></div><div style="font-size:10px;color:#b91c1c;">(Mengurangi Setoran)</div></div>
            <?php if ($sales_summary['pending'] > 0): ?>
                <div class="summary-card"><div class="summary-title">Pending (Live)</div><div class="summary-value"><?= number_format($sales_summary['pending'],0,',','.') ?></div></div>
            <?php endif; ?>
        </div>

        <?php if ($sales_summary['pending'] > 0 && $sales_summary['total'] === 0): ?>
            <div class="summary-card" style="border:1px solid #3a4046;background:#1f2327;">
                <div class="summary-title">Catatan</div>
                <div class="summary-value" style="font-size:13px;">Transaksi final kosong. Data sementara ada di live_sales (pending).</div>
            </div>
        <?php endif; ?>

    </div>
</div>