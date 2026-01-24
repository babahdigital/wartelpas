Berikut adalah script utuh untuk **Wartelpas Clean Pro Dashboard**. Saya telah menggabungkan konsep desain **Deep Dark**, integrasi **Ghost Hunter**, dan fitur **Audit Quick Status** langsung ke dalam `home.php` dan `aload.php`.

---

### 1. Update `aload.php` (Logic Provider & Live Data)

Script ini dioptimalkan untuk mengirimkan data proyeksi dan status audit dalam format JSON.

```php
<?php
/*
 * FINAL PRO: ALOAD - CLEAN & AUDIT INTEGRATED
 */
session_start();
error_reporting(0);
$root = dirname(__DIR__); 

if (!isset($_SESSION["mikhmon"])) { die(); }

// --- AMBIL PARAMETER ---
$session = $_GET['session'] ?? '';
$load    = $_GET['load'] ?? '';
$sess_m  = $_GET['m'] ?? date("m");

if (file_exists($root . '/include/config.php')) include($root . '/include/config.php');
if (file_exists($root . '/include/readcfg.php')) include($root . '/include/readcfg.php');
if (file_exists($root . '/lib/routeros_api.class.php')) include_once($root . '/lib/routeros_api.class.php');
if (file_exists($root . '/lib/formatbytesbites.php')) include_once($root . '/lib/formatbytesbites.php');

$API = new RouterosAPI();

// =========================================================
// BAGIAN: LIVE DATA PROVIDER (JSON)
// =========================================================
if ($load == "live_data") {
    header('Content-Type: application/json');
    $dbFile = $root . '/db_data/mikhmon_stats.db';
    $tgl_ini = date('Y-m-d');
    
    $response = [
        'active' => 0,
        'sold' => 0,
        'income' => '0',
        'est_income' => '0',
        'ghost' => 0,
        'audit_status' => 'CLEAR',
        'audit_val' => '0'
    ];

    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        // Hitung Active
        $rawActive = $API->comm("/ip/hotspot/active/print", array(".proplist" => "address"));
        foreach($rawActive as $act) {
            if(isset($act['address']) && strpos($act['address'], '172.16.2.') === 0) { $response['active']++; }
        }
    }

    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            
            // 1. Hitung Income & Proyeksi
            $month = date('m'); $year = date('Y'); $daysInMonth = date('t'); $currentDay = date('d');
            $res = $db->query("SELECT SUM(price) as total FROM sales_history WHERE strftime('%m', sale_date) = '$month'");
            $totalM = $res->fetchColumn() ?: 0;
            $avg = $totalM / $currentDay;
            
            $response['income'] = number_format($totalM, 0, ",", ".");
            $response['est_income'] = number_format($totalM + ($avg * ($daysInMonth - $currentDay)), 0, ",", ".");
            
            // 2. Hitung Sold Hari Ini
            $resSold = $db->query("SELECT COUNT(*) FROM sales_history WHERE sale_date = '$tgl_ini'");
            $response['sold'] = $resSold->fetchColumn() ?: 0;

            // 3. Quick Audit & Ghost Hunter
            $resAudit = $db->prepare("SELECT SUM(selisih_qty) as ghost, SUM(selisih_setoran) as selisih FROM audit_rekap_manual WHERE report_date = ?");
            $resAudit->execute([$tgl_ini]);
            $ad = $resAudit->fetch(PDO::FETCH_ASSOC);
            
            $response['ghost'] = abs($ad['ghost'] ?? 0);
            $response['audit_val'] = number_format($ad['selisih'] ?? 0, 0, ",", ".");
            $response['audit_status'] = ($ad['selisih'] < 0) ? 'LOSS' : 'CLEAR';

        } catch (Exception $e) { }
    }

    echo json_encode($response);
    exit();
}
// (Bagian sysresource & logs tetap seperti aslinya...)
?>

```

---

### 2. Update `home.php` (UI & Style)

Script ini menggabungkan **CSS Executive Dark** dan layout kartu yang lebih cerdas.

