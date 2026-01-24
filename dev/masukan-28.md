Saya akan memperbaiki semua masalah yang Anda sebutkan dan meningkatkan tampilan dashboard menjadi lebih kokoh dan profesional. Mari kita lakukan langkah demi langkah:

## 1. **Perbaikan CSS (dashboard-clean-pro.css) - Update Lengkap:**

```css
/* ============ DASHBOARD CLEAN PRO - ENHANCED ============ */

:root {
    --bg-main: #121417;
    --bg-card: #1c1f26;
    --bg-hover: #252a33;
    --accent-blue: #00c0ef;
    --accent-green: #2ecc71;
    --accent-yellow: #f39c12;
    --accent-red: #e74c3c;
    --accent-purple: #605ca8;
    --accent-audit: #d81b60;
    --text-dim: #8898aa;
    --text-bright: #e0e0e0;
    --border-soft: rgba(255, 255, 255, 0.08);
    --border-medium: rgba(255, 255, 255, 0.12);
    --trx-row-height: 44px;
    --safe-area-inset: env(safe-area-inset-bottom, 0px);
    --shadow-card: 0 8px 30px rgba(0, 0, 0, 0.25);
    --shadow-nav: 0 4px 20px rgba(0, 0, 0, 0.35);
}

/* Fix 1: GLOBAL RESET */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

/* Fix 2: BODY & LAYOUT */
html, body {
    margin: 0;
    height: 100%;
    width: 100%;
    background-color: var(--bg-main);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

body {
    padding-top: 60px;
    overflow-x: hidden;
}

/* Fix 3: MAIN CONTAINER */
#main {
    padding: 20px 24px 24px 24px !important;
    margin-top: 0px !important;
    background-color: var(--bg-main) !important;
    min-height: calc(100vh - 60px);
    width: 100%;
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

/* Fix 4: MAIN CONTENT */
#reloadHome {
    background-color: var(--bg-main);
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
    position: relative;
    flex: 1;
}

/* Fix 5: KPI GRID - ENHANCED */
.row-kpi {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    width: 100%;
}

.kpi-box {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 22px 20px;
    border: 1px solid var(--border-soft);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    min-height: 130px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: var(--shadow-card);
}

.kpi-box:hover {
    transform: translateY(-4px);
    background: var(--bg-hover);
    border-color: var(--border-medium);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}

.kpi-box h1 {
    font-size: 32px;
    font-weight: 800;
    margin: 0 0 6px 0;
    letter-spacing: -0.5px;
    line-height: 1.1;
    color: var(--text-bright);
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.kpi-box .label {
    font-size: 11px;
    color: var(--text-dim);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

/* Border Colors */
.border-green { border-left: 5px solid var(--accent-green); }
.border-blue { border-left: 5px solid var(--accent-blue); }
.border-yellow { border-left: 5px solid var(--accent-yellow); }
.border-audit { border-left: 5px solid var(--accent-purple); }
.border-loss { border-left: 5px solid var(--accent-red); }
.border-warning { border-left: 5px solid #ff9800; }

/* Fix 6: DASHBOARD GRID */
.dashboard-grid {
    display: grid;
    grid-template-columns: 8fr 4fr;
    gap: 20px;
    flex: 1;
    min-height: 0;
    width: 100%;
    height: 100%;
}

/* Fix 7: CARD STYLES - ENHANCED */
.card {
    background: var(--bg-card) !important;
    border-radius: 16px !important;
    border: 1px solid var(--border-soft) !important;
    box-shadow: var(--shadow-card) !important;
    display: flex;
    flex-direction: column;
    min-height: 420px;
    width: 100%;
    overflow: hidden;
    transition: transform 0.25s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.35) !important;
}

.card-header {
    border-bottom: 1px solid var(--border-soft) !important;
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    background: rgba(28, 31, 38, 0.9);
    backdrop-filter: blur(10px);
}

.card-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #eee;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header h3 i {
    color: var(--accent-blue);
    font-size: 14px;
}

/* Fix 8: CHART CONTAINER - FULL HEIGHT */
.card-body {
    padding: 0 !important;
    flex: 1 !important;
    position: relative;
    min-height: 0;
    width: 100%;
    height: 100%;
}

#chart_container {
    width: 100% !important;
    height: 100% !important;
    min-height: 350px;
    position: relative;
    padding: 15px 20px;
}

#chart_income_stat {
    width: 100% !important;
    height: 100% !important;
    min-height: 350px;
}

/* Fix 9: TABLE STYLES - ENHANCED */
.card-transaction .card-body {
    padding: 0;
    display: flex;
    flex-direction: column;
}

.table-container {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    width: 100%;
    flex: 1;
}

.table-container table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.table-container thead {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #1a1d24;
}

.table-container th {
    text-align: left;
    font-size: 12px;
    color: var(--text-dim);
    padding: 16px 12px;
    border-bottom: 1px solid var(--border-soft);
    font-weight: 600;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    background: #1a1d24;
}

/* Enhanced column widths */
.table-container th:nth-child(1),
.table-container td:nth-child(1) {
    width: 18%;
    min-width: 70px;
    padding-left: 20px;
}

.table-container th:nth-child(2),
.table-container td:nth-child(2) {
    width: 37%;
    min-width: 140px;
}

.table-container th:nth-child(3),
.table-container td:nth-child(3) {
    width: 15%;
    min-width: 60px;
    text-align: center;
}

.table-container th:nth-child(4),
.table-container td:nth-child(4) {
    width: 30%;
    min-width: 90px;
    text-align: right;
    padding-right: 20px;
}

/* Table row styling */
.table-container tbody tr {
    border-bottom: 1px solid var(--border-soft);
    transition: background-color 0.2s ease;
}

.table-container tbody tr:hover {
    background-color: var(--bg-hover);
}

.table-container td {
    padding: 15px 12px;
    font-size: 13.5px;
    color: var(--text-bright);
    vertical-align: middle;
    height: var(--trx-row-height);
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Time column styling */
.table-container td:first-child {
    font-family: 'SF Mono', 'Roboto Mono', monospace;
    font-size: 13px;
    color: var(--text-dim);
}

/* Blok badge styling */
.table-container td:nth-child(3) span {
    background: #2a2d35;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    min-width: 28px;
    display: inline-block;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Price column styling */
.table-container td:last-child {
    font-family: 'SF Mono', 'Roboto Mono', monospace;
    font-weight: 700;
    font-size: 14px;
}

/* Fix 10: RESOURCE FOOTER */
.resource-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 12px;
    color: var(--text-dim);
    background: var(--bg-card);
    padding: 16px 24px;
    border-radius: 12px;
    border: 1px solid var(--border-soft);
    margin-top: auto;
    flex-shrink: 0;
    justify-content: flex-start;
    align-items: center;
    width: 100%;
    box-shadow: var(--shadow-card);
}

.resource-footer span {
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    padding: 4px 8px;
    border-radius: 6px;
    background: rgba(255,255,255,0.03);
}

/* Fix 11: MONTH TABS - ENHANCED */
.month-tabs {
    display: flex;
    gap: 8px;
    font-size: 11px;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 6px;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.month-tabs::-webkit-scrollbar {
    display: none;
}

.month-tab {
    color: var(--text-dim);
    cursor: pointer;
    padding: 6px 12px;
    white-space: nowrap;
    flex-shrink: 0;
    border-radius: 8px;
    background: rgba(255,255,255,0.03);
    border: 1px solid transparent;
    transition: all 0.2s ease;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.month-tab:hover {
    background: rgba(255,255,255,0.07);
    border-color: var(--border-medium);
}

.month-tab.active {
    color: #fff;
    background: var(--accent-blue);
    border-color: var(--accent-blue);
    box-shadow: 0 4px 12px rgba(0, 192, 239, 0.3);
}

/* Fix 12: GHOST ALERT */
.ghost-alert {
    background: linear-gradient(135deg, var(--accent-red), #ff5252);
    color: #fff;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.5px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 3px 8px rgba(231, 76, 60, 0.3);
    position: absolute;
    top: 12px;
    right: 12px;
}

/* Fix 13: AUDIT DETAIL - ENHANCED */
.audit-detail {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 8px;
    line-height: 1.5;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
    padding: 8px;
    background: rgba(255,255,255,0.03);
    border-radius: 6px;
    border-left: 3px solid var(--accent-purple);
}

/* Fix 14: HIGHCHARTS FIX */
.highcharts-container {
    width: 100% !important;
    height: 100% !important;
}

.highcharts-root {
    width: 100% !important;
    height: 100% !important;
}

/* Remove chart border */
.highcharts-background {
    fill: transparent !important;
}

/* Fix 15: BEAUTIFUL SCROLLBAR */
.table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-container::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 4px;
    margin: 4px;
}

.table-container::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #444, #555);
    border-radius: 4px;
    border: 2px solid transparent;
    background-clip: padding-box;
    transition: all 0.3s ease;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #555, #666);
    transform: scale(1.1);
}

.table-container::-webkit-scrollbar-corner {
    background: transparent;
}

/* Fix 16: LIVE INDICATOR */
.blink {
    animation: blinker 2s ease-in-out infinite;
}

@keyframes blinker {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Fix 17: LOADING STATE */
#loading-halus {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, var(--accent-blue), var(--accent-green));
    animation: loading-bar 1.5s infinite linear;
    display: none;
    z-index: 1000;
    border-radius: 3px;
}

@keyframes loading-bar {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Fix 18: CARD TRANSACTION FOOTER */
.card-transaction .card-footer {
    padding: 12px 20px;
    background: var(--bg-hover);
    font-size: 11px;
    color: var(--text-dim);
    border-top: 1px solid var(--border-soft);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* Fix 19: EMPTY STATE */
.text-center {
    text-align: center;
    color: var(--text-dim) !important;
    font-style: italic;
    padding: 40px 20px !important;
}

/* ============ RESPONSIVE BREAKPOINTS ============ */

/* Tablet */
@media (max-width: 1024px) {
    .row-kpi {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .kpi-box {
        min-height: 120px;
        padding: 18px 16px;
    }
    
    .kpi-box h1 {
        font-size: 28px;
    }
}

/* Mobile */
@media (max-width: 768px) {
    #main {
        padding: 16px !important;
    }
    
    .row-kpi {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .card {
        min-height: 380px;
    }
    
    .card-header {
        padding: 14px 16px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .month-tabs {
        width: 100%;
        gap: 6px;
    }
    
    .month-tab {
        padding: 5px 10px;
        font-size: 10px;
    }
    
    .table-container th,
    .table-container td {
        padding: 12px 8px;
        font-size: 12.5px;
    }
    
    .resource-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 16px;
    }
}

/* Small Mobile */
@media (max-width: 480px) {
    .table-container th,
    .table-container td {
        padding: 10px 6px;
        font-size: 12px;
    }
    
    .table-container td:first-child {
        font-size: 12px;
    }
    
    .table-container td:last-child {
        font-size: 13px;
    }
}

/* Fix 20: SAFE AREAS */
@supports (padding: max(0px)) {
    #main {
        padding-left: max(20px, env(safe-area-inset-left)) !important;
        padding-right: max(20px, env(safe-area-inset-right)) !important;
        padding-bottom: max(20px, env(safe-area-inset-bottom)) !important;
    }
}
```

