<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026)
 * FINAL 18: ALOAD - CLEAN PRO DASHBOARD
 * - Update: Matikan Load Bar (Pace) khusus halaman Dashboard.
 * - Update: Live Indicator minimalis di bawah kartu (Stream: Update 10 Detik).
 * - Fix: AJAX Auto-Refresh mode hening (Global: False).
 */
session_start();
error_reporting(0);

$root = dirname(__DIR__); 

if (!isset($_SESSION["mikhmon"])) { die(); }

// --- AMBIL PARAMETER ---
$session = isset($_GET['session']) ? $_GET['session'] : '';
$load    = isset($_GET['load']) ? $_GET['load'] : '';
$sess_m  = isset($_GET['m']) ? $_GET['m'] : '';

// --- SET TIMEZONE ---
if (isset($_SESSION['timezone']) && !empty($_SESSION['timezone'])) {
    date_default_timezone_set($_SESSION['timezone']);
}

// --- SET FILTER SESSION ---
if (!empty($sess_m)) { $_SESSION['filter_month'] = (int)$sess_m; }
if (!isset($_SESSION['filter_month'])) { $_SESSION['filter_month'] = (int)date("m"); }
$_SESSION['filter_year'] = (int)date("Y");

// --- INCLUDE LIBRARY ---
if (file_exists($root . '/include/config.php')) include($root . '/include/config.php');
if (file_exists($root . '/include/readcfg.php')) include($root . '/include/readcfg.php');
if (file_exists($root . '/include/env.php')) include($root . '/include/env.php');
if (file_exists($root . '/include/auto_rusak.php')) include_once($root . '/include/auto_rusak.php');
if (file_exists($root . '/lib/routeros_api.class.php')) include_once($root . '/lib/routeros_api.class.php');
if (file_exists($root . '/lib/formatbytesbites.php')) include_once($root . '/lib/formatbytesbites.php');
if (file_exists($root . '/report/laporan/helpers.php')) include_once($root . '/report/laporan/helpers.php');

session_write_close(); 

$API = new RouterosAPI();
$API->debug = false;

// --- FUNGSI BANTUAN ---
if (!function_exists('formatDTM')) { function formatDTM($dtm) { return str_replace(["w", "d", "h", "m"], ["w ", "d ", "h ", "m "], $dtm); } }
if (!function_exists('formatBytes')) { function formatBytes($size, $precision = 2) { if ($size <= 0) return '0 B'; $base = log($size, 1024); $suffixes = array('B', 'KB', 'MB', 'GB', 'TB'); return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)]; } }
if (!function_exists('normalizeDate')) { 
    function normalizeDate($d) { 
        return str_replace(
            ['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'], 
            ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'], 
            strtolower($d)
        ); 
    } 
}

if (!function_exists('format_room_short_local')) {
    function format_room_short_local($room) {
        $raw = trim((string)$room);
        if ($raw === '') return '-';
        if (preg_match('/(\d+)/', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    }
}

if (!function_exists('parse_uptime_seconds')) {
    function parse_uptime_seconds($uptime) {
        $uptime = trim((string)$uptime);
        if ($uptime === '') return 0;
        $total = 0;
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $uptime, $m)) {
            return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
        }
        if (preg_match('/^(\d+)d\s*(\d{1,2}:\d{2}:\d{2})$/', $uptime, $m)) {
            $total += (int)$m[1] * 86400;
            $total += parse_uptime_seconds($m[2]);
            return $total;
        }
        if (preg_match_all('/(\d+)(w|d|h|m|s)/', $uptime, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $mt) {
                $val = (int)$mt[1];
                switch ($mt[2]) {
                    case 'w': $total += $val * 604800; break;
                    case 'd': $total += $val * 86400; break;
                    case 'h': $total += $val * 3600; break;
                    case 'm': $total += $val * 60; break;
                    case 's': $total += $val; break;
                }
            }
            return $total;
        }
        return 0;
    }
}

if (!function_exists('norm_date_from_raw_report')) {
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
}

