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
<link rel="stylesheet" href="css/dashboard-clean-pro.css">

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

    function updateDashboard() {
        $.getJSON("./dashboard/aload.php?load=live_data&session=<?= $session ?>", function(data) {
            $('#kpi-active').text(data.active);
            $('#kpi-sold').text(data.sold);
            $('#kpi-income').text('Rp ' + data.income);
            $('#kpi-est').text('Proyeksi: Rp ' + data.est_income);

            if (parseInt(data.ghost || 0, 10) > 0) {
                $('#ghost-tag').show();
            } else {
                $('#ghost-tag').hide();
            }

            $('#audit-status').text(data.audit_status);
            $('#audit-val').text('Selisih: Rp ' + data.audit_val);
            if (data.audit_status === 'LOSS') {
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
        updateDashboard();
        setInterval(updateDashboard, 10000);
    });
</script>

<div id="reloadHome">
    <div id="r_1" class="row" style="margin-bottom: 20px;">
        <div class="col-12 text-center" style="padding:20px; color:#666;">
            <i class="fa fa-refresh fa-spin"></i> Menghubungkan ke Router...
        </div>
    </div>

    <div class="row">
        <div class="col-3">
            <div class="kpi-box border-green">
                <h1 id="kpi-active">0</h1>
                <div class="label">User Active <span class="blink" style="color:var(--accent-green)">● Live</span></div>
            </div>
        </div>
        <div class="col-3">
            <div class="kpi-box border-blue">
                <div id="ghost-tag" style="display:none;" class="ghost-alert blink"><i class="fa fa-ghost"></i> GHOST</div>
                <h1 id="kpi-sold">0</h1>
                <div class="label">Voucher Terjual (Hari Ini)</div>
            </div>
        </div>
        <div class="col-3">
            <div class="kpi-box border-yellow">
                <h1 id="kpi-income">Rp 0</h1>
                <div class="label" id="kpi-est">Proyeksi: Rp 0</div>
            </div>
        </div>
        <div class="col-3" id="audit-card-wrap">
            <div id="audit-box" class="kpi-box border-audit">
                <h1 id="audit-status">CLEAR</h1>
                <div class="label" id="audit-val">Selisih: Rp 0</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-8">
            <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;"><i class="fa fa-line-chart"></i> Performa Bisnis</h3>
                    <div style="font-size:10px; color:#aaa; display:flex; align-items:center;">
                        <i class="fa fa-circle text-green blink" style="font-size: 8px; margin-right: 5px;"></i> LIVE DATA
                    </div>
                </div>
                <div class="card-body" id="r_2_content"></div>
            </div>
        </div>
        <div class="col-4">
            <div class="card" style="height: 520px;">
                <div class="card-header"><h3 style="margin:0;"><i class="fa fa-history"></i> Transaksi</h3></div>
                <div class="card-body" style="padding:0; overflow-y:auto;">
                    <table class="table" style="margin-bottom:0; width:100%;">
                        <thead style="background:#151719; position: sticky; top: 0; z-index: 5;">
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