## 2. **Perbaikan aload.php - Chart Tanggal Dinamis:**

Di bagian `$load == "hotspot"` dalam `aload.php`, ganti kode untuk chart menjadi:

```php
if ($load == "hotspot") {

    $filterMonth = $_SESSION['filter_month'];
    $filterYear = $_SESSION['filter_year'];
    
    // Tentukan tanggal mulai dinamis (dari tanggal 21 atau awal bulan)
    $currentDay = (int)date('d');
    $startDay = 1;
    
    // Jika bulan filter sama dengan bulan sekarang
    if ($filterMonth == (int)date('m') && $filterYear == (int)date('Y')) {
        $startDay = ($currentDay >= 21) ? 21 : 1;
        $endDay = $currentDay;
    } else {
        $endDay = (int)date("t", mktime(0, 0, 0, $filterMonth, 1, $filterYear));
    }
    
    $daysInMonth = $endDay - $startDay + 1;
    $dailyIncome = array_fill($startDay, $daysInMonth, 0);
    $dailyQty = array_fill($startDay, $daysInMonth, 0);

    // ... [kode pengolahan data tetap sama] ...

    // Buat array kategori dinamis
    $categories = [];
    for ($d = $startDay; $d <= $endDay; $d++) {
        $categories[] = (string)$d;
    }
    
    // Ambil data sesuai range tanggal
    $dataIncome = [];
    $dataQty = [];
    for ($d = $startDay; $d <= $endDay; $d++) {
        $dataIncome[] = $dailyIncome[$d] ?? 0;
        $dataQty[] = $dailyQty[$d] ?? 0;
    }

    $jsonCategories = json_encode($categories);
    $jsonDataIncome = json_encode($dataIncome, JSON_NUMERIC_CHECK);
    $jsonDataQty = json_encode($dataQty, JSON_NUMERIC_CHECK);
    
    // ... [kode Highcharts tetap sama, tambahkan borderWidth: 0] ...
?>
<script type="text/javascript">
    if(typeof Highcharts !== 'undefined') {
        Highcharts.chart('chart_income_stat', {
            chart: {
                backgroundColor: 'transparent',
                type: 'area',
                spacingBottom: 0,
                reflow: true,
                zoomType: 'xy',
                height: null,
                borderWidth: 0, // Hapus border
                spacing: [10, 0, 10, 0]
            },
            // ... [setting lainnya] ...
        });
    }
</script>
<?php
}
```

