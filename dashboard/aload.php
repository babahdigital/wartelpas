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
$test_date = isset($_GET['test_date']) ? $_GET['test_date'] : '';
$testDateObj = DateTime::createFromFormat('Y-m-d', $test_date);
$hasTestDate = $testDateObj && $testDateObj->format('Y-m-d') === $test_date;
$testYear = $hasTestDate ? (int)$testDateObj->format('Y') : null;
$testMonth = $hasTestDate ? (int)$testDateObj->format('m') : null;
$testDay = $hasTestDate ? (int)$testDateObj->format('d') : null;
$testDateStr = $hasTestDate ? $testDateObj->format('Y-m-d') : '';

// --- SET TIMEZONE ---
if (isset($_SESSION['timezone']) && !empty($_SESSION['timezone'])) {
    date_default_timezone_set($_SESSION['timezone']);
}

// --- SET FILTER SESSION ---
if (!empty($sess_m)) { $_SESSION['filter_month'] = (int)$sess_m; }
if (!isset($_SESSION['filter_month'])) { $_SESSION['filter_month'] = (int)date("m"); }
$_SESSION['filter_year'] = (int)date("Y");
if ($hasTestDate) {
    $_SESSION['filter_month'] = $testMonth;
    $_SESSION['filter_year'] = $testYear;
}

// --- INCLUDE LIBRARY ---
if (file_exists($root . '/include/config.php')) include($root . '/include/config.php');
if (file_exists($root . '/include/readcfg.php')) include($root . '/include/readcfg.php');
if (file_exists($root . '/lib/routeros_api.class.php')) include_once($root . '/lib/routeros_api.class.php');
if (file_exists($root . '/lib/formatbytesbites.php')) include_once($root . '/lib/formatbytesbites.php');

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

