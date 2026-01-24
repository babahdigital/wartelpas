<?php
/*
 * ALOAD - CLEAN PRO DASHBOARD DATA PROVIDER
 */
session_start();
error_reporting(0);

$root = dirname(__DIR__); 
if (!isset($_SESSION["mikhmon"])) { die(); }

// --- PARAMETERS ---
$load = isset($_GET['load']) ? $_GET['load'] : '';
$sess_m = isset($_GET['m']) ? $_GET['m'] : '';

if (!empty($sess_m)) { $_SESSION['filter_month'] = (int)$sess_m; }
if (!isset($_SESSION['filter_month'])) { $_SESSION['filter_month'] = (int)date("m"); }
$_SESSION['filter_year'] = (int)date("Y");

// --- INCLUDES ---
include_once($root . '/include/config.php');
include_once($root . '/include/readcfg.php');
include_once($root . '/lib/routeros_api.class.php');
include_once($root . '/lib/formatbytesbites.php');

$API = new RouterosAPI();

// --- CASE: LIVE DATA (JSON) ---
if ($load == "live_data") {
    header('Content-Type: application/json');
    $res = ['active'=>0, 'sold'=>0, 'income'=>'0', 'est_income'=>'0', 'ghost'=>0, 'audit_status'=>'CLEAR', 'audit_val'=>'0', 'audit_detail'=>[]];

    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $active = $API->comm("/ip/hotspot/active/print", [".proplist"=>"address"]);
        if(is_array($active)) {
            foreach($active as $a) { if(strpos($a['address'], '172.16.2.') === 0) $res['active']++; }
        }
    }

    $dbFile = $root . '/db_data/mikhmon_stats.db';
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $today = date('Y-m-d');
            $m = date('m'); $y = date('Y');
            
            // Hitung Income & Terjual
            $qSales = $db->prepare("SELECT SUM(price) as total, COUNT(*) as qty FROM sales_history WHERE sale_date LIKE ?");
            $qSales->execute([$y.'-'.$m.'%']);
            $sData = $qSales->fetch(PDO::FETCH_ASSOC);
            $res['income'] = number_format($sData['total'], 0, ',', '.');
            
            $qToday = $db->prepare("SELECT COUNT(*) as qty FROM sales_history WHERE sale_date = ?");
            $qToday->execute([$today]);
            $res['sold'] = $qToday->fetchColumn();

            // Hitung Proyeksi
            $day = (int)date('d'); $daysInM = (int)date('t');
            $avg = ($sData['total'] / $day);
            $res['est_income'] = number_format($sData['total'] + ($avg * ($daysInM - $day)), 0, ',', '.');

            // Audit
            $qAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = ?");
            $qAudit->execute([$today]);
            $aData = $qAudit->fetch(PDO::FETCH_ASSOC);
            $res['ghost'] = abs($aData['selisih_qty'] ?: 0);
            $res['audit_val'] = number_format($aData['selisih_setoran'] ?: 0, 0, ',', '.');
            $res['audit_status'] = ($aData['selisih_setoran'] < 0) ? 'LOSS' : 'CLEAR';
            $res['audit_detail'] = ['ghost'=>$res['ghost'], 'cash_expected'=>number_format($aData['expected_setoran'], 0, ',', '.')];
        } catch(Exception $e) {}
    }
    echo json_encode($res);
    exit;
}

// --- CASE: SYSRESOURCE ---
if ($load == "sysresource") {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $resource = $API->comm("/system/resource/print")[0];
        $cpu = $resource['cpu-load'];
        $uptime = $resource['uptime'];
        $ram = formatBytes($resource['free-memory']);
        $hdd = formatBytes($resource['free-hdd-space']);
    }
    echo '<div id="r_1" class="resource-footer">
            <span><i class="fa fa-server"></i> CPU: '.$cpu.'%</span>
            <span><i class="fa fa-microchip"></i> RAM: '.$ram.'</span>
            <span><i class="fa fa-hdd-o"></i> HDD: '.$hdd.'</span>
            <span><i class="fa fa-bolt"></i> Uptime: '.$uptime.'</span>
          </div>';
    exit;
}

// --- CASE: LOGS (TABLE TRANSAKSI) ---
if ($load == "logs") {
    $m = $_SESSION['filter_month'];
    $y = $_SESSION['filter_year'];
    $dbFile = $root . '/db_data/mikhmon_stats.db';
    
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $stmt = $db->prepare("SELECT * FROM sales_history WHERE strftime('%m', sale_date) = ? AND strftime('%Y', sale_date) = ? ORDER BY id DESC LIMIT 20");
            $stmt->execute([str_pad($m, 2, '0', STR_PAD_LEFT), $y]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(count($rows) == 0) {
                echo "<tr><td colspan='4' class='text-center' style='padding:30px; color:#555;'>Belum ada transaksi.</td></tr>";
            }

            foreach($rows as $row) {
                // Ekstrak Blok dari Comment
                $blok = "-";
                if(preg_match('/Blok-([A-Z])/i', $row['comment'], $match)) { $blok = strtoupper($match[1]); }
                
                // Warna IDR
                $color = "#ccc";
                if($row['price'] >= 20000) $color = "var(--accent-yellow)";
                elseif($row['price'] >= 10000) $color = "var(--accent-blue)";
                elseif($row['price'] >= 5000) $color = "var(--accent-green)";

                echo "<tr>";
                echo "<td style='color:var(--text-dim); font-family:monospace;'>".date('H:i', strtotime($row['sale_date']))."</td>";
                echo "<td style='font-weight:600;'>".$row['username']."</td>";
                echo "<td align='center'><span style='background:#333; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:bold;'>".$blok."</span></td>";
                echo "<td align='right' style='color:".$color."; font-weight:bold;'>".number_format($row['price'],0,',','.')."</td>";
                echo "</tr>";
            }
        } catch(Exception $e) {}
    }
    exit;
}

// --- CASE: HOTSPOT (CHART) ---
if ($load == "hotspot") {
    // Logic chart tetap menggunakan Highcharts dari file aload.php yang lama 
    // namun pastikan background: 'transparent' pada konfigurasinya.
    include($root . '/dashboard/chart_logic.php'); // Asumsi logic dipisah atau tulis ulang di sini
}
?>