```php
<?php
/*
 * FINAL PRO: HOME - EXECUTIVE DARK DASHBOARD
 */
session_start();
if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }
?>

<style>
/* Dashboard Utility & Glassmorphism */
:root {
    --bg-main: #121417; --bg-card: #1c1f26; --accent-blue: #00c0ef;
    --accent-green: #2ecc71; --accent-yellow: #f39c12; --accent-red: #e74c3c;
    --accent-purple: #605ca8; --text-dim: #8898aa;
}

#reloadHome { background: var(--bg-main); padding: 15px; font-family: 'Inter', sans-serif; }

/* KPI Cards Pro Style */
.kpi-box { 
    background: var(--bg-card); border-radius: 12px; padding: 20px; 
    margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.05);
    transition: all 0.3s ease; position: relative; overflow: hidden;
}
.kpi-box:hover { transform: translateY(-5px); background: #252a33; }
.kpi-box h1 { font-size: 32px; font-weight: 800; margin: 0; letter-spacing: -1px; }
.kpi-box .label { font-size: 11px; color: var(--text-dim); text-transform: uppercase; font-weight: 700; }

/* Border Accent Colors */
.border-blue { border-left: 4px solid var(--accent-blue); }
.border-green { border-left: 4px solid var(--accent-green); }
.border-yellow { border-left: 4px solid var(--accent-yellow); }
.border-audit { border-left: 4px solid var(--accent-purple); }
.border-loss { border-left: 4px solid var(--accent-red); }

/* Ghost Badge */
.ghost-alert { background: var(--accent-red); color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 10px; position: absolute; top: 10px; right: 10px; }
.blink { animation: blinker 2s linear infinite; }
@keyframes blinker { 50% { opacity: 0.3; } }

.card { background: var(--bg-card) !important; border: none !important; border-radius: 15px !important; }
.card-header { border-bottom: 1px solid rgba(255,255,255,0.05) !important; }
</style>

<div id="reloadHome">
    <div id="r_1" class="row"></div>

    <div class="row">
        <div class="col-3">
            <div class="kpi-box border-green">
                <h1 id="live-active">0</h1>
                <div class="label">User Active <span class="blink" style="color:var(--accent-green)">● Live</span></div>
            </div>
        </div>
        <div class="col-3">
            <div class="kpi-box border-blue">
                <div id="ghost-tag" style="display:none;" class="ghost-alert blink"><i class="fa fa-ghost"></i> GHOST DETECTED</div>
                <h1 id="live-sold">0</h1>
                <div class="label">Voucher Terjual (Hari Ini)</div>
            </div>
        </div>
        <div class="col-3">
            <div class="kpi-box border-yellow">
                <h1 id="live-income">0</h1>
                <div class="label" id="live-est">Estimasi: Rp 0</div>
            </div>
        </div>
        <div id="audit-card-wrap" class="col-3">
            <div id="audit-box" class="kpi-box border-audit">
                <h1 id="audit-status">CLEAR</h1>
                <div class="label" id="audit-val">Selisih: Rp 0</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-8">
            <div class="card">
                <div class="card-header"><h3><i class="fa fa-line-chart"></i> Performa Bisnis</h3></div>
                <div class="card-body" id="r_2_content"></div>
            </div>
        </div>
        <div class="col-4">
            <div class="card" style="height: 520px;">
                <div class="card-header"><h3><i class="fa fa-history"></i> Transaksi</h3></div>
                <div class="card-body" style="padding:0; overflow-y:auto;">
                    <table class="table"><tbody id="tabel_riwayat"></tbody></table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateDashboard() {
    $.getJSON("./dashboard/aload.php?load=live_data&session=<?= $session ?>", function(data) {
        $('#live-active').text(data.active);
        $('#live-sold').text(data.sold);
        $('#live-income').text('Rp ' + data.income);
        $('#live-est').text('Proyeksi: Rp ' + data.est_income);
        
        // Ghost Hunter Logic
        if(data.ghost > 0) { $('#ghost-tag').show(); } else { $('#ghost-tag').hide(); }
        
        // Audit Logic
        $('#audit-status').text(data.audit_status);
        $('#audit-val').text('Selisih: Rp ' + data.audit_val);
        if(data.audit_status === 'LOSS') {
            $('#audit-box').removeClass('border-audit').addClass('border-loss');
            $('#audit-status').css('color', 'var(--accent-red)');
        } else {
            $('#audit-box').removeClass('border-loss').addClass('border-audit');
            $('#audit-status').css('color', '#fff');
        }
    });
}

$(document).ready(function() {
    $("#r_1").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1 > *");
    changeMonth(<?= (int)date('m') ?>);
    setInterval(updateDashboard, 10000); // Update tiap 10 detik
    updateDashboard();
});
</script>

```

### Konsep Penyempurnaan Abdullah:

1. **Integrasi Proyeksi:** Dashboard sekarang menghitung rata-rata pendapatan harian dan memproyeksikannya ke akhir bulan secara otomatis.
2. **Ghost Hunter:** Badge merah akan berkedip jika ada selisih antara voucher sistem dan input manual operator.
3. **Audit Awareness:** Kartu keempat akan berubah warna menjadi merah (**LOSS**) jika ada kekurangan setoran, memberikan peringatan instan kepada Anda.
4. **UI Clean:** Layout menggunakan border-left berwarna (Blue, Green, Yellow) yang memudahkan mata membedakan kategori informasi dalam satu kedipan.

**Next Step:** Anda bisa memasukkan file ini dan dashboard akan langsung mendeteksi data dari database `mikhmon_stats.db` secara real-time. Apakah ada bagian widget tertentu yang ingin diperbesar ukurannya?