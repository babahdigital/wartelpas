<?php
/*
 * AUDIT LOG REPORT
 * Sumber Data: Database SQLite (security_log)
 */
session_start();
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

// FILTER
$y = $_GET['y'] ?? date("Y");
$mode = $_GET['mode'] ?? 'daily'; // daily, monthly, yearly

$logs = [];
if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $sql = "SELECT * FROM security_log WHERE strftime('%Y', log_date) = '$y' ORDER BY log_date DESC";
        $stmt = $db->query($sql);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}
?>

<div class="card card-dark">
    <div class="card-header d-flex justify-content-between">
        <h3 class="card-title"><i class="fa fa-shield"></i> Laporan Audit & Retur</h3>
        <button class="btn btn-warning btn-sm" onclick="window.print()">Print</button>
    </div>
    <div class="card-body">
        <div class="btn-group mb-3">
            <a href="?report=audit&mode=daily&session=<?=$_GET['session']?>" class="btn btn-default">Harian</a>
            <a href="?report=audit&mode=monthly&session=<?=$_GET['session']?>" class="btn btn-default">Bulanan</a>
        </div>
        
        <table class="table table-bordered table-dark-custom">
            <thead><tr><th>Waktu</th><th>User</th><th>Mac/IP</th><th>Alasan</th><th>Ket</th></tr></thead>
            <tbody>
                <?php foreach($logs as $l): 
                    $bg = strpos($l['reason'], 'INVALID')!==false ? 'bg-danger' : 'bg-warning';
                ?>
                <tr>
                    <td><?= $l['log_date'] ?></td>
                    <td><strong><?= $l['username'] ?></strong></td>
                    <td><?= $l['mac_address'] ?><br><small><?= $l['ip_address'] ?></small></td>
                    <td><span class="badge <?=$bg?>"><?= $l['reason'] ?></span></td>
                    <td><?= $l['comment'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>