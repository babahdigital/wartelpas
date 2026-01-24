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
        var loadingBar = $("#loading-halus");
        if (loadingBar.length) loadingBar.show();
        var chartDone = false;
        var logsDone = false;
        function finishLoading() {
            if (chartDone && logsDone && loadingBar.length) loadingBar.hide();
        }
        chartWrap.css("opacity", "0.5");
        $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#ccc;"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br>Memproses Grafik...</div>');
        $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center" style="padding:20px;"><i class="fa fa-circle-o-notch fa-spin"></i> Memuat...</td></tr>');

        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m)
            .done(function(data) {
                $("#r_2_content").html(data);
                chartWrap = $("#chart_container");
                if (!chartWrap.length) chartWrap = $("#r_2_content");
                chartWrap.css("opacity", "1");
            })
            .fail(function() {
                $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#c33;"><i class="fa fa-warning"></i> Gagal memuat grafik.</div>');
            })
            .always(function() {
                chartDone = true;
                finishLoading();
            });

        setTimeout(function() {
            $.get("./dashboard/aload.php?session=<?= $session ?>&load=logs&m=" + m)
                .done(function(dataLogs) {
                    if(dataLogs.trim() == "") {
                        $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center text-muted" style="padding:20px;">Belum ada transaksi.</td></tr>');
                    } else {
                        $("#tabel_riwayat").html(dataLogs);
                    }
                })
                .fail(function() {
                    $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center text-danger">Gagal koneksi server.</td></tr>');
                })
                .always(function() {
                    logsDone = true;
                    finishLoading();
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

            if (data.audit_status === 'LOSS') {
                $('#audit-box').removeClass('border-audit border-warning').addClass('border-loss');
                $('#audit-status').text('LOSS').css('color', 'var(--accent-red)');
            } else if (parseInt(data.ghost || 0, 10) > 0) {
                $('#audit-box').removeClass('border-audit border-loss').addClass('border-warning');
                $('#audit-status').text('GHOST').css('color', 'var(--accent-yellow)');
            } else {
                $('#audit-box').removeClass('border-loss border-warning').addClass('border-audit');
                $('#audit-status').text('CLEAR').css('color', '#fff');
            }

            $('#audit-val').text('Selisih: Rp ' + data.audit_val);

            var detail = [];
            if (data.audit_detail) {
                if (parseInt(data.audit_detail.ghost || 0, 10) > 0) {
                    detail.push('Missing: ' + data.audit_detail.ghost + ' Lbr');
                }
                if (parseInt(data.audit_detail.miss_10 || 0, 10) > 0 || parseInt(data.audit_detail.miss_30 || 0, 10) > 0) {
                    detail.push('10m: ' + (data.audit_detail.miss_10 || 0) + ' | 30m: ' + (data.audit_detail.miss_30 || 0));
                }
                if (data.audit_detail.cash_expected) {
                    detail.push('Cash: Rp ' + data.audit_detail.cash_expected);
                }
                if (data.audit_detail.last_update) {
                    detail.push('Upd: ' + data.audit_detail.last_update);
                }
            }
            $('#audit-detail').text(detail.join(' • '));
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
    <div id="loading-halus"></div>
    <div id="r_1" class="row" style="margin-bottom: 10px;">
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
                <div class="audit-detail" id="audit-detail"></div>
            </div>
        </div>
    </div>

    <div class="row-main-content">
        <div class="col-left">
            <div class="card card-chart">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;"><i class="fa fa-line-chart"></i> Performa Bisnis</h3>
                    <div style="font-size:10px; color:#aaa; display:flex; align-items:center;">
                        <i class="fa fa-circle text-green blink" style="font-size: 8px; margin-right: 5px;"></i> LIVE DATA
                    </div>
                </div>
                <div class="card-body chart-body" id="r_2_content"></div>
            </div>
        </div>
        <div class="col-right">
            <div class="card card-transaction">
                <div class="card-header"><h3 style="margin:0;"><i class="fa fa-history"></i> Transaksi</h3></div>
                <div class="card-body">
                    <div class="table-scroll">
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
</div>