## 3. **Perbaikan home.php & dashboard.html - Tampilan Riwayat:**

Ganti bagian tabel di `home.php` dan `dashboard.html`:

```html
<div class="card card-transaction">
    <div class="card-header">
        <h3><i class="fa fa-history"></i> TRANSAKSI TERAKHIR</h3>
        <span class="blink" style="font-size:10px; font-weight:bold; color:var(--accent-green); letter-spacing:0.5px;">
            <i class="fa fa-circle"></i> LIVE
        </span>
    </div>
    <div class="card-body" style="display:flex; flex-direction:column;">
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
                <tbody id="tabel_riwayat">
                    <tr><td colspan="4" class="text-center" style="padding:30px; color:#8898aa; font-style:italic;">
                        <i class="fa fa-clock-o" style="margin-right:8px;"></i>Memuat transaksi...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer">
        <span id="row-count">Menampilkan 0 transaksi</span>
        <span style="color:var(--accent-green); font-size:10px;">
            <i class="fa fa-refresh"></i> Auto-refresh
        </span>
    </div>
</div>
```

## 4. **Perbaikan Script JavaScript - Audit Enhanced:**

Di bagian JavaScript `updateDashboard()`, perbarui kode untuk audit:

```javascript
function updateDashboard() {
    $.getJSON(withTestDate("./dashboard/aload.php?load=live_data&session=<?= $session ?>"), function(data) {
        // ... [kode existing untuk KPI] ...
        
        // Enhanced Audit Display
        if (data.audit_status === 'LOSS') {
            $('#audit-box').removeClass('border-audit border-warning').addClass('border-loss');
            $('#audit-status').html('<i class="fa fa-exclamation-triangle"></i> LOSS')
                .css('color', 'var(--accent-red)');
        } else if (parseInt(data.ghost || 0, 10) > 0) {
            $('#audit-box').removeClass('border-audit border-loss').addClass('border-warning');
            $('#audit-status').html('<i class="fa fa-ghost"></i> GHOST')
                .css('color', 'var(--accent-yellow)');
        } else {
            $('#audit-box').removeClass('border-loss border-warning').addClass('border-audit');
            $('#audit-status').html('<i class="fa fa-check-circle"></i> CLEAR')
                .css('color', 'var(--accent-green)');
        }
        
        $('#audit-val').html('<strong>Selisih:</strong> Rp ' + data.audit_val);
        
        // Enhanced audit detail dengan HTML yang lebih terstruktur
        var detail = '';
        if (data.audit_detail) {
            detail += '<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px;">';
            
            if (parseInt(data.audit_detail.ghost || 0, 10) > 0) {
                detail += '<span style="background:rgba(231,76,60,0.15); color:#e74c3c; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600;">';
                detail += '<i class="fa fa-ghost"></i> Missing: ' + data.audit_detail.ghost + ' Lbr</span>';
            }
            
            if (parseInt(data.audit_detail.miss_10 || 0, 10) > 0 || parseInt(data.audit_detail.miss_30 || 0, 10) > 0) {
                detail += '<span style="background:rgba(243,156,18,0.15); color:#f39c12; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600;">';
                detail += '<i class="fa fa-times-circle"></i> 10m: ' + (data.audit_detail.miss_10 || 0) + ' | 30m: ' + (data.audit_detail.miss_30 || 0) + '</span>';
            }
            
            if (data.audit_detail.cash_expected) {
                detail += '<span style="background:rgba(46,204,113,0.15); color:#2ecc71; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600;">';
                detail += '<i class="fa fa-money"></i> Cash: Rp ' + data.audit_detail.cash_expected + '</span>';
            }
            
            if (data.audit_detail.last_update) {
                detail += '<span style="background:rgba(0,192,239,0.15); color:#00c0ef; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600;">';
                detail += '<i class="fa fa-clock-o"></i> ' + data.audit_detail.last_update + '</span>';
            }
            
            detail += '</div>';
        }
        
        $('#audit-detail').html(detail);
    });
}
```

