<?php

require_once __DIR__ . '/helpers.php';

$root_dir = dirname(__DIR__, 2);
require_once $root_dir . '/include/acl.php';
$is_superadmin = isset($_SESSION['mikhmon']) ? isSuperAdmin() : false;
$is_operator = isset($_SESSION['mikhmon']) ? isOperator() : false;
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$session_id = $_GET['session'] ?? '';
if ($session_id !== '') {
    $cfgFile = $root_dir . '/include/config.php';
    $readCfg = $root_dir . '/include/readcfg.php';
    if (file_exists($cfgFile)) {
        require_once $cfgFile;
        if (isset($data[$session_id]) && file_exists($readCfg)) {
            require_once $readCfg;
        }
    }
}
$pricing = $env['pricing'] ?? [];
$profiles_cfg = $env['profiles'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$profile_price_map = $pricing['profile_prices'] ?? [];
$profile_alias_map = $pricing['profile_aliases'] ?? [];
$profile_labels_map = $profiles_cfg['labels'] ?? [];
$GLOBALS['profile_price_map'] = $profile_price_map;
$label10 = $profiles_cfg['label_10'] ?? '10 Menit';
$label30 = $profiles_cfg['label_30'] ?? '30 Menit';
$audit_profiles = [];
if (!empty($profile_price_map)) {
    foreach ($profile_price_map as $k => $v) {
        $key = strtolower(trim((string)$k));
        if ($key === '') continue;
        $num = null;
        if (preg_match('/(\d+)/', $key, $m)) {
            $num = (int)$m[1];
        }
        $label = $profile_labels_map[$key] ?? '';
        if ($label === '') {
            $label = $num !== null ? ($num . ' Menit') : $key;
        }
        $audit_profiles[] = [
            'key' => $key,
            'label' => $label,
            'price' => (int)$v,
            'minutes' => $num
        ];
    }
    usort($audit_profiles, function($a, $b){
        $ma = $a['minutes'] ?? null;
        $mb = $b['minutes'] ?? null;
        if ($ma === null && $mb === null) return strcmp($a['key'], $b['key']);
        if ($ma === null) return 1;
        if ($mb === null) return -1;
        return $ma <=> $mb;
    });
}
$blok_cfg = $env['blok'] ?? ($env['blocks'] ?? []);
$blok_letters_raw = (string)($blok_cfg['letters'] ?? 'A-F');
$blok_letters_raw = trim($blok_letters_raw);
$blok_letters = [];
if ($blok_letters_raw !== '') {
    $parts = preg_split('/\s*,\s*/', $blok_letters_raw);
    if (count($parts) === 1 && preg_match('/^([A-Z])\s*-\s*([A-Z])$/i', $blok_letters_raw, $m)) {
        $blok_letters = range(strtoupper($m[1]), strtoupper($m[2]));
    } else {
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (preg_match('/^[A-Za-z]$/', $p)) {
                $blok_letters[] = strtoupper($p);
            }
        }
    }
}
if (empty($blok_letters)) {
    $blok_letters = range('A', 'F');
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$rows = [];
$cur = isset($currency) ? $currency : 'Rp';
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
$audit_expected_setoran_adj_total = 0;
$audit_selisih_setoran_adj_total = 0;
$has_audit_adjusted = false;
$audit_locked_today = false;
$current_daily_note = '';

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
            expenses_amt INTEGER,
            expenses_desc TEXT,
            selisih_qty INTEGER,
            selisih_setoran INTEGER,
            note TEXT,
            user_evidence TEXT,
            status TEXT DEFAULT 'OPEN',
            is_locked INTEGER DEFAULT 0,
            locked_at DATETIME,
            locked_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (report_date, blok_name)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS daily_report_notes (
            report_date TEXT PRIMARY KEY,
            note TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN audit_username TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN user_evidence TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN expenses_amt INTEGER"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN expenses_desc TEXT"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN is_locked INTEGER DEFAULT 0"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN locked_at DATETIME"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE audit_rekap_manual ADD COLUMN locked_by TEXT"); } catch (Exception $e) {}
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

            // Tambahkan data login_history untuk status rusak/retur/invalid agar selalu terbaca
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
                    first_login_real
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
                                            AND (
                                                substr(login_time_real,1,10) = :d OR
                                                substr(last_login_real,1,10) = :d OR
                                                substr(logout_time_real,1,10) = :d OR
                                                substr(updated_at,1,10) = :d OR
                                                login_date = :d
                                            )
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
                $stmtLock = $db->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(is_locked,0) = 1");
                $stmtLock->execute([':d' => $filter_date]);
                $audit_locked_today = (int)$stmtLock->fetchColumn() > 0;
            } catch (Exception $e) {
                $audit_locked_today = false;
            }
        }

        if ($req_show === 'harian') {
            try {
                $stmtNote = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
                $stmtNote->execute([':d' => $filter_date]);
                $current_daily_note = $stmtNote->fetchColumn() ?: '';
            } catch (Exception $e) {
                $current_daily_note = '';
            }
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

// Simpan catatan harian
if (isset($db) && $db instanceof PDO && isset($_POST['save_daily_note'])) {
    $note_date = trim($_POST['note_date'] ?? '');
    $note_text = trim($_POST['note_text'] ?? '');
    if ($note_text !== '') {
        $note_text = mb_substr($note_text, 0, 500);
    }
    if ($note_date !== '') {
        try {
            if ($note_text !== '') {
                $stmtNote = $db->prepare("INSERT INTO daily_report_notes (report_date, note, updated_at)
                    VALUES (:d, :n, CURRENT_TIMESTAMP)
                    ON CONFLICT(report_date) DO UPDATE SET note=excluded.note, updated_at=CURRENT_TIMESTAMP");
                $stmtNote->execute([':d' => $note_date, ':n' => $note_text]);
            } else {
                $db->prepare("DELETE FROM daily_report_notes WHERE report_date = :d")->execute([':d' => $note_date]);
            }
        } catch (Exception $e) {}
    }
    $note_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
    if (!headers_sent()) {
        header('Location: ' . $note_redirect);
        exit;
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

// Kunci audit manual (harian)
if (isset($db) && $db instanceof PDO && isset($_GET['audit_lock'])) {
    if ($is_operator) {
        $audit_error = 'Akses ditolak. Kunci audit hanya untuk Superadmin.';
        $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
        if (!headers_sent()) {
            header('Location: ' . $audit_redirect);
            exit;
        }
    }
    $lock_date = trim($_GET['audit_date'] ?? $filter_date);
    if ($req_show !== 'harian' || $lock_date === '') {
        $audit_error = 'Penguncian hanya tersedia untuk rekap harian.';
    } else {
        try {
            $stmtCnt = $db->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d");
            $stmtCnt->execute([':d' => $lock_date]);
            $rowCount = (int)$stmtCnt->fetchColumn();
            if ($rowCount <= 0) {
                $audit_error = 'Belum ada audit manual yang bisa dikunci.';
            } else {
                $lock_by = isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : 'system';
                $stmtLock = $db->prepare("UPDATE audit_rekap_manual SET is_locked = 1, locked_at = CURRENT_TIMESTAMP, locked_by = :u WHERE report_date = :d");
                $stmtLock->execute([':u' => $lock_by, ':d' => $lock_date]);
                $audit_locked_today = true;
            }
        } catch (Exception $e) {
            $audit_error = 'Gagal mengunci audit.';
        }
    }
    $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($lock_date);
    if (!headers_sent()) {
        header('Location: ' . $audit_redirect);
        exit;
    }
}

// Hapus audit manual rekap (harian)
if (isset($db) && $db instanceof PDO && isset($_GET['audit_delete'])) {
    if ($is_operator) {
        $audit_error = 'Akses ditolak. Hapus audit hanya untuk Superadmin.';
        $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
        if (!headers_sent()) {
            header('Location: ' . $audit_redirect);
            exit;
        }
    }
    $del_date = trim($_GET['audit_date'] ?? '');
    $del_blok = strtoupper(trim($_GET['audit_blok'] ?? ''));
    if ($del_date !== '' && $del_blok !== '') {
        try {
            $stmtLock = $db->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(is_locked,0) = 1");
            $stmtLock->execute([':d' => $del_date]);
            $is_locked = (int)$stmtLock->fetchColumn() > 0;
            if ($is_locked) {
                $audit_error = 'Audit sudah dikunci dan tidak bisa dihapus.';
            } else {
                $stmt = $db->prepare("DELETE FROM audit_rekap_manual WHERE report_date = :d AND UPPER(blok_name) = :b");
                $stmt->execute([':d' => $del_date, ':b' => $del_blok]);
            }
        } catch (Exception $e) {}
        $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($del_date);
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

$retur_ref_map = [];
foreach ($rows as $r) {
    $ref_user = extract_retur_user_from_ref($r['comment'] ?? '');
    if ($ref_user !== '') {
        $retur_ref_map[strtolower($ref_user)] = true;
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
    if ($username !== '' && isset($retur_ref_map[strtolower($username)])) {
        continue;
    }
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
    $comment = format_first_login($r['first_login_real'] ?? '');
    $raw_comment = (string)($r['comment'] ?? '');
    $blok_row = (string)($r['blok_name'] ?? '');
    if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $raw_comment)) {
        continue;
    }
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    if ($profile === '' || $profile === '-') {
        $hint = (string)($r['validity'] ?? '') . ' ' . $raw_comment;
        if (preg_match('/\b30\s*(menit|m)\b|30menit|profile\s*[:=]?\s*30\b|\b30m\b/i', $hint)) {
            $profile = $label30;
            if ($price <= 0) $price = $price30;
        } elseif (preg_match('/\b10\s*(menit|m)\b|10menit|profile\s*[:=]?\s*10\b|\b10m\b/i', $hint)) {
            $profile = $label10;
            if ($price <= 0) $price = $price10;
        }
    } elseif ($price <= 0) {
        if (preg_match('/\b30\s*(menit|m)\b|30menit|\b30m\b/i', $profile)) {
            $price = $price30;
        } elseif (preg_match('/\b10\s*(menit|m)\b|10menit|\b10m\b/i', $profile)) {
            $price = $price10;
        }
    }
    $line_price = $price * $qty;
    if ($profile === '' || $profile === '-') {
        $hint = (string)($r['validity'] ?? '') . ' ' . $raw_comment;
        if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $hint)) {
            $profile = $label30;
            if ($price <= 0) $price = $price30;
        } elseif (preg_match('/\b10\s*(menit|m)\b|10menit/i', $hint)) {
            $profile = $label10;
            if ($price <= 0) $price = $price10;
        }
    } elseif ($price <= 0) {
        if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $profile)) {
            $price = $price30;
        } elseif (preg_match('/\b10\s*(menit|m)\b|10menit/i', $profile)) {
            $price = $price10;
        }
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

    $gross_add = 0;
    $loss_rusak = 0;
    $loss_invalid = 0;
    $net_add = 0;

    if ($status === 'invalid') {
        $gross_add = 0;
        $net_add = 0;
    } elseif ($status === 'retur') {
        $gross_add = 0;
        $net_add = $line_price;
    } elseif ($status === 'rusak') {
        $gross_add = $line_price;
        $loss_rusak = $line_price;
        $net_add = 0;
    } else {
        $gross_add = $line_price;
        $net_add = $line_price;
    }

    $is_laku = !in_array($status, ['rusak', 'invalid'], true);

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

        if ($is_laku) {
            $by_block[$blok]['qty'] += 1;
        }
        $by_block[$blok]['gross'] += $gross_add;
        $by_block[$blok]['rusak'] += $loss_rusak;
        $by_block[$blok]['invalid'] += $loss_invalid;
        $by_block[$blok]['net'] += $net_add;
        if ($status === 'retur') $by_block[$blok]['retur'] += 1;
        if ($status === 'rusak') $by_block[$blok]['rusak_qty'] += 1;
        if ($status === 'invalid') $by_block[$blok]['invalid_qty'] += 1;

        if ($is_laku) {
            $by_profile[$profile]['qty'] += 1;
        }
        $by_profile[$profile]['gross'] += $gross_add;
        $by_profile[$profile]['rusak'] += $loss_rusak;
        $by_profile[$profile]['invalid'] += $loss_invalid;
        $by_profile[$profile]['net'] += $net_add;
        if ($status === 'retur') $by_profile[$profile]['retur'] += 1;
    }

    if (empty($valid_blocks) || isset($valid_blocks[$blok])) {
        $total_bandwidth += (int)($r['last_bytes'] ?? 0);
    }
    $dt_display = $sale_dt !== '' ? format_date_dmy($sale_dt) : format_date_dmy($sale_date);
    $retur_user = extract_retur_user_from_ref($comment);
    $profile_label = resolve_profile_label($profile);
    if ($profile_label === '') $profile_label = $profile;
    $list[] = [
        'dt' => $dt_display,
        'user' => $r['username'] ?? '-',
        'profile' => $profile_label,
        'blok' => $blok,
        'status' => strtoupper($status),
        'price' => $line_price,
        'net' => $net_add,
        'bytes' => (int)($r['last_bytes'] ?? 0),
        'comment' => $comment,
        'retur_ref_user' => $retur_user
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
        $audit_setoran_manual = (int)($_POST['audit_setoran_manual'] ?? 0);
        $audit_qty_10 = (int)($_POST['audit_qty_10'] ?? 0);
        $audit_qty_30 = (int)($_POST['audit_qty_30'] ?? 0);
        $audit_profile_qty = [];
        if (!empty($_POST['audit_profile_qty']) && is_array($_POST['audit_profile_qty'])) {
            foreach ($_POST['audit_profile_qty'] as $k => $v) {
                $key = strtolower(trim((string)$k));
                if ($key === '') continue;
                $audit_profile_qty[$key] = (int)$v;
            }
        } elseif ($audit_qty_10 > 0 || $audit_qty_30 > 0) {
            $audit_profile_qty = [
                '10menit' => $audit_qty_10,
                '30menit' => $audit_qty_30
            ];
        }
        if (!empty($audit_profile_qty)) {
            $audit_qty = array_sum($audit_profile_qty);
        }
        $audit_setoran_auto = 0;
        if (!empty($audit_profile_qty)) {
            foreach ($audit_profile_qty as $k => $v) {
                $qty_val = (int)$v;
                $price_val = isset($profile_price_map[$k]) ? (int)$profile_price_map[$k] : (int)resolve_price_from_profile($k);
                $audit_setoran_auto += ($qty_val * $price_val);
            }
        }
        if ($audit_setoran_manual !== 1 && $audit_setoran_auto > 0 && $audit_setoran !== $audit_setoran_auto) {
            $audit_setoran_manual = 1;
        }
        $audit_exp_amt = (int)($_POST['audit_expense_amt'] ?? 0);
        $audit_exp_desc = trim($_POST['audit_expense_desc'] ?? '');
        $audit_note = '';
        $audit_status = 'OPEN';

        if ($audit_blok_raw === '' || $audit_date === '') {
            $audit_error = 'Blok dan tanggal wajib diisi.';
        }
        if ($audit_error === '' && $audit_qty <= 0) {
            $audit_error = 'Qty manual wajib diisi.';
        }
        if ($audit_error === '' && ($audit_exp_amt > 0 && $audit_exp_desc === '')) {
            $audit_error = 'Keterangan pengeluaran wajib diisi.';
        }
        if ($audit_error === '' && $audit_blok !== 'BLOK-LAIN') {
            if ($req_show !== 'harian') {
                $audit_error = 'Audit manual hanya tersedia untuk rekap harian.';
            }
        }
        if ($audit_error === '' && $audit_blok !== 'BLOK-LAIN') {
            try {
                $stmtLock = $db->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(is_locked,0) = 1");
                $stmtLock->execute([':d' => $audit_date]);
                $is_locked = (int)$stmtLock->fetchColumn() > 0;
                if ($is_locked) {
                    $audit_error = 'Audit sudah dikunci dan tidak bisa diubah.';
                }
            } catch (Exception $e) {}
        }

        if ($audit_error === '') {
            $expected = ['qty' => 0, 'net' => 0, 'rusak_qty' => 0, 'retur_qty' => 0];
            $rows_src = !empty($rows) ? $rows : (isset($db) && $db instanceof PDO ? fetch_rows_for_audit($db, $audit_date) : []);
            if (!empty($rows_src)) {
                $expected = calc_expected_for_block($rows_src, $audit_date, $audit_blok);
            }

            $audit_expected_qty = (int)($expected['qty'] ?? 0);
            $audit_expected_setoran = (int)($expected['net'] ?? 0);
            $audit_selisih_qty = $audit_qty - $audit_expected_qty;
            $audit_selisih_setoran = $audit_setoran - $audit_expected_setoran;
            if ($audit_exp_amt > 0 && $audit_exp_desc !== '') {
                $audit_setoran = $audit_setoran + $audit_exp_amt;
            }

            $default_profile_key = !empty($audit_profiles) ? (string)($audit_profiles[0]['key'] ?? '10menit') : '10menit';
            $audit_evidence = [
                'profile_qty' => !empty($audit_profile_qty) ? $audit_profile_qty : [
                    'qty_10' => $audit_qty_10,
                    'qty_30' => $audit_qty_30
                ],
                'manual_setoran' => $audit_setoran_manual ? 1 : 0,
                'users' => []
            ];

            $audit_user_stmt = null;
            if (!empty($audit_users) && isset($db) && $db instanceof PDO) {
                try {
                    $audit_user_stmt = $db->prepare("SELECT last_uptime, last_bytes, last_status, validity, raw_comment FROM login_history WHERE username = :u LIMIT 1");
                } catch (Exception $e) {
                    $audit_user_stmt = null;
                }
            }

            if (!empty($audit_users)) {
                foreach ($audit_users as $u) {
                    $u = trim((string)$u);
                    if ($u === '') continue;
                    $profile_key = $default_profile_key;
                    $last_uptime = '';
                    $last_bytes = 0;
                    $last_status = 'unknown';
                    $price_val = 0;
                    if ($audit_user_stmt) {
                        $variants = [$u];
                        if (stripos($u, 'vc-') === 0) {
                            $variants[] = substr($u, 3);
                        } else {
                            $variants[] = 'vc-' . $u;
                        }
                        $found = false;
                        foreach ($variants as $vu) {
                            $vu = trim((string)$vu);
                            if ($vu === '') continue;
                            try {
                                $audit_user_stmt->execute([':u' => $vu]);
                                $hist = $audit_user_stmt->fetch(PDO::FETCH_ASSOC);
                                if (!empty($hist)) {
                                    $last_uptime = trim((string)($hist['last_uptime'] ?? ''));
                                    $last_bytes = (int)($hist['last_bytes'] ?? 0);
                                    $last_status = strtolower((string)($hist['last_status'] ?? 'unknown'));
                                    $profile_src = (string)($hist['validity'] ?? '');
                                    if ($profile_src === '') {
                                        $profile_src = extract_profile_from_comment((string)($hist['raw_comment'] ?? ''));
                                    }
                                    $pk = normalize_profile_key($profile_src);
                                    if ($pk !== '') $profile_key = $pk;
                                    $found = true;
                                    break;
                                }
                            } catch (Exception $e) {}
                        }
                        if (!$found) {
                            $last_uptime = $last_uptime !== '' ? $last_uptime : '';
                        }
                    }
                    if ($profile_key !== '' && preg_match('/^\d+$/', $profile_key)) {
                        $profile_key = $profile_key . 'menit';
                    }
                    if ($profile_key === '') $profile_key = $default_profile_key;
                    $price_val = resolve_price_from_profile($profile_key);
                    $audit_evidence['users'][$u] = [
                        'profile_key' => $profile_key,
                        'last_status' => $last_status,
                        'last_uptime' => $last_uptime,
                        'last_bytes' => $last_bytes,
                        'price' => $price_val
                    ];
                }
            }

            try {
                $stmt = $db->prepare("INSERT INTO audit_rekap_manual
                    (report_date, blok_name, audit_username, expected_qty, expected_setoran, reported_qty, actual_setoran, expenses_amt, expenses_desc, selisih_qty, selisih_setoran, note, user_evidence, status, updated_at)
                    VALUES (:d, :b, :u, :eq, :es, :rq, :rs, :ea, :ed, :sq, :ss, :n, :ev, :st, CURRENT_TIMESTAMP)
                    ON CONFLICT(report_date, blok_name) DO UPDATE SET
                        audit_username=excluded.audit_username,
                        expected_qty=excluded.expected_qty,
                        expected_setoran=excluded.expected_setoran,
                        reported_qty=excluded.reported_qty,
                        actual_setoran=excluded.actual_setoran,
                        expenses_amt=excluded.expenses_amt,
                        expenses_desc=excluded.expenses_desc,
                        selisih_qty=excluded.selisih_qty,
                        selisih_setoran=excluded.selisih_setoran,
                        note=excluded.note,
                        user_evidence=excluded.user_evidence,
                        status=excluded.status,
                        updated_at=CURRENT_TIMESTAMP");
                $stmt->execute([
                    ':d' => $audit_date,
                    ':b' => $audit_blok,
                    ':u' => $audit_user_list,
                    ':eq' => $audit_expected_qty,
                    ':es' => $audit_expected_setoran,
                    ':rq' => $audit_qty,
                    ':rs' => $audit_setoran,
                    ':ea' => $audit_exp_amt,
                    ':ed' => $audit_exp_desc,
                    ':sq' => $audit_selisih_qty,
                    ':ss' => $audit_selisih_setoran,
                    ':n' => $audit_note,
                    ':ev' => json_encode($audit_evidence),
                    ':st' => $audit_status
                ]);
            } catch (Exception $e) {
                $audit_error = 'Gagal menyimpan audit.';
            }
        }

        if ($audit_is_ajax) {
            header('Content-Type: application/json');
            if (!empty($audit_error)) {
                echo json_encode(['ok' => false, 'message' => $audit_error]);
            } else {
                $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($audit_date);
                echo json_encode(['ok' => true, 'redirect' => $audit_redirect]);
            }
            exit;
        }

        if (empty($audit_error)) {
            $audit_redirect = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($audit_date);
            if (!headers_sent()) {
                header('Location: ' . $audit_redirect);
                exit;
            }
        }
    }
}

// Hitung selisih audit, jika ada
if ($req_show === 'harian' && isset($db) && $db instanceof PDO) {
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
            [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($ar);
            $audit_expected_setoran_adj_total += (int)$expected_adj_setoran;
            $audit_selisih_setoran_adj_total += (int)$manual_setoran - (int)$expected_adj_setoran;
            $has_audit_adjusted = true;
        }
    } catch (Exception $e) {
        $audit_rows = [];
    }
}
if ($req_show !== 'harian' && isset($db) && $db instanceof PDO) {
    try {
        $stmtAudit = $db->prepare("SELECT expected_setoran, actual_setoran, user_evidence FROM audit_rekap_manual WHERE report_date LIKE :p");
        $stmtAudit->execute([':p' => $filter_date . '%']);
        foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($ar);
            $audit_selisih_setoran_adj_total += (int)$manual_setoran - (int)$expected_adj_setoran;
        }
    } catch (Exception $e) {
    }
}

$has_audit_rows = !empty($audit_rows);
$audit_ghost_hint = $has_audit_rows ? build_ghost_hint($audit_total_selisih_qty, $audit_total_selisih_setoran) : '';

if (empty($list) && $last_available_date !== '' && $filter_date !== $last_available_date) {
    $no_sales_message = 'Tidak ada data untuk tanggal ini. Data terakhir: ' . $last_available_date . '.';
}
if (empty($list) && $req_show === 'harian' && isset($db) && $db instanceof PDO && table_exists($db, 'login_history')) {
    try {
        $stmtLH = $db->prepare("SELECT COUNT(*) FROM login_history WHERE username != '' AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d) AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'");
        $stmtLH->execute([':d' => $filter_date]);
        $lhCount = (int)$stmtLH->fetchColumn();
        if ($lhCount > 0) {
            $no_sales_message = 'Data penjualan belum tersinkron. Jalankan report/laporan/services/sync_sales.php agar transaksi muncul di laporan.';
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

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
