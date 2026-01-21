<?php
/*
 * LAPORAN PENJUALAN (WARTELPAS)
 * Aturan: RUSAK = pendapatan berkurang, RETUR = pendapatan tetap.
 */
session_start();
error_reporting(0);

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$rows = [];
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';
$hp_redirect = '';

// Ringkasan HP (harian)
$hp_total_units = 0;
$hp_active_units = 0;
$hp_rusak_units = 0;
$hp_spam_units = 0;
$hp_wartel_units = 0;
$hp_kamtib_units = 0;

// Filter periode
$req_show = $_GET['show'] ?? 'harian';
$mode = 'final';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
        $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
        $filter_date = $filter_date ?: date('Y-m');
} else {
        $req_show = 'tahunan';
        $filter_date = $filter_date ?: date('Y');
}

function norm_date_from_raw_report($raw_date) {
        $raw = trim((string)$raw_date);
        if ($raw === '') return '';
        if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
                $mon = strtolower(substr($raw, 0, 3));
                $map = [
                        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
                        'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
                ];
                $mm = $map[$mon] ?? '';
                if ($mm !== '') {
                        $parts = explode('/', $raw);
                        return $parts[2] . '-' . $mm . '-' . $parts[1];
                }
        }
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
                $parts = explode('/', $raw);
                return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
        }
        return '';
}

function sanitize_comment_short($comment) {
    $comment = (string)$comment;
    $comment = preg_replace('/\s*\|\s*IP\s*:[^|]+/i', '', $comment);
    $comment = preg_replace('/\s*\|\s*MAC\s*:[^|]+/i', '', $comment);
    $comment = preg_replace('/\s+\|\s+/', ' | ', $comment);
    return trim($comment);
}

function format_first_login($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('Y-m-d H:i:s', $ts);
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

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS phone_block_daily (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_date TEXT,
            blok_name TEXT,
            unit_type TEXT,
            total_units INTEGER,
            active_units INTEGER,
            rusak_units INTEGER,
            spam_units INTEGER,
            notes TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(report_date, blok_name, unit_type)
        )");
        try { $db->exec("ALTER TABLE phone_block_daily ADD COLUMN unit_type TEXT"); } catch (Exception $e) {}
        $res = $db->query("SELECT 
                sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                sh.username, sh.profile, sh.profile_snapshot,
                sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
            sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
            sh.full_raw_data, lh.last_status, lh.last_bytes, lh.first_login_real
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
            ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
            ls.full_raw_data, lh2.last_status, lh2.last_bytes, lh2.first_login_real
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);

        if ($req_show === 'harian') {
            try {
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
            } catch (Exception $e) {
                $hp_total_units = 0;
                $hp_active_units = 0;
                $hp_rusak_units = 0;
                $hp_spam_units = 0;
                $hp_wartel_units = 0;
                $hp_kamtib_units = 0;
            }
        }
    } catch (Exception $e) {
        $rows = [];
    }
}

