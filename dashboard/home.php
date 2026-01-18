<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026)
 * FINAL 7: HOME - STATIC CARD LAYOUT
 * - Fix: Kerangka Card Riwayat ditulis langsung di sini agar PASTI MUNCUL.
 * - Fix: AJAX hanya mengisi bagian <tbody> saja.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); } 
else {
  if(isset($API) && !$API->connected) { $API->connect($iphost, $userhost, decrypt($passwdhost)); }
  $getclock = $API->comm("/system/clock/print");
  $clock = isset($getclock[0]) ? $getclock[0] : ['time'=>'00:00:00', 'date'=>'jan/01/1970'];
  $timezone = isset($clock['time-zone-name']) ? $clock['time-zone-name'] : 'Asia/Jakarta';
  $_SESSION['timezone'] = $timezone;
  date_default_timezone_set($timezone);
}
?>
    
<script type="text/javascript">
    function changeMonth(m) {
        // 1. Loading Visual untuk Grafik
        $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#ccc;"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br>Memproses Grafik...</div>');
        
        // 2. Loading Visual untuk Tabel (Hanya isi tbody)
        $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center" style="padding:20px;"><i class="fa fa-circle-o-notch fa-spin"></i> Mengambil data...</td></tr>');

        // 3. Request Grafik
        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m, function(data) {
            $("#r_2_content").html(data);
        });

        // 4. Request Data Riwayat (Hanya Baris) - Delay 1 detik agar aman
        setTimeout(function() {
            $.get("./dashboard/aload.php?session=<?= $session ?>&load=logs&m=" + m, function(dataLogs) {
                if(dataLogs.trim() == "") {
                    $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center">Tidak ada data.</td></tr>');
                } else {
                    $("#tabel_riwayat").html(dataLogs);
                }
            }).fail(function() {
                $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center text-danger">Gagal koneksi server.</td></tr>');
            });
        }, 1000);
    }

    $(document).ready(function() {
        $("#r_1").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1 > *");
        changeMonth(<?= (int)date('m') ?>);
    });
</script>

<div id="reloadHome">
    <div id="r_1" class="row">
        <div class="col-12 text-center" style="padding:20px;">Loading System Info...</div>
    </div>

    <div class="row">
        <div class="col-8">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa fa-wifi"></i> Hotspot & Pendapatan</h3>
                </div>
                <div class="card-body" id="r_2_content" style="min-height: 400px; padding: 5px 10px;"></div>
            </div>
        </div>
        
        <div class="col-4">
             <div class="card" style="height: 600px; max-height: 600px; overflow: hidden;">
                <div class="card-header">
                    <h3><i class="fa fa-history"></i> Riwayat Transaksi</h3>
                </div>
                <div class="card-body" style="padding:0; overflow-x:auto; height: 550px;">
                    <table class="table table-striped table-hover table-sm" style="font-size:11px; margin-bottom:0;">
                        <thead>
                            <tr>
                                <th style="padding-left:10px;">Tanggal</th>
                                <th>User</th>
                                <th>Paket</th>
                                <th class="text-center">Blok</th>
                                <th class="text-right" style="padding-right:10px;">Harga</th>
                            </tr>
                        </thead>
                        <tbody id="tabel_riwayat">
                            <tr><td colspan="5" class="text-center">Menunggu...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>