if (!function_exists('table_exists')) {
    function table_exists($db, $name) {
        try {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :n LIMIT 1");
            $stmt->execute([':n' => $name]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('resolve_stats_db_file')) {
    function resolve_stats_db_file($root_dir) {
        $env = $GLOBALS['env'] ?? [];
        $system_cfg = $env['system'] ?? [];
        $db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
            return $db_rel;
        }
        return $root_dir . '/' . ltrim($db_rel, '/');
    }
}


// =========================================================
// BAGIAN KHUSUS: LIVE DATA PROVIDER (JSON)
// =========================================================
if ($load == "live_data") {

    header('Content-Type: application/json');

    $dataResponse = [
        'active' => 0,
        'sold' => 0,
        'income' => '0',
        'gross_income' => '0',
        'est_income' => '0',
        'ghost' => 0,
        'audit_status' => 'CLEAR',
        'audit_val' => '0'
    ];

    $counthotspotactive = 0;
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $expected_server = strtolower((string)($hotspot_server ?? 'wartel'));
        $rawActive = $API->comm("/ip/hotspot/active/print", array(".proplist" => "server"));
        if (is_array($rawActive)) {
            foreach ($rawActive as $act) {
                $server = isset($act['server']) ? strtolower((string)$act['server']) : '';
                if ($server === $expected_server) {
                    $counthotspotactive++;
                }
            }
        }
    }

    $dbFile = resolve_stats_db_file($root);
    $today = date('Y-m-d');
    $month = date('m');
    $year = date('Y');
    $monthShort = date('M');
    $daysInMonth = (int)date('t');
    $currentDay = (int)date('d');

    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $monthLike = $year . '-' . $month . '%';
            $raw1 = $month . '/%/' . $year;
            $raw2 = '%/' . $month . '/' . $year;
            $raw3 = $monthShort . '/%/' . $year;

            $dayRaw1 = date('m/d/Y', strtotime($today)) . '%';
            $dayRaw2 = date('d/m/Y', strtotime($today)) . '%';
            $dayRaw3 = date('M/d/Y', strtotime($today)) . '%';

            $sumIncome = 0;
            $sumSold = 0;

            $hasSales = table_exists($db, 'sales_history');
            $hasLive = table_exists($db, 'live_sales');
            $hasLogin = table_exists($db, 'login_history');

            $rows = [];
            $loginSelect = $hasLogin
                ? 'lh.last_status'
                : "'' AS last_status";
            $loginSelect2 = $hasLogin
                ? 'lh2.last_status'
                : "'' AS last_status";
            $loginJoin = $hasLogin ? 'LEFT JOIN login_history lh ON lh.username = sh.username' : '';
            $loginJoin2 = $hasLogin ? 'LEFT JOIN login_history lh2 ON lh2.username = ls.username' : '';

            $selects = [];
            if ($hasSales) {
                $selects[] = "SELECT
                    sh.raw_date, sh.sale_date,
                    sh.username, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid,
                    sh.comment, sh.blok_name,
                    sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.qty,
                    $loginSelect
                    FROM sales_history sh
                    $loginJoin";
            }
            if ($hasLive) {
                $selects[] = "SELECT
                    ls.raw_date, ls.sale_date,
                    ls.username, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid,
                    ls.comment, ls.blok_name,
                    ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.qty,
                    $loginSelect2
                    FROM live_sales ls
                    $loginJoin2
                    WHERE ls.sync_status = 'pending'";
            }

            if (!empty($selects)) {
                $sql = implode(" UNION ALL ", $selects);
                $res = $db->query($sql);
                if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
            }

            $monthKey = $year . '-' . $month;
            $seen_user_day = [];
            $total_net_month = 0;
            $total_net_today = 0;
            $total_gross_today = 0;

            foreach ($rows as $r) {
                $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
                if ($sale_date === '' || strpos($sale_date, $monthKey) !== 0) {
                    continue;
                }

                $username = trim((string)($r['username'] ?? ''));
                if ($username !== '') {
                    $user_day_key = $username . '|' . $sale_date;
                    if (isset($seen_user_day[$user_day_key])) {
                        continue;
                    }
                    $seen_user_day[$user_day_key] = true;
                }

                $raw_comment = (string)($r['comment'] ?? '');
                $blok_row = (string)($r['blok_name'] ?? '');
                if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $raw_comment)) {
                    continue;
                }

                $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
                if ($price <= 0) {
                    $price = (int)($r['sprice_snapshot'] ?? 0);
                }
                $qty = (int)($r['qty'] ?? 0);
                if ($qty <= 0) $qty = 1;
                $line_price = $price * $qty;

                $status = strtolower((string)($r['status'] ?? ''));
                $lh_status = strtolower((string)($r['last_status'] ?? ''));
                $cmt_low = strtolower($raw_comment);

                if ($status === '' || $status === 'normal') {
                    if ((int)($r['is_invalid'] ?? 0) === 1) $status = 'invalid';
                    elseif ((int)($r['is_retur'] ?? 0) === 1) $status = 'retur';
                    elseif ((int)($r['is_rusak'] ?? 0) === 1) $status = 'rusak';
                    elseif ($lh_status === 'invalid') $status = 'invalid';
                    elseif ($lh_status === 'retur') $status = 'retur';
                    elseif ($lh_status === 'rusak') $status = 'rusak';
                    elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
                    elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';
                    elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
                    else $status = 'normal';
                }

                $loss_rusak = ($status === 'rusak') ? $line_price : 0;
                $loss_invalid = ($status === 'invalid') ? $line_price : 0;
                $net_add = $line_price - $loss_rusak - $loss_invalid;
                $total_net_month += $net_add;
                if ($sale_date === $today) {
                    $total_net_today += $net_add;
                    if (!in_array($status, ['retur', 'invalid'], true)) {
                        $total_gross_today += $line_price;
                    }
                }
            }

            $sumIncome = $total_net_today;
            $sumIncomeMonth = $total_net_month;
            $sumGrossToday = $total_gross_today;

            $sumSold = 0;
            if (table_exists($db, 'sales_history') || table_exists($db, 'live_sales') || table_exists($db, 'login_history')) {
                try {
                    $hasSales = table_exists($db, 'sales_history');
                    $hasLive = table_exists($db, 'live_sales');
                    $hasLogin = table_exists($db, 'login_history');

                    $rows = [];
                    $loginSelect = $hasLogin
                        ? 'lh.last_status'
                        : "'' AS last_status";
                    $loginSelect2 = $hasLogin
                        ? 'lh2.last_status'
                        : "'' AS last_status";
                    $loginJoin = $hasLogin ? 'LEFT JOIN login_history lh ON lh.username = sh.username' : '';
                    $loginJoin2 = $hasLogin ? 'LEFT JOIN login_history lh2 ON lh2.username = ls.username' : '';

                    $selects = [];
                    if ($hasSales) {
                        $selects[] = "SELECT
                            sh.raw_date, sh.sale_date,
                            sh.username, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid,
                            sh.comment, sh.blok_name,
                            $loginSelect
                            FROM sales_history sh
                            $loginJoin";
                    }
                    if ($hasLive) {
                        $selects[] = "SELECT
                            ls.raw_date, ls.sale_date,
                            ls.username, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid,
                            ls.comment, ls.blok_name,
                            $loginSelect2
                            FROM live_sales ls
                            $loginJoin2
                            WHERE ls.sync_status = 'pending'";
                    }

                    if (!empty($selects)) {
                        $sql = implode(" UNION ALL ", $selects);
                        $res = $db->query($sql);
                        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
                    }

                    if ($hasLogin) {
                        $salesCount = 0;
                        if ($hasSales) {
                            $stmtCnt = $db->prepare("SELECT COUNT(*) FROM sales_history WHERE sale_date = :d");
                            $stmtCnt->execute([':d' => $today]);
                            $salesCount += (int)$stmtCnt->fetchColumn();
                        }
                        if ($hasLive) {
                            $stmtCnt2 = $db->prepare("SELECT COUNT(*) FROM live_sales WHERE sale_date = :d");
                            $stmtCnt2->execute([':d' => $today]);
                            $salesCount += (int)$stmtCnt2->fetchColumn();
                        }
                        if ($salesCount === 0) {
                            $stmtFallback = $db->prepare("SELECT
                                '' AS raw_date,
                                COALESCE(NULLIF(substr(login_time_real,1,10),''), login_date) AS sale_date,
                                username,
                                '' AS status,
                                0 AS is_rusak,
                                0 AS is_retur,
                                0 AS is_invalid,
                                raw_comment AS comment,
                                blok_name,
                                last_status
                            FROM login_history
                            WHERE username != ''
                              AND (substr(login_time_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR login_date = :d)
                              AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'");
                            $stmtFallback->execute([':d' => $today]);
                            $rows = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }

                    $seen_user_day = [];
                    $unique_laku_users = [];
                    foreach ($rows as $r) {
                        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
                        if ($sale_date !== $today) continue;

                        $username = trim((string)($r['username'] ?? ''));
                        if ($username === '') continue;
                        $user_day_key = $username . '|' . $sale_date;
                        if (isset($seen_user_day[$user_day_key])) continue;
                        $seen_user_day[$user_day_key] = true;

                        $raw_comment = (string)($r['comment'] ?? '');
                        $blok_row = (string)($r['blok_name'] ?? '');
                        if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $raw_comment)) {
                            continue;
                        }

                        $status = strtolower((string)($r['status'] ?? ''));
                        $lh_status = strtolower((string)($r['last_status'] ?? ''));
                        $cmt_low = strtolower($raw_comment);

                        if ($status === '' || $status === 'normal') {
                            if ((int)($r['is_invalid'] ?? 0) === 1) $status = 'invalid';
                            elseif ((int)($r['is_retur'] ?? 0) === 1) $status = 'retur';
                            elseif ((int)($r['is_rusak'] ?? 0) === 1) $status = 'rusak';
                            elseif ($lh_status === 'invalid') $status = 'invalid';
                            elseif ($lh_status === 'retur') $status = 'retur';
                            elseif ($lh_status === 'rusak') $status = 'rusak';
                            elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
                            elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';
                            elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
                            else $status = 'normal';
                        }

                        $is_laku = !in_array($status, ['rusak', 'retur', 'invalid'], true);
                        if ($is_laku) {
                            $unique_laku_users[$username] = true;
                        }
                    }

                    $sumSold = count($unique_laku_users);
                } catch (Exception $e) {
                    $sumSold = 0;
                }
            }

            $avgDaily = $currentDay > 0 ? ($sumIncomeMonth / $currentDay) : 0;
            $estIncome = $sumIncomeMonth + ($avgDaily * ($daysInMonth - $currentDay));

            $dataResponse['sold'] = $sumSold;
            $dataResponse['income'] = number_format($sumIncome, 0, ",", ".");
            $dataResponse['gross_income'] = number_format($sumGrossToday, 0, ",", ".");
            $dataResponse['est_income'] = number_format($estIncome, 0, ",", ".");

            $stmtAudit = $db->prepare("SELECT SUM(selisih_qty) AS ghost_qty, SUM(selisih_setoran) AS selisih
                FROM audit_rekap_manual WHERE report_date = :d");
            $stmtAudit->execute([':d' => $today]);
            $auditRow = $stmtAudit->fetch(PDO::FETCH_ASSOC) ?: [];
            $ghostQty = (int)($auditRow['ghost_qty'] ?? 0);
            $selisih = (int)($auditRow['selisih'] ?? 0);

            $miss10 = 0;
            $miss30 = 0;
            $sumExpected = 0;
            $sumExpenses = 0;

            $stmtDetail = $db->prepare("SELECT expected_setoran, expenses_amt, user_evidence
                FROM audit_rekap_manual WHERE report_date = :d");
            $stmtDetail->execute([':d' => $today]);
            foreach ($stmtDetail->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sumExpected += (int)($row['expected_setoran'] ?? 0);
                $sumExpenses += (int)($row['expenses_amt'] ?? 0);
                if (!empty($row['user_evidence'])) {
                    $ev = json_decode((string)$row['user_evidence'], true);
                    if (is_array($ev) && !empty($ev['users']) && is_array($ev['users'])) {
                        foreach ($ev['users'] as $u) {
                            $k = (string)($u['profile_kind'] ?? '10');
                            $st = strtolower((string)($u['last_status'] ?? ''));
                            if ($st === 'rusak' || $st === 'invalid') {
                                if ($k === '30') $miss30++;
                                else $miss10++;
                            }
                        }
                    }
                }
            }
            $cashExpected = $sumExpected - $sumExpenses;
            if ($cashExpected < 0) $cashExpected = 0;

            $dataResponse['ghost'] = abs($ghostQty);
            $dataResponse['audit_val'] = number_format($selisih, 0, ",", ".");
            $dataResponse['audit_status'] = ($selisih < 0) ? 'LOSS' : 'CLEAR';
            $dataResponse['audit_detail'] = [
                'ghost' => abs($ghostQty),
                'miss_10' => $miss10,
                'miss_30' => $miss30,
                'cash_expected' => number_format($cashExpected, 0, ",", "."),
                'last_update' => date('H:i')
            ];
        } catch (Exception $e) {
        }
    }

    $dataResponse['active'] = $counthotspotactive;
    echo json_encode($dataResponse);
    exit();

}


