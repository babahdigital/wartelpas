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
$hp_rusak_units = 0;
$hp_spam_units = 0;
$hp_wartel_units = 0;
$hp_kamtib_units = 0;
$audit_rows = [];
$audit_error = '';
$audit_redirect = '';
$audit_total_expected_qty = 0;
$audit_total_reported_qty = 0;
$audit_total_expected_setoran = 0;
$audit_total_actual_setoran = 0;
$audit_total_selisih_qty = 0;
$audit_total_selisih_setoran = 0;

// Filter periode
$req_show = $_GET['show'] ?? 'harian';
$date_param_provided = isset($_GET['date']) && trim((string)$_GET['date']) !== '';
$auto_date_mode = isset($_GET['auto_date']) && $_GET['auto_date'] == '1';
$auto_date_applied = false;
$last_available_date = '';
$mode = 'final';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    if (!$date_param_provided || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
        $filter_date = date('Y-m-d');
    } else {
        $filter_date = substr($filter_date, 0, 10);
    }
} elseif ($req_show === 'bulanan') {
    if (!$date_param_provided || !preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
        $filter_date = date('Y-m');
    } else {
        $filter_date = substr($filter_date, 0, 7);
    }
} else {
    $req_show = 'tahunan';
    if (!$date_param_provided || !preg_match('/^\d{4}$/', $filter_date)) {
        $filter_date = date('Y');
    } else {
        $filter_date = substr($filter_date, 0, 4);
    }
}

function norm_date_from_raw_report($raw_date) {
        $raw = trim((string)$raw_date);
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }
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

function normalize_block_name($blok_name, $comment = '') {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '' && $comment !== '') {
        if (preg_match('/\bblok\s*[-_]?\s*([A-Z0-9]+)\b/i', $comment, $m)) {
            $raw = strtoupper($m[1]);
        }
    }
    if ($raw === '') return 'BLOK-LAIN';
    $raw = preg_replace('/^BLOK[-_\s]*/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        $raw = $m[1];
    }
    return 'BLOK-' . $raw;
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
    return date('d-m-Y H:i:s', $ts);
}

function render_audit_lines($lines) {
    if (empty($lines)) return '-';
    return implode('', array_map(function($line) {
        return '<div class="audit-line">' . htmlspecialchars((string)$line) . '</div>';
    }, $lines));
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
        $db->exec("CREATE TABLE IF NOT EXISTS live_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_date TEXT,
            raw_time TEXT,
            sale_date TEXT,
            sale_time TEXT,
            sale_datetime TEXT,
            username TEXT,
            profile TEXT,
            profile_snapshot TEXT,
            price INTEGER,
            price_snapshot INTEGER,
            sprice_snapshot INTEGER,
            validity TEXT,
            comment TEXT,
            blok_name TEXT,
            status TEXT,
            is_rusak INTEGER,
            is_retur INTEGER,
            is_invalid INTEGER,
            qty INTEGER,
            full_raw_data TEXT UNIQUE,
            sync_status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            synced_at DATETIME
        )");
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_date TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_time TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_datetime TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN profile_snapshot TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN price_snapshot INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sprice_snapshot INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN validity TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN status TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_rusak INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_retur INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_invalid INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN qty INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN blok_name TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN full_raw_data TEXT"); } catch (Exception $e) {}
        $db->exec("CREATE TABLE IF NOT EXISTS settlement_log (
            report_date TEXT PRIMARY KEY,
            status TEXT,
            triggered_at DATETIME,
            completed_at DATETIME,
            source TEXT,
            message TEXT
        )");
        try { $db->exec("ALTER TABLE settlement_log ADD COLUMN completed_at DATETIME"); } catch (Exception $e) {}
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
        $db->exec("CREATE TABLE IF NOT EXISTS audit_rekap_manual (
            report_date TEXT,
            blok_name TEXT,
            audit_username TEXT,
            expected_qty INTEGER,
            expected_setoran INTEGER,
            reported_qty INTEGER,
            actual_setoran INTEGER,
            selisih_qty INTEGER,
            selisih_setoran INTEGER,
            note TEXT,
            user_evidence TEXT,
            status TEXT DEFAULT 'OPEN',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_date, blok_name)
        )");
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN audit_username TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN user_evidence TEXT"); } catch (Exception $e) {}
        try {
            $hasSales = table_exists($db, 'sales_history');
            $hasLive = table_exists($db, 'live_sales');
            $hasLogin = table_exists($db, 'login_history');

            $loginSelect = $hasLogin
                ? 'lh.last_status, lh.last_bytes, lh.first_login_real'
                : "'' AS last_status, 0 AS last_bytes, '' AS first_login_real";
            $loginSelect2 = $hasLogin
                ? 'lh2.last_status, lh2.last_bytes, lh2.first_login_real'
                : "'' AS last_status, 0 AS last_bytes, '' AS first_login_real";
            $loginJoin = $hasLogin ? 'LEFT JOIN login_history lh ON lh.username = sh.username' : '';
            $loginJoin2 = $hasLogin ? 'LEFT JOIN login_history lh2 ON lh2.username = ls.username' : '';

            $selects = [];
            if ($hasSales) {
                $selects[] = "SELECT 
                    sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                    sh.username, sh.profile, sh.profile_snapshot,
                    sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
                    sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
                    sh.full_raw_data, $loginSelect
                    FROM sales_history sh
                    $loginJoin";
            }
            if ($hasLive) {
                $selects[] = "SELECT 
                    ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                    ls.username, ls.profile, ls.profile_snapshot,
                    ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                    ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
                    ls.full_raw_data, $loginSelect2
                    FROM live_sales ls
                    $loginJoin2
                    WHERE ls.sync_status = 'pending'";
            }

            if (!empty($selects)) {
                $sql = implode(" UNION ALL ", $selects) . " ORDER BY sale_datetime DESC, raw_date DESC";
                $res = $db->query($sql);
                if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = [];
            }
        } catch (Exception $e) {
            $rows = [];
        }

        if ($req_show === 'harian' && table_exists($db, 'login_history')) {
            try {
                $salesCount = 0;
                if (table_exists($db, 'sales_history')) {
                    $stmtCnt = $db->prepare("SELECT COUNT(*) FROM sales_history WHERE sale_date = :d");
                    $stmtCnt->execute([':d' => $filter_date]);
                    $salesCount += (int)$stmtCnt->fetchColumn();
                }
                if (table_exists($db, 'live_sales')) {
                    $stmtCnt2 = $db->prepare("SELECT COUNT(*) FROM live_sales WHERE sale_date = :d");
                    $stmtCnt2->execute([':d' => $filter_date]);
                    $salesCount += (int)$stmtCnt2->fetchColumn();
                }
                if ($salesCount === 0) {
                    $stmtFallback = $db->prepare("SELECT
                        '' AS raw_date,
                        '' AS raw_time,
                        COALESCE(NULLIF(substr(login_time_real,1,10),''), login_date) AS sale_date,
                        COALESCE(NULLIF(substr(login_time_real,12,8),''), login_time) AS sale_time,
                        COALESCE(NULLIF(login_time_real,''), NULLIF(last_login_real,'')) AS sale_datetime,
                        username,
                        COALESCE(NULLIF(validity,''), '-') AS profile,
                        COALESCE(NULLIF(validity,''), '-') AS profile_snapshot,
                        CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price,
                        CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price_snapshot,
                        CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS sprice_snapshot,
                        validity,
                        raw_comment AS comment,
                        blok_name,
                        '' AS status,
                        0 AS is_rusak,
                        0 AS is_retur,
                        0 AS is_invalid,
                        1 AS qty,
                        '' AS full_raw_data,
                        last_status,
                        last_bytes,
                        first_login_real
                    FROM login_history
                    WHERE username != ''
                      AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d)
                      AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'
                    ORDER BY sale_datetime DESC");
                    $stmtFallback->execute([':d' => $filter_date]);
                    $rows = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                $rows = [];
            }
        }

        if (!$date_param_provided && $req_show === 'harian' && !empty($rows)) {
            foreach ($rows as $r) {
                $cand = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
                if ($cand !== '' && ($last_available_date === '' || $cand > $last_available_date)) {
                    $last_available_date = $cand;
                }
            }
            if ($auto_date_mode && $last_available_date !== '' && $last_available_date !== $filter_date) {
                $filter_date = $last_available_date;
                $auto_date_applied = true;
            }
        }

        $settled_today = false;
        $settlement_time = '';
        try {
            $stmtSet = $db->prepare("SELECT status, triggered_at, completed_at FROM settlement_log WHERE report_date = :d LIMIT 1");
            $stmtSet->execute([':d' => $filter_date]);
            $srow = $stmtSet->fetch(PDO::FETCH_ASSOC);
            $settled_today = $srow && strtolower((string)$srow['status']) === 'done';
            if ($srow) {
                $settlement_time = $srow['completed_at'] ?: ($srow['triggered_at'] ?? '');
            }
        } catch (Exception $e) {
            $settled_today = false;
        }

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

// Hapus audit manual rekap (harian)
if (isset($db) && $db instanceof PDO && isset($_GET['audit_delete'])) {
    $del_date = trim($_GET['audit_date'] ?? '');
    $del_blok = strtoupper(trim($_GET['audit_blok'] ?? ''));
    if ($del_date !== '' && $del_blok !== '') {
        try {
            $stmt = $db->prepare("DELETE FROM audit_rekap_manual WHERE report_date = :d AND UPPER(blok_name) = :b");
            $stmt->execute([':d' => $del_date, ':b' => $del_blok]);
        } catch (Exception $e) {}
        $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
        if (!headers_sent()) {
            header('Location: ' . $audit_redirect);
            exit;
        }
    }
}

if ((!empty($hp_redirect) || !empty($audit_redirect)) && headers_sent()) {
    $redir = $audit_redirect ?: $hp_redirect;
    echo '<script>window.location.replace(' . json_encode($redir) . ');</script>';
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

function calc_expected_for_block(array $rows, $audit_date, $audit_blok) {
    $seen_sales = [];
    $seen_user_day = [];
    $qty_total = 0;
    $rusak_qty = 0;
    $retur_qty = 0;
    $invalid_qty = 0;
    $net_total = 0;

    foreach ($rows as $r) {
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        if ($sale_date !== $audit_date) continue;

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
            $unique_key = $username . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($r['sale_time'] ?? '')));
            if ($unique_key === $username . '|') {
                $unique_key = $username . '|' . $sale_date . '|' . ($r['sale_time'] ?? '');
            }
        } elseif ($sale_date !== '') {
            $unique_key = 'date|' . $sale_date . '|' . ($r['sale_time'] ?? '');
        }
        if ($unique_key !== '') {
            if (isset($seen_sales[$unique_key])) continue;
            $seen_sales[$unique_key] = true;
        }

        $raw_comment = (string)($r['comment'] ?? '');
        $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);
        if ($blok !== $audit_blok) continue;

        $status = strtolower((string)($r['status'] ?? ''));
        $lh_status = strtolower((string)($r['last_status'] ?? ''));
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

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;
        $line_price = $price * $qty;

        $gross_add = ($status === 'invalid') ? 0 : $line_price;
        $loss_rusak = ($status === 'rusak') ? $line_price : 0;
        $loss_invalid = ($status === 'invalid') ? $line_price : 0;
        $net_add = $gross_add - $loss_rusak - $loss_invalid;

        $qty_total += 1;
        if ($status === 'rusak') $rusak_qty += 1;
        if ($status === 'retur') $retur_qty += 1;
        if ($status === 'invalid') $invalid_qty += 1;
        $net_total += $net_add;
    }

    $expected_qty = max(0, $qty_total - $rusak_qty - $invalid_qty);
    return [
        'qty' => $expected_qty,
        'raw_qty' => $qty_total,
        'rusak_qty' => $rusak_qty,
        'invalid_qty' => $invalid_qty,
        'net' => $net_total,
        'retur_qty' => $retur_qty
    ];
}

