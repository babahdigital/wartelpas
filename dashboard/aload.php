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
    $sys_hdd = isset($resource['free-hdd-space']) ? formatBytes($resource['free-hdd-space'], 2) : '0 B';
    ?>
    <div id="r_1" class="row">
      <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-calendar"></i></div><div class="box-group-area"><span>Date & Time<br><?= $sys_date . " " . $sys_time ?><br>Uptime : <?= $sys_uptime ?></span></div></div></div></div>
      <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-info-circle"></i></div><div class="box-group-area"><span>Board : <?= $sys_board ?><br/>Model : <?= $sys_model ?><br/>Router OS : <?= $sys_os ?></span></div></div></div></div>
      <div class="col-4"><div class="box bmh-75 box-bordered"><div class="box-group"><div class="box-group-icon"><i class="fa fa-server"></i></div><div class="box-group-area"><span>CPU : <?= $sys_cpu ?>%<br/>Free Mem : <?= $sys_mem ?><br/>Free HDD : <?= $sys_hdd ?></span></div></div></div></div> 
    </div>
    <?php

// =========================================================
// BAGIAN 2: DASHBOARD UTAMA & ANALISA
// =========================================================
} else if ($load == "hotspot") {
    
    // --- Initial Load HTML ---
    $countFreshUsers = 0; $counthotspotactive = 0; $liveUserBytes = []; $mikrotikScripts = [];
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $getclock = $API->comm("/system/clock/print");
        if(isset($getclock[0]['time-zone-name'])) date_default_timezone_set($getclock[0]['time-zone-name']);
        
        $allWartelUsers = $API->comm("/ip/hotspot/user/print", array("?server" => "wartel"));
        foreach ($allWartelUsers as $u) {
            if (isset($u['uptime']) && ($u['uptime'] == '0s' || $u['uptime'] == '')) { $countFreshUsers++; }
            $b_in = isset($u['bytes-in']) ? $u['bytes-in'] : 0;
            $b_out = isset($u['bytes-out']) ? $u['bytes-out'] : 0;
            $liveUserBytes[$u['name']] = $b_in + $b_out;
        }
        $rawActive = $API->comm("/ip/hotspot/active/print", array(".proplist" => "address"));
        if(is_array($rawActive)) { foreach($rawActive as $act) { if(isset($act['address']) && strpos($act['address'], '172.16.2.') === 0) { $counthotspotactive++; } } }
        $mikrotikScripts = $API->comm("/system/script/print", array("?comment" => "mikhmon"));
    }

    $filterMonth = $_SESSION['filter_month']; $filterYear = $_SESSION['filter_year'];
    $monthShort = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des'];
    $monthFull = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];

    $dbFile = $root . '/db_data/mikhmon_stats.db'; $rawDataMerged = []; $userStatsMap = [];
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $resStats = $db->query("SELECT username, bytes_total FROM user_stats");
            if ($resStats) { foreach($resStats as $row) { $userStatsMap[$row['username']] = $row['bytes_total']; } }
            $resSales = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC LIMIT 1500");
            if ($resSales) { foreach($resSales as $row) { $rawDataMerged[] = $row['full_raw_data']; } }
        } catch (Exception $e) { }
    }
    if (is_array($mikrotikScripts)) { foreach ($mikrotikScripts as $script) { if(isset($script['name'])) $rawDataMerged[] = $script['name']; } }
    $rawDataMerged = array_unique($rawDataMerged);

    $daysInMonth = (int)date("t", mktime(0, 0, 0, $filterMonth, 1, $filterYear));
    $dailyIncome = array_fill(1, $daysInMonth, 0);
    $dailyQty = array_fill(1, $daysInMonth, 0);
    $totalVoucher = 0; $totalData = 0; $totalIncome = 0;
    $blokStats = []; $totalTrxAnalisa = 0; $totalOmsetAnalisa = 0;

    foreach ($rawDataMerged as $rowString) {
        $parts = explode("-|-", $rowString);
        if (count($parts) >= 4) {
            $rawDateString = trim($parts[0]); 
            $price = (int)preg_replace('/[^0-9]/', '', $parts[3]);
            $username = isset($parts[2]) ? trim($parts[2]) : '';
            $profile = isset($parts[7]) ? trim($parts[7]) : '';
            $comment = isset($parts[8]) ? trim($parts[8]) : '';
            $tstamp = strtotime(str_replace("/", "-", normalizeDate($rawDateString)));
            if (!$tstamp) continue;
            $d_month = (int)date("m", $tstamp); $d_year  = (int)date("Y", $tstamp); $d_day   = (int)date("d", $tstamp);

            if ($d_month == $filterMonth && $d_year == $filterYear) {
                $totalVoucher++; $totalIncome += $price;
                if ($d_day >= 1 && $d_day <= $daysInMonth) {
                    $dailyIncome[$d_day] += $price;
                    $dailyQty[$d_day] += 1;
                }
                if (isset($liveUserBytes[$username])) { $totalData += $liveUserBytes[$username]; } elseif (isset($userStatsMap[$username])) { $totalData += $userStatsMap[$username]; }

                // Analisa
                $blokName = "Unknown";
                if (preg_match('/Blok-([A-Za-z0-9]+)/i', $comment, $match)) { $blokName = strtoupper($match[1]); } 
                elseif (preg_match('/Kamar-([A-Za-z0-9]+)/i', $comment, $match)) { $blokName = "KMR-".strtoupper($match[1]); }
                else { $blokName = "Lainnya"; } 
                if (!isset($blokStats[$blokName])) { $blokStats[$blokName] = ['omset'=>0, 'qty'=>0, 'paket_10'=>0, 'paket_30'=>0]; }
                $blokStats[$blokName]['omset'] += $price; $blokStats[$blokName]['qty']++;
                if (stripos($profile, '10') !== false) { $blokStats[$blokName]['paket_10']++; } 
                elseif (stripos($profile, '30') !== false) { $blokStats[$blokName]['paket_30']++; }
                $totalOmsetAnalisa += $price; $totalTrxAnalisa++;
            }
        }
    }
    $currentDay = (int)date("d");
    if ($filterMonth != (int)date("m") || $filterYear != (int)date("Y")) {
        $currentDay = $daysInMonth;
    }
    $avgDailyIncome = $currentDay > 0 ? ($totalIncome / $currentDay) : 0;
    $estIncome = $totalIncome + ($avgDailyIncome * ($daysInMonth - $currentDay));

    $jsonCategories = json_encode(array_map('strval', range(1, $daysInMonth)));
    $jsonDataIncome = json_encode(array_values($dailyIncome), JSON_NUMERIC_CHECK);
    $jsonDataQty = json_encode(array_values($dailyQty), JSON_NUMERIC_CHECK);
    $avgOmset = ($totalTrxAnalisa > 0 && count($blokStats) > 0) ? ($totalOmsetAnalisa / count($blokStats)) : 0;
    uasort($blokStats, function($a, $b) { return $b['omset'] - $a['omset']; });
    ?>

    <div id="view-dashboard">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 15px;">
            <div>
                <div style="font-size:12px; color:#aaa;">Total Pendapatan</div>
                <h2 style="margin:0; font-weight:bold; color:#fff;">Rp <span id="chart-income"><?= number_format($totalIncome, 0, ",", ".") ?></span></h2>
                <?php if ($filterMonth == (int)date("m") && $filterYear == (int)date("Y")) : ?>
                    <div style="font-size:11px; color:#00c0ef; margin-top:2px;">
                        <i class="fa fa-crosshairs"></i> Proyeksi Akhir Bulan: <b>Rp <?= number_format($estIncome, 0, ",", ".") ?></b>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tab-container" style="display: flex; gap: 5px; flex-wrap:wrap; align-items: center;">
                <button class="btn btn-sm bg-purple" onclick="$('#view-dashboard').hide(); $('#view-analytics').fadeIn();" style="margin-right:15px; box-shadow: 2px 2px 5px rgba(0,0,0,0.3);"><i class="fa fa-line-chart"></i> <b>ANALISA BISNIS</b></button>
                <?php foreach($monthShort as $mNum => $mName) {
                    $active = ($mNum == $filterMonth) ? 'font-weight:bold; color:#fff; border-bottom:2px solid #fff;' : 'color:#8898aa;';
                    echo "<a style='cursor:pointer; padding:5px; $active' onclick='changeMonth($mNum)'>$mName</a>";
                } ?>
            </div>
        </div>
        <div id="chart_container" style="width:100%;">
            <div id="chart_income_stat" style="width:100%; height:320px;"></div>
        </div>
        <script type="text/javascript">
            if(typeof Highcharts !== 'undefined') {
                Highcharts.chart('chart_income_stat', {
                    chart: { backgroundColor: 'transparent', height: 320, zoomType: 'xy' },
                    title: { text: '' },
                    xAxis: { categories: <?= $jsonCategories ?>, crosshair: true, lineColor: '#444', tickColor: '#444', labels: {style:{color:'#ccc'}}, gridLineWidth: 0 },
                    yAxis: [{
                        labels: { style: { color: '#00c0ef' }, formatter: function () { return (this.value / 1000) + 'k'; } },
                        title: { text: 'Pendapatan (Rp)', style: { color: '#00c0ef' } },
                        gridLineColor: '#333'
                    }, {
                        title: { text: 'Terjual (Lbr)', style: { color: '#f39c12' } },
                        labels: { style: { color: '#f39c12' } },
                        opposite: true,
                        gridLineWidth: 0
                    }],
                    tooltip: { shared: true, backgroundColor: 'rgba(0,0,0,0.85)', style: {color: '#fff'}, borderRadius: 8 },
                    plotOptions: {
                        area: {
                            marker: { enabled: false },
                            fillColor: {
                                linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                                stops: [[0, '#00c0ef'], [1, 'rgba(0,192,239,0)']]
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

            // Live update ditangani oleh home.php
        </script>
        
        <style>
            .blink { animation: blinker 1.5s linear infinite; }
            @keyframes blinker { 50% { opacity: 0; } }
            
            /* SEMBUNYIKAN LOAD BAR (PACE) HANYA SAAT DI HALAMAN INI */
            .pace { display: none !important; }
        </style>
    </div>

    <div id="view-analytics" style="display: none;">
        <div class="row">
            <div class="col-12">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #605ca8; padding-bottom: 10px; margin-bottom: 15px;">
                    <h3 style="margin:0; color:#fff;"><i class="fa fa-line-chart"></i> Evaluasi Bisnis (<?= $monthFull[$filterMonth] . " " . $filterYear; ?>)</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printAnalytics()" style="margin-right:5px;"><i class="fa fa-print"></i> Cetak Laporan</button>
                        <button class="btn btn-warning btn-sm" onclick="$('#view-analytics').hide(); $('#view-dashboard').fadeIn();"><i class="fa fa-arrow-left"></i> KEMBALI</button>
                    </div>
                </div>
            </div>
        </div>
        <div id="print-area">
            <div class="row">
                <div class="col-4">
                    <div class="box bg-green box-solid">
                        <div class="box-header with-border"><h3 class="box-title">BLOK SULTAN (Terlaris)</h3></div>
                        <div class="box-body text-center">
                            <?php 
                            $bestBlok = array_key_first($blokStats);
                            if ($bestBlok) { echo "<h1 style='font-size:36px; margin:0;'>$bestBlok</h1><span>Rp " . number_format($blokStats[$bestBlok]['omset'],0,',','.') . "</span>"; } 
                            else { echo "<h3>-</h3>"; }
                            ?>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="box bg-blue box-solid">
                        <div class="box-header with-border"><h3 class="box-title">TOTAL PENDAPATAN</h3></div>
                        <div class="box-body text-center"><h1 style='font-size:36px; margin:0;'>Rp <?= number_format($totalOmsetAnalisa,0,',','.'); ?></h1><span>Dari <?= $totalTrxAnalisa; ?> Transaksi</span></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="box bg-red box-solid">
                        <div class="box-header with-border"><h3 class="box-title">PERLU PERHATIAN (Sepi)</h3></div>
                        <div class="box-body text-center">
                            <?php 
                            $lowBlok = array_key_last($blokStats);
                            if ($lowBlok) { echo "<h1 style='font-size:36px; margin:0;'>$lowBlok</h1><span>Rp " . number_format($blokStats[$lowBlok]['omset'],0,',','.') . "</span>"; } 
                            else { echo "<h3>-</h3>"; }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                    <div class="box">
                        <div class="box-header"><h3 class="box-title">Detail Performa per Blok (<?= $monthFull[$filterMonth]; ?>)</h3></div>
                        <div class="box-body table-responsive no-padding">
                            <table class="table table-hover table-bordered table-striped" style="width:100%; border-collapse: collapse;">
                                <thead class="bg-gray">
                                    <tr><th style="border:1px solid #ddd;">No</th><th style="border:1px solid #ddd;">Nama Blok</th><th style="border:1px solid #ddd;" class="text-center">Voucher</th><th style="border:1px solid #ddd;" class="text-center">10 Menit</th><th style="border:1px solid #ddd;" class="text-center">30 Menit</th><th style="border:1px solid #ddd;" class="text-right">Omset</th><th style="border:1px solid #ddd;" class="text-center">Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php $rank = 1; foreach ($blokStats as $blok => $data) { if($blok == "Lainnya") continue; $statusText = "BURUK"; $statusColor = "red"; if ($data['omset'] >= $avgOmset * 1.2) { $statusText = "SANGAT BAIK"; $statusColor="green"; } elseif ($data['omset'] >= $avgOmset) { $statusText = "BAIK"; $statusColor="blue"; } elseif ($data['omset'] >= $avgOmset * 0.5) { $statusText = "CUKUP"; $statusColor="#f39c12"; } else { $statusText = "BURUK"; $statusColor="red"; } ?>
                                    <tr><td style="border:1px solid #ddd;">#<?= $rank++; ?></td><td style="border:1px solid #ddd; font-weight:bold; font-size:14px;"><?= $blok; ?></td><td style="border:1px solid #ddd;" class="text-center"><?= $data['qty']; ?></td><td style="border:1px solid #ddd;" class="text-center text-muted"><?= $data['paket_10']; ?></td><td style="border:1px solid #ddd;" class="text-center text-muted"><?= $data['paket_30']; ?></td><td style="border:1px solid #ddd;" class="text-right" style="font-weight:bold;">Rp <?= number_format($data['omset'],0,',','.'); ?></td><td style="border:1px solid #ddd; color:<?= $statusColor; ?>; font-weight:bold;" class="text-center"><?= $statusText; ?></td></tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function printAnalytics() {
        var printContent = document.getElementById('print-area').innerHTML;
        var win = window.open('', '', 'height=700,width=800');
        win.document.write('<html><head><title>Laporan Analisa Bisnis - Wartel Pas</title><style>body { font-family: sans-serif; font-size: 12px; } table { width: 100%; border-collapse: collapse; margin-top:10px; } th, td { border: 1px solid #000; padding: 5px; text-align: left; } .text-center { text-align: center; } .text-right { text-align: right; } .row { display: flex; flex-wrap: wrap; margin-bottom: 20px; } .col-4 { width: 33.33%; padding: 0 5px; box-sizing: border-box; } .box { border: 1px solid #000; padding: 10px; margin-bottom: 5px; } .box-header { border-bottom: 1px solid #ccc; font-weight:bold; margin-bottom:5px; } h1 { font-size: 24px; margin: 5px 0; } h3 { font-size: 14px; margin: 0; }</style></head><body><h2 style="text-align:center;">Laporan Evaluasi Bisnis - Wartel Pas</h2><p style="text-align:center;">Periode: <?= $monthFull[$filterMonth] . " " . $filterYear; ?></p>' + printContent + '</body></html>');
        win.document.close(); win.print();
    }
    </script>
    <?php

// =========================================================
// BAGIAN 3: RIWAYAT LOGS (TETAP SAMA)
// =========================================================
} else if ($load == "logs") {
    // (Kode Logs Tetap Sama seperti FINAL 12)
    $filterMonth = $_SESSION['filter_month']; $filterYear  = $_SESSION['filter_year'];
    if($API->connect($iphost, $userhost, decrypt($passwdhost))){ $mikrotikScripts = $API->comm("/system/script/print", array("?comment" => "mikhmon")); } else { $mikrotikScripts = []; }
    $dbFile = $root . '/db_data/mikhmon_stats.db'; $rawDataMerged = []; 
    if (file_exists($dbFile)) { try { $db = new PDO('sqlite:' . $dbFile); $resSales = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC LIMIT 500"); if ($resSales) { foreach($resSales as $row) { $rawDataMerged[] = $row['full_raw_data']; } } } catch (Exception $e) {} }
    if (is_array($mikrotikScripts)) { foreach ($mikrotikScripts as $script) { if(isset($script['name'])) $rawDataMerged[] = $script['name']; } }
    $rawDataMerged = array_unique($rawDataMerged);
    $finalLogs = [];
    foreach ($rawDataMerged as $rowString) {
        $parts = explode("-|-", $rowString);
        if (count($parts) >= 2) {
            $rawDate = isset($parts[0]) ? trim($parts[0]) : '-'; $rawTime = isset($parts[1]) ? trim($parts[1]) : '00:00:00'; 
            $dateTimeStr = normalizeDate($rawDate) . " " . $rawTime; $tstamp = strtotime(str_replace("/", "-", $dateTimeStr));
            if ($tstamp) {
                $d_month = (int)date("m", $tstamp); $d_year  = (int)date("Y", $tstamp);
                if ($d_month == $filterMonth && $d_year == $filterYear) {
                    $username = isset($parts[2]) ? trim($parts[2]) : '-'; $price = isset($parts[3]) ? (int)preg_replace('/[^0-9]/', '', $parts[3]) : 0;
                    $paket = (isset($parts[7]) && $parts[7] != "") ? trim($parts[7]) : '-'; $comment = (isset($parts[8])) ? trim($parts[8]) : '';
                    $key = $tstamp . "_" . rand(100,999);
                    $finalLogs[$key] = [ 'time_str' => date("d/m/Y H:i", $tstamp), 'username' => $username, 'paket' => $paket, 'comment' => $comment, 'price' => $price ];
                }
            }
        }
    }
    krsort($finalLogs);
    $maxShow = 20; $count = 0;
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
        echo "<td style='padding:8px 10px; vertical-align:middle; color:#888; font-family:monospace;'>" . substr($log['time_str'], 11, 5) . "</td>";
        echo "<td style='padding:8px 10px; vertical-align:middle; font-weight:600; color:#eee;'>" . $log['username'] . "</td>";
        echo "<td class='text-center' style='padding:8px 10px; vertical-align:middle;'><span style='background:#333; padding:2px 6px; border-radius:3px; font-size:10px; color:#aaa;'>" . $blokDisplay . "</span></td>";
        echo "<td class='text-right' style='padding:8px 10px; vertical-align:middle; font-family:monospace; font-size:12px; font-weight:bold; color:$colorClass;'$titleAttr>" . number_format($log['price'],0,',','.') . "</td>";
        echo "</tr>";
        $count++;
    }
    if ($count == 0) { echo "<tr><td colspan='5' class='text-center' style='padding:20px;'>Belum ada transaksi bulan ini.</td></tr>"; }
}
?>