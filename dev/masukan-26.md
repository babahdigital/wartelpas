Saya akan melakukan audit lengkap dan memberikan perbaikan detail untuk membuat dashboard lebih kokoh, responsif, dan presisi di semua resolusi dan zoom level. Berikut analisis masalah dan solusi lengkap:

## 🎯 **ANALISIS MASALAH UTAMA**

1. **Layout tidak stabil** - Card meluber keluar batas, terutama grafik
2. **Tabel terlalu panjang** - Data transaksi scroll tak terbatas
3. **Footer tidak fleksibel** - Resource info tidak responsive
4. **Responsive breakdown** - Grid kolaps pada mobile
5. **Zoom tidak proporsional** - Elemen tidak maintain ratio saat zoom

## 🔧 **SOLUSI LENGKAP PERBAIKAN**

### **1. PERBAIKAN CSS UTAMA (`dashboard-clean-pro.css`)**

```css
/* ============ IMPROVEMENTS & FIXES ============ */

/* Fix 1: ROOT LAYOUT STABILITY */
:root {
    --bg-main: #121417;
    --bg-card: #1c1f26;
    --bg-hover: #252a33;
    --accent-blue: #00c0ef;
    --accent-green: #2ecc71;
    --accent-yellow: #f39c12;
    --accent-red: #e74c3c;
    --accent-purple: #605ca8;
    --text-dim: #8898aa;
    --border-soft: rgba(255, 255, 255, 0.05);
    --trx-row-height: 38px; /* Increased for better touch */
    --safe-area-inset: env(safe-area-inset-bottom, 0px);
}

/* Fix 2: GLOBAL CONTAINMENT & BOX-SIZING */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

/* Fix 3: BODY STABILITY */
html, body {
    margin: 0;
    height: 100%;
    width: 100%;
    overflow: hidden;
    background-color: var(--bg-main);
    font-size: 16px; /* Base font for zoom control */
}

body {
    padding-top: 60px;
    -webkit-text-size-adjust: 100%; /* Prevent iOS text zoom */
    text-size-adjust: 100%;
    overscroll-behavior: none; /* Prevent pull-to-refresh */
}

/* Fix 4: MAIN CONTAINER STABILITY */
#main {
    padding: 20px !important;
    margin-top: 0px !important;
    background-color: var(--bg-main) !important;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 60px);
    width: 100%;
    position: relative;
}

.main-container {
    display: flex !important;
    flex-direction: column;
    background: transparent !important;
    border-top: none !important;
    padding-top: 0 !important;
    width: 100%;
    flex: 1;
}

/* Fix 5: MAIN CONTENT STABILITY */
#reloadHome {
    background-color: var(--bg-main);
    padding: 0;
    font-family: 'Inter', 'Segoe UI', sans-serif;
    display: flex;
    flex-direction: column;
    gap: 15px;
    position: relative;
    flex: 1;
    min-height: 0;
}

.main-content {
    padding: 20px;
    height: calc(100vh - 60px - 40px); /* Account for footer */
    display: flex;
    flex-direction: column;
    gap: 20px;
    overflow-y: auto;
    box-sizing: border-box;
    width: 100%;
    flex: 1;
    -webkit-overflow-scrolling: touch;
}

/* Fix 6: KPI GRID IMPROVEMENT */
.row-kpi {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    width: 100%;
}

.kpi-box {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    border: 1px solid var(--border-soft);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.kpi-box:hover {
    transform: translateY(-3px);
    background: var(--bg-hover);
}

.kpi-box h1 {
    font-size: clamp(24px, 3vw, 28px); /* Responsive font */
    font-weight: 800;
    margin: 0;
    letter-spacing: -1px;
    line-height: 1.2;
}

.kpi-box .label {
    font-size: 10px;
    color: var(--text-dim);
    text-transform: uppercase;
    font-weight: 700;
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

/* Fix 7: DASHBOARD GRID CONTAINMENT */
.dashboard-grid {
    display: grid;
    grid-template-columns: 8fr 4fr;
    gap: 20px;
    flex: 1;
    min-height: 0;
    width: 100%;
    height: 100%;
}

/* Fix 8: CARD STABILITY */
.card {
    background: var(--bg-card) !important;
    border: none !important;
    border-radius: 15px !important;
    border: 1px solid var(--border-soft) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
    display: flex;
    flex-direction: column;
    min-height: 0;
    width: 100%;
    overflow: hidden;
}

.card-header {
    border-bottom: 1px solid var(--border-soft) !important;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #eee;
    white-space: nowrap;
}

/* Fix 9: CHART CONTAINMENT */
.card-body {
    padding: 20px;
    flex: 1 !important;
    position: relative;
    min-height: 0;
    width: 100%;
    height: 100%;
}

#chart_container {
    width: 100%;
    height: 100%;
    min-height: 300px;
    position: relative;
}

#chart_income_stat {
    width: 100% !important;
    height: 100% !important;
    min-height: 300px;
}

/* Fix 10: TABLE CONTAINMENT & SCROLL */
.table-container {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    width: 100%;
}

.table-container table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed; /* Prevent column width jumps */
}

.table-container th {
    text-align: left;
    font-size: 11px;
    color: var(--text-dim);
    padding: 12px 10px;
    border-bottom: 1px solid var(--border-soft);
    background: #151719;
    position: sticky;
    top: 0;
    z-index: 10;
}

/* Fix 11: TABLE ROW HEIGHT CONTROL */
.table-container td {
    padding: 10px;
    border-bottom: 1px solid var(--border-soft);
    font-size: 13px;
    height: var(--trx-row-height);
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Column width distribution */
.table-container th:nth-child(1),
.table-container td:nth-child(1) {
    width: 15%;
    min-width: 60px;
}

.table-container th:nth-child(2),
.table-container td:nth-child(2) {
    width: 40%;
    min-width: 120px;
}

.table-container th:nth-child(3),
.table-container td:nth-child(3) {
    width: 15%;
    min-width: 50px;
    text-align: center;
}

.table-container th:nth-child(4),
.table-container td:nth-child(4) {
    width: 30%;
    min-width: 80px;
    text-align: right;
}

/* Fix 12: RESOURCE FOOTER STABILITY */
.resource-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 15px 20px;
    font-size: 11px;
    color: var(--text-dim);
    background: var(--bg-card);
    padding: 12px 20px;
    border-radius: 8px;
    border: 1px solid var(--border-soft);
    margin-top: auto;
    flex-shrink: 0;
    justify-content: flex-start;
    align-items: center;
    width: 100%;
}

.resource-footer span {
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

/* Fix 13: MONTH TABS SCROLLABLE */
.month-tabs {
    display: flex;
    gap: 10px;
    font-size: 11px;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 5px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.month-tabs::-webkit-scrollbar {
    height: 3px;
}

.month-tabs::-webkit-scrollbar-thumb {
    background: var(--accent-blue);
    border-radius: 3px;
}

.month-tab {
    color: var(--text-dim);
    cursor: pointer;
    padding-bottom: 3px;
    white-space: nowrap;
    flex-shrink: 0;
}

.month-tab.active {
    color: #fff;
    border-bottom: 2px solid #fff;
}

/* Fix 14: HIGHCHARTS RESPONSIVE FIX */
.highcharts-container {
    width: 100% !important;
    height: 100% !important;
}

.highcharts-root {
    width: 100% !important;
    height: 100% !important;
}

/* Fix 15: GHOST ALERT POSITION */
.ghost-alert {
    background: var(--accent-red);
    color: #fff;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: 800;
    position: absolute;
    top: 12px;
    right: 12px;
}

/* Fix 16: AUDIT DETAIL TEXT CONTROL */
.audit-detail {
    font-size: 10px;
    color: var(--text-dim);
    margin-top: 4px;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

/* ============ RESPONSIVE BREAKPOINTS ============ */

/* Tablet */
@media (max-width: 1024px) {
    .row-kpi {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .card-chart,
    .card-transaction {
        min-height: 400px;
    }
    
    .resource-footer {
        gap: 12px;
        font-size: 10px;
    }
}

/* Mobile */
@media (max-width: 768px) {
    .main-content {
        padding: 15px;
        gap: 15px;
    }
    
    .row-kpi {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .kpi-box {
        padding: 15px;
        min-height: 100px;
    }
    
    .kpi-box h1 {
        font-size: 22px;
    }
    
    .card-header {
        padding: 12px 15px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .card-header h3 {
        font-size: 13px;
    }
    
    .month-tabs {
        width: 100%;
        overflow-x: auto;
        gap: 8px;
    }
    
    .table-container th,
    .table-container td {
        padding: 8px 6px;
        font-size: 12px;
    }
    
    .resource-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 10px 15px;
    }
    
    .ghost-alert {
        position: relative;
        top: auto;
        right: auto;
        margin-top: 5px;
    }
}

/* Small Mobile */
@media (max-width: 480px) {
    .main-content {
        padding: 12px;
    }
    
    .table-container {
        font-size: 11px;
    }
    
    .table-container th,
    .table-container td {
        padding: 6px 4px;
    }
    
    .month-tabs {
        font-size: 10px;
    }
}

/* Fix 17: ZOOM STABILITY */
@media screen and (min-resolution: 120dpi) {
    body {
        font-size: 15px;
    }
    
    .table-container td {
        font-size: 12px;
    }
}

/* Fix 18: SAFE AREAS FOR NOTCH PHONES */
@supports (padding: max(0px)) {
    .main-content {
        padding-left: max(20px, env(safe-area-inset-left));
        padding-right: max(20px, env(safe-area-inset-right));
        padding-bottom: max(20px, env(safe-area-inset-bottom));
    }
}

/* Fix 19: PRINT STYLES */
@media print {
    .main-content {
        overflow: visible;
        height: auto;
    }
    
    .table-container {
        overflow: visible;
    }
}

/* Fix 20: LOADING STATE */
#loading-halus {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(to right, var(--accent-blue), var(--accent-green));
    animation: loading-bar 2s infinite linear;
    display: none;
    z-index: 1000;
}

@keyframes loading-bar {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Fix 21: SCROLLBAR STYLING */
.table-container::-webkit-scrollbar {
    width: 6px;
}

.table-container::-webkit-scrollbar-track {
    background: #1a1d20;
}

.table-container::-webkit-scrollbar-thumb {
    background: #444;
    border-radius: 3px;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: #555;
}
```