function fetch_rows_for_audit(PDO $db, $audit_date) {
    $rows = [];
    $hasSales = table_exists($db, 'sales_history');
    $hasLive = table_exists($db, 'live_sales');
    $hasLogin = table_exists($db, 'login_history');

    if ($hasSales) {
        $sql = "SELECT
            sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
            sh.username, sh.profile, sh.profile_snapshot,
            sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
            sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
            sh.full_raw_data,
            " . ($hasLogin ? "lh.last_status" : "'' AS last_status") . "
            FROM sales_history sh
            " . ($hasLogin ? "LEFT JOIN login_history lh ON lh.username = sh.username" : "") . "
            WHERE sh.sale_date = :d";
        $stmt = $db->prepare($sql);
        $stmt->execute([':d' => $audit_date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($hasLive) {
        $sql = "SELECT
            ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
            ls.username, ls.profile, ls.profile_snapshot,
            ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
            ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
            ls.full_raw_data,
            " . ($hasLogin ? "lh2.last_status" : "'' AS last_status") . "
            FROM live_sales ls
            " . ($hasLogin ? "LEFT JOIN login_history lh2 ON lh2.username = ls.username" : "") . "
            WHERE ls.sale_date = :d AND ls.sync_status = 'pending'";
        $stmt = $db->prepare($sql);
        $stmt->execute([':d' => $audit_date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if (empty($rows) && $hasLogin) {
        $stmtFallback = $db->prepare("SELECT
            '' AS raw_date,
            '' AS raw_time,
            COALESCE(NULLIF(substr(login_time_real,1,10),''), login_date) AS sale_date,
            COALESCE(NULLIF(substr(login_time_real,12,8),''), login_time) AS sale_time,
            COALESCE(NULLIF(login_time_real,''), NULLIF(last_login_real,'')) AS sale_datetime,
            username,
            COALESCE(NULLIF(validity,''), '-') AS profile,
            COALESCE(NULLIF(validity,''), '-') AS profile_snapshot,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price_snapshot,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS sprice_snapshot,
            validity,
            raw_comment AS comment,
            blok_name,
            '' AS status,
            0 AS is_rusak,
            0 AS is_retur,
            0 AS is_invalid,
            1 AS qty,
            '' AS full_raw_data,
            last_status
            FROM login_history
            WHERE username != ''
              AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d)
              AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'" );
        $stmtFallback->execute([':d' => $audit_date]);
        $rows = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
    }

    return $rows;
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
$unique_laku_users = [];

$seen_sales = [];
$seen_user_day = [];

$valid_blocks = [];
if ($req_show === 'harian' && isset($db) && $db instanceof PDO) {
    try {
        $stmtBlk = $db->prepare("SELECT DISTINCT blok_name FROM phone_block_daily WHERE report_date = :d AND unit_type = 'TOTAL'");
        $stmtBlk->execute([':d' => $filter_date]);
        $blkRows = $stmtBlk->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blkRows as $br) {
            $nb = normalize_block_name($br['blok_name'] ?? '');
            if ($nb !== '') $valid_blocks[$nb] = true;
        }
    } catch (Exception $e) {}
}

$by_block = [];
$by_profile = [];
$no_sales_message = '';

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

        $username = $r['username'] ?? '';
        if ($username !== '' && $sale_date !== '') {
            $user_day_key = $username . '|' . $sale_date;
            if (isset($seen_user_day[$user_day_key])) {
                continue;
            }
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
        $comment = format_first_login($r['first_login_real'] ?? '');
        $raw_comment = (string)($r['comment'] ?? '');
        $blok_row = (string)($r['blok_name'] ?? '');
        if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $raw_comment)) {
            continue;
        }
        $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
        $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);
        $status = strtolower((string)($r['status'] ?? ''));
        $lh_status = strtolower((string)($r['last_status'] ?? ''));
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

        $gross_add = ($status === 'invalid') ? 0 : $line_price;
        $loss_rusak = ($status === 'rusak') ? $line_price : 0;
        $loss_invalid = ($status === 'invalid') ? $line_price : 0;
        $net_add = $gross_add - $loss_rusak - $loss_invalid;
        $is_laku = !in_array($status, ['rusak', 'retur', 'invalid'], true);

        if (!$use_summary) {
            if ($is_laku && $username !== '') {
                $unique_laku_users[$username] = true;
            }
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

            if (!isset($by_block[$blok])) {
                $by_block[$blok] = ['qty'=>0,'gross'=>0,'rusak'=>0,'invalid'=>0,'net'=>0,'retur'=>0,'rusak_qty'=>0,'invalid_qty'=>0];
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
            if ($status === 'rusak') $by_block[$blok]['rusak_qty'] += 1;
            if ($status === 'invalid') $by_block[$blok]['invalid_qty'] += 1;

            $by_profile[$profile]['qty'] += 1;
            $by_profile[$profile]['gross'] += $gross_add;
            $by_profile[$profile]['rusak'] += $loss_rusak;
            $by_profile[$profile]['invalid'] += $loss_invalid;
            $by_profile[$profile]['net'] += $net_add;
            if ($status === 'retur') $by_profile[$profile]['retur'] += 1;
        }

        if (empty($valid_blocks) || isset($valid_blocks[$blok])) {
            $total_bandwidth += (int)($r['last_bytes'] ?? 0);
        }
        $list[] = [
                'dt' => $sale_dt,
                'user' => $r['username'] ?? '-',
                'profile' => $profile,
                'blok' => $blok,
                'status' => strtoupper($status),
                'price' => $line_price,
                'net' => $net_add,
                'bytes' => (int)($r['last_bytes'] ?? 0),
            'comment' => $comment
        ];
}

ksort($by_block, SORT_NATURAL | SORT_FLAG_CASE);
ksort($by_profile, SORT_NATURAL | SORT_FLAG_CASE);
$total_qty_laku = count($unique_laku_users);

$audit_user_options = [];
if (isset($db) && $db instanceof PDO && $req_show === 'harian') {
    try {
        $user_set = [];
        if (table_exists($db, 'login_events')) {
            $stmtOpt = $db->prepare("SELECT DISTINCT username FROM login_events WHERE date_key = :d AND username != ''");
            $stmtOpt->execute([':d' => $filter_date]);
            foreach ($stmtOpt->fetchAll(PDO::FETCH_COLUMN, 0) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $user_set[$u] = true;
            }
        }
        if (table_exists($db, 'sales_history')) {
            $stmtOpt = $db->prepare("SELECT DISTINCT username FROM sales_history WHERE sale_date = :d AND username != ''");
            $stmtOpt->execute([':d' => $filter_date]);
            foreach ($stmtOpt->fetchAll(PDO::FETCH_COLUMN, 0) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $user_set[$u] = true;
            }
        }
        if (table_exists($db, 'live_sales')) {
            $stmtOpt = $db->prepare("SELECT DISTINCT username FROM live_sales WHERE sale_date = :d AND username != ''");
            $stmtOpt->execute([':d' => $filter_date]);
            foreach ($stmtOpt->fetchAll(PDO::FETCH_COLUMN, 0) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $user_set[$u] = true;
            }
        }
        if (table_exists($db, 'login_history')) {
            $stmtOpt = $db->prepare("SELECT DISTINCT username FROM login_history WHERE username != '' AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d) AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'");
            $stmtOpt->execute([':d' => $filter_date]);
            foreach ($stmtOpt->fetchAll(PDO::FETCH_COLUMN, 0) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $user_set[$u] = true;
            }
        }
        $audit_user_options = array_keys($user_set);
        natcasesort($audit_user_options);
        $audit_user_options = array_values($audit_user_options);
    } catch (Exception $e) {
        $audit_user_options = [];
    }
}

// Simpan audit manual rekap harian (qty + uang)
if (isset($db) && $db instanceof PDO && $req_show === 'harian') {
    if (isset($_POST['audit_submit']) || isset($_POST['audit_blok'])) {
        $audit_is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
        $audit_blok_raw = trim($_POST['audit_blok'] ?? '');
        $audit_date = trim($_POST['audit_date'] ?? '');
        $audit_blok = normalize_block_name($audit_blok_raw);
        $audit_user_raw = trim($_POST['audit_username'] ?? '');
        $audit_users = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\n,]+/', $audit_user_raw)))));
        $audit_user_list = implode(', ', $audit_users);
        $audit_qty = (int)($_POST['audit_qty'] ?? 0);
        $audit_setoran = (int)($_POST['audit_setoran'] ?? 0);
        $audit_qty_10 = (int)($_POST['audit_qty_10'] ?? 0);
        $audit_qty_30 = (int)($_POST['audit_qty_30'] ?? 0);
        $audit_note = '';
        $audit_status = 'OPEN';

        if ($audit_blok_raw === '' || $audit_date === '') {
            $audit_error = 'Blok dan tanggal wajib diisi.';
        } else {
            $user_rows = [];
            $user_events = [];
            $blok_from_user = '';
            $auto_qty_10 = 0;
            $auto_qty_30 = 0;
            $rusak_10 = 0;
            $rusak_30 = 0;
            $retur_10 = 0;
            $retur_30 = 0;
            $invalid_10 = 0;
            $invalid_30 = 0;
            $user_profile_label = [];
            $user_profile_kind = [];
            $user_price_value = [];
            $user_status = [];
            $auto_status_users = [];
            if (table_exists($db, 'login_history')) {
                $stmtAuto = $db->prepare("SELECT username, blok_name, raw_comment, last_status, last_bytes, last_uptime, first_login_real, last_login_real, login_time_real, logout_time_real, first_ip, first_mac, last_ip, last_mac
                    FROM login_history
                    WHERE username != '' AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d)");
                $stmtAuto->execute([':d' => $audit_date]);
                foreach ($stmtAuto->fetchAll(PDO::FETCH_ASSOC) as $rowAuto) {
                    $u = trim((string)($rowAuto['username'] ?? ''));
                    if ($u === '') continue;
                    $blok_u = normalize_block_name($rowAuto['blok_name'] ?? '', $rowAuto['raw_comment'] ?? '');
                    if ($blok_u !== $audit_blok) continue;
                    $st = strtolower((string)($rowAuto['last_status'] ?? ''));
                    $cmt = strtolower((string)($rowAuto['raw_comment'] ?? ''));
                    if ($st === '' || $st === 'normal') {
                        if (strpos($cmt, 'invalid') !== false) $st = 'invalid';
                        elseif (strpos($cmt, 'retur') !== false) $st = 'retur';
                        elseif (strpos($cmt, 'rusak') !== false) $st = 'rusak';
                    }
                    if (in_array($st, ['rusak', 'retur'], true)) {
                        $rowAuto['_status'] = $st;
                        $auto_status_users[$u] = $rowAuto;
                    }
                }
            }
            if (table_exists($db, 'sales_history')) {
                $stmtAutoSales = $db->prepare("SELECT username, blok_name, comment, status, is_rusak, is_retur, is_invalid, profile_snapshot, profile, validity, price_snapshot, price, sprice_snapshot
                    FROM sales_history WHERE username != '' AND sale_date = :d");
                $stmtAutoSales->execute([':d' => $audit_date]);
                foreach ($stmtAutoSales->fetchAll(PDO::FETCH_ASSOC) as $rowSales) {
                    $u = trim((string)($rowSales['username'] ?? ''));
                    if ($u === '') continue;
                    $blok_u = normalize_block_name($rowSales['blok_name'] ?? '', $rowSales['comment'] ?? '');
                    if ($blok_u !== $audit_blok) continue;
                    $st = strtolower((string)($rowSales['status'] ?? ''));
                    $cmt = strtolower((string)($rowSales['comment'] ?? ''));
                    if ($st === '' || $st === 'normal') {
                        if ((int)($rowSales['is_invalid'] ?? 0) === 1) $st = 'invalid';
                        elseif ((int)($rowSales['is_retur'] ?? 0) === 1) $st = 'retur';
                        elseif ((int)($rowSales['is_rusak'] ?? 0) === 1) $st = 'rusak';
                        elseif (strpos($cmt, 'invalid') !== false) $st = 'invalid';
                        elseif (strpos($cmt, 'retur') !== false) $st = 'retur';
                        elseif (strpos($cmt, 'rusak') !== false) $st = 'rusak';
                    }
                    if (in_array($st, ['rusak', 'retur'], true) && !isset($auto_status_users[$u])) {
                        $rowSales['_status'] = $st;
                        $rowSales['raw_comment'] = $rowSales['comment'] ?? '';
                        $auto_status_users[$u] = $rowSales;
                    }
                }
            }
            if (table_exists($db, 'live_sales')) {
                $stmtAutoLive = $db->prepare("SELECT username, blok_name, comment, status, is_rusak, is_retur, is_invalid, profile_snapshot, profile, validity, price_snapshot, price, sprice_snapshot
                    FROM live_sales WHERE username != '' AND sale_date = :d");
                $stmtAutoLive->execute([':d' => $audit_date]);
                foreach ($stmtAutoLive->fetchAll(PDO::FETCH_ASSOC) as $rowLive) {
                    $u = trim((string)($rowLive['username'] ?? ''));
                    if ($u === '') continue;
                    if (isset($auto_status_users[$u])) continue;
                    $blok_u = normalize_block_name($rowLive['blok_name'] ?? '', $rowLive['comment'] ?? '');
                    if ($blok_u !== $audit_blok) continue;
                    $st = strtolower((string)($rowLive['status'] ?? ''));
                    $cmt = strtolower((string)($rowLive['comment'] ?? ''));
                    if ($st === '' || $st === 'normal') {
                        if ((int)($rowLive['is_invalid'] ?? 0) === 1) $st = 'invalid';
                        elseif ((int)($rowLive['is_retur'] ?? 0) === 1) $st = 'retur';
                        elseif ((int)($rowLive['is_rusak'] ?? 0) === 1) $st = 'rusak';
                        elseif (strpos($cmt, 'invalid') !== false) $st = 'invalid';
                        elseif (strpos($cmt, 'retur') !== false) $st = 'retur';
                        elseif (strpos($cmt, 'rusak') !== false) $st = 'rusak';
                    }
                    if (in_array($st, ['rusak', 'retur'], true)) {
                        $rowLive['_status'] = $st;
                        $rowLive['raw_comment'] = $rowLive['comment'] ?? '';
                        $auto_status_users[$u] = $rowLive;
                    }
                }
            }
            if (!empty($auto_status_users)) {
                $audit_users = array_values(array_unique(array_merge($audit_users, array_keys($auto_status_users))));
            }
            if (!empty($audit_users)) {
                foreach ($audit_users as $u) {
                    $user_row = null;
                    $profile_label = '';
                    $price_value = 0;
                    if (table_exists($db, 'login_history')) {
                        $stmtU = $db->prepare("SELECT username, blok_name, raw_comment, first_login_real, last_login_real, login_time_real, logout_time_real, last_status, last_bytes, last_uptime, first_ip, first_mac, last_ip, last_mac
                            FROM login_history WHERE username = :u LIMIT 1");
                        $stmtU->execute([':u' => $u]);
                        $user_row = $stmtU->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$user_row && isset($auto_status_users[$u])) {
                        $user_row = $auto_status_users[$u];
                    }
                    if (table_exists($db, 'sales_history')) {
                        $stmtPf = $db->prepare("SELECT profile_snapshot, profile, validity, price_snapshot, price, sprice_snapshot FROM sales_history WHERE username = :u AND sale_date = :d ORDER BY sale_time DESC LIMIT 1");
                        $stmtPf->execute([':u' => $u, ':d' => $audit_date]);
                        $pf = $stmtPf->fetch(PDO::FETCH_ASSOC) ?: [];
                        $profile_label = (string)($pf['profile_snapshot'] ?? ($pf['profile'] ?? ($pf['validity'] ?? '')));
                        $price_value = (int)($pf['price_snapshot'] ?? $pf['price'] ?? 0);
                        if ($price_value <= 0) $price_value = (int)($pf['sprice_snapshot'] ?? 0);
                    }
                    if ($profile_label === '' && table_exists($db, 'live_sales')) {
                        $stmtPf2 = $db->prepare("SELECT profile_snapshot, profile, validity, price_snapshot, price, sprice_snapshot FROM live_sales WHERE username = :u AND sale_date = :d ORDER BY sale_time DESC LIMIT 1");
                        $stmtPf2->execute([':u' => $u, ':d' => $audit_date]);
                        $pf2 = $stmtPf2->fetch(PDO::FETCH_ASSOC) ?: [];
                        $profile_label = (string)($pf2['profile_snapshot'] ?? ($pf2['profile'] ?? ($pf2['validity'] ?? '')));
                        if ($price_value <= 0) {
                            $price_value = (int)($pf2['price_snapshot'] ?? $pf2['price'] ?? 0);
                            if ($price_value <= 0) $price_value = (int)($pf2['sprice_snapshot'] ?? 0);
                        }
                    }
                    $profile_kind = '';
                    $profile_low = strtolower($profile_label);
                    if (preg_match('/\b30\s*(menit|m)\b|30menit/', $profile_low)) $profile_kind = '30';
                    elseif (preg_match('/\b10\s*(menit|m)\b|10menit/', $profile_low)) $profile_kind = '10';
                    else $profile_kind = '10';
                    if ($profile_kind === '30') $auto_qty_30++;
                    else $auto_qty_10++;
                    $u_status = '';
                    $u_cmt = '';
                    if (is_array($user_row)) {
                        $u_status = strtolower((string)($user_row['last_status'] ?? ''));
                        $u_cmt = strtolower((string)($user_row['raw_comment'] ?? ''));
                    }
                    if (($u_status === '' || $u_status === 'normal') && isset($auto_status_users[$u]['_status'])) {
                        $u_status = (string)$auto_status_users[$u]['_status'];
                    }
                    if ($u_status === '' || $u_status === 'normal') {
                        if (strpos($u_cmt, 'invalid') !== false) $u_status = 'invalid';
                        elseif (strpos($u_cmt, 'retur') !== false) $u_status = 'retur';
                        elseif (strpos($u_cmt, 'rusak') !== false) $u_status = 'rusak';
                    }
                    $user_profile_label[$u] = $profile_label;
                    $user_profile_kind[$u] = $profile_kind;
                    $user_price_value[$u] = $price_value;
                    $user_status[$u] = $u_status;
                    if ($profile_kind === '30') {
                        if ($u_status === 'rusak') $rusak_30++;
                        elseif ($u_status === 'retur') $retur_30++;
                        elseif ($u_status === 'invalid') $invalid_30++;
                    } else {
                        if ($u_status === 'rusak') $rusak_10++;
                        elseif ($u_status === 'retur') $retur_10++;
                        elseif ($u_status === 'invalid') $invalid_10++;
                    }
                    if ($user_row) $user_rows[$u] = $user_row;
                    if (table_exists($db, 'login_events')) {
                        $stmtEv2 = $db->prepare("SELECT seq, login_time, logout_time FROM login_events WHERE username = :u AND date_key = :d ORDER BY seq ASC, id ASC");
                        $stmtEv2->execute([':u' => $u, ':d' => $audit_date]);
                        $user_events[$u] = $stmtEv2->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            }
            $profile_qty_sum = $audit_qty_10 + $audit_qty_30;
            $net_qty_10 = max(0, ($audit_qty_10 - $rusak_10 - $invalid_10 + $retur_10));
            $net_qty_30 = max(0, ($audit_qty_30 - $rusak_30 - $invalid_30 + $retur_30));
            $audit_qty_raw = $profile_qty_sum;
            $audit_qty = $net_qty_10 + $net_qty_30;
            $audit_setoran = ($net_qty_10 * 5000) + ($net_qty_30 * 20000);
            if ($profile_qty_sum <= 0) {
                $audit_error = 'Qty per profile wajib diisi.';
            }
            $expected_qty = 0;
            $expected_setoran = 0;
            if (!empty($rows)) {
                $expected = calc_expected_for_block($rows, $audit_date, $audit_blok);
                $expected_qty = (int)($expected['qty'] ?? 0);
                $expected_setoran = (int)($expected['net'] ?? 0);
            } elseif (isset($db) && $db instanceof PDO) {
                $audit_rows_src = fetch_rows_for_audit($db, $audit_date);
                $expected = calc_expected_for_block($audit_rows_src, $audit_date, $audit_blok);
                $expected_qty = (int)($expected['qty'] ?? 0);
                $expected_setoran = (int)($expected['net'] ?? 0);
            } else {
                $expected_qty = (int)($by_block[$audit_blok]['qty'] ?? 0);
                $expected_setoran = (int)($by_block[$audit_blok]['net'] ?? 0);
            }
            $selisih_qty = $audit_qty - $expected_qty;
            $selisih_setoran = $audit_setoran - $expected_setoran;
            if (empty($audit_error)) {
                $evidence = [];
                $evidence['profile_qty'] = [
                    'qty_10' => $audit_qty_10,
                    'qty_30' => $audit_qty_30
                ];
                if (!empty($audit_users)) {
                    $evidence['users'] = [];
                    foreach ($audit_users as $u) {
                        $ur = $user_rows[$u] ?? null;
                        $blok_u = '';
                        if ($ur) {
                            $blok_u = normalize_block_name($ur['blok_name'] ?? '', $ur['raw_comment'] ?? '');
                        }
                        $evidence['users'][$u] = [
                            'blok' => $blok_u,
                            'profile_label' => $user_profile_label[$u] ?? '',
                            'profile_kind' => $user_profile_kind[$u] ?? '',
                            'price' => $user_price_value[$u] ?? 0,
                            'first_login_real' => $ur['first_login_real'] ?? '',
                            'last_login_real' => $ur['last_login_real'] ?? '',
                            'login_time_real' => $ur['login_time_real'] ?? '',
                            'logout_time_real' => $ur['logout_time_real'] ?? '',
                            'last_status' => $user_status[$u] ?? ($ur['last_status'] ?? ''),
                            'last_bytes' => (int)($ur['last_bytes'] ?? 0),
                            'last_uptime' => $ur['last_uptime'] ?? '',
                            'first_ip' => $ur['first_ip'] ?? '',
                            'first_mac' => $ur['first_mac'] ?? '',
                            'last_ip' => $ur['last_ip'] ?? '',
                            'last_mac' => $ur['last_mac'] ?? '',
                            'events' => $user_events[$u] ?? []
                        ];
                    }
                }
                $evidence_json = !empty($evidence) ? json_encode($evidence) : '';
                try {
                    $stmt = $db->prepare("INSERT INTO audit_rekap_manual
                        (report_date, blok_name, audit_username, expected_qty, expected_setoran, reported_qty, actual_setoran, selisih_qty, selisih_setoran, note, user_evidence, status, updated_at)
                        VALUES (:d, :b, :u, :eq, :es, :rq, :as, :sq, :ss, :n, :ev, :st, CURRENT_TIMESTAMP)
                        ON CONFLICT(report_date, blok_name) DO UPDATE SET
                            audit_username=excluded.audit_username,
                            expected_qty=excluded.expected_qty,
                            expected_setoran=excluded.expected_setoran,
                            reported_qty=excluded.reported_qty,
                            actual_setoran=excluded.actual_setoran,
                            selisih_qty=excluded.selisih_qty,
                            selisih_setoran=excluded.selisih_setoran,
                            note=excluded.note,
                            user_evidence=excluded.user_evidence,
                            status=excluded.status,
                            updated_at=CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([
                        ':d' => $audit_date,
                        ':b' => $audit_blok,
                        ':u' => $audit_user_list,
                        ':eq' => $expected_qty,
                        ':es' => $expected_setoran,
                        ':rq' => $audit_qty,
                        ':as' => $audit_setoran,
                        ':sq' => $selisih_qty,
                        ':ss' => $selisih_setoran,
                        ':n' => $audit_note,
                        ':ev' => $evidence_json,
                        ':st' => $audit_status
                    ]);
                } catch (Exception $e) {
                    $audit_error = 'Gagal menyimpan audit.';
                }
            }
        }

        if (empty($audit_error)) {
            $redir_date = $audit_date !== '' ? $audit_date : $filter_date;
            $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($redir_date);
            if (!headers_sent() && !$audit_is_ajax) {
                header('Location: ' . $audit_redirect);
                exit;
            }
        }

        if ($audit_is_ajax) {
            header('Content-Type: application/json');
            if (!empty($audit_error)) {
                echo json_encode(['ok' => false, 'message' => $audit_error]);
            } else {
                echo json_encode(['ok' => true, 'redirect' => $audit_redirect]);
            }
            exit;
        }
    }

    try {
        $stmtAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name");
        $stmtAudit->execute([':d' => $filter_date]);
        $audit_rows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
        foreach ($audit_rows as $ar) {
            $audit_total_expected_qty += (int)($ar['expected_qty'] ?? 0);
            $audit_total_reported_qty += (int)($ar['reported_qty'] ?? 0);
            $audit_total_expected_setoran += (int)($ar['expected_setoran'] ?? 0);
            $audit_total_actual_setoran += (int)($ar['actual_setoran'] ?? 0);
            $audit_total_selisih_qty += (int)($ar['selisih_qty'] ?? 0);
            $audit_total_selisih_setoran += (int)($ar['selisih_setoran'] ?? 0);
        }
    } catch (Exception $e) {
        $audit_rows = [];
    }
}

if (empty($list) && $last_available_date !== '' && $filter_date !== $last_available_date) {
    $no_sales_message = 'Tidak ada data untuk tanggal ini. Data terakhir: ' . $last_available_date . '.';
}
if (empty($list) && $req_show === 'harian' && isset($db) && $db instanceof PDO && table_exists($db, 'login_history')) {
    try {
        $stmtLH = $db->prepare("SELECT COUNT(*) FROM login_history WHERE username != '' AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d) AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'");
        $stmtLH->execute([':d' => $filter_date]);
        $lhCount = (int)$stmtLH->fetchColumn();
        if ($lhCount > 0) {
            $no_sales_message = 'Data penjualan belum tersinkron. Jalankan sync_sales.php agar transaksi muncul di laporan.';
        }
    } catch (Exception $e) {}
}

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

<?php $is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1'; ?>

<?php if ($is_ajax) { ob_start(); } ?>

<?php if (!empty($hp_redirect) && headers_sent() && !$is_ajax): ?>
    <script>window.location.replace('<?= htmlspecialchars($hp_redirect, ENT_QUOTES); ?>');</script>
<?php endif; ?>

<?php if (!$is_ajax): ?>
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
    .modal-backdrop { position: fixed; inset: 0; background: rgba(18,18,18,0.95); display: none; align-items: center; justify-content: center; z-index: 10000; }
    .modal-card { background: #2c2c2c; color: #e0e0e0; border-radius: 8px; width: 520px; max-width: 92vw; border: 1px solid #444; box-shadow: 0 10px 30px rgba(0,0,0,0.5); overflow: hidden; }
    .modal-header { background: #252525; border-bottom: 1px solid #3d3d3d; padding: 20px; display: flex; align-items: center; justify-content: space-between; }
    .modal-title { font-weight: 600; color: #fff; font-size: 18px; margin: 0; }
    .modal-close { background: transparent; border: none; color: #fff; opacity: 0.7; font-size: 22px; line-height: 1; cursor: pointer; }
    .modal-close:hover { opacity: 1; }
    .modal-body { padding: 25px; color: #ccc; }
    .modal-footer { background: #252525; border-top: 1px solid #3d3d3d; padding: 15px 25px; display: flex; gap: 8px; justify-content: flex-end; }
    .modal-note { font-size: 12px; color: #9aa0a6; margin-top: 8px; line-height: 1.4; }
    .btn-default-dark { background:#424242; color:#fff; border:1px solid #555; }
    .btn-default-dark:hover { background:#505050; color:#fff; }
    .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    .form-input { width: 100%; background: #343a40; border: 1px solid var(--border-col); color: #fff; padding: 8px 10px; border-radius: 6px; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 4px; border: none; color: #fff; background: #3a4046; cursor: pointer; }
    .btn-act-danger { background: #e74c3c; }
    .audit-neg { color:#ff6b6b; font-weight:700; }
    .audit-pos { color:#8bd0ff; font-weight:700; }
    .audit-zero { color:var(--txt-muted); }
    .audit-user-picker { position: relative; }
    .audit-user-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px; }
    .audit-user-chip { display:inline-flex; align-items:center; gap:6px; background:#343a40; border:1px solid var(--border-col); color:#e9ecef; border-radius:999px; padding:2px 8px; font-size:12px; }
    .audit-user-chip button { background:transparent; border:none; color:#fff; cursor:pointer; font-size:12px; line-height:1; padding:0; }
    .audit-user-suggest { position:absolute; left:0; right:0; top:100%; background:#1f2328; border:1px solid #5a646e; border-radius:6px; max-height:180px; overflow:auto; z-index:10002; display:none; margin-top:4px; box-shadow:0 8px 18px rgba(0,0,0,0.45); }
    .audit-user-suggest .item { padding:7px 10px; cursor:pointer; font-size:12px; color:#e6edf3; border-bottom:1px solid #2c3238; }
    .audit-user-suggest .item:last-child { border-bottom:none; }
    .audit-user-suggest .item:hover { background:#2b3137; }
    .audit-modal-desc { margin:6px 0 0; }
    .audit-profile-row { display:flex; gap:14px; align-items:center; flex-wrap:nowrap; }
    .audit-profile-item { display:flex; align-items:center; gap:8px; }
    .audit-profile-item label { display:flex; align-items:center; gap:6px; margin:0; }
    .audit-profile-item input[type="number"] { width:90px; padding:6px 8px; }
    .audit-table th, .audit-table td { padding:8px 10px; font-size:0.9rem; }
    .audit-line { border-bottom:1px dashed #3a4046; padding:2px 0; }
    .audit-line:last-child { border-bottom:none; }
</style>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="hpModal" class="modal-backdrop" onclick="if(event.target===this){closeHpModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Input Handphone per Blok (Harian)</div>
            <button type="button" class="modal-close" onclick="closeHpModal()">&times;</button>
        </div>
        <form id="hpForm" method="post" action="report/hp_save.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <div class="modal-body">
                <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;">
                    <div style="font-size:22px;color:#4caf50;line-height:1;"><i class="fa fa-info-circle"></i></div>
                    <div style="font-size:12px;color:#9aa0a6;line-height:1.4;">
                        Data harian akan otomatis mengikuti data tanggal terakhir jika belum ada input untuk hari ini.
                        Edit hanya diperlukan saat ada perubahan atau penambahan.
                    </div>
                </div>
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-print" onclick="closeHpModal()">Batal</button>
                <button type="submit" id="hpSubmitBtn" name="hp_submit" class="btn-print" disabled>Simpan</button>
            </div>
        </form>
    </div>
</div>

<div id="auditModal" class="modal-backdrop" onclick="if(event.target===this){closeAuditModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Audit Manual Rekap Harian</div>
            <button type="button" class="modal-close" onclick="closeAuditModal()">&times;</button>
        </div>
        <form id="auditForm" method="post" action="report/selling.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="audit_submit" value="1">
            <div class="modal-body">
                <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:6px;">
                    <div style="font-size:22px;color:#4ea8ff;line-height:1;"><i class="fa fa-edit"></i></div>
                    <div class="audit-modal-desc" style="font-size:12px;color:#9aa0a6;line-height:1.4;">
                        Isi laporan manual untuk membandingkan dengan rekap sistem (qty dan uang). Selisih akan dihitung otomatis.
                    </div>
                </div>
                <div class="form-grid-2">
                    <div>
                        <label>Blok</label>
                        <select class="form-input" name="audit_blok" required>
                            <option value="" disabled selected>Pilih Blok</option>
                            <?php foreach (range('A','F') as $b): ?>
                                <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tanggal</label>
                        <input class="form-input" type="date" name="audit_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <label style="display:block;margin-top:6px;">Profil 10 Menit</label>
                            <input class="form-input" type="number" id="audit_prof10_qty" name="audit_qty_10" min="0" value="0" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block;margin-top:6px;">Profil 30 Menit</label>
                            <input class="form-input" type="number" id="audit_prof30_qty" name="audit_qty_30" min="0" value="0" required>
                        </div>
                    </div>
                    <div class="modal-note">Isi sesuai data dari lapangan.</div>
                </div>
                <div class="form-grid-2" style="margin-top:10px;">
                    <div>
                        <label>Total Qty (otomatis)</label>
                        <input class="form-input" type="number" name="audit_qty" min="0" value="0" readonly>
                    </div>
                    <div>
                        <label>Total Setoran (otomatis)</label>
                        <input class="form-input" type="number" name="audit_setoran" min="0" value="0" readonly>
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <label>Username Yang Tidak Dilaporkan</label>
                    <input type="hidden" name="audit_username" id="auditUsernameHidden">
                    <div class="audit-user-picker">
                        <div id="audit-user-chips" class="audit-user-chips"></div>
                        <input class="form-input" type="text" id="audit-user-input" placeholder="Rusak & Retur Automatis By System">
                        <div id="audit-user-suggest" class="audit-user-suggest"></div>
                    </div>
                </div>
                <div id="auditClientError" style="display:none;margin-top:8px;color:#fca5a5;font-size:12px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-print" onclick="closeAuditModal()">Batal</button>
                <button type="submit" id="auditSubmitBtn" name="audit_submit" class="btn-print">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
    var settlementTimer = null;
    var hpDeleteUrl = '';
    var settlementLastFetch = 0;
    var auditUserOptions = <?= json_encode($audit_user_options ?? []); ?>;
    var auditSelectedUsers = [];
    window.auditEditing = false;
    function formatDateDMY(dateStr){
        if (!dateStr) return '-';
        var m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return dateStr;
        return m[3] + '-' + m[2] + '-' + m[1];
    }
    function closeDeleteHpModal(){
        var modal = document.getElementById('hp-delete-modal');
        if (modal) modal.style.display = 'none';
    }
    function confirmDeleteHpModal(){
        if (!hpDeleteUrl) return closeDeleteHpModal();
        window.location.href = hpDeleteUrl;
    }
    function openDeleteHpModal(url, blok, date){
        hpDeleteUrl = url || '';
        var modal = document.getElementById('hp-delete-modal');
        var text = document.getElementById('hp-delete-text');
        var dateText = formatDateDMY(date || '');
        if (text) text.textContent = 'Hapus data Blok ' + (blok || '-') + ' tanggal ' + (dateText || '-') + '?';
        if (modal) modal.style.display = 'flex';
    }
    function manualSettlement(){
        var btn = document.getElementById('btn-settlement');
        if (!btn || btn.disabled) return;
        var modal = document.getElementById('settlement-modal');
        var logBox = document.getElementById('settlement-log');
        var logWrap = document.getElementById('settlement-log-wrap');
        var footer = document.getElementById('settlement-footer');
        var statusEl = document.getElementById('settlement-status');
        var processEl = document.getElementById('processStatus');
        var closeBtn = document.getElementById('settlement-close');
        var confirmBox = document.getElementById('settlement-confirm');
        var startBtn = document.getElementById('settlement-start');
        var cancelBtn = document.getElementById('settlement-cancel');
        window.settleDone = false;
        if (window.settleTimer) { clearInterval(window.settleTimer); window.settleTimer = null; }
        window.settleQueue = [];
        window.settleSeen = {};
        window.settleInfoShown = false;
        window.settleStatus = '';
        window.settleFastMode = false;
        updateSettlementCloseState();
        if (modal) modal.style.display = 'flex';
        if (logBox) logBox.innerHTML = '';
        if (logWrap) logWrap.style.display = 'none';
        if (footer) footer.style.display = 'none';
        if (statusEl) statusEl.textContent = 'Menunggu konfirmasi';
        if (processEl) processEl.innerHTML = '<i class="fa fa-refresh"></i> Menunggu proses...';
        if (closeBtn) {
            closeBtn.disabled = true;
            closeBtn.style.opacity = '0.6';
            closeBtn.style.cursor = 'not-allowed';
        }
        if (confirmBox) confirmBox.style.display = 'flex';
        if (cancelBtn) {
            cancelBtn.disabled = false;
            cancelBtn.style.opacity = '1';
            cancelBtn.style.cursor = 'pointer';
            cancelBtn.onclick = function(){
                if (modal) modal.style.display = 'none';
            };
        }
        if (startBtn) {
            startBtn.onclick = function(){
                if (confirmBox) confirmBox.style.display = 'none';
                if (logWrap) logWrap.style.display = 'block';
                if (footer) footer.style.display = 'flex';
                if (statusEl) statusEl.textContent = 'Menjalankan settlement...';
                if (processEl) processEl.innerHTML = '<i class="fa fa-refresh fa-spin"></i> Menghubungkan ke MikroTik...';
                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.style.cursor = 'not-allowed';
                if (closeBtn) {
                    closeBtn.disabled = true;
                    closeBtn.style.opacity = '0.6';
                    closeBtn.style.cursor = 'not-allowed';
                }
                if (cancelBtn) {
                    cancelBtn.disabled = true;
                    cancelBtn.style.opacity = '0.6';
                    cancelBtn.style.cursor = 'not-allowed';
                }
                if (logBox) {
                    logBox.innerHTML = '<span class="cursor-blink"></span>';
                }
                enqueueSettlementLogs([
                    { time: '', topic: 'system,info', type: 'info', message: 'Sabar, sedang mengambil log settlement...' }
                ]);
                var params = new URLSearchParams();
                params.set('session', '<?= htmlspecialchars($session_id); ?>');
                params.set('date', '<?= htmlspecialchars($filter_date); ?>');
                params.set('action', 'start');
                // Mulai polling lebih dulu agar log tampil real-time walau start request lambat
                pollSettlementLogs();
                fetch('report/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (!data || !data.ok) {
                            if (statusEl) statusEl.textContent = (data && data.message) ? data.message : 'Settlement gagal.';
                            btn.disabled = false;
                            btn.style.opacity = '1';
                            btn.style.cursor = 'pointer';
                            if (cancelBtn) {
                                cancelBtn.disabled = false;
                                cancelBtn.style.opacity = '0.8';
                                cancelBtn.style.cursor = 'pointer';
                            }
                            return;
                        }
                        // polling sudah berjalan
                    })
                    .catch(function(){
                        if (statusEl) statusEl.textContent = 'Settlement gagal.';
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                        if (cancelBtn) {
                            cancelBtn.disabled = false;
                            cancelBtn.style.opacity = '0.8';
                            cancelBtn.style.cursor = 'pointer';
                        }
                    });
            };
        }
    }

    function pollSettlementLogs(){
        var logBox = document.getElementById('settlement-log');
        var statusEl = document.getElementById('settlement-status');
        var processEl = document.getElementById('processStatus');
        var closeBtn = document.getElementById('settlement-close');
        var params = new URLSearchParams();
        params.set('session', '<?= htmlspecialchars($session_id); ?>');
        params.set('date', '<?= htmlspecialchars($filter_date); ?>');
        params.set('action', 'logs');
        params.set('_', Date.now().toString());
        fetch('report/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-store' }, cache: 'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data && Array.isArray(data.logs) && logBox) {
                    enqueueSettlementLogs(data.logs);
                }
                if (data && data.info_message) {
                    if (!window.settleInfoShown) {
                        window.settleInfoShown = true;
                        enqueueSettlementLogs([
                            { time: '', topic: 'system,info', type: 'info', message: data.info_message }
                        ]);
                    }
                }
                if (data && data.status) {
                    window.settleStatus = data.status;
                    updateSettlementStatus();
                }
                if (data && data.status === 'done') {
                    window.settleDone = true;
                    updateSettlementCloseState();
                    updateSettlementStatus();
                    softReloadSelling();
                    clearTimeout(settlementTimer);
                    return;
                }
                if (data && data.status === 'failed') {
                    window.settleDone = true;
                    updateSettlementCloseState();
                    updateSettlementStatus();
                    clearTimeout(settlementTimer);
                    return;
                }
                settlementTimer = setTimeout(pollSettlementLogs, 600);
            })
            .catch(function(){
                settlementTimer = setTimeout(pollSettlementLogs, 800);
            });
    }

    function openSettlementResetModal(){
        var modal = document.getElementById('settlement-reset-modal');
        if (modal) modal.style.display = 'flex';
    }
    function closeSettlementResetModal(){
        var modal = document.getElementById('settlement-reset-modal');
        if (modal) modal.style.display = 'none';
    }
    function confirmSettlementReset(){
        var params = new URLSearchParams();
        params.set('session', '<?= htmlspecialchars($session_id); ?>');
        params.set('date', '<?= htmlspecialchars($filter_date); ?>');
        params.set('action', 'reset');
        fetch('report/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(){
                window.location.reload();
            })
            .catch(function(){
                window.location.reload();
            });
    }

    function closeSettlementModal(){
        var closeBtn = document.getElementById('settlement-close');
        if (closeBtn && closeBtn.disabled) return;
        var modal = document.getElementById('settlement-modal');
        if (modal) modal.style.display = 'none';
    }

    function updateSettlementCloseState(){
        var closeBtn = document.getElementById('settlement-close');
        if (!closeBtn) return;
        var canClose = !!window.settleDone && !window.settleTyping && (!window.settleQueue || window.settleQueue.length === 0);
        if (canClose) {
            closeBtn.disabled = false;
            closeBtn.removeAttribute('disabled');
            closeBtn.style.opacity = '1';
            closeBtn.style.cursor = 'pointer';
        } else {
            closeBtn.disabled = true;
            closeBtn.style.opacity = '0.6';
            closeBtn.style.cursor = 'not-allowed';
        }
    }

    function updateSettlementStatus(){
        var statusEl = document.getElementById('settlement-status');
        var processEl = document.getElementById('processStatus');
        if (!window.settleStatus) return;
        var ready = !!window.settleDone && !window.settleTyping && (!window.settleQueue || window.settleQueue.length === 0);
        if (!ready) {
            if (statusEl) statusEl.textContent = 'Berjalan';
            if (processEl) processEl.innerHTML = '<i class="fa fa-refresh fa-spin"></i> Sedang memproses...';
            return;
        }
        if (window.settleStatus === 'done') {
            if (statusEl) statusEl.textContent = 'Selesai';
            if (processEl) processEl.innerHTML = '<i class="fa fa-check-circle"></i> Selesai';
            if (!window.settleFinalInfoShown) {
                enqueueSettlementLogs([
                    { time: '', topic: 'system,info', type: 'system', message: 'Semua proses selesai. Silakan tutup terminal.' }
                ]);
                window.settleFinalInfoShown = true;
            }
        } else if (window.settleStatus === 'failed') {
            if (statusEl) statusEl.textContent = 'Gagal';
            if (processEl) processEl.innerHTML = '<i class="fa fa-times-circle"></i> Gagal';
        }
    }

    function enqueueSettlementLogs(logs){
        if (!window.settleQueue) window.settleQueue = [];
        if (!window.settleSeen) window.settleSeen = {};
        var newItems = 0;
        logs.forEach(function(row){
            if (!row) return;
            var key = [row.time || '', row.topic || '', row.message || ''].join('|');
            if (window.settleSeen[key]) return;
            window.settleSeen[key] = true;
            window.settleQueue.push(row);
            newItems++;
        });
        if (window.settleQueue.length > 100) {
            window.settleFastMode = true;
        }
        if (!window.settleTimer) {
            window.settleTimer = setInterval(renderSettlementLogItem, 100);
        }
    }

    function renderSettlementLogItem(){
        if (window.settleTyping && !window.settleFastMode) return;
        if (!window.settleQueue || window.settleQueue.length === 0) {
            if (window.settleDone) {
                updateSettlementStatus();
                clearInterval(window.settleTimer);
                window.settleTimer = null;
                updateSettlementCloseState();
            }
            return;
        }
        var logBox = document.getElementById('settlement-log');
        if (!logBox) return;
        var row = window.settleQueue.shift();
        var t = row.time || '';
        var topic = row.topic || 'system,info';
        var msg = row.message || '';
        var cls = row.type || 'info';
        var line = document.createElement('div');
        line.className = 'log-entry';
        var promptSpan = document.createElement('span');
        promptSpan.className = 'log-prompt';
        promptSpan.textContent = '> ';
        var timeSpan = document.createElement('span');
        timeSpan.className = 'log-time';
        timeSpan.textContent = t ? (String(t) + ' ') : '';
        var topicSpan = document.createElement('span');
        topicSpan.className = 'log-topic';
        topicSpan.textContent = topic ? (String(topic) + ' ') : '';
        var msgSpan = document.createElement('span');
        msgSpan.className = 'log-' + String(cls).replace(/[^a-z]/gi,'');
        msgSpan.textContent = '';
        line.appendChild(promptSpan);
        line.appendChild(timeSpan);
        line.appendChild(topicSpan);
        line.appendChild(msgSpan);
        var cursor = logBox.querySelector('.cursor-blink');
        if (cursor) cursor.remove();
        logBox.appendChild(line);
        logBox.scrollTop = logBox.scrollHeight;
        if (window.settleFastMode) {
            msgSpan.textContent = String(msg);
            var fastCursor = document.createElement('span');
            fastCursor.className = 'cursor-blink';
            logBox.appendChild(fastCursor);
            logBox.scrollTop = logBox.scrollHeight;
            updateSettlementCloseState();
            updateSettlementStatus();
            return;
        }
        window.settleTyping = true;
        var lineDelay = 150;
        setTimeout(function(){
            typeSettlementMessage(msgSpan, String(msg), 15, function(){
                window.settleTyping = false;
                var newCursor = document.createElement('span');
                newCursor.className = 'cursor-blink';
                logBox.appendChild(newCursor);
                logBox.scrollTop = logBox.scrollHeight;
                updateSettlementCloseState();
                updateSettlementStatus();
            });
        }, lineDelay);
    }

    function typeSettlementMessage(target, text, speed, done){
        var i = 0;
        var len = text.length;
        function typeChar(){
            if (i >= len) {
                if (done) done();
                return;
            }
            target.textContent += text.charAt(i);
            i += 1;
            setTimeout(typeChar, speed);
        }
        typeChar();
    }

    (function(){
        var closeBtn = document.getElementById('settlement-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(){
                if (closeBtn.disabled) return;
                var modal = document.getElementById('settlement-modal');
                if (modal) modal.style.display = 'none';
            });
        }
    })();
    (function(){
        var modal = document.getElementById('hp-delete-modal');
        var closeBtn = document.getElementById('hp-delete-close');
        var cancelBtn = document.getElementById('hp-delete-cancel');
        var confirmBtn = document.getElementById('hp-delete-confirm');
        var close = function(){ if (modal) modal.style.display = 'none'; };
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (cancelBtn) cancelBtn.addEventListener('click', close);
        if (confirmBtn) confirmBtn.addEventListener('click', function(){
            if (!hpDeleteUrl) return close();
            window.location.href = hpDeleteUrl;
        });
    })();
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
                window.sellingPauseReload = true;
                var fd = new FormData(form);
                fd.append('ajax', '1');
                fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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

    function openHpModal(){
        var modal = document.getElementById('hpModal');
        if (modal) modal.style.display = 'flex';
        window.sellingPauseReload = true;
    }

    function closeHpModal(){
        var modal = document.getElementById('hpModal');
        if (modal) modal.style.display = 'none';
        window.sellingPauseReload = false;
    }

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

        openHpModal();
    };

    function openAuditModal(){
        var modal = document.getElementById('auditModal');
        if (modal) modal.style.display = 'flex';
        window.sellingPauseReload = true;
        if (!window.auditEditing && typeof resetAuditUserPicker === 'function') {
            resetAuditUserPicker();
        }
    }

    function closeAuditModal(){
        var modal = document.getElementById('auditModal');
        if (modal) modal.style.display = 'none';
        window.sellingPauseReload = false;
        window.auditEditing = false;
    }

    window.openAuditEdit = function(btn){
        var form = document.getElementById('auditForm');
        if (!form || !btn) return;
        window.auditEditing = true;
        var blok = btn.getAttribute('data-blok') || '';
        var date = btn.getAttribute('data-date') || '';
        var user = btn.getAttribute('data-user') || '';
        var qty = btn.getAttribute('data-qty') || '0';
        var setoran = btn.getAttribute('data-setoran') || '0';
        var qty10 = btn.getAttribute('data-qty10') || '0';
        var qty30 = btn.getAttribute('data-qty30') || '0';
        var blokSelect = form.querySelector('select[name="audit_blok"]');
        if (blokSelect) blokSelect.value = blok;
        var dateInput = form.querySelector('input[name="audit_date"]');
        if (dateInput) dateInput.value = date;
        if (typeof setAuditUserPicker === 'function') {
            setAuditUserPicker(user);
        }
        var qtyInput = form.querySelector('input[name="audit_qty"]');
        if (qtyInput) qtyInput.value = qty;
        var setInput = form.querySelector('input[name="audit_setoran"]');
        if (setInput) setInput.value = setoran;
        var qty10Input = form.querySelector('input[name="audit_qty_10"]');
        if (qty10Input) qty10Input.value = qty10;
        var qty30Input = form.querySelector('input[name="audit_qty_30"]');
        if (qty30Input) qty30Input.value = qty30;
        if (qty10Input || qty30Input) {
            var ev = new Event('input', { bubbles: true });
            if (qty10Input) qty10Input.dispatchEvent(ev);
            if (qty30Input) qty30Input.dispatchEvent(ev);
        }
        openAuditModal();
    };

    function closeDeleteAuditModal(){
        var modal = document.getElementById('audit-delete-modal');
        if (modal) modal.style.display = 'none';
    }
    function confirmDeleteAuditModal(){
        if (!window.auditDeleteUrl) return closeDeleteAuditModal();
        window.location.href = window.auditDeleteUrl;
    }
    function openDeleteAuditModal(url, blok, date){
        window.auditDeleteUrl = url || '';
        var modal = document.getElementById('audit-delete-modal');
        var text = document.getElementById('audit-delete-text');
        var dateText = formatDateDMY(date || '');
        if (text) text.textContent = 'Hapus audit Blok ' + (blok || '-') + ' tanggal ' + (dateText || '-') + '?';
        if (modal) modal.style.display = 'flex';
    }

    (function(){
        var form = document.getElementById('auditForm');
        var btn = document.getElementById('auditSubmitBtn');
        var err = document.getElementById('auditClientError');
        var qty10 = document.getElementById('audit_prof10_qty');
        var qty30 = document.getElementById('audit_prof30_qty');
        var qtyTotal = form ? form.querySelector('input[name="audit_qty"]') : null;
        var setoranTotal = form ? form.querySelector('input[name="audit_setoran"]') : null;
        var price10 = 5000;
        var price30 = 20000;

        function updateAuditTotals(){
            var v10 = qty10 ? parseInt(qty10.value || '0', 10) : 0;
            var v30 = qty30 ? parseInt(qty30.value || '0', 10) : 0;
            var sumQty = v10 + v30;
            var sumRp = (v10 * price10) + (v30 * price30);
            if (qtyTotal) qtyTotal.value = sumQty;
            if (setoranTotal) setoranTotal.value = sumRp;
        }
        if (qty10) qty10.addEventListener('input', updateAuditTotals);
        if (qty30) qty30.addEventListener('input', updateAuditTotals);
        updateAuditTotals();
        if (!form) return;
        form.addEventListener('submit', function(e){
            e.preventDefault();
            if (btn && btn.disabled) return;
            if (err) err.style.display = 'none';
            var qtyInput = form.querySelector('input[name="audit_qty"]');
            var totalQty = qtyInput ? parseInt(qtyInput.value || '0', 10) : 0;
            var v10 = qty10 ? parseInt(qty10.value || '0', 10) : 0;
            var v30 = qty30 ? parseInt(qty30.value || '0', 10) : 0;
            var sumQty = v10 + v30;
            var hasUsers = auditSelectedUsers && auditSelectedUsers.length > 0;
            if (totalQty <= 0) {
                if (err) {
                    err.textContent = 'Qty per profile wajib diisi.';
                    err.style.display = 'block';
                }
                return;
            }
            if (!hasUsers && sumQty <= 0) {
                if (err) {
                    err.textContent = 'Qty per profile wajib diisi.';
                    err.style.display = 'block';
                }
                return;
            }
            if (sumQty > 0 && sumQty !== totalQty) {
                if (err) {
                    err.textContent = 'Qty per profile harus sama dengan Qty Manual.';
                    err.style.display = 'block';
                }
                return;
            }
            window.sellingPauseReload = true;
            var fd = new FormData(form);
            fd.append('ajax', '1');
            fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r){ return r.text(); })
                .then(function(text){
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    if (data && data.ok && data.redirect) {
                        window.location.replace(data.redirect);
                        return;
                    }
                    var msg = (data && data.message) ? data.message : 'Respon tidak valid dari server.';
                    if (err) {
                        err.textContent = msg;
                        err.style.display = 'block';
                    }
                })
                .catch(function(){
                    if (err) {
                        err.textContent = 'Gagal mengirim data. Coba lagi.';
                        err.style.display = 'block';
                    }
                });
        });
    })();

    function renderAuditSelected(){
        var chipWrap = document.getElementById('audit-user-chips');
        var hidden = document.getElementById('auditUsernameHidden');
        if (!chipWrap || !hidden) return;
        chipWrap.innerHTML = '';
        var list = auditSelectedUsers.slice();
        list.forEach(function(u){
            var chip = document.createElement('span');
            chip.className = 'audit-user-chip';
            chip.textContent = u;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '×';
            btn.onclick = function(){ removeAuditUser(u); };
            chip.appendChild(btn);
            chipWrap.appendChild(chip);
        });
        hidden.value = list.join(', ');
    }

    function addAuditUser(u){
        u = String(u || '').trim();
        if (!u) return;
        if (auditSelectedUsers.indexOf(u) !== -1) return;
        auditSelectedUsers.push(u);
        renderAuditSelected();
    }

    function removeAuditUser(u){
        auditSelectedUsers = auditSelectedUsers.filter(function(x){ return x !== u; });
        renderAuditSelected();
    }

    function setAuditUserPicker(raw){
        auditSelectedUsers = [];
        var arr = String(raw || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        arr.forEach(addAuditUser);
        renderAuditSelected();
    }

    function resetAuditUserPicker(){
        auditSelectedUsers = [];
        renderAuditSelected();
        var input = document.getElementById('audit-user-input');
        if (input) input.value = '';
        hideAuditSuggest();
    }

    function showAuditSuggest(items){
        var box = document.getElementById('audit-user-suggest');
        if (!box) return;
        box.innerHTML = '';
        if (!items || !items.length) {
            box.style.display = 'none';
            return;
        }
        items.forEach(function(u){
            var el = document.createElement('div');
            el.className = 'item';
            el.textContent = u;
            el.onclick = function(){
                addAuditUser(u);
                var input = document.getElementById('audit-user-input');
                if (input) input.value = '';
                hideAuditSuggest();
            };
            box.appendChild(el);
        });
        box.style.display = 'block';
    }

    function hideAuditSuggest(){
        var box = document.getElementById('audit-user-suggest');
        if (box) box.style.display = 'none';
    }

    (function(){
        var input = document.getElementById('audit-user-input');
        if (!input) return;
        input.addEventListener('input', function(){
            var q = String(input.value || '').toLowerCase().trim();
            if (!q) return hideAuditSuggest();
            var items = (auditUserOptions || []).filter(function(u){
                return u.toLowerCase().indexOf(q) !== -1 && auditSelectedUsers.indexOf(u) === -1;
            }).slice(0, 12);
            showAuditSuggest(items);
        });
        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                var q = String(input.value || '').trim();
                if (!q) return;
                var exact = (auditUserOptions || []).find(function(u){ return u.toLowerCase() === q.toLowerCase(); });
                if (exact) {
                    addAuditUser(exact);
                    input.value = '';
                    hideAuditSuggest();
                }
            }
        });
        document.addEventListener('click', function(e){
            var box = document.getElementById('audit-user-suggest');
            var wrap = document.querySelector('.audit-user-picker');
            if (!box || !wrap) return;
            if (!wrap.contains(e.target)) hideAuditSuggest();
        });
    })();
</script>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="selling-content">
<?php endif; ?>

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
            <?php endif; ?>
            <button class="btn-print" type="button" onclick="openHpModal()">Input HP Blok</button>
            <?php if ($req_show === 'harian'): ?>
                <button class="btn-print" type="button" onclick="openAuditModal()">Audit Manual</button>
            <?php endif; ?>
            <button class="btn-print" type="button" id="btn-settlement" onclick="manualSettlement()" <?= (!empty($settled_today) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '') ?>>Settlement</button>
            <?php if (!empty($settled_today)): ?>
                <button class="btn-print" type="button" id="btn-settlement-reset" onclick="openSettlementResetModal()" style="background:#ff9800;color:#fff;">Reset</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding:16px;">
        <?php if (!empty($no_sales_message)): ?>
            <div style="background:#2b2b2b;border:1px solid #555;border-radius:6px;padding:10px 12px;margin-bottom:12px;color:#f3c969;">
                <i class="fa fa-info-circle"></i> <?= htmlspecialchars($no_sales_message); ?>
            </div>
        <?php elseif (!empty($auto_date_applied)): ?>
            <div style="background:#2b2b2b;border:1px solid #555;border-radius:6px;padding:10px 12px;margin-bottom:12px;color:#8bd0ff;">
                <i class="fa fa-info-circle"></i> Menampilkan data terakhir: <?= htmlspecialchars($filter_date); ?>
            </div>
        <?php endif; ?>
        <div class="summary-grid">
            <?php
                $audit_qty_cls = $audit_total_selisih_qty > 0 ? 'audit-pos' : ($audit_total_selisih_qty < 0 ? 'audit-neg' : 'audit-zero');
                $audit_setoran_cls = $audit_total_selisih_setoran > 0 ? 'audit-pos' : ($audit_total_selisih_setoran < 0 ? 'audit-neg' : 'audit-zero');
            ?>
            <div class="summary-card">
                <div class="summary-title">Gross Income</div>
                <div class="summary-value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Voucher Rusak</div>
                <div class="summary-value" ><span style="color: crimson;"><?= number_format($total_qty_rusak,0,',','.') ?></span></div>
                <div style="font-size:12px;color:var(--txt-muted)">10 Menit: <?= number_format($rusak_10m,0,',','.') ?> | 30 Menit: <?= number_format($rusak_30m,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Device</div>
                <div style="margin-top:7px; margin-bottom:10px;">
                    <span class="summary-badge badge-wartel">WARTEL: <?= number_format($hp_wartel_units,0,',','.') ?></span>
                    <span class="summary-badge badge-kamtib">KAMTIB: <?= number_format($hp_kamtib_units,0,',','.') ?></span>
                </div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top:6px;">
                    Total: <span style="color:#f39c12;"><?= number_format($hp_total_units,0,',','.') ?></span> | Aktif: <span class="text-green"><?= number_format($hp_active_units,0,',','.') ?></span> | Rusak: <?= number_format($hp_rusak_units,0,',','.') ?> | Spam: <?= number_format($hp_spam_units,0,',','.') ?>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Voucher Terjual</div>
                <div class="summary-value"><?= number_format($total_qty_laku,0,',','.') ?></div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top: 1px;">Rusak: <?= number_format($total_qty_rusak,0,',','.') ?> | Retur: <?= number_format($total_qty_retur,0,',','.') ?> | Bandwidth: <?= htmlspecialchars(format_bytes_short($total_bandwidth)) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Net Income</div>
                <div class="summary-value" style="color:#2ecc71;"><?= $cur ?> <?= number_format($total_net,0,',','.') ?></div>
            </div>
        </div>
        <div id="settlement-time" style="margin-top:12px;font-size:12px;color:var(--txt-muted);">
            Settlement terakhir: <?= $settlement_time ? date('d-m-Y H:i:s', strtotime($settlement_time)) : '-' ?>
        </div>
    </div>
</div>


<?php
$hp_rows = [];
$hp_rows_total = [];
$hp_summary = [];
if (isset($db) && $db instanceof PDO && $req_show === 'harian') {
        try {
                // Auto-carry over data HP dari tanggal terakhir jika tanggal ini kosong
                $stmtCount = $db->prepare("SELECT COUNT(*) FROM phone_block_daily WHERE report_date = :d");
                $stmtCount->execute([':d' => $filter_date]);
                $hasRows = (int)$stmtCount->fetchColumn();
                if ($hasRows === 0) {
                    $stmtLast = $db->prepare("SELECT MAX(report_date) FROM phone_block_daily WHERE report_date < :d");
                    $stmtLast->execute([':d' => $filter_date]);
                    $lastDate = $stmtLast->fetchColumn();
                    if ($lastDate) {
                        $db->beginTransaction();
                        $stmtSrc = $db->prepare("SELECT blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes
                            FROM phone_block_daily WHERE report_date = :d");
                        $stmtSrc->execute([':d' => $lastDate]);
                        $stmtIns = $db->prepare("INSERT OR IGNORE INTO phone_block_daily
                            (report_date, blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes, updated_at)
                            VALUES (:rd, :bn, :ut, :t, :a, :r, :s, :n, CURRENT_TIMESTAMP)");
                        while ($row = $stmtSrc->fetch(PDO::FETCH_ASSOC)) {
                            $stmtIns->execute([
                                ':rd' => $filter_date,
                                ':bn' => $row['blok_name'],
                                ':ut' => $row['unit_type'],
                                ':t' => (int)($row['total_units'] ?? 0),
                                ':a' => (int)($row['active_units'] ?? 0),
                                ':r' => (int)($row['rusak_units'] ?? 0),
                                ':s' => (int)($row['spam_units'] ?? 0),
                                ':n' => (string)($row['notes'] ?? '')
                            ]);
                        }
                        $db->commit();
                    }
                }

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
            <table class="table-dark-solid text-nowrap audit-table">
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
                                    <button type="button" class="btn-act btn-act-danger" onclick="openDeleteHpModal('<?= './?report=selling' . $session_qs . '&show=' . $req_show . '&date=' . urlencode($filter_date) . '&hp_delete=1&blok=' . urlencode($bname) . '&hp_date=' . urlencode($filter_date); ?>','<?= htmlspecialchars($bname); ?>','<?= htmlspecialchars($filter_date); ?>')">
                                        <i class="fa fa-trash"></i>
                                    </button>
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

<?php if (!empty($audit_error)): ?>
    <div class="card-solid mb-3">
        <div class="card-body" style="padding:12px;color:#fca5a5;">
            <?= htmlspecialchars($audit_error); ?>
        </div>
    </div>
<?php endif; ?>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-check-square-o mr-2"></i> Audit Manual Rekap (Harian)</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th>Blok</th>
                        <th class="text-center">QTY</th>
                        <th class="text-center">Selisih</th>
                        <th class="text-right">Setoran</th>
                        <th class="text-center">Selisih</th>
                        <th class="text-center">Rusak</th>
                        <th class="text-center">Retur</th>
                        <th class="text-center">QTY 10</th>
                        <th class="text-center">QTY 30</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_rows)): ?>
                        <tr><td colspan="10" style="text-align:center;color:var(--txt-muted);padding:30px;">Belum ada audit manual.</td></tr>
                    <?php else: 
                        $price10 = 5000;
                        $price30 = 20000;
                        $audit_manual_qty_display_total = 0;
                        $audit_manual_setoran_display_total = 0;
                        $audit_system_qty_display_total = 0;
                        $audit_system_setoran_display_total = 0;
                        foreach ($audit_rows as $ar): ?>
                        <?php
                            $sq = (int)($ar['selisih_qty'] ?? 0);
                            $ss = (int)($ar['selisih_setoran'] ?? 0);
                            $audit_date_row = $ar['report_date'] ?? $filter_date;
                            $audit_block_row = normalize_block_name($ar['blok_name'] ?? '');
                            $sys_rusak = (int)($by_block[$ar['blok_name']]['rusak_qty'] ?? 0);
                            $sys_retur = (int)($by_block[$ar['blok_name']]['retur'] ?? 0);
                            $expected_qty = (int)($ar['expected_qty'] ?? 0);
                            $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                            if ($audit_date_row !== '') {
                                $rows_src = !empty($rows) ? $rows : (isset($db) && $db instanceof PDO ? fetch_rows_for_audit($db, $audit_date_row) : []);
                                if (!empty($rows_src)) {
                                    $expected = calc_expected_for_block($rows_src, $audit_date_row, $audit_block_row);
                                    $expected_qty = (int)($expected['qty'] ?? 0);
                                    $expected_setoran = (int)($expected['net'] ?? 0);
                                    $sys_rusak = (int)($expected['rusak_qty'] ?? $sys_rusak);
                                    $sys_retur = (int)($expected['retur_qty'] ?? $sys_retur);
                                }
                            }
                            $cls_q = $sq > 0 ? 'audit-pos' : ($sq < 0 ? 'audit-neg' : 'audit-zero');
                            $cls_s = $ss > 0 ? 'audit-pos' : ($ss < 0 ? 'audit-neg' : 'audit-zero');
                            $evidence = [];
                            $profile10 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile30 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile10_sum = 0;
                            $profile30_sum = 0;
                            $profile_qty_10 = 0;
                            $profile_qty_30 = 0;
                            $manual_rusak_10 = 0;
                            $manual_rusak_30 = 0;
                            $manual_retur_10 = 0;
                            $manual_retur_30 = 0;
                            $manual_invalid_10 = 0;
                            $manual_invalid_30 = 0;
                            $has_manual_evidence = false;
                            if (!empty($ar['user_evidence'])) {
                                $evidence = json_decode((string)$ar['user_evidence'], true);
                                if (is_array($evidence)) {
                                    $has_manual_evidence = true;
                                    if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                                        $profile_qty_10 = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                                        $profile_qty_30 = (int)($evidence['profile_qty']['qty_30'] ?? 0);
                                    }
                                    if (!empty($evidence['users']) && is_array($evidence['users'])) {
                                        foreach ($evidence['users'] as $uname => $ud) {
                                            $cnt = isset($ud['events']) && is_array($ud['events']) ? count($ud['events']) : 0;
                                            $upt = trim((string)($ud['last_uptime'] ?? ''));
                                            $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                            $price_val = (int)($ud['price'] ?? 0);
                                            $upt = $upt !== '' ? $upt : '-';
                                            $kind = (string)($ud['profile_kind'] ?? '10');
                                            $u_status = strtolower((string)($ud['last_status'] ?? ''));
                                            $bucket = ($kind === '30') ? $profile30 : $profile10;
                                            $bucket['user'][] = (string)$uname;
                                            $bucket['up'][] = $upt;
                                            $bucket['byte'][] = $lb;
                                            $bucket['login'][] = $cnt . 'x';
                                            $bucket['total'][] = number_format($price_val,0,',','.');
                                            if ($kind === '30') {
                                                $profile30_sum += $price_val;
                                                $profile30 = $bucket;
                                                if ($u_status === 'rusak') $manual_rusak_30++;
                                                elseif ($u_status === 'retur') $manual_retur_30++;
                                                elseif ($u_status === 'invalid') $manual_invalid_30++;
                                            } else {
                                                $profile10_sum += $price_val;
                                                $profile10 = $bucket;
                                                if ($u_status === 'rusak') $manual_rusak_10++;
                                                elseif ($u_status === 'retur') $manual_retur_10++;
                                                elseif ($u_status === 'invalid') $manual_invalid_10++;
                                            }
                                        }
                                    } else {
                                        $cnt = isset($evidence['events']) && is_array($evidence['events']) ? count($evidence['events']) : 0;
                                        $upt = trim((string)($evidence['last_uptime'] ?? ''));
                                        $lb = format_bytes_short((int)($evidence['last_bytes'] ?? 0));
                                        $price_val = (int)($evidence['price'] ?? 0);
                                        $upt = $upt !== '' ? $upt : '-';
                                        $profile10['user'][] = '-';
                                        $profile10['up'][] = $upt;
                                        $profile10['byte'][] = $lb;
                                        $profile10['login'][] = $cnt . 'x';
                                        $profile10['total'][] = number_format($price_val,0,',','.');
                                        $profile10_sum += $price_val;
                                    }
                                }
                            }
                            if ($profile_qty_10 <= 0) $profile_qty_10 = count($profile10['user'] ?? []);
                            if ($profile_qty_30 <= 0) $profile_qty_30 = count($profile30['user'] ?? []);
                            $manual_net_qty_10 = max(0, $profile_qty_10 - $manual_rusak_10 - $manual_invalid_10 + $manual_retur_10);
                            $manual_net_qty_30 = max(0, $profile_qty_30 - $manual_rusak_30 - $manual_invalid_30 + $manual_retur_30);
                            $manual_display_qty = $has_manual_evidence ? ($manual_net_qty_10 + $manual_net_qty_30) : (int)($ar['reported_qty'] ?? 0);
                            $manual_display_setoran = $has_manual_evidence ? (($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30)) : (int)($ar['actual_setoran'] ?? 0);
                            $expected_adj_qty = $expected_qty;
                            $expected_adj_setoran = $expected_setoran;
                            if ($has_manual_evidence) {
                                $expected_adj_qty = max(0, $expected_qty - $manual_rusak_10 - $manual_rusak_30 - $manual_invalid_10 - $manual_invalid_30 + $manual_retur_10 + $manual_retur_30);
                                $expected_adj_setoran = max(0, $expected_setoran
                                    - (($manual_rusak_10 + $manual_invalid_10) * $price10)
                                    - (($manual_rusak_30 + $manual_invalid_30) * $price30)
                                    + ($manual_retur_10 * $price10)
                                    + ($manual_retur_30 * $price30));
                            }
                            $sq = $manual_display_qty - $expected_adj_qty;
                            $ss = $manual_display_setoran - $expected_adj_setoran;
                            $cls_q = $sq > 0 ? 'audit-pos' : ($sq < 0 ? 'audit-neg' : 'audit-zero');
                            $cls_s = $ss > 0 ? 'audit-pos' : ($ss < 0 ? 'audit-neg' : 'audit-zero');
                            $audit_manual_qty_display_total += $manual_display_qty;
                            $audit_manual_setoran_display_total += $manual_display_setoran;
                            $audit_system_qty_display_total += $expected_adj_qty;
                            $audit_system_setoran_display_total += $expected_adj_setoran;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($ar['blok_name'] ?? '-') ?></td>
                            <td class="text-center"><?= number_format($manual_display_qty,0,',','.') ?></td>
                            <td class="text-center"><span class="<?= $cls_q; ?>"><?= number_format($sq,0,',','.') ?></span></td>
                            <td class="text-right"><?= number_format($manual_display_setoran,0,',','.') ?></td>
                            <td class="text-center"><span class="<?= $cls_s; ?>"><?= number_format($ss,0,',','.') ?></span></td>
                            <td class="text-center"><small><?= number_format($sys_rusak,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format($sys_retur,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format($profile_qty_10,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format($profile_qty_30,0,',','.') ?></small></td>
                            <td class="text-right">
                                <button type="button" class="btn-act" onclick="openAuditEdit(this)"
                                    data-blok="<?= htmlspecialchars($ar['blok_name'] ?? ''); ?>"
                                    data-user="<?= htmlspecialchars($ar['audit_username'] ?? ''); ?>"
                                    data-date="<?= htmlspecialchars($ar['report_date'] ?? $filter_date); ?>"
                                    data-qty="<?= (int)($ar['reported_qty'] ?? 0); ?>"
                                    data-setoran="<?= (int)($ar['actual_setoran'] ?? 0); ?>"
                                    data-qty10="<?= (int)$profile_qty_10; ?>"
                                    data-qty30="<?= (int)$profile_qty_30; ?>">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <button type="button" class="btn-act btn-act-danger" onclick="openDeleteAuditModal('<?= './?report=selling' . $session_qs . '&show=' . $req_show . '&date=' . urlencode($filter_date) . '&audit_delete=1&audit_blok=' . urlencode($ar['blok_name'] ?? '') . '&audit_date=' . urlencode($filter_date); ?>','<?= htmlspecialchars($ar['blok_name'] ?? '-'); ?>','<?= htmlspecialchars($filter_date); ?>')">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
            $audit_system_qty_total = (int)($audit_system_qty_display_total ?? $total_qty_laku);
            $audit_system_setoran_total = (int)($audit_system_setoran_display_total ?? $total_net);
            $audit_manual_qty_total = (int)($audit_manual_qty_display_total ?? $audit_total_reported_qty);
            $audit_manual_setoran_total = (int)($audit_manual_setoran_display_total ?? $audit_total_actual_setoran);
            $audit_selisih_qty_total = $audit_manual_qty_total - $audit_system_qty_total;
            $audit_selisih_setoran_total = $audit_manual_setoran_total - $audit_system_setoran_total;
        ?>
        <div class="hp-total-bar">
            <div>Sistem Qty (Total): <b><?= number_format($audit_system_qty_total,0,',','.') ?></b></div>
            <div>Manual Qty: <b><?= number_format($audit_manual_qty_total,0,',','.') ?></b></div>
            <div>Selisih Qty: <b><?= number_format($audit_selisih_qty_total,0,',','.') ?></b></div>
            <div>Sistem Rp (Total): <b><?= number_format($audit_system_setoran_total,0,',','.') ?></b></div>
            <div>Manual Rp: <b><?= number_format($audit_manual_setoran_total,0,',','.') ?></b></div>
            <div>Selisih Rp: <b><?= number_format($audit_selisih_setoran_total,0,',','.') ?></b></div>
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

<?php if (!$is_ajax): ?>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="settlement-reset-modal" class="modal-backdrop" onclick="if(event.target===this){closeSettlementResetModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-refresh" style="color:#ff9800;margin-right:6px;"></i> Reset Settlement</div>
            <button type="button" class="modal-close" onclick="closeSettlementResetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="line-height:1.6;">Reset status settlement untuk tanggal ini agar tombol Settlement bisa dipakai lagi?</div>
            <div class="modal-note">Tindakan ini hanya menghapus status selesai pada sistem, tidak mengubah data MikroTik.</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-print btn-default-dark" onclick="closeSettlementResetModal()">Batal</button>
            <button type="button" class="btn-print" style="background:#ff9800;color:#fff;" onclick="confirmSettlementReset()">Ya, Reset</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<style>
    .terminal-window { background-color:#000; border:1px solid #555; padding:12px; font-family:'Courier New', Courier, monospace; font-size:12px; max-height:260px; overflow-y:auto; border-radius:4px; color:#d0d0d0; box-shadow: inset 0 0 10px rgba(0,0,0,0.8); }
    .terminal-window::-webkit-scrollbar { width:10px; }
    .terminal-window::-webkit-scrollbar-track { background:#111; }
    .terminal-window::-webkit-scrollbar-thumb { background:#444; border-radius:2px; }
    .terminal-window::-webkit-scrollbar-thumb:hover { background:#666; }
    .log-entry { margin-bottom:4px; line-height:1.4; display:block; }
    .log-prompt { color:#00ff00; margin-right:6px; font-weight:700; }
    .log-time { color:#888; margin-right:8px; }
    .log-topic { color:#aaa; margin-right:8px; font-weight:bold; }
    .log-info { color:#d0d0d0; }
    .log-success { color:#00ff00; }
    .log-warning { color:#ffeb3b; }
    .log-error { color:#ff5252; }
    .log-system { color:#00bcd4; }
    .cursor-blink { display:inline-block; width:8px; height:15px; background-color:#00ff00; animation: blink 1s infinite; vertical-align:middle; margin-left:5px; }
    @keyframes blink { 0%{opacity:0;} 50%{opacity:1;} 100%{opacity:0;} }
</style>
<div id="settlement-modal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);z-index:10050;">
    <div style="background:#1f1f1f;color:#e5e5e5;border:1px solid #444;border-radius:8px;width:720px;max-width:94vw;box-shadow:0 10px 30px rgba(0,0,0,0.6);overflow:hidden;">
        <div style="padding:14px 18px;border-bottom:1px solid #333;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
            <span><i class="fa fa-cog fa-spin" style="margin-right:6px;"></i> Settlement Manual</span>
            <span id="settlement-status" style="font-size:12px;color:#9aa0a6;">Menunggu konfirmasi</span>
        </div>
        <div style="padding:16px 18px;">
            <div id="settlement-confirm" style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:#111;border:1px solid #333;border-radius:6px;padding:12px;margin-bottom:12px;">
                <div style="font-size:12px;color:#cbd5e1;">Jalankan settlement manual sekarang?</div>
                <div style="display:flex;gap:8px;">
                    <button id="settlement-start" type="button" class="btn-print">Mulai</button>
                    <button id="settlement-cancel" type="button" class="btn-print" style="opacity:.8;">Batal</button>
                </div>
            </div>
            <div id="settlement-log-wrap" style="display:none;">
                <div style="font-size:12px;color:#9aa0a6;margin-bottom:8px;">Log settlement (MikroTik)</div>
                <div id="settlement-log" class="terminal-window"></div>
            </div>
        </div>
        <div id="settlement-footer" style="padding:10px 18px;border-top:1px solid #333;display:none;justify-content:space-between;gap:8px;align-items:center;">
            <span id="processStatus" style="font-size:12px;color:#ff9800;"><i class="fa fa-refresh fa-spin"></i> Menunggu proses...</span>
            <button id="settlement-close" type="button" class="btn-print" onclick="closeSettlementModal()" disabled style="opacity:.6;cursor:not-allowed;">Tutup</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="hp-delete-modal" class="modal-backdrop" onclick="if(event.target===this){closeDeleteHpModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-exclamation-triangle" style="color:#ff9800;margin-right:6px;"></i> Konfirmasi Hapus</div>
            <button type="button" id="hp-delete-close" onclick="closeDeleteHpModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="hp-delete-text" style="text-align:left;line-height:1.6;">Hapus data ini?</div>
            <div class="modal-note">Tindakan ini menghapus seluruh data untuk Blok terkait (TOTAL, WARTEL, KAMTIB) pada tanggal tersebut.</div>
        </div>
        <div class="modal-footer">
            <button type="button" id="hp-delete-cancel" onclick="closeDeleteHpModal()" class="btn-print btn-default-dark">Batal</button>
            <button type="button" id="hp-delete-confirm" onclick="confirmDeleteHpModal()" class="btn-print" style="background:#ff9800;color:#fff;">Ya, Hapus</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="audit-delete-modal" class="modal-backdrop" onclick="if(event.target===this){closeDeleteAuditModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-exclamation-triangle" style="color:#ff9800;margin-right:6px;"></i> Konfirmasi Hapus</div>
            <button type="button" onclick="closeDeleteAuditModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="audit-delete-text" style="text-align:left;line-height:1.6;">Hapus audit ini?</div>
            <div class="modal-note">Tindakan ini hanya menghapus catatan audit manual untuk blok dan tanggal tersebut.</div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeDeleteAuditModal()" class="btn-print btn-default-dark">Batal</button>
            <button type="button" onclick="confirmDeleteAuditModal()" class="btn-print" style="background:#ff9800;color:#fff;">Ya, Hapus</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_ajax) { echo ob_get_clean(); exit; } ?>

<?php if (!$is_ajax): ?>
<script>
    function softReloadSelling(){
        var content = document.getElementById('selling-content');
        if (!content) return;
        if (window.sellingPauseReload) return;
        var modal = document.getElementById('hpModal');
        if (modal && modal.style.display === 'flex') return;
        var auditModal = document.getElementById('auditModal');
        if (auditModal && auditModal.style.display === 'flex') return;
        var current = new URL(window.location.href);
        var url = new URL('report/aload_selling.php', window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'));
        current.searchParams.forEach(function(v, k){
            if (k !== 'ajax') url.searchParams.set(k, v);
        });
        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.text(); })
            .then(function(html){ content.innerHTML = html; })
            .catch(function(){});
    }
    window.sellingPauseReload = false;
    setInterval(softReloadSelling, 30000);
</script>
<?php endif; ?>

<!-- Pendapatan per Blok/Profile sementara disembunyikan sesuai permintaan -->