<?php
/*
 * LAPORAN KEUANGAN (V-SMART)
 * Fitur: Valid (Income), Rusak (Income), Retur (0), Invalid (Loss)
 */
session_start();
error_reporting(0);

// Setup
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$data_valid = [];
$data_loss = [];
$total_income = 0;
$total_loss = 0;

// Filter Input
$req_show = $_GET['show'] ?? 'harian';
$target_date = $_GET['idhr'] ?? date("M/d/Y");

// 1. FETCH AUDIT LOG (PENTING!)
// Kita ambil daftar user yang PERNAH di-invalid-kan untuk cross-check history
$auditMap = [];
if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $logs = $db->query("SELECT username, reason FROM security_log WHERE reason LIKE '%INVALID%'");
        foreach($logs as $l) $auditMap[$l['username']] = true;
    } catch(Exception $e){}
}

// 2. FETCH SALES HISTORY
$raw_data = [];
if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $res = $db->query("SELECT full_raw_data FROM sales_history ORDER BY id DESC");
        foreach($res as $r) $raw_data[] = $r['full_raw_data'];
    } catch(Exception $e){}
}

// 3. PROCESS DATA
foreach ($raw_data as $row) {
    // Format: Date-|-Time-|-User-|-Price-|-|-|-|-|-Comment
    $d = explode("-|-", $row);
    if(count($d) < 9) continue;
    
    $date = $d[0]; $user = $d[2]; $price = (int)$d[3]; $comm = $d[8];
    
    // Filter Tanggal (Harian)
    if ($req_show == 'harian' && stripos($date, $target_date) === false) continue;
    
    // --- CORE LOGIC ACCOUNTING ---
    
    // A. CEK DB LOG: Apakah user ini pernah di-invalid-kan?
    // Jika YA, maka hapus dari Income, masukkan Loss.
    if (isset($auditMap[$user]) || stripos($comm, 'Audit: INVALID') !== false) {
        $total_loss += $price;
        $data_loss[] = ['d'=>$date, 'u'=>$user, 'p'=>$price, 'c'=>"INVALID (Audit Log)"];
        continue;
    }
    
    // B. USER BARU (HASIL RETUR): Income 0 (Agar tidak double)
    if (stripos($comm, 'Valid: Retur') !== false) {
        $data_valid[] = ['d'=>$date, 'u'=>$user, 'p'=>0, 'c'=>$comm . " (Free Replacement)"];
        continue;
    }
    
    // C. USER LAMA (YANG RUSAK): Tetap Income (Jangan dihapus)
    // Karena uang fisik sudah diterima dari user ini.
    if (stripos($comm, 'Audit: RUSAK') !== false) {
        $comm .= " [RETUR]";
        $data_valid[] = ['d'=>$date, 'u'=>$user, 'p'=>$price, 'c'=>$comm];
        $total_income += $price;
        continue;
    }
    
    // D. NORMAL
    $data_valid[] = ['d'=>$date, 'u'=>$user, 'p'=>$price, 'c'=>$comm];
    $total_income += $price;
}
?>

<style>
    .card-dark { background: #343a40; color: white; }
    .table-dark-c th { background: #454d55; border-color: #666; color:white; }
    .table-dark-c td { border-color: #666; }
</style>

<div class="row">
    <div class="col-md-6">
        <div class="small-box bg-success">
            <div class="inner"><h3>Rp <?= number_format($total_income,0,',','.') ?></h3><p>Pendapatan Bersih</p></div>
            <div class="icon"><i class="fa fa-money"></i></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="small-box bg-danger">
            <div class="inner"><h3>Rp <?= number_format($total_loss,0,',','.') ?></h3><p>Estimasi Kerugian (Invalid)</p></div>
            <div class="icon"><i class="fa fa-trash"></i></div>
        </div>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">Rincian Penjualan Valid</h3>
        <div class="card-tools"><button class="btn btn-tool" onclick="window.print()"><i class="fa fa-print"></i></button></div>
    </div>
    <div class="card-body p-0 table-responsive" style="height: 300px;">
        <table class="table table-head-fixed table-dark-c text-nowrap">
            <thead><tr><th>Tanggal</th><th>User</th><th>Info</th><th class="text-right">Harga</th></tr></thead>
            <tbody>
                <?php foreach($data_valid as $v): ?>
                <tr>
                    <td><?=$v['d']?></td><td><?=$v['u']?></td><td><small><?=$v['c']?></small></td>
                    <td class="text-right"><?= number_format($v['p'],0,',','.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if(count($data_loss)>0): ?>
<div class="card card-danger mt-3">
    <div class="card-header"><h3 class="card-title">Rincian Kerugian (Invalid)</h3></div>
    <div class="card-body p-0">
        <table class="table table-dark-c">
            <thead><tr><th>User</th><th>Alasan</th><th class="text-right">Rugi</th></tr></thead>
            <tbody>
                <?php foreach($data_loss as $l): ?>
                <tr>
                    <td><?=$l['u']?></td><td><?=$l['c']?></td>
                    <td class="text-right"><?= number_format($l['p'],0,',','.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>