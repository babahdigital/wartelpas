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
        var chartWrap = $("#chart_container");
        if (!chartWrap.length) chartWrap = $("#r_2_content");
        chartWrap.css("opacity", "0.5");
        $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#ccc;"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br>Memproses Grafik...</div>');
        $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center" style="padding:20px;"><i class="fa fa-circle-o-notch fa-spin"></i> Memuat...</td></tr>');

        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m, function(data) {
            $("#r_2_content").html(data);
            chartWrap = $("#chart_container");
            if (!chartWrap.length) chartWrap = $("#r_2_content");
            chartWrap.css("opacity", "1");
        });

        setTimeout(function() {
            $.get("./dashboard/aload.php?session=<?= $session ?>&load=logs&m=" + m, function(dataLogs) {
                if(dataLogs.trim() == "") {
                    $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center text-muted" style="padding:20px;">Belum ada transaksi.</td></tr>');
                } else {
                    $("#tabel_riwayat").html(dataLogs);
                }
            }).fail(function() {
                $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center text-danger">Gagal koneksi server.</td></tr>');
            });
        }, 500);
    }

    $(document).ready(function() {
        $("#r_1").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1 > *");
        changeMonth(<?= (int)date('m') ?>);
    });
</script>

<div id="reloadHome">
    <div id="r_1" class="row" style="margin-bottom: 20px;">
        <div class="col-12 text-center" style="padding:20px; color:#666;">
            <i class="fa fa-refresh fa-spin"></i> Menghubungkan ke Router...
        </div>
    </div>

    <div class="row">
        <div class="col-9">
            <div class="card" style="border:1px solid #333; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                <div class="card-header" style="background:#222; border-bottom:1px solid #444; padding:12px 15px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; color:#ddd;"><i class="fa fa-area-chart" style="margin-right:8px; color:#00c0ef;"></i> Performa Bisnis</h3>
                    <div style="font-size:10px; color:#aaa; display:flex; align-items:center;">
                        <i class="fa fa-circle text-green blink" style="font-size: 8px; margin-right: 5px;"></i> LIVE DATA
                    </div>
                </div>
                <div class="card-body" id="r_2_content" style="min-height: 420px; padding: 15px; background:#2b3035;"></div>
            </div>
        </div>

        <div class="col-3">
             <div class="card" style="border:1px solid #333; height: 580px; max-height: 580px; overflow: hidden; display:flex; flex-direction:column; background:#222;">
                <div class="card-header" style="background:#1a1d20; border-bottom: 1px solid #444; padding:12px 15px;">
                    <h3 style="margin:0; font-size:14px; color:#ddd;"><i class="fa fa-clock-o" style="margin-right:6px; color:#f39c12;"></i> Log Transaksi</h3>
                </div>
                <div class="card-body" style="padding:0; overflow-y:auto; flex:1; background:#222;">
                    <table class="table table-hover" style="font-size:11px; margin-bottom:0; width:100%; color:#ccc;">
                        <thead style="background: #151719; position: sticky; top: 0; z-index: 5;">
                            <tr>
                                <th style="padding:8px 10px; border-bottom:1px solid #444; color:#888;">Jam</th>
                                <th style="padding:8px 10px; border-bottom:1px solid #444; color:#888;">User</th>
                                <th style="padding:8px 10px; border-bottom:1px solid #444; color:#888; text-align:center;">Blok</th>
                                <th class="text-right" style="padding:8px 10px; border-bottom:1px solid #444; color:#888;">IDR</th>
                            </tr>
                        </thead>
                        <tbody id="tabel_riwayat">
                            <tr><td colspan="4" class="text-center" style="padding:20px; color:#555;">Menunggu...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .blink { animation: blinker 2s linear infinite; }
    @keyframes blinker { 50% { opacity: 0.3; } }

    .card-body::-webkit-scrollbar { width: 5px; }
    .card-body::-webkit-scrollbar-track { background: #1a1d20; }
    .card-body::-webkit-scrollbar-thumb { background: #444; border-radius: 2px; }
    .card-body::-webkit-scrollbar-thumb:hover { background: #555; }

    #tabel_riwayat tr td { border-bottom: 1px solid #333; }
    #tabel_riwayat tr:hover td { background-color: #2c3238; color: #fff; }
</style>