// =========================================================
// BAGIAN KHUSUS: LIVE DATA PROVIDER (JSON)
// =========================================================
if ($load == "live_data") {

    header('Content-Type: application/json');

    $dataResponse = [
        'active' => 0,
        'sold' => 0,
        'income' => '0',
        'est_income' => '0',
        'ghost' => 0,
        'audit_status' => 'CLEAR',
        'audit_val' => '0'
    ];

    $counthotspotactive = 0;
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $rawActive = $API->comm("/ip/hotspot/active/print", array(".proplist" => "address"));
        if (is_array($rawActive)) {
            foreach ($rawActive as $act) {
                if (isset($act['address']) && strpos($act['address'], '172.16.2.') === 0) {
                    $counthotspotactive++;
                }
            }
        }
    }

    $dbFile = $root . '/db_data/mikhmon_stats.db';
    $today = $hasTestDate ? $testDateStr : date('Y-m-d');
    $month = $hasTestDate ? str_pad((string)$testMonth, 2, '0', STR_PAD_LEFT) : date('m');
    $year = $hasTestDate ? (string)$testYear : date('Y');
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
            $tables = [];
            if (table_exists($db, 'sales_history')) $tables[] = 'sales_history';
            if (table_exists($db, 'live_sales')) $tables[] = 'live_sales';

            foreach ($tables as $tbl) {
                $stmtIncome = $db->prepare("SELECT SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1))
                    FROM $tbl
                    WHERE (sale_date LIKE :m OR raw_date LIKE :r1 OR raw_date LIKE :r2 OR raw_date LIKE :r3)");
                $stmtIncome->execute([':m' => $monthLike, ':r1' => $raw1, ':r2' => $raw2, ':r3' => $raw3]);
                $sumIncome += (int)($stmtIncome->fetchColumn() ?: 0);

                $stmtSold = $db->prepare("SELECT COUNT(*)
                    FROM $tbl
                    WHERE (sale_date = :d OR raw_date LIKE :dr1 OR raw_date LIKE :dr2 OR raw_date LIKE :dr3)");
                $stmtSold->execute([':d' => $today, ':dr1' => $dayRaw1, ':dr2' => $dayRaw2, ':dr3' => $dayRaw3]);
                $sumSold += (int)($stmtSold->fetchColumn() ?: 0);
            }

            $avgDaily = $currentDay > 0 ? ($sumIncome / $currentDay) : 0;
            $estIncome = $sumIncome + ($avgDaily * ($daysInMonth - $currentDay));

            $dataResponse['sold'] = $sumSold;
            $dataResponse['income'] = number_format($sumIncome, 0, ",", ".");
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

    $currentMonth = $hasTestDate ? (int)$testMonth : (int)date('m');
    $currentYear = $hasTestDate ? (int)$testYear : (int)date('Y');
    $currentDay = $hasTestDate ? (int)$testDay : (int)date('d');

    $dbFile = $root . '/db_data/mikhmon_stats.db';
    $rawDataMerged = [];
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $resSales = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC LIMIT 1500");
            if ($resSales) { foreach($resSales as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
            if (table_exists($db, 'live_sales')) {
                try {
                    $resLive = $db->query("SELECT full_raw_data FROM live_sales ORDER BY id DESC LIMIT 500");
                    if ($resLive) { foreach($resLive as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
                } catch (Exception $e) { }
            }
        } catch (Exception $e) { }
    }
    $rawDataMerged = array_unique($rawDataMerged);

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

    foreach ($rawDataMerged as $rowString) {
        $parts = explode("-|-", $rowString);
        if (count($parts) >= 4) {
            $rawDateString = trim($parts[0]);
            $price = (int)preg_replace('/[^0-9]/', '', $parts[3]);
            $tstamp = strtotime(str_replace("/", "-", normalizeDate($rawDateString)));
            if (!$tstamp) continue;
            $d_month = (int)date("m", $tstamp); $d_year = (int)date("Y", $tstamp); $d_day = (int)date("d", $tstamp);
            if ($d_month == $filterMonth && $d_year == $filterYear) {
                if ($d_day >= $startDay && $d_day <= $endDay) {
                    $dailyIncome[$d_day] += $price;
                    $dailyQty[$d_day] += 1;
                }
            }
        }
    }

    $categories = [];
    $dataIncome = [];
    $dataQty = [];
    for ($d = $startDay; $d <= $endDay; $d++) {
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
                Highcharts.chart('chart_income_stat', {
                    chart: { backgroundColor: 'transparent', plotBackgroundColor: 'transparent', type: 'area', spacingBottom: 0, reflow: true, zoomType: 'xy', height: null, borderWidth: 0, spacing: [10, 0, 10, 0] },
                    title: { text: null },
                    xAxis: {
                        categories: <?= $jsonCategories ?>,
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
    $dbFile = $root . '/db_data/mikhmon_stats.db';
    $rawDataMerged = [];

    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            if (table_exists($db, 'sales_history')) {
                $resSales = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC LIMIT 1500");
                if ($resSales) { foreach($resSales as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
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
        $parts = explode("-|-", $rowString);
        if (count($parts) >= 4) {
            $rawDateString = trim($parts[0]);
            $price = (int)preg_replace('/[^0-9]/', '', $parts[3]);
            $username = isset($parts[2]) ? trim($parts[2]) : '';
            $tstamp = strtotime(str_replace("/", "-", normalizeDate($rawDateString)));
            if (!$tstamp) continue;

            $d_month = (int)date("m", $tstamp);
            $d_year  = (int)date("Y", $tstamp);
            if ($d_month != $filterMonth || $d_year != $filterYear) continue;
            if ($hasTestDate) {
                $d_full = date('Y-m-d', $tstamp);
                if ($d_full !== $testDateStr) continue;
            }

            $paket = (isset($parts[7]) && $parts[7] != "") ? trim($parts[7]) : '-';
            $comment = (isset($parts[8])) ? trim($parts[8]) : '';
            $key = $tstamp . "_" . rand(100,999);
            $finalLogs[$key] = [ 'time_str' => date("d/m/Y H:i", $tstamp), 'username' => $username, 'paket' => $paket, 'comment' => $comment, 'price' => $price ];
        }
    }

    krsort($finalLogs);
    $maxShow = 5; $count = 0;
    foreach ($finalLogs as $log) {
        if ($count >= $maxShow) break;

        $blokDisplay = "-";
        if (preg_match('/Blok-([A-Za-z]+)/i', $log['comment'], $match)) {
            $blokDisplay = strtoupper($match[1]);
        } elseif ($log['comment'] != "") {
            $cleanCom = preg_replace('/[^A-Za-z]/', '', $log['comment']);
            if (strlen($cleanCom) > 0) $blokDisplay = strtoupper(substr($cleanCom, 0, 1));
        }

        $colorClass = "#ccc";
        if ($log['price'] >= 20000) { $colorClass = "#f39c12"; }
        elseif ($log['price'] >= 10000) { $colorClass = "#00c0ef"; }
        elseif ($log['price'] >= 5000) { $colorClass = "#00a65a"; }

        $paketTitle = trim((string)($log['paket'] ?? ''));
        $titleAttr = $paketTitle !== '' && $paketTitle !== '-' ? " title=\"Paket: " . htmlspecialchars($paketTitle) . "\"" : '';

        echo "<tr>";
        echo "<td style='color:#8898aa; font-family:monospace;'>" . substr($log['time_str'], 11, 5) . "</td>";
        echo "<td style='font-weight:600; font-size:12px; overflow:hidden; text-overflow:ellipsis;' title='" . htmlspecialchars($log['username']) . "'>" . $log['username'] . "</td>";
        echo "<td style='text-align:center;'><span style='background:#333; padding:2px 6px; border-radius:3px; font-size:10px;'>" . $blokDisplay . "</span></td>";
        echo "<td style='text-align:right; font-family:monospace; font-size:12px; font-weight:bold; color:$colorClass;'$titleAttr>" . number_format($log['price'],0,',','.') . "</td>";
        echo "</tr>";
        $count++;
    }
    if ($count == 0) { echo "<tr><td colspan='4' class='text-center' style='padding:20px;'>Belum ada transaksi bulan ini.</td></tr>"; }
}
?>