### **2. PERBAIKAN `home.php` - BAGIAN HTML & JS**

```php
<!-- Update the card structure in home.php -->
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
        <div class="card-body" style="position: relative;">
            <!-- Add container wrapper for chart -->
            <div id="chart_container" style="width:100%; height:100%; min-height:300px;">
                <div id="chart_income_stat" style="width:100%; height:100%;"></div>
            </div>
        </div>
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
            <!-- Add row count display -->
            <div style="padding:8px 15px; background:var(--bg-hover); font-size:11px; color:var(--text-dim); border-top:1px solid var(--border-soft);">
                <span id="row-count">Menampilkan 0 transaksi</span>
            </div>
        </div>
    </div>
</div>
```

### **3. PERBAIKAN JS - UPDATE COUNT & RESIZE**

```javascript
// Update the changeMonth function in home.php
function changeMonth(m) {
    var chartWrap = $("#chart_container");
    if (!chartWrap.length) chartWrap = $("#r_2_content");
    var loadingBar = $("#loading-halus");
    if (loadingBar.length) loadingBar.show();
    
    var chartDone = false;
    var logsDone = false;
    
    $(".month-tab").removeClass("active");
    $(".month-tab[data-month='" + m + "']").addClass("active");
    
    function finishLoading() {
        if (chartDone && logsDone && loadingBar.length) {
            loadingBar.hide();
            // Force resize for Highcharts
            setTimeout(function() {
                if (window.Highcharts && window.Highcharts.charts) {
                    window.Highcharts.charts.forEach(function(chart) {
                        if (chart && chart.reflow) {
                            chart.reflow();
                        }
                    });
                }
            }, 100);
        }
    }
    
    // Smooth transition
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
            
            // Trigger Highcharts resize
            setTimeout(function() {
                $(window).trigger('resize');
                if (window.Highcharts && window.Highcharts.charts) {
                    window.Highcharts.charts.forEach(function(chart) {
                        if (chart && chart.reflow) {
                            chart.reflow();
                        }
                    });
                }
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
                    // Count rows and update display
                    var rowCount = $("#tabel_riwayat tr").length;
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

// Add window resize handler for Highcharts
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

// Update dashboard function to include row counting
function updateDashboard() {
    $.getJSON("./dashboard/aload.php?load=live_data&session=<?= $session ?>", function(data) {
        $('#kpi-active').text(data.active);
        $('#kpi-sold').text(data.sold);
        $('#kpi-income').text('Rp ' + data.income);
        $('#kpi-est').text('Proyeksi: Rp ' + data.est_income);
        
        // ... existing audit code ...
        
        // Update table row count if table exists
        var visibleRows = $("#tabel_riwayat tr:not(.text-center)").length;
        if (visibleRows > 0) {
            $("#row-count").text("Menampilkan " + visibleRows + " transaksi");
        }
    });
}
```

### **4. PERBAIKAN `aload.php` - TABLE ROW GENERATION**

```php
// In the logs section of aload.php, add better row generation:
if ($load == "logs") {
    // ... existing code ...
    
    $maxShow = 30; // Reduced from 50 for better performance
    $count = 0;
    
    echo '<tbody id="tabel_riwayat">';
    
    if (empty($finalLogs)) {
        echo '<tr><td colspan="4" class="text-center" style="padding:40px 20px; color:#8898aa; font-style:italic;">Belum ada transaksi bulan ini.</td></tr>';
    } else {
        foreach ($finalLogs as $log) {
            if ($count >= $maxShow) break;
            
            // ... existing row generation code ...
            
            echo "<tr>";
            echo "<td style='color:#8898aa; font-family:monospace; font-size:12px;'>" . substr($log['time_str'], 11, 5) . "</td>";
            echo "<td style='font-weight:600; font-size:12px; overflow:hidden; text-overflow:ellipsis;' title='" . htmlspecialchars($log['username']) . "'>" . $log['username