// Simpan input handphone per blok (harian)
if (isset($db) && $db instanceof PDO && isset($_POST['hp_submit'])) {
    $hp_is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    $blok_name = trim($_POST['blok_name'] ?? '');
    $report_date = trim($_POST['report_date'] ?? '');
    $total_units = (int)($_POST['total_units'] ?? 0);
    $rusak_units = (int)($_POST['rusak_units'] ?? 0);
    $spam_units = (int)($_POST['spam_units'] ?? 0);
    $active_units = max(0, $total_units - $rusak_units - $spam_units);
    $notes = trim($_POST['notes'] ?? '');

    $use_wartel = isset($_POST['unit_wartel']) ? 1 : 0;
    $use_kamtib = isset($_POST['unit_kamtib']) ? 1 : 0;
    $wartel_units = (int)($_POST['wartel_units'] ?? 0);
    $kamtib_units = (int)($_POST['kamtib_units'] ?? 0);
    $sum_units = ($use_wartel ? $wartel_units : 0) + ($use_kamtib ? $kamtib_units : 0);

    $hp_saved = false;
    if ($blok_name !== '' && $report_date !== '') {
        try {
            if (!$use_wartel && !$use_kamtib) {
                $hp_error = 'Pilih minimal salah satu unit (WARTEL/KAMTIB).';
            } elseif ($use_wartel && !$use_kamtib && $total_units !== $wartel_units) {
                $hp_error = 'Jika hanya WARTEL dipilih, jumlahnya harus sama dengan total.';
            } elseif (!$use_wartel && $use_kamtib && $total_units !== $kamtib_units) {
                $hp_error = 'Jika hanya KAMTIB dipilih, jumlahnya harus sama dengan total.';
            } elseif ($use_wartel && $use_kamtib && $total_units !== $sum_units) {
                $hp_error = 'Total unit harus sama dengan jumlah WARTEL + KAMTIB.';
            } elseif ($total_units < ($rusak_units + $spam_units)) {
                $hp_error = 'Total unit tidak boleh kurang dari Rusak + Spam.';
            } else {
                $stmt = $db->prepare("INSERT INTO phone_block_daily
                    (report_date, blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes, updated_at)
                    VALUES (:d, :b, :ut, :t, :a, :r, :s, :n, CURRENT_TIMESTAMP)
                    ON CONFLICT(report_date, blok_name, unit_type) DO UPDATE SET
                      unit_type=excluded.unit_type,
                      total_units=excluded.total_units,
                      active_units=excluded.active_units,
                      rusak_units=excluded.rusak_units,
                      spam_units=excluded.spam_units,
                      notes=excluded.notes,
                      updated_at=CURRENT_TIMESTAMP
                ");

                // Simpan TOTAL
                $stmt->execute([
                    ':d' => $report_date,
                    ':b' => $blok_name,
                    ':ut' => 'TOTAL',
                    ':t' => $total_units,
                    ':a' => $active_units,
                    ':r' => $rusak_units,
                    ':s' => $spam_units,
                    ':n' => $notes
                ]);

                // Simpan breakdown WARTEL/KAMTIB (hanya total)
                if ($use_wartel) {
                    $stmt->execute([
                        ':d' => $report_date,
                        ':b' => $blok_name,
                        ':ut' => 'WARTEL',
                        ':t' => $wartel_units,
                        ':a' => 0,
                        ':r' => 0,
                        ':s' => 0,
                        ':n' => ''
                    ]);
                }
                if ($use_kamtib) {
                    $stmt->execute([
                        ':d' => $report_date,
                        ':b' => $blok_name,
                        ':ut' => 'KAMTIB',
                        ':t' => $kamtib_units,
                        ':a' => 0,
                        ':r' => 0,
                        ':s' => 0,
                        ':n' => ''
                    ]);
                }
                $hp_saved = true;
            }
        } catch (Exception $e) {}
    }
    if ($hp_saved && empty($hp_error)) {
        $hp_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
        if (!headers_sent()) {
            header('Location: ' . $hp_redirect);
            exit;
        }
    }
    if ($hp_is_ajax) {
        header('Content-Type: application/json');
        if (!empty($hp_error)) {
            echo json_encode(['ok' => false, 'message' => $hp_error]);
        } elseif ($hp_saved) {
            $redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
            echo json_encode(['ok' => true, 'redirect' => $redirect]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan data.']);
        }
        exit;
    }
}

// Hapus data handphone per blok (harian)
if (isset($db) && $db instanceof PDO && isset($_GET['hp_delete'])) {
    $del_date = trim($_GET['hp_date'] ?? '');
    $del_blok = strtoupper(trim($_GET['blok'] ?? ''));
    if ($del_date !== '' && $del_blok !== '') {
        try {
            $stmt = $db->prepare("DELETE FROM phone_block_daily WHERE report_date = :d AND UPPER(blok_name) = :b");
            $stmt->execute([':d' => $del_date, ':b' => $del_blok]);
        } catch (Exception $e) {}
        $hp_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
        if (!headers_sent()) {
            header('Location: ' . $hp_redirect);
            exit;
        }
    }
}

if (!empty($hp_redirect) && headers_sent()) {
    echo '<script>window.location.replace(' . json_encode($hp_redirect) . ');</script>';
    exit;
}

function table_exists(PDO $db, $table) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

// Olah data
$list = [];
$total_gross = 0;
$total_rusak = 0;
$total_invalid = 0;
$total_net = 0;
$total_qty = 0;
$total_qty_retur = 0;
$total_qty_rusak = 0;
$total_qty_invalid = 0;
$rusak_10m = 0;
$total_bandwidth = 0;
$rusak_30m = 0;

$by_block = [];
$by_profile = [];

$use_summary = false;
// Summary dimatikan agar data live + final tetap akurat
$period_type = $req_show === 'harian' ? 'day' : ($req_show === 'bulanan' ? 'month' : 'year');
$period_key = $filter_date;

if (false && isset($db) && $db instanceof PDO && table_exists($db, 'sales_summary_period')) {
    $use_summary = true;
    try {
        $stmt = $db->prepare("SELECT * FROM sales_summary_period WHERE period_type = :pt AND period_key = :pk LIMIT 1");
        $stmt->execute([':pt' => $period_type, ':pk' => $period_key]);
        $sum = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sum) {
            $total_qty = (int)($sum['qty'] ?? 0);
            $total_qty_retur = (int)($sum['qty_retur'] ?? 0);
            $total_qty_rusak = (int)($sum['qty_rusak'] ?? 0);
            $total_qty_invalid = (int)($sum['qty_invalid'] ?? 0);
            $total_gross = (int)($sum['gross'] ?? 0);
            $total_rusak = (int)($sum['rusak'] ?? 0);
            $total_invalid = (int)($sum['invalid'] ?? 0);
            $total_net = (int)($sum['net'] ?? 0);
        } else {
            $use_summary = false;
        }

        if ($use_summary) {
            $stmt = $db->prepare("SELECT * FROM sales_summary_block WHERE period_type = :pt AND period_key = :pk ORDER BY blok_name");
            $stmt->execute([':pt' => $period_type, ':pk' => $period_key]);
            $blockRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($blockRows as $b) {
                $by_block[$b['blok_name']] = [
                    'qty' => (int)($b['qty'] ?? 0),
                    'retur' => (int)($b['qty_retur'] ?? 0),
                    'rusak' => (int)($b['qty_rusak'] ?? 0),
                    'invalid' => (int)($b['qty_invalid'] ?? 0),
                    'gross' => (int)($b['gross'] ?? 0),
                    'net' => (int)($b['net'] ?? 0)
                ];
            }

            $stmt = $db->prepare("SELECT * FROM sales_summary_profile WHERE period_type = :pt AND period_key = :pk ORDER BY profile_name");
            $stmt->execute([':pt' => $period_type, ':pk' => $period_key]);
            $profileRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($profileRows as $p) {
                $by_profile[$p['profile_name']] = [
                    'qty' => (int)($p['qty'] ?? 0),
                    'retur' => (int)($p['qty_retur'] ?? 0),
                    'rusak' => (int)($p['qty_rusak'] ?? 0),
                    'invalid' => (int)($p['qty_invalid'] ?? 0),
                    'gross' => (int)($p['gross'] ?? 0),
                    'net' => (int)($p['net'] ?? 0)
                ];
            }
        }
    } catch (Exception $e) {
        $use_summary = false;
    }
}

foreach ($rows as $r) {
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        $sale_time = $r['sale_time'] ?? ($r['raw_time'] ?? '');
        $sale_dt = $sale_date && $sale_time ? ($sale_date . ' ' . $sale_time) : ($sale_date ?: ($r['raw_date'] ?? '-'));

        $match = false;
        if ($req_show === 'harian') $match = ($sale_date === $filter_date);
        elseif ($req_show === 'bulanan') $match = (strpos((string)$sale_date, $filter_date) === 0);
        else $match = (strpos((string)$sale_date, $filter_date) === 0);
        if (!$match) continue;

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        $comment = format_first_login($r['first_login_real'] ?? '');
        $status = strtolower((string)($r['status'] ?? ''));
        $lh_status = strtolower((string)($r['last_status'] ?? ''));
        $cmt_low = strtolower($comment);

        if ($status === '' || $status === 'normal') {
                if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
                elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
                elseif (strpos($cmt_low, 'retur') !== false || $lh_status === 'retur') $status = 'retur';
                else $status = 'normal';
        }

        $gross_add = ($status === 'retur' || $status === 'invalid') ? 0 : $price;
        $loss_rusak = ($status === 'rusak') ? $price : 0;
        $loss_invalid = ($status === 'invalid') ? $price : 0;
        $net_add = $gross_add - $loss_rusak - $loss_invalid;

        if (!$use_summary) {
            $total_qty++;
            if ($status === 'retur') $total_qty_retur++;
            if ($status === 'rusak') {
                $total_qty_rusak++;
                $p = strtolower((string)$profile);
                if (preg_match('/\b10\s*(menit|m)\b/i', $p)) {
                    $rusak_10m++;
                } elseif (preg_match('/\b30\s*(menit|m)\b/i', $p)) {
                    $rusak_30m++;
                }
            }
            if ($status === 'invalid') $total_qty_invalid++;

            $total_gross += $gross_add;
            $total_rusak += $loss_rusak;
            $total_invalid += $loss_invalid;
            $total_net += $net_add;

            $blok = $r['blok_name'] ?? '-';
            $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');

            if (!isset($by_block[$blok])) {
                $by_block[$blok] = ['qty'=>0,'gross'=>0,'rusak'=>0,'invalid'=>0,'net'=>0,'retur'=>0];
            }
            if (!isset($by_profile[$profile])) {
                $by_profile[$profile] = ['qty'=>0,'gross'=>0,'rusak'=>0,'invalid'=>0,'net'=>0,'retur'=>0];
            }

            $by_block[$blok]['qty'] += 1;
            $by_block[$blok]['gross'] += $gross_add;
            $by_block[$blok]['rusak'] += $loss_rusak;
            $by_block[$blok]['invalid'] += $loss_invalid;
            $by_block[$blok]['net'] += $net_add;
            if ($status === 'retur') $by_block[$blok]['retur'] += 1;

            $by_profile[$profile]['qty'] += 1;
            $by_profile[$profile]['gross'] += $gross_add;
            $by_profile[$profile]['rusak'] += $loss_rusak;
            $by_profile[$profile]['invalid'] += $loss_invalid;
            $by_profile[$profile]['net'] += $net_add;
            if ($status === 'retur') $by_profile[$profile]['retur'] += 1;
        }

        $total_bandwidth += (int)($r['last_bytes'] ?? 0);
        $list[] = [
                'dt' => $sale_dt,
                'user' => $r['username'] ?? '-',
                'profile' => $profile,
                'blok' => $blok,
                'status' => strtoupper($status),
                'price' => $price,
                'net' => $net_add,
                'bytes' => (int)($r['last_bytes'] ?? 0),
            'comment' => $comment
        ];
}

ksort($by_block, SORT_NATURAL | SORT_FLAG_CASE);
ksort($by_profile, SORT_NATURAL | SORT_FLAG_CASE);
$total_qty_laku = max(0, $total_qty - $total_qty_retur - $total_qty_rusak - $total_qty_invalid);

$tx_page_size = 50;
$tx_page = isset($_GET['tx_page']) ? (int)$_GET['tx_page'] : 1;
if ($tx_page < 1) $tx_page = 1;
$tx_total = count($list);
$tx_pages = $tx_page_size > 0 ? (int)ceil($tx_total / $tx_page_size) : 1;
if ($tx_pages < 1) $tx_pages = 1;
if ($tx_page > $tx_pages) $tx_page = $tx_pages;
$tx_offset = ($tx_page - 1) * $tx_page_size;
$list_page = array_slice($list, $tx_offset, $tx_page_size);
?>

<?php if (!empty($hp_redirect) && headers_sent()): ?>
    <script>window.location.replace('<?= htmlspecialchars($hp_redirect, ENT_QUOTES); ?>');</script>
<?php endif; ?>

<style>
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; }
    html, body { height: 100%; min-height: 100%; }
    body { overflow-x: hidden; }
    .wrapper { min-height: 100vh; height: auto; }
    #main { min-height: 100vh; height: auto; overflow: visible; }
    .main-container { min-height: calc(100vh - 55px); height: auto; overflow: visible; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .card-footer-solid { background: #23272b; padding: 10px 16px; border-top: 1px solid var(--border-col); border-radius: 0 0 8px 8px; width: 100%; box-sizing: border-box; }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .table-dark-solid tr:hover td { background: #32383e; }
    .unit-stack { font-family: monospace; font-size: 12px; line-height: 1.2; }
    .unit-wartel { color: #52c41a; }
    .unit-kamtib { color: #4ea8ff; }
    .hp-notes { max-width: 150px; white-space: normal !important; word-break: break-word; line-height: 1.3; text-align: right; }
    .summary-badge { display:inline-block; font-size:11px; padding:2px 8px; border:1px solid #495057; margin-right:6px; background:#2b3137; color:#e9ecef; }
    .badge-wartel { background:#1f3b2b; border-color:#2f6b4a; color:#7ee2a8; }
    .badge-kamtib { background:#223049; border-color:#355a8f; color:#9cc7ff; }
    .text-green { color:#2ecc71; }
    .summary-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .tx-pager { display:flex; gap:6px; justify-content:center; align-items:center; padding:10px; }
        .tx-pager a, .tx-pager span { background:#343a40; border:1px solid var(--border-col); color:#fff; padding:4px 10px; border-radius:6px; font-size:12px; text-decoration:none; }
        .tx-pager .active { background:#4ea8ff; border-color:#4ea8ff; color:#0b1220; font-weight:700; }
        .hp-total-bar { display:flex; gap:12px; flex-wrap:wrap; padding:10px 14px; border-top:1px solid var(--border-col); background:#262b31; color:var(--txt-muted); font-size:12px; }
        .hp-total-bar b { color:#fff; }
    .summary-card { background: #23272b; border: 1px solid var(--border-col); border-radius: 8px; padding: 14px; }
    .summary-title { font-size: 0.8rem; color: var(--txt-muted); text-transform: uppercase; letter-spacing: 1px; }
    .summary-value { font-size: 1.4rem; font-weight: 700; margin-top: 6px; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .st-normal { background: #4b545c; color: #ccc; border: 1px solid #6c757d; }
    .st-retur { background: #8e44ad; color: #fff; }
    .st-rusak { background: var(--c-orange); color: #fff; }
    .st-invalid { background: var(--c-red); color: #fff; }
    .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .filter-bar select, .filter-bar input { background: #343a40; border: 1px solid var(--border-col); color: #fff; padding: 6px 10px; border-radius: 6px; }
    .btn-print { background: var(--c-blue); color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 10000; }
    .modal-card { background: #1f2937; color: #e5e7eb; border-radius: 10px; width: 480px; max-width: 90vw; padding: 16px; box-shadow: 0 8px 20px rgba(0,0,0,0.4); }
    .modal-title { font-weight: 700; margin-bottom: 12px; }
    .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    .form-input { width: 100%; background: #343a40; border: 1px solid var(--border-col); color: #fff; padding: 8px 10px; border-radius: 6px; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 4px; border: none; color: #fff; background: #3a4046; cursor: pointer; }
    .btn-act-danger { background: #e74c3c; }
</style>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-line-chart mr-2"></i> Laporan Penjualan</h3>
        <div class="filter-bar">
            <form method="get" action="" class="filter-bar">
                <input type="hidden" name="report" value="selling">
                <?php if ($session_id !== ''): ?>
                    <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
                <?php endif; ?>
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
                    <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="width:100px;">
                <?php endif; ?>
            </form>
            <?php
                $print_rekap_url = 'report/print_rekap.php?show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
                if ($session_id !== '') $print_rekap_url .= '&session=' . urlencode($session_id);
                $print_rincian_url = 'report/print_rincian.php?date=' . urlencode($filter_date);
                if ($session_id !== '') $print_rincian_url .= '&session=' . urlencode($session_id);
            ?>
            <button class="btn-print" onclick="window.open('<?= $print_rekap_url ?>','_blank')">Print Rekap</button>
            <?php if ($req_show === 'harian'): ?>
                <button class="btn-print" onclick="window.open('<?= $print_rincian_url ?>','_blank')">Print Rincian</button>
            <?php else: ?>
                <button class="btn-print" style="opacity:.6;cursor:not-allowed;" disabled>Print Rincian</button>
            <?php endif; ?>
            <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i></button>
            <button class="btn-print" onclick="document.getElementById('hpModal').style.display='flex'">Input HP Blok</button>
        </div>
    </div>
    <div class="card-body" style="padding:16px;">
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title">Pendapatan Kotor</div>
                <div class="summary-value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Voucher Rusak</div>
                <div class="summary-value" ><span style="color: crimson;"><?= number_format($total_qty_rusak,0,',','.') ?></span></div>
                <div style="font-size:12px;color:var(--txt-muted)">10 Menit: <?= number_format($rusak_10m,0,',','.') ?> | 30 Menit: <?= number_format($rusak_30m,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Handphone</div>
                <div style="margin-top:7px; margin-bottom:10px;">
                    <span class="summary-badge badge-wartel">WARTEL: <?= number_format($hp_wartel_units,0,',','.') ?></span>
                    <span class="summary-badge badge-kamtib">KAMTIB: <?= number_format($hp_kamtib_units,0,',','.') ?></span>
                </div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top:6px;">
                    Total: <span style="color:#f39c12;"><?= number_format($hp_total_units,0,',','.') ?></span> | Aktif: <span class="text-green"><?= number_format($hp_active_units,0,',','.') ?></span> | Rusak: <?= number_format($hp_rusak_units,0,',','.') ?> | Spam: <?= number_format($hp_spam_units,0,',','.') ?>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Voucher Laku</div>
                <div class="summary-value"><?= number_format($total_qty_laku,0,',','.') ?></div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top: 1px;">Rusak: <?= number_format($total_qty_rusak,0,',','.') ?> | Retur: <?= number_format($total_qty_retur,0,',','.') ?> | Bandwidth: <?= htmlspecialchars(format_bytes_short($total_bandwidth)) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Pendapatan Bersih</div>
                <div class="summary-value" style="color:#2ecc71;"><?= $cur ?> <?= number_format($total_net,0,',','.') ?></div>
            </div>
        </div>
    </div>
</div>

<div id="hpModal" class="modal-backdrop" onclick="if(event.target===this){this.style.display='none';}">
    <div class="modal-card">
        <div class="modal-title">Input Handphone per Blok (Harian)</div>
        <form id="hpForm" method="post" action="report/hp_save.php">
            <input type="hidden" name="report" value="selling">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <div class="form-grid-2">
                <div>
                    <label>Blok</label>
                    <select class="form-input" name="blok_name" required>
                        <option value="" disabled selected>Pilih Blok</option>
                        <?php foreach (range('A','F') as $b): ?>
                            <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tanggal</label>
                    <input class="form-input" type="date" name="report_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                </div>
            </div>
            <div class="form-grid-2" style="margin-top:10px;">
                <div>
                    <label>Total Unit</label>
                    <input class="form-input" type="number" name="total_units" min="0" value="0" required>
                </div>
                <div>
                    <label>Rusak</label>
                    <input class="form-input" type="number" name="rusak_units" min="0" value="0">
                </div>
                <div>
                    <label>Spam</label>
                    <input class="form-input" type="number" name="spam_units" min="0" value="0">
                </div>
            </div>
            <input type="hidden" name="active_units" value="0">
            <div style="margin-top:10px;">
                <label>Distribusi Unit (wajib pilih salah satu)</label>
                <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:6px;">
                    <label style="display:flex; gap:6px; align-items:center;">
                        <input type="checkbox" id="chk_wartel" name="unit_wartel" value="1">
                        <span>WARTEL</span>
                    </label>
                    <label style="display:flex; gap:6px; align-items:center;">
                        <input type="checkbox" id="chk_kamtib" name="unit_kamtib" value="1">
                        <span>KAMTIB</span>
                    </label>
                </div>
                <div class="form-grid-2" style="margin-top:8px;">
                    <div id="wartel_wrap" style="display:none;">
                        <label>Jumlah WARTEL</label>
                        <input class="form-input" type="number" name="wartel_units" min="0" value="0">
                    </div>
                    <div id="kamtib_wrap" style="display:none;">
                        <label>Jumlah KAMTIB</label>
                        <input class="form-input" type="number" name="kamtib_units" min="0" value="0">
                    </div>
                </div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top:6px;">
                    Jika memilih satu unit saja, jumlahnya harus sama dengan Total Unit.
                </div>
                <div id="hpClientError" style="display:none;margin-top:8px;color:#fca5a5;font-size:12px;"></div>
            </div>
            <div style="margin-top:10px;">
                <label>Catatan</label>
                <input class="form-input" name="notes" placeholder="opsional">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-print" onclick="document.getElementById('hpModal').style.display='none'">Batal</button>
                <button type="submit" id="hpSubmitBtn" name="hp_submit" class="btn-print" disabled>Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function(){
        var w = document.getElementById('chk_wartel');
        var k = document.getElementById('chk_kamtib');
        var ww = document.getElementById('wartel_wrap');
        var kw = document.getElementById('kamtib_wrap');
        var wu = ww ? ww.querySelector('input') : null;
        var ku = kw ? kw.querySelector('input') : null;
        var form = document.getElementById('hpForm');
        var btn = document.getElementById('hpSubmitBtn');
        var err = document.getElementById('hpClientError');
        var totalEl = form ? form.querySelector('input[name="total_units"]') : null;
        var activeEl = form ? form.querySelector('input[name="active_units"]') : null;
        var wartelEl = form ? form.querySelector('input[name="wartel_units"]') : null;
        var kamtibEl = form ? form.querySelector('input[name="kamtib_units"]') : null;
        var rusakEl = form ? form.querySelector('input[name="rusak_units"]') : null;
        var spamEl = form ? form.querySelector('input[name="spam_units"]') : null;
        function toggle(){
            if (ww) ww.style.display = w && w.checked ? 'block' : 'none';
            if (kw) kw.style.display = k && k.checked ? 'block' : 'none';
            if (wu) wu.required = !!(w && w.checked);
            if (ku) ku.required = !!(k && k.checked);
            validate();
        }
        function validate(){
            if (!form || !btn || !err) return;
            var total = totalEl ? parseInt(totalEl.value || '0', 10) : 0;
            var wartel = wartelEl ? parseInt(wartelEl.value || '0', 10) : 0;
            var kamtib = kamtibEl ? parseInt(kamtibEl.value || '0', 10) : 0;
            var rusak = rusakEl ? parseInt(rusakEl.value || '0', 10) : 0;
            var spam = spamEl ? parseInt(spamEl.value || '0', 10) : 0;
            if (activeEl) {
                var calcActive = total - rusak - spam;
                activeEl.value = calcActive >= 0 ? calcActive : 0;
            }
            var useW = !!(w && w.checked);
            var useK = !!(k && k.checked);
            var msg = '';
            if (!useW && !useK) {
                msg = 'Pilih minimal salah satu unit (WARTEL/KAMTIB).';
            } else if (useW && !useK && total !== wartel) {
                msg = 'Jika hanya WARTEL dipilih, jumlahnya harus sama dengan total.';
            } else if (!useW && useK && total !== kamtib) {
                msg = 'Jika hanya KAMTIB dipilih, jumlahnya harus sama dengan total.';
            } else if (useW && useK && total !== (wartel + kamtib)) {
                msg = 'Total unit harus sama dengan jumlah WARTEL + KAMTIB.';
            } else if (total < (rusak + spam)) {
                msg = 'Total unit tidak boleh kurang dari Rusak + Spam.';
            }
            if (msg) {
                err.textContent = msg;
                err.style.display = 'block';
                btn.disabled = true;
            } else {
                err.textContent = '';
                err.style.display = 'none';
                btn.disabled = false;
            }
        }
        if (w) w.addEventListener('change', toggle);
        if (k) k.addEventListener('change', toggle);
        if (totalEl) totalEl.addEventListener('input', validate);
        if (wartelEl) wartelEl.addEventListener('input', validate);
        if (kamtibEl) kamtibEl.addEventListener('input', validate);
        if (rusakEl) rusakEl.addEventListener('input', validate);
        if (spamEl) spamEl.addEventListener('input', validate);
        if (form) {
            form.addEventListener('submit', function(e){
                e.preventDefault();
                if (btn && btn.disabled) return;
                var fd = new FormData(form);
                fetch(form.action, { method: 'POST', body: fd })
                    .then(function(r){ return r.text(); })
                    .then(function(text){
                        var data = null;
                        try { data = JSON.parse(text); } catch (e) {}
                        if (data && data.ok && data.redirect) {
                            window.location.replace(data.redirect);
                            return;
                        }
                        var msg = (data && data.message) ? data.message : 'Respon tidak valid dari server.';
                        err.textContent = msg;
                        err.style.display = 'block';
                        if (btn) btn.disabled = true;
                    })
                    .catch(function(){
                        err.textContent = 'Gagal mengirim data. Coba lagi.';
                        err.style.display = 'block';
                        if (btn) btn.disabled = true;
                    });
            });
        }
        toggle();
    })();

    window.openHpEdit = function(btn){
        var form = document.getElementById('hpForm');
        if (!form || !btn) return;
        form.querySelector('select[name="blok_name"]').value = btn.getAttribute('data-blok') || '';
        form.querySelector('input[name="report_date"]').value = btn.getAttribute('data-date') || '';
        form.querySelector('input[name="total_units"]').value = btn.getAttribute('data-total') || '0';
        form.querySelector('input[name="rusak_units"]').value = btn.getAttribute('data-rusak') || '0';
        form.querySelector('input[name="spam_units"]').value = btn.getAttribute('data-spam') || '0';
        form.querySelector('input[name="notes"]').value = btn.getAttribute('data-notes') || '';

        var wartel = parseInt(btn.getAttribute('data-wartel') || '0', 10);
        var kamtib = parseInt(btn.getAttribute('data-kamtib') || '0', 10);

        var w = document.getElementById('chk_wartel');
        var k = document.getElementById('chk_kamtib');
        var wartelEl = form.querySelector('input[name="wartel_units"]');
        var kamtibEl = form.querySelector('input[name="kamtib_units"]');

        if (w) w.checked = wartel > 0;
        if (k) k.checked = kamtib > 0;
        if (wartelEl) wartelEl.value = wartel;
        if (kamtibEl) kamtibEl.value = kamtib;

        if (typeof window.dispatchEvent === 'function') {
            var evt = new Event('change');
            if (w) w.dispatchEvent(evt);
            if (k) k.dispatchEvent(evt);
        }

        document.getElementById('hpModal').style.display = 'flex';
    };
</script>

<?php
$hp_rows = [];
$hp_rows_total = [];
$hp_summary = [];
if (isset($db) && $db instanceof PDO && $req_show === 'harian') {
        try {
                $stmt = $db->prepare("SELECT * FROM phone_block_daily WHERE report_date = :d ORDER BY blok_name,
                    CASE unit_type WHEN 'TOTAL' THEN 0 WHEN 'WARTEL' THEN 1 WHEN 'KAMTIB' THEN 2 ELSE 3 END");
                $stmt->execute([':d' => $filter_date]);
                $hp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $hp_breakdown = [];
                foreach ($hp_rows as $row) {
                    $bname = $row['blok_name'] ?? '';
                    $ut = $row['unit_type'] ?? '';
                    if ($bname === '' || ($ut !== 'WARTEL' && $ut !== 'KAMTIB')) continue;
                    if (!isset($hp_breakdown[$bname])) $hp_breakdown[$bname] = ['WARTEL' => 0, 'KAMTIB' => 0];
                    $hp_breakdown[$bname][$ut] = (int)($row['total_units'] ?? 0);
                }

                $hp_rows_total = array_values(array_filter($hp_rows, function($row){
                    return strtoupper((string)($row['unit_type'] ?? '')) === 'TOTAL';
                }));

                $stmt2 = $db->prepare("SELECT unit_type,
            SUM(total_units) AS total_units,
            SUM(active_units) AS active_units,
            SUM(rusak_units) AS rusak_units,
            SUM(spam_units) AS spam_units
          FROM phone_block_daily
                    WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
          GROUP BY unit_type
          ORDER BY unit_type");
        $stmt2->execute([':d' => $filter_date]);
        $hp_summary = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
                $hp_rows = [];
        $hp_rows_total = [];
        $hp_summary = [];
        }
}
?>

<?php if ($req_show === 'harian'): ?>
<?php if (!empty($hp_error)): ?>
    <div class="card-solid mb-3">
        <div class="card-body" style="padding:12px;color:#fca5a5;">
            <?= htmlspecialchars($hp_error); ?>
        </div>
    </div>
<?php endif; ?>
<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-mobile mr-2"></i> Data Handphone per Blok (Harian)</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th>Blok</th>
                        <th>Unit</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-center">Rusak</th>
                        <th class="text-center">Spam</th>
                        <th class="text-right">Catatan</th>
                        <th class="text-right">Hapus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hp_rows_total)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--txt-muted);padding:30px;">Belum ada input.</td></tr>
                    <?php else: foreach ($hp_rows_total as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['blok_name'] ?? '-') ?></td>
                            <td>
                                <?php
                                    $bname = $r['blok_name'] ?? '';
                                    $bw = $hp_breakdown[$bname]['WARTEL'] ?? 0;
                                    $bk = $hp_breakdown[$bname]['KAMTIB'] ?? 0;
                                ?>
                                <div class="unit-stack unit-wartel">WARTEL: <?= (int)$bw; ?></div>
                                <div class="unit-stack unit-kamtib">KAMTIB: <?= (int)$bk; ?></div>
                            </td>
                            <td class="text-center"><?= (int)($r['total_units'] ?? 0) ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['active_units'] ?? 0) : '-' ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['rusak_units'] ?? 0) : '-' ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['spam_units'] ?? 0) : '-' ?></td>
                            <td class="hp-notes"><small title="<?= htmlspecialchars($r['notes'] ?? '') ?>"><?= htmlspecialchars($r['notes'] ?? '') ?></small></td>
                            <td class="text-right">
                                <?php if (($r['unit_type'] ?? '') === 'TOTAL'): ?>
                                    <button type="button" class="btn-act" onclick="openHpEdit(this)"
                                        data-blok="<?= htmlspecialchars($bname); ?>"
                                        data-date="<?= htmlspecialchars($filter_date); ?>"
                                        data-total="<?= (int)($r['total_units'] ?? 0); ?>"
                                        data-rusak="<?= (int)($r['rusak_units'] ?? 0); ?>"
                                        data-spam="<?= (int)($r['spam_units'] ?? 0); ?>"
                                        data-notes="<?= htmlspecialchars($r['notes'] ?? ''); ?>"
                                        data-wartel="<?= (int)$bw; ?>"
                                        data-kamtib="<?= (int)$bk; ?>">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <a class="btn-act btn-act-danger" href="./?report=selling<?= $session_qs; ?>&show=<?= $req_show; ?>&date=<?= urlencode($filter_date); ?>&hp_delete=1&blok=<?= urlencode($bname); ?>&hp_date=<?= urlencode($filter_date); ?>" onclick="return confirm('Hapus data blok <?= htmlspecialchars($bname); ?> untuk <?= htmlspecialchars($filter_date); ?>?')">
                                        <i class="fa fa-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--txt-muted);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="hp-total-bar">
            <div>Total: <b><?= number_format($hp_total_units,0,',','.') ?></b></div>
            <div>Aktif: <b><?= number_format($hp_active_units,0,',','.') ?></b></div>
            <div>Rusak: <b><?= number_format($hp_rusak_units,0,',','.') ?></b></div>
            <div>Spam: <b><?= number_format($hp_spam_units,0,',','.') ?></b></div>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-list-alt mr-2"></i> Rincian Transaksi</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>User</th>
                        <th>Profile</th>
                        <th>Blok</th>
                        <th>Status</th>
                        <th class="text-right">Harga</th>
                        <th class="text-right">Efektif</th>
                        <th class="text-right">Bandwidth</th>
                        <th class="text-right">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_page)): ?>
                        <tr><td colspan="9" style="text-align:center;color:var(--txt-muted);padding:30px;">Tidak ada data pada periode ini.</td></tr>
                    <?php else: foreach ($list_page as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['dt']) ?></td>
                            <td><?= htmlspecialchars($it['user']) ?></td>
                            <td><?= htmlspecialchars($it['profile']) ?></td>
                            <td><?= htmlspecialchars($it['blok']) ?></td>
                            <td>
                                <?php
                                    $st = strtolower($it['status']);
                                    $cls = $st === 'rusak' ? 'st-rusak' : ($st === 'retur' ? 'st-retur' : ($st === 'invalid' ? 'st-invalid' : 'st-normal'));
                                ?>
                                <span class="status-badge <?= $cls; ?>"><?= htmlspecialchars($it['status']) ?></span>
                            </td>
                            <td class="text-right"><?= number_format($it['price'],0,',','.') ?></td>
                            <td class="text-right"><?= number_format($it['net'],0,',','.') ?></td>
                            <td class="text-right"><?= htmlspecialchars(format_bytes_short((int)($it['bytes'] ?? 0))) ?></td>
                            <td class="text-right"><small><?= htmlspecialchars($it['comment']) ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($tx_pages > 1): ?>
            <?php
                $tx_base = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
                $tx_link = function($p) use ($tx_base) { return $tx_base . '&tx_page=' . $p; };
                $tx_window = 2;
                $tx_start = max(1, $tx_page - $tx_window);
                $tx_end = min($tx_pages, $tx_page + $tx_window);
            ?>
            <div class="card-footer-solid">
                <div class="tx-pager">
                    <?php if ($tx_page > 1): ?>
                        <a href="<?= $tx_link(1); ?>">« First</a>
                        <a href="<?= $tx_link($tx_page - 1); ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php for ($p = $tx_start; $p <= $tx_end; $p++): ?>
                        <?php if ($p == $tx_page): ?>
                            <span class="active"><?= $p; ?></span>
                        <?php else: ?>
                            <a href="<?= $tx_link($p); ?>"><?= $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($tx_page < $tx_pages): ?>
                        <a href="<?= $tx_link($tx_page + 1); ?>">Next ›</a>
                        <a href="<?= $tx_link($tx_pages); ?>">Last »</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pendapatan per Blok/Profile sementara disembunyikan sesuai permintaan -->