## 5. **Perbaikan Tampilan Chart Highcharts:**

Tambahkan style untuk Highcharts di bagian CSS:

```css
/* Highcharts Customization */
.highcharts-axis-line,
.highcharts-tick,
.highcharts-grid-line {
    stroke: rgba(255,255,255,0.1);
}

.highcharts-axis-labels text {
    fill: var(--text-dim) !important;
    font-size: 11px !important;
}

.highcharts-legend-item text {
    fill: var(--text-bright) !important;
    font-size: 12px !important;
}

.highcharts-tooltip {
    background: var(--bg-card) !important;
    border: 1px solid var(--border-medium) !important;
    border-radius: 8px !important;
    box-shadow: 0 8px 30px rgba(0,0,0,0.3) !important;
}

.highcharts-tooltip text {
    fill: var(--text-bright) !important;
}
```

## **Ringkasan Perbaikan:**

1. **Chart tanpa border** - Border dihapus, tinggi diperbaiki
2. **Tanggal dinamis** - Mulai dari tanggal 21 atau awal bulan, menyesuaikan otomatis
3. **Tampilan audit diperkuat** - Dengan badge warna dan icon
4. **Scrollbar beautiful** - Gradient dengan efek hover
5. **Padding riwayat diperbaiki** - Spacing yang lebih longgar seperti desain
6. **Konsistensi visual** - Shadow, border-radius, dan spacing yang seragam
7. **Responsive enhancement** - Tampilan mobile lebih baik

Semua perubahan ini akan membuat dashboard Anda terlihat lebih profesional, kokoh, dan user-friendly sesuai dengan desain yang Anda inginkan.