// =========================================================
// BAGIAN 1: SYSTEM RESOURCE
// =========================================================
if ($load == "sysresource") {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $getclock = $API->comm("/system/clock/print");
        $clock = isset($getclock[0]) ? $getclock[0] : ['time'=>'00:00:00', 'date'=>'jan/01/1970'];
        if(isset($clock['time-zone-name'])) date_default_timezone_set($clock['time-zone-name']);
        $resource = $API->comm("/system/resource/print")[0];
        $routerboard = $API->comm("/system/routerboard/print")[0];
    } else {
        $clock = ['time'=>'--', 'date'=>'--'];
        $resource = ['uptime'=>'--', 'board-name'=>'--', 'version'=>'--', 'cpu-load'=>'0', 'free-memory'=>0, 'free-hdd-space'=>0];
        $routerboard = ['model'=>'--'];
    }
    
    $sys_date = isset($clock['date']) ? ucfirst($clock['date']) : '--';
    $sys_time = isset($clock['time']) ? $clock['time'] : '--';
    $sys_uptime = isset($resource['uptime']) ? formatDTM($resource['uptime']) : '--';
    $sys_board = isset($resource['board-name']) ? $resource['board-name'] : '--';
    $sys_model = isset($routerboard['model']) ? $routerboard['model'] : '--';
    $sys_os = isset($resource['version']) ? $resource['version'] : '--';
    $sys_cpu = isset($resource['cpu-load']) ? $resource['cpu-load'] : '0';
    $sys_mem = isset($resource['free-memory']) ? formatBytes($resource['free-memory'], 2) : '0 B';
    $sys_mem_raw = isset($resource['free-memory']) ? (int)$resource['free-memory'] : 0;
    $sys_mem_total = isset($resource['total-memory']) ? (int)$resource['total-memory'] : 0;
    $sys_mem_pct = $sys_mem_total > 0 ? round(($sys_mem_raw / $sys_mem_total) * 100) : 0;
    if ($sys_mem_pct < 0) $sys_mem_pct = 0;
    if ($sys_mem_pct > 100) $sys_mem_pct = 100;
    $sys_hdd = isset($resource['free-hdd-space']) ? formatBytes($resource['free-hdd-space'], 2) : '0 B';
    $cpu_class = ((int)$sys_cpu >= 90) ? 'text-danger' : '';
    ?>
    <div id="r_1_content_raw" style="display: contents;">
        <span class="<?= $cpu_class ?>"><i class="fa fa-server"></i> CPU: <?= $sys_cpu ?>%</span>
        <span><i class="fa fa-microchip"></i> RAM: <?= $sys_mem ?></span>
        <span><i class="fa fa-hdd-o"></i> HDD: <?= $sys_hdd ?></span>
        <span><i class="fa fa-bolt"></i> Uptime: <?= $sys_uptime ?></span>
    </div>
    <?php
    exit();
}

