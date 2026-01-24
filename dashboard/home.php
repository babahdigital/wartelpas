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
        $(".month-tab").removeClass("active");
        $(".month-tab[data-month='" + m + "']").addClass("active");
        function reflowCharts() {
            if (window.Highcharts && window.Highcharts.charts) {
                window.Highcharts.charts.forEach(function(chart) {
                    if (chart && chart.reflow) {
                        chart.reflow();
                    }
                });
            }
        }
        function finishLoading() {
            if (chartDone && logsDone && loadingBar.length) loadingBar.hide();
        }
        chartWrap.css("opacity", "0.7");
        $("#r_2_content").html('<div style="text-align:center; padding:50px; color:#ccc;">Memproses grafik...</div>');
        $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center" style="padding:20px;">Memuat...</td></tr>');
        $("#row-count").text("Memuat...");

        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m)
            .done(function(data) {
                $("#r_2_content").html(data);
                chartWrap = $("#chart_container");
                if (!chartWrap.length) chartWrap = $("#r_2_content");
                chartWrap.css("opacity", "1");
                setTimeout(function() {
                    $(window).trigger('resize');
                    reflowCharts();
                }, 300);
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
                        $("#row-count").text("0 transaksi ditemukan");
                    } else {
                        $("#tabel_riwayat").html(dataLogs);
                        var rowCount = $("#tabel_riwayat tr").length;
                        if (rowCount > 10) rowCount = 10;
                        $("#row-count").text("Menampilkan " + rowCount + " transaksi");
                    }
                })
                .fail(function() {
                    $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center text-danger">Gagal koneksi server.</td></tr>');
                    $("#row-count").text("Error loading data");
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
            var visibleRows = $("#tabel_riwayat tr:not(.text-center)").length;
            if (visibleRows > 10) visibleRows = 10;
            if (visibleRows > 0) {
                $("#row-count").text("Menampilkan " + visibleRows + " transaksi");
            }
        });
    }

    $(document).ready(function() {
        $("#r_1_display").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1_content_raw");
        changeMonth(<?= (int)date('m') ?>);
        updateDashboard();
        setInterval(updateDashboard, 10000);
        setInterval(function() {
            $("#r_1_display").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1_content_raw");
        }, 10000);
        $(".month-tab").on("click", function() {
            var m = $(this).data("month");
            if (m) changeMonth(m);
        });
        $(window).on('resize', function() {
            if (window.Highcharts && window.Highcharts.charts) {
                setTimeout(function() {
                    window.Highcharts.charts.forEach(function(chart) {
                        if (chart && chart.reflow) {
                            chart.reflow();
                        }
                    });
                }, 150);
            }
        });
    });
</script>

<?php
$monthFull = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
$activeMonth = (int)date('n');
?>

<div id="reloadHome" class="main-content">
    <div id="loading-halus"></div>
    <div class="row-kpi">
        <div class="kpi-box border-green">
            <h1 id="kpi-active">0</h1>
            <div class="label">
                User Active
                <span class="blink" style="color:var(--accent-green)">● LIVE</span>
            </div>
        </div>
        <div class="kpi-box border-blue">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <h1 id="kpi-sold">0</h1>
                <span id="ghost-tag" style="display:none;" class="ghost-alert blink"><i class="fa fa-ghost"></i> GHOST</span>
            </div>
            <div class="label">Voucher Terjual (Hari Ini)</div>
        </div>
        <div class="kpi-box border-yellow">
            <h1 id="kpi-income">Rp 0</h1>
            <div class="label" id="kpi-est">Proyeksi: Rp 0</div>
        </div>
        <div class="kpi-box border-audit" id="audit-box">
            <h1 id="audit-status">CLEAR</h1>
            <div class="label" id="audit-val">Selisih: Rp 0</div>
            <div class="audit-detail" id="audit-detail"></div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="card card-chart">
            <div class="card-header">
                <h3><i class="fa fa-line-chart"></i> PERFORMA BISNIS</h3>
                <div class="month-tabs">
                    <?php foreach ($monthFull as $num => $name) : ?>
                        <span class="month-tab<?= $num === $activeMonth ? ' active' : '' ?>" data-month="<?= $num ?>"><?= $name ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body" id="r_2_content"></div>
        </div>
        <div class="card card-transaction">
            <div class="card-header">
                <h3><i class="fa fa-history"></i> TRANSAKSI TERAKHIR</h3>
                <span class="blink" style="font-size:9px; font-weight:bold; color:var(--accent-green);">UPDATED</span>
            </div>
            <div class="card-body" style="padding:0; display:flex; flex-direction:column;">
                <div class="table-container" style="flex:1; min-height:300px;">
                    <table style="table-layout: fixed;">
                        <thead>
                            <tr>
                                <th style="width:15%">JAM</th>
                                <th style="width:40%">USER</th>
                                <th style="width:15%; text-align:center;">BLOK</th>
                                <th style="width:30%; text-align:right;">IDR</th>
                            </tr>
                        </thead>
                        <tbody id="tabel_riwayat">
                            <tr><td colspan="4" class="text-center" style="padding:20px; color:#555;">Menunggu...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="padding:8px 15px; background:var(--bg-hover); font-size:11px; color:var(--text-dim); border-top:1px solid var(--border-soft);">
                    <span id="row-count">Menampilkan 0 transaksi</span>
                </div>
            </div>
        </div>
    </div>

    <div class="resource-footer" id="r_1_display">
        <span><i class="fa fa-refresh fa-spin"></i> Memuat resource...</span>
    </div>
</div>