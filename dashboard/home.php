<?php
/*
 * FINAL CLEAN PRO DASHBOARD - HOME
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); exit; }

$session = isset($_GET['session']) ? $_GET['session'] : '';
?>
<link rel="stylesheet" href="css/dashboard-clean-pro.css">

<script type="text/javascript">
    function changeMonth(m) {
        var chartWrap = $("#r_2_content");
        var loadingBar = $("#loading-halus");
        loadingBar.show();
        
        $(".month-tab").removeClass("active");
        $(".month-tab[data-month='" + m + "']").addClass("active");
        
        chartWrap.css("opacity", "0.4");
        $("#tabel_riwayat").html('<tr><td colspan="4" class="text-center" style="padding:40px; color:#555;"><i class="fa fa-refresh fa-spin"></i> Memuat data...</td></tr>');

        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m)
            .done(function(data) {
                chartWrap.html(data).css("opacity", "1");
            });

        setTimeout(function() {
            $.get("./dashboard/aload.php?session=<?= $session ?>&load=logs&m=" + m)
                .done(function(dataLogs) {
                    $("#tabel_riwayat").html(dataLogs);
                    loadingBar.hide();
                });
        }, 300);
    }

    function updateDashboard() {
        $.getJSON("./dashboard/aload.php?load=live_data&session=<?= $session ?>", function(data) {
            $('#kpi-active').text(data.active);
            $('#kpi-sold').text(data.sold);
            $('#kpi-income').text('Rp ' + data.income);
            $('#kpi-est').text('Proyeksi: Rp ' + data.est_income);

            if (parseInt(data.ghost || 0) > 0) { $('#ghost-tag').show(); } else { $('#ghost-tag').hide(); }

            // Logic warna kartu audit
            var auditBox = $('#audit-box');
            auditBox.removeClass('border-audit border-loss border-yellow');
            if (data.audit_status === 'LOSS') {
                auditBox.addClass('border-loss');
                $('#audit-status').text('LOSS').css('color', 'var(--accent-red)');
            } else if (parseInt(data.ghost || 0) > 0) {
                auditBox.addClass('border-yellow');
                $('#audit-status').text('GHOST').css('color', 'var(--accent-yellow)');
            } else {
                auditBox.addClass('border-audit');
                $('#audit-status').text('CLEAR').css('color', '#fff');
            }

            $('#audit-val').text('Selisih: Rp ' + data.audit_val);
            $('#audit-detail').text((data.audit_detail.ghost ? 'Missing: ' + data.audit_detail.ghost + ' Lbr • ' : '') + 'Cash: Rp ' + data.audit_detail.cash_expected);
        });
    }

    $(document).ready(function() {
        $("#r_1").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1 > *");
        changeMonth(<?= (int)date('m') ?>);
        updateDashboard();
        setInterval(updateDashboard, 10000);
        $(".month-tab").on("click", function() { changeMonth($(this).data("month")); });
    });
</script>

<div id="reloadHome" class="main-content">
    <div id="loading-halus"></div>
    
    <!-- KPI Row -->
    <div class="row-kpi">
        <div class="kpi-box border-green">
            <h1 id="kpi-active">0</h1>
            <div class="label">User Active <span class="blink" style="color:var(--accent-green)">● LIVE</span></div>
        </div>
        <div class="kpi-box border-blue">
            <div style="display:flex; justify-content:space-between;">
                <h1 id="kpi-sold">0</h1>
                <span id="ghost-tag" style="display:none;" class="ghost-alert blink"><i class="fa fa-ghost"></i> GHOST</span>
            </div>
            <div class="label">Terjual (Hari Ini)</div>
        </div>
        <div class="kpi-box border-yellow">
            <h1 id="kpi-income">Rp 0</h1>
            <div class="label" id="kpi-est">Proyeksi: Rp 0</div>
        </div>
        <div class="kpi-box border-audit" id="audit-box">
            <h1 id="audit-status">CLEAR</h1>
            <div class="label" id="audit-val">Selisih: Rp 0</div>
            <div id="audit-detail" style="font-size:9px; color:var(--text-dim); margin-top:5px;">Memuat info...</div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="dashboard-grid">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-line-chart"></i> PERFORMA BISNIS</h3>
                <div class="month-tabs">
                    <?php 
                    $months = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des'];
                    $curM = (int)date('m');
                    foreach($months as $num => $name): ?>
                        <span class="month-tab <?= ($num==$curM)?'active':'' ?>" data-month="<?= $num ?>"><?= $name ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body" id="r_2_content" style="padding:10px;"></div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-history"></i> TRANSAKSI TERAKHIR</h3>
                <span class="blink" style="font-size:9px; font-weight:bold; color:var(--accent-green);">LIVE UPDATED</span>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>JAM</th>
                                <th>USER</th>
                                <th style="text-align:center;">BLOK</th>
                                <th style="text-align:right;">IDR</th>
                            </tr>
                        </thead>
                        <tbody id="tabel_riwayat"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="r_1" class="resource-footer"></div>
</div>