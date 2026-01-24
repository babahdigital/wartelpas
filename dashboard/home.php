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
        $("#r_2_content").css("opacity", "0.5");
        $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#ccc;"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br>Memproses Grafik...</div>');
        $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center" style="padding:20px;"><i class="fa fa-circle-o-notch fa-spin"></i> Mengambil data...</td></tr>');

        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m, function(data) {
            $("#r_2_content").html(data);
            $("#r_2_content").css("opacity", "1");
        });

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
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;"><i class="fa fa-bar-chart"></i> Performa Bisnis</h3>
                    <small style="font-size:10px; color:#bbb; font-weight:bold; letter-spacing:0.5px; background: rgba(255,255,255,0.05); padding:4px 8px; border-radius:4px;">
                        <i class="fa fa-circle text-green blink" style="font-size:8px; margin-right:4px;"></i> LIVE STREAM
                    </small>
                </div>
                <div class="card-body" id="r_2_content" style="min-height: 400px; padding: 5px 10px;"></div>
            </div>
        </div>
        
        <div class="col-4">
             <div class="card" style="height: 600px; max-height: 600px; overflow: hidden; display:flex; flex-direction:column;">
                <div class="card-header" style="border-bottom: 1px solid #444;">
                    <h3 style="margin:0;"><i class="fa fa-history"></i> Transaksi Terakhir</h3>
                </div>
                <div class="card-body" style="padding:0; overflow-y:auto; flex:1;">
                    <table class="table table-striped table-hover" style="font-size:11px; margin-bottom:0; width:100%;">
                        <thead style="background: #2b3035; position: sticky; top: 0; z-index: 5;">
                            <tr>
                                <th style="padding:10px;">Waktu</th>
                                <th style="padding:10px;">User</th>
                                <th style="padding:10px;">Paket</th>
                                <th class="text-center" style="padding:10px;">Blok</th>
                                <th class="text-right" style="padding:10px;">Nominal</th>
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

<style>
    .blink { animation: blinker 1.5s linear infinite; }
    @keyframes blinker { 50% { opacity: 0; } }

    .card-body::-webkit-scrollbar { width: 6px; }
    .card-body::-webkit-scrollbar-track { background: #222; }
    .card-body::-webkit-scrollbar-thumb { background: #444; border-radius: 3px; }
    .card-body::-webkit-scrollbar-thumb:hover { background: #555; }
</style>