// =========================================================
// BAGIAN 2: DASHBOARD UTAMA & ANALISA
// =========================================================
if ($load == "hotspot") {

    $filterMonth = $_SESSION['filter_month'];
    $filterYear = $_SESSION['filter_year'];

    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    $currentDay = (int)date('d');

    $dbFile = resolve_stats_db_file($root);
    $rowsMerged = [];
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $monthKey = sprintf('%04d-%02d', $filterYear, $filterMonth);
            $raw1 = sprintf('%02d/%%/%04d', $filterMonth, $filterYear);
            $raw2 = sprintf('%%/%02d/%04d', $filterMonth, $filterYear);
            $raw3 = date('M', mktime(0, 0, 0, $filterMonth, 1, $filterYear)) . '/%/' . $filterYear;

            if (table_exists($db, 'sales_history')) {
                $stmt = $db->prepare("SELECT sale_date, raw_date, price, price_snapshot, sprice_snapshot, qty, full_raw_data
                    FROM sales_history
                    WHERE sale_date LIKE :m OR raw_date LIKE :r1 OR raw_date LIKE :r2 OR raw_date LIKE :r3");
                $stmt->execute([':m' => $monthKey . '%', ':r1' => $raw1, ':r2' => $raw2, ':r3' => $raw3]);
                $rowsMerged = array_merge($rowsMerged, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }

            if (table_exists($db, 'live_sales')) {
                $stmt = $db->prepare("SELECT sale_date, raw_date, price, price_snapshot, sprice_snapshot, qty, full_raw_data
                    FROM live_sales
                    WHERE sync_status='pending' AND (sale_date LIKE :m OR raw_date LIKE :r1 OR raw_date LIKE :r2 OR raw_date LIKE :r3)");
                $stmt->execute([':m' => $monthKey . '%', ':r1' => $raw1, ':r2' => $raw2, ':r3' => $raw3]);
                $rowsMerged = array_merge($rowsMerged, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
        } catch (Exception $e) { }
    }

    $daysInMonth = (int)date("t", mktime(0, 0, 0, $filterMonth, 1, $filterYear));
    $startDay = 1;
    if ($filterMonth === $currentMonth && $filterYear === $currentYear) {
        $startDay = ($currentDay >= 21) ? 21 : 1;
        $endDay = $currentDay;
    } else {
        $endDay = $daysInMonth;
    }

    if ($endDay < $startDay) {
        $startDay = 1;
        $endDay = $daysInMonth;
    }

    $daysInRange = $endDay - $startDay + 1;
    $dailyIncome = array_fill($startDay, $daysInRange, 0);
    $dailyQty = array_fill($startDay, $daysInRange, 0);

    $seen_raw = [];
    foreach ($rowsMerged as $row) {
        $raw_key = (string)($row['full_raw_data'] ?? '');
        if ($raw_key !== '') {
            if (isset($seen_raw[$raw_key])) continue;
            $seen_raw[$raw_key] = true;
        }

        $sale_date = (string)($row['sale_date'] ?? '');
        if ($sale_date === '') {
            $sale_date = norm_date_from_raw_report($row['raw_date'] ?? '');
        }
        if ($sale_date === '') continue;

        $tstamp = strtotime($sale_date);
        if (!$tstamp) continue;
        $d_month = (int)date('m', $tstamp);
        $d_year = (int)date('Y', $tstamp);
        $d_day = (int)date('d', $tstamp);
        if ($d_month != $filterMonth || $d_year != $filterYear) continue;

        if ($d_day >= $startDay && $d_day <= $endDay) {
            $price = (int)($row['price_snapshot'] ?? $row['price'] ?? 0);
            if ($price <= 0) $price = (int)($row['sprice_snapshot'] ?? 0);
            $qty = (int)($row['qty'] ?? 0);
            if ($qty <= 0) $qty = 1;
            $dailyIncome[$d_day] += ($price * $qty);
            $dailyQty[$d_day] += $qty;
        }
    }

    $categories = [];
    $dataIncome = [];
    $dataQty = [];
    $skipDay3 = ($filterMonth === $currentMonth && $filterYear === $currentYear);
    for ($d = $startDay; $d <= $endDay; $d++) {
        if ($skipDay3 && $d === 3) {
            continue;
        }
        $categories[] = (string)$d;
        $dataIncome[] = $dailyIncome[$d] ?? 0;
        $dataQty[] = $dailyQty[$d] ?? 0;
    }

    $jsonCategories = json_encode($categories);
    $jsonDataIncome = json_encode($dataIncome, JSON_NUMERIC_CHECK);
    $jsonDataQty = json_encode($dataQty, JSON_NUMERIC_CHECK);
    ?>

    <div id="view-dashboard">
        <div id="chart_container" style="width:100%; height:100%;">
            <div id="chart_income_stat" style="width:100%; height:100%;"></div>
        </div>
        <script type="text/javascript">
            if(typeof Highcharts !== 'undefined') {
                var catLen = <?= count($categories) ?>;
                Highcharts.chart('chart_income_stat', {
                    chart: { backgroundColor: 'transparent', plotBackgroundColor: 'transparent', type: 'area', spacingBottom: 0, reflow: true, zoomType: 'xy', height: null, borderWidth: 0, spacing: [10, 0, 10, 0], marginLeft: 12, marginRight: 12 },
                    title: { text: null },
                    xAxis: {
                        categories: <?= $jsonCategories ?>,
                        min: 0,
                        max: catLen > 0 ? (catLen - 1) : null,
                        startOnTick: false,
                        endOnTick: false,
                        minPadding: 0,
                        maxPadding: 0,
                        tickmarkPlacement: 'on',
                        crosshair: false,
                        lineWidth: 0,
                        tickLength: 0,
                        tickWidth: 0,
                        gridLineWidth: 0,
                        minorGridLineWidth: 0,
                        labels: { style: { color: '#888', fontSize: '10px' } },
                        lineColor: 'transparent'
                    },
                    yAxis: [{
                        title: { text: null },
                        gridLineWidth: 0,
                        minorGridLineWidth: 0,
                        lineWidth: 0,
                        tickLength: 0,
                        tickWidth: 0,
                        labels: { style: { color: '#00c0ef' }, formatter: function () { return (this.value / 1000) + 'k'; } }
                    }, {
                        title: { text: null },
                        opposite: true,
                        gridLineWidth: 0,
                        minorGridLineWidth: 0,
                        lineWidth: 0,
                        tickLength: 0,
                        tickWidth: 0,
                        labels: { style: { color: '#f39c12' } }
                    }],
                    tooltip: { shared: true, backgroundColor: '#1c1f26', style: {color: '#fff'}, borderWidth: 0, borderRadius: 10 },
                    plotOptions: {
                        series: {
                            pointPlacement: 'on'
                        },
                        area: {
                            marker: { enabled: false },
                            fillColor: {
                                linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                                stops: [[0, 'rgba(0,192,239,0.3)'], [1, 'rgba(0,192,239,0)']]
                            }
                        },
                        spline: { marker: { enabled: true, radius: 2 } }
                    },
                    series: [{
                        name: 'Pendapatan',
                        type: 'area',
                        yAxis: 0,
                        data: <?= $jsonDataIncome ?>,
                        color: '#00c0ef',
                        tooltip: { valuePrefix: 'Rp ' }
                    }, {
                        name: 'Terjual',
                        type: 'spline',
                        yAxis: 1,
                        data: <?= $jsonDataQty ?>,
                        color: '#f39c12',
                        marker: { lineWidth: 1, lineColor: '#f39c12', fillColor: '#fff' },
                        tooltip: { valueSuffix: ' lbr' }
                    }],
                    credits: { enabled: false },
                    legend: { itemStyle: { color: '#ccc' }, itemHoverStyle: { color: '#fff' } }
                });
            }
        </script>

        <style>
            .blink { animation: blinker 1.5s linear infinite; }
            @keyframes blinker { 50% { opacity: 0; } }

            /* SEMBUNYIKAN LOAD BAR (PACE) HANYA SAAT DI HALAMAN INI */
            .pace { display: none !important; }
        </style>
    </div>
    <?php

}

if ($load == "logs") {

    $filterMonth = $_SESSION['filter_month'];
    $filterYear = $_SESSION['filter_year'];
    $dbFile = resolve_stats_db_file($root);
    $rawDataMerged = [];

    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            if (table_exists($db, 'sales_history')) {
                $monthLike = sprintf('%04d-%02d%%', (int)$filterYear, (int)$filterMonth);
                $raw1 = sprintf('%02d/%%/%04d', (int)$filterMonth, (int)$filterYear);
                $raw2 = sprintf('%%/%02d/%04d', (int)$filterMonth, (int)$filterYear);
                $raw3 = date('M', mktime(0, 0, 0, (int)$filterMonth, 1, (int)$filterYear)) . '/%/' . (int)$filterYear;
                try {
                    $stmtSales = $db->prepare("SELECT full_raw_data FROM sales_history WHERE (sale_date LIKE :monthLike OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3) ORDER BY id DESC LIMIT 1000");
                    $stmtSales->execute([
                        ':monthLike' => $monthLike,
                        ':raw1' => $raw1,
                        ':raw2' => $raw2,
                        ':raw3' => $raw3
                    ]);
                    foreach ($stmtSales->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $rawDataMerged[] = $row['full_raw_data'];
                    }
                } catch (Exception $e) {
                    $resSales = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC LIMIT 1000");
                    if ($resSales) { foreach($resSales as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
                }
            }
            if (table_exists($db, 'live_sales')) {
                try {
                    $resLive = $db->query("SELECT full_raw_data FROM live_sales ORDER BY id DESC LIMIT 500");
                    if ($resLive) { foreach($resLive as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
                } catch (Exception $e) { }
            }
        } catch (Exception $e) { }
    }

    $finalLogs = [];
    foreach ($rawDataMerged as $rowString) {
        $parts = split_sales_raw($rowString);
        if (count($parts) >= 4) {
            $rawDateString = trim($parts[0]);
            $price = (int)preg_replace('/[^0-9]/', '', $parts[3]);
            $username = isset($parts[2]) ? trim($parts[2]) : '';
            $tstamp = strtotime(str_replace("/", "-", normalizeDate($rawDateString)));
            if (!$tstamp) continue;

            $d_month = (int)date("m", $tstamp);
            $d_year  = (int)date("Y", $tstamp);
            if ($d_month != $filterMonth || $d_year != $filterYear) continue;

            $paket = (isset($parts[7]) && $parts[7] != "") ? trim($parts[7]) : '-';
            $comment = (isset($parts[8])) ? trim($parts[8]) : '';
            $key = $tstamp . '_' . str_pad((string)count($finalLogs), 4, '0', STR_PAD_LEFT);
            $finalLogs[$key] = [ 'time_str' => date("d/m/Y H:i", $tstamp), 'username' => $username, 'paket' => $paket, 'comment' => $comment, 'price' => $price, 'status' => 'USED' ];
        }
    }

    if (!empty($finalLogs) && isset($db)) {
        $usernames = [];
        foreach ($finalLogs as $log) {
            $uname = trim((string)($log['username'] ?? ''));
            if ($uname !== '') {
                $usernames[$uname] = true;
            }
        }

        if (!empty($usernames)) {
            $placeholders = implode(',', array_fill(0, count($usernames), '?'));
            try {
                $stmtLogin = $db->prepare("SELECT username, COALESCE(NULLIF(last_login_real,''), first_login_real) AS last_login_real, last_uptime, last_status, customer_name, room_name, blok_name, raw_comment, validity FROM login_history WHERE username IN ($placeholders)");
                $stmtLogin->execute(array_keys($usernames));
                $loginMap = [];
                foreach ($stmtLogin->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $loginMap[$row['username']] = [
                        'last_login_real' => $row['last_login_real'] ?? '',
                        'last_uptime' => $row['last_uptime'] ?? '',
                        'last_status' => $row['last_status'] ?? '',
                        'customer_name' => $row['customer_name'] ?? '',
                        'room_name' => $row['room_name'] ?? '',
                        'blok_name' => $row['blok_name'] ?? '',
                        'raw_comment' => $row['raw_comment'] ?? '',
                        'validity' => $row['validity'] ?? ''
                    ];
                }
                $rekeyed = [];
                $now_ts = time();
                foreach ($finalLogs as $k => $log) {
                    $uname = $log['username'] ?? '';
                    $lastLogin = $loginMap[$uname]['last_login_real'] ?? '';
                    $uptimeRaw = $loginMap[$uname]['last_uptime'] ?? '';
                    $statusRaw = strtolower((string)($loginMap[$uname]['last_status'] ?? ''));
                    $validityRaw = (string)($loginMap[$uname]['validity'] ?? '');
                    $commentRaw = (string)($loginMap[$uname]['raw_comment'] ?? '');
                    $ts = $lastLogin ? strtotime($lastLogin) : false;
                    if ($ts !== false && $uptimeRaw !== '') {
                        $uptimeSec = parse_uptime_seconds($uptimeRaw);
                        $profile_minutes = 0;
                        if (function_exists('auto_rusak_profile_minutes')) {
                            $profile_minutes = auto_rusak_profile_minutes($validityRaw, $commentRaw);
                        }
                        if ($profile_minutes <= 0 && function_exists('detect_profile_kind_from_label')) {
                            $profile_minutes = (int)detect_profile_kind_from_label($validityRaw);
                        }
                        if ($profile_minutes <= 0) {
                            $profile_minutes = 10;
                        }
                        $allowed_end = $ts + ($profile_minutes * 60);
                        if ($uptimeSec > 0) {
                            $end_ts = max($allowed_end, $ts + $uptimeSec);
                        } else {
                            $end_ts = $allowed_end;
                        }
                        if ($statusRaw === 'online' && $end_ts < ($now_ts - 60)) {
                            $statusRaw = 'used';
                        }
                    } elseif ($ts !== false) {
                        $profile_minutes = 0;
                        if (function_exists('auto_rusak_profile_minutes')) {
                            $profile_minutes = auto_rusak_profile_minutes($validityRaw, $commentRaw);
                        }
                        if ($profile_minutes <= 0 && function_exists('detect_profile_kind_from_label')) {
                            $profile_minutes = (int)detect_profile_kind_from_label($validityRaw);
                        }
                        if ($profile_minutes <= 0) {
                            $profile_minutes = 10;
                        }
                        $allowed_end = $ts + ($profile_minutes * 60);
                        if ($statusRaw === 'online' && $allowed_end < ($now_ts - 60)) {
                            $statusRaw = 'used';
                        }
                    }
                    $log['uptime'] = $uptimeRaw;
                    $log['status'] = $statusRaw !== '' ? $statusRaw : ($log['status'] ?? 'USED');
                    $log['customer_name'] = $loginMap[$uname]['customer_name'] ?? '';
                    $log['room_name'] = $loginMap[$uname]['room_name'] ?? '';
                    $log['blok_name'] = $loginMap[$uname]['blok_name'] ?? '';
                    $log['validity'] = $loginMap[$uname]['validity'] ?? '';
                    if (($log['comment'] ?? '') === '' && !empty($loginMap[$uname]['raw_comment'])) {
                        $log['comment'] = $loginMap[$uname]['raw_comment'];
                    }
                    $ts = $lastLogin ? strtotime($lastLogin) : false;
                    if ($ts !== false) {
                        $log['time_str'] = date('d/m/Y H:i', $ts);
                        $rekeyed[$ts . '_' . str_pad((string)count($rekeyed), 4, '0', STR_PAD_LEFT)] = $log;
                    } else {
                        $rekeyed[$k] = $log;
                    }
                }
                $finalLogs = $rekeyed;
            } catch (Exception $e) {
            }
        }
    }

    krsort($finalLogs, SORT_STRING);
    $maxShow = 8; $count = 0;
    foreach ($finalLogs as $log) {
        if ($count >= $maxShow) break;

        $blokDisplay = "-";
        $blokRaw = trim((string)($log['blok_name'] ?? ''));
        if ($blokRaw !== '') {
            $blokRaw = strtoupper(preg_replace('/^BLOK[-_\s]*/i', '', $blokRaw));
            $blokRaw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $blokRaw));
            if (preg_match('/^([A-Z])/', $blokRaw, $mb)) {
                $blokDisplay = $mb[1];
            } elseif ($blokRaw !== '') {
                $blokDisplay = $blokRaw;
            }
        } elseif (preg_match('/Blok-([A-Za-z0-9]+)/i', $log['comment'], $match)) {
            $blk = strtoupper($match[1]);
            $blokDisplay = preg_match('/^([A-Z])/', $blk, $mb) ? $mb[1] : $blk;
        } elseif ($log['comment'] != "") {
            $cleanCom = preg_replace('/[^A-Za-z0-9]/', '', $log['comment']);
            if (strlen($cleanCom) > 0) $blokDisplay = strtoupper(substr($cleanCom, 0, 1));
        }

        $paketTitle = trim((string)($log['paket'] ?? ''));
        $titleAttr = $paketTitle !== '' && $paketTitle !== '-' ? " title=\"Paket: " . htmlspecialchars($paketTitle) . "\"" : '';

        $nameDisplay = trim((string)($log['customer_name'] ?? ''));
        if ($nameDisplay !== '') {
            if (function_exists('format_customer_name')) {
                $nameDisplay = format_customer_name($nameDisplay);
            } else {
                $nameDisplay = ucwords(strtolower($nameDisplay));
            }
        } else {
            $nameDisplay = '-';
        }
        $roomDisplay = trim((string)($log['room_name'] ?? ''));
        $roomDisplay = $roomDisplay !== '' ? format_room_short_local($roomDisplay) : '-';

        $profileRaw = trim((string)($log['paket'] ?? ''));
        if ($profileRaw === '') $profileRaw = trim((string)($log['validity'] ?? ''));
        $profileDisplay = $profileRaw !== '' && function_exists('resolve_profile_label') ? resolve_profile_label($profileRaw) : $profileRaw;
        if ($profileDisplay === '') $profileDisplay = '-';

        $statusLabel = strtoupper((string)($log['status'] ?? 'USED'));
        if ($statusLabel === 'USED') {
            $statusLabel = 'TERPAKAI';
        }
        $statusColor = '#6c757d';
        if ($statusLabel === 'ONLINE') $statusColor = '#2ecc71';
        elseif ($statusLabel === 'TERPAKAI') $statusColor = '#00c0ef';
        elseif ($statusLabel === 'RUSAK') $statusColor = '#e74c3c';
        elseif ($statusLabel === 'RETUR') $statusColor = '#f39c12';
        elseif ($statusLabel === 'INVALID') $statusColor = '#9b59b6';

        echo "<tr class='zoom-resilient'>";
        echo "<td class='time-col'>" . substr($log['time_str'], 11, 5) . "</td>";
        echo "<td class='user-col' title='" . htmlspecialchars($log['username']) . "'>" . strtoupper($log['username']) . "</td>";
        echo "<td class='profile-col text-center'" . $titleAttr . ">" . htmlspecialchars($profileDisplay) . "</td>";
        echo "<td class='name-col text-center'>" . htmlspecialchars($nameDisplay) . "</td>";
        echo "<td class='blok-col text-center'><span class='blok-badge'>" . $blokDisplay . "</span></td>";
        echo "<td class='room-col text-center'>" . htmlspecialchars($roomDisplay) . "</td>";
        echo "<td class='status-col text-center'><span class='status-badge' style='background:" . $statusColor . "'>" . $statusLabel . "</span></td>";
        echo "</tr>";
        $count++;
    }
    if ($count == 0) { echo "<tr><td colspan='7' class='text-center' style='padding:20px;'>Belum ada transaksi bulan ini.</td></tr>"; }
}
?>