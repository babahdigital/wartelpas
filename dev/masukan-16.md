Halo Pak Abdullah.

Berdasarkan screenshot dan *source code* yang Anda kirimkan, dashboard Wartelpas Anda sudah berada di level **80% (Sangat Baik)**. Tampilannya bersih (Clean Dark Mode) dan datanya relevan.

Namun, untuk mencapai level **100% (Sempurna & Profesional)**, ada beberapa aspek "Kekosongan Informasi" dan "Visual UX" yang perlu ditingkatkan agar Owner tidak perlu mengklik tombol "Analisa Bisnis" untuk melihat kesehatan bisnisnya.

Berikut adalah **Masukan Lengkap (Design & Logic)** beserta revisi kodenya.

---

### 1. Analisa Masalah & Konsep Perbaikan

1. **Indikator "Stream Data" Tercecer:**
* *Masalah:* Teks "Stream Data" (kelap-kelip hijau) berada di baris sendiri di bawah kartu. Ini membuang *space* vertikal.
* *Solusi:* Pindahkan ke dalam Header Card "Hotspot & Pendapatan" agar lebih rapi.


2. **Chart Kurang "Bicara" (Single Dimension):**
* *Masalah:* Chart hanya menampilkan *Income* (Batang Biru). Owner tidak tahu apakah omzet naik karena jual banyak voucher murah, atau sedikit voucher mahal.
* *Solusi:* Gunakan **Dual-Axis Chart**.
* **Batang (Column):** Pendapatan (Rupiah).
* **Garis (Line):** Jumlah Voucher Terjual (Lembar).
* Ini memberikan korelasi visual: "Oh, tanggal ini omzet tinggi padahal yang beli sedikit (berarti banyak yang beli paket mahal)."




3. **Hilangnya Prediksi (Forecasting):**
* *Masalah:* Data hanya bersifat historis (yang sudah terjadi). Owner butuh motivasi.
* *Solusi:* Tambahkan **"Proyeksi Akhir Bulan"**. Rumus: *(Rata-rata harian x Sisa hari) + Total Saat Ini*.


4. **Tabel Riwayat Monoton:**
* *Masalah:* Tabel di kanan terlalu polos.
* *Solusi:* Berikan **Badge Warna** pada kolom Harga dan Blok agar mata lebih cepat menangkap pola penjualan.



---

### 2. Implementasi Code (Copy-Paste)

Berikut adalah revisi kode yang menggabungkan semua ide di atas.

#### A. Update `home.php` (Perbaikan Layout & Struktur)

Ganti seluruh isi `dashboard/home.php` dengan kode ini. Perhatikan posisi "Stream Data" dan struktur tabel yang lebih modern.

```php
<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026)
 * FINAL PRO: HOME - INTEGRATED DASHBOARD
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
        // Loading Visual Lebih Halus
        $("#chart_container").css("opacity", "0.5");
        $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center" style="padding:20px; color:#aaa;"><i class="fa fa-circle-o-notch fa-spin"></i> Memuat data...</td></tr>');

        // Request Grafik & Data
        $.get("./dashboard/aload.php?session=<?= $session ?>&load=hotspot&m=" + m, function(data) {
            $("#r_2_content").html(data);
            $("#chart_container").css("opacity", "1");
        });

        // Request Tabel Riwayat
        setTimeout(function() {
            $.get("./dashboard/aload.php?session=<?= $session ?>&load=logs&m=" + m, function(dataLogs) {
                if(dataLogs.trim() == "") {
                    $("#tabel_riwayat").html('<tr><td colspan="5" class="text-center text-muted" style="padding:20px;">Belum ada transaksi.</td></tr>');
                } else {
                    $("#tabel_riwayat").html(dataLogs);
                }
            });
        }, 500);
    }

    $(document).ready(function() {
        $("#r_1").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1 > *");
        changeMonth(<?= (int)date('m') ?>);
    });
</script>

<div id="reloadHome">
    <div id="r_1" class="row">
        <div class="col-12 text-center" style="padding:20px; color:#666;">
            <i class="fa fa-refresh fa-spin"></i> Menghubungkan ke Router...
        </div>
    </div>

    <div class="row">
        <div class="col-8">
            <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;"><i class="fa fa-bar-chart"></i> Performa Bisnis</h3>
                    
                    <small style="font-size:10px; color:#bbb; font-weight:bold; letter-spacing: 0.5px; background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 4px;">
                        <i class="fa fa-circle text-green blink" style="font-size: 8px; margin-right: 4px;"></i> LIVE STREAM
                    </small>
                </div>
                <div class="card-body" id="r_2_content" style="min-height: 400px; padding: 5px 10px;">
                    </div>
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
                            <tr><td colspan="5" class="text-center" style="padding:20px;">Menunggu...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Animasi Kedip untuk Live Stream */
    .blink { animation: blinker 1.5s linear infinite; }
    @keyframes blinker { 50% { opacity: 0; } }
    
    /* Perbaikan Scrollbar Tabel */
    .card-body::-webkit-scrollbar { width: 6px; }
    .card-body::-webkit-scrollbar-track { background: #222; }
    .card-body::-webkit-scrollbar-thumb { background: #444; border-radius: 3px; }
    .card-body::-webkit-scrollbar-thumb:hover { background: #555; }
</style>

```

#### B. Update `aload.php` (Chart Dual Axis & Proyeksi)

Ini adalah bagian paling penting. Saya menambahkan logika untuk **Quantity (Jumlah Lembar)** dan **Forecasting**.

Cari bagian `else if ($load == "hotspot")` di `aload.php` dan ganti blok kodenya dengan yang ini (atau sesuaikan logika chart-nya):

```php
// ... (Bagian awal koneksi API & Database tetap sama) ...

    // ARRAY UNTUK CHART (DUAL AXIS)
    $dailyIncome = array_fill(1, $daysInMonth, 0);
    $dailyQty    = array_fill(1, $daysInMonth, 0); // ARRAY BARU: JUMLAH VOUCHER

    $totalVoucher = 0; $totalData = 0; $totalIncome = 0;
    
    // ... (Looping data tetap sama) ...
            if ($d_month == $filterMonth && $d_year == $filterYear) {
                $totalVoucher++; 
                $totalIncome += $price;
                
                if ($d_day >= 1 && $d_day <= $daysInMonth) { 
                    $dailyIncome[$d_day] += $price; 
                    $dailyQty[$d_day] += 1; // HITUNG QTY HARIAN
                }
                
                // ... (Analisa blok tetap sama) ...
            }
    // ... (Akhir looping) ...

    // HITUNG PROYEKSI (FORECASTING)
    $currentDay = (int)date("d");
    if ($filterMonth != (int)date("m")) { $currentDay = $daysInMonth; } // Jika buka bulan lalu, hari = full
    
    $avgDailyIncome = $currentDay > 0 ? ($totalIncome / $currentDay) : 0;
    $estIncome = $totalIncome + ($avgDailyIncome * ($daysInMonth - $currentDay));
    
    // FORMAT JSON CHART
    $jsonCategories = json_encode(array_map('strval', range(1, $daysInMonth)));
    $jsonDataIncome = json_encode(array_values($dailyIncome), JSON_NUMERIC_CHECK);
    $jsonDataQty    = json_encode(array_values($dailyQty), JSON_NUMERIC_CHECK); // DATA QTY

    ?>

    <div id="view-dashboard">
        <div class="row" style="margin-bottom: 15px;">
            <div class="col-3 col-box-6"><div class="box bg-blue bmh-75"><a href="./?hotspot=active&session=<?= $session; ?>"><div class="box-group"><div class="box-group-icon"><i class="fa fa-wifi"></i></div><div class="box-group-area"><h2 id="live-active" style="margin:0;"><?= $counthotspotactive; ?></h2><div style="font-size:11px;">User Active</div></div></div></a></div></div>
            <div class="col-3 col-box-6"><div class="box bg-green bmh-75"><a href="./?hotspot=users&profile=all&session=<?= $session; ?>"><div class="box-group"><div class="box-group-icon"><i class="fa fa-users"></i></div><div class="box-group-area"><h2 id="live-fresh" style="margin:0;"><?= $countFreshUsers; ?></h2><div style="font-size:11px;">Voucher Ready</div></div></div></a></div></div>
            <div class="col-3 col-box-6"><div class="box bg-yellow bmh-75"><a href="./?report=selling&session=<?= $session; ?>"><div class="box-group"><div class="box-group-icon"><i class="fa fa-ticket"></i></div><div class="box-group-area"><h2 id="live-sold" style="margin:0;"><?= $totalVoucher; ?></h2><div style="font-size:11px;">Terjual (<?= $monthShort[$filterMonth]; ?>)</div></div></div></a></div></div>
            <div class="col-3 col-box-6"><div class="box bg-red bmh-75"><a href="./?report=selling&session=<?= $session; ?>"><div class="box-group"><div class="box-group-icon"><i class="fa fa-database"></i></div><div class="box-group-area"><h2 id="live-traffic" style="margin:0;"><?= formatBytes($totalData); ?></h2><div style="font-size:11px;">Traffic (<?= $monthShort[$filterMonth]; ?>)</div></div></div></a></div></div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 10px;">
            <div>
                <div style="font-size:12px; color:#aaa;">Total Pendapatan</div>
                <h2 style="margin:0; font-weight:bold; color:#fff;">Rp <span id="live-income"><?= number_format($totalIncome, 0, ",", ".") ?></span></h2>
                <?php if ($filterMonth == (int)date("m")) : ?>
                    <div style="font-size:11px; color:#00c0ef; margin-top:2px;">
                        <i class="fa fa-crosshairs"></i> Proyeksi Akhir Bulan: <b>Rp <?= number_format($estIncome, 0, ",", ".") ?></b>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tab-container" style="display: flex; gap: 2px; flex-wrap:wrap; justify-content:flex-end;">
                <button class="btn btn-xs bg-purple" onclick="$('#view-dashboard').hide(); $('#view-analytics').fadeIn();" style="margin-right:10px;"><i class="fa fa-search"></i> ANALISA</button>
                <?php foreach($monthShort as $mNum => $mName) {
                    $btnStyle = ($mNum == $filterMonth) ? 'background:#3c8dbc; color:#fff;' : 'background:transparent; color:#8898aa; border:1px solid #444;';
                    echo "<button class='btn btn-xs' style='$btnStyle margin-left:2px;' onclick='changeMonth($mNum)'>$mName</button>";
                } ?>
            </div>
        </div>

        <div id="chart_income_stat" style="width:100%; height:320px;"></div>
        
        <script type="text/javascript">
            if(typeof Highcharts !== 'undefined') {
                Highcharts.chart('chart_income_stat', {
                    chart: { backgroundColor: 'transparent', height: 320, zoomType: 'xy' },
                    title: { text: '' },
                    xAxis: { categories: <?= $jsonCategories ?>, crosshair: true, lineColor: '#444', tickColor: '#444', labels: {style:{color:'#ccc'}} },
                    yAxis: [{ // Primary yAxis (Rupiah)
                        labels: { style: { color: '#00c0ef' }, formatter: function () { return this.value / 1000 + 'k'; } },
                        title: { text: 'Pendapatan (Rp)', style: { color: '#00c0ef' } },
                        gridLineColor: '#333'
                    }, { // Secondary yAxis (Lembar)
                        title: { text: 'Terjual (Lbr)', style: { color: '#f39c12' } },
                        labels: { style: { color: '#f39c12' } },
                        opposite: true,
                        gridLineWidth: 0
                    }],
                    tooltip: { shared: true, backgroundColor: 'rgba(0,0,0,0.85)', style: {color: '#fff'}, borderRadius: 8 },
                    plotOptions: { column: { borderRadius: 2 } },
                    series: [{
                        name: 'Pendapatan',
                        type: 'column',
                        yAxis: 0,
                        data: <?= $jsonDataIncome ?>,
                        color: { linearGradient: { x1: 0, x2: 0, y1: 0, y2: 1 }, stops: [[0, '#00c0ef'], [1, '#007aa3']] }, // Gradient Blue
                        tooltip: { valuePrefix: 'Rp ' }
                    }, {
                        name: 'Terjual',
                        type: 'spline',
                        yAxis: 1,
                        data: <?= $jsonDataQty ?>,
                        color: '#f39c12', // Orange
                        marker: { lineWidth: 2, lineColor: '#f39c12', fillColor: '#fff' },
                        tooltip: { valueSuffix: ' lbr' }
                    }], 
                    credits: { enabled: false },
                    legend: { itemStyle: { color: '#ccc' }, itemHoverStyle: { color: '#fff' } }
                });
            }
            // ... (Kode auto update tetap sama) ...
        </script>
    </div>
    ```

#### C. Update `aload.php` (Bagian Logs/Tabel Kanan)

Ganti bagian `else if ($load == "logs")` dengan kode ini untuk mempercantik tabel:

```php
// ...
} else if ($load == "logs") {
    // ... (Logika ambil data sama) ...
    // ... (Looping data) ...
    
    foreach ($finalLogs as $log) {
        if ($count >= $maxShow) break;
        
        $blokDisplay = "-";
        // ... (Logika blok display sama) ...

        // STYLING BARIS
        $badgeClass = "badge bg-gray";
        if ($log['price'] >= 20000) { $badgeClass = "badge bg-yellow"; } // Mahal = Emas
        elseif ($log['price'] >= 10000) { $badgeClass = "badge bg-blue"; } // Sedang = Biru
        elseif ($log['price'] >= 5000) { $badgeClass = "badge bg-green"; } // Murah = Hijau

        echo "<tr>";
        echo "<td style='padding-left:10px; vertical-align:middle; color:#ccc; font-size:10px;'>" . substr($log['time_str'], 0, 5) . " <span style='color:#666'>" . substr($log['time_str'], 11) . "</span></td>";
        echo "<td style='vertical-align:middle; font-weight:bold; color:#fff;'>" . $log['username'] . "</td>";
        echo "<td style='vertical-align:middle; color:#aaa; font-size:10px;'>" . $log['paket'] . "</td>";
        echo "<td class='text-center' style='vertical-align:middle;'><span class='badge bg-gray' style='font-size:9px;'>" . $blokDisplay . "</span></td>";
        echo "<td class='text-right' style='padding-right:10px; vertical-align:middle;'><span class='$badgeClass' style='font-size:10px;'>Rp " . number_format($log['price'],0,',','.') . "</span></td>";
        echo "</tr>";
        $count++;
    }
    // ...
}

```

### Apa yang Anda Dapatkan Sekarang?

1. **Layout Rapih:** Indikator "Stream Data" sudah masuk header, tidak berantakan lagi.
2. **Chart Pro:** Anda bisa melihat **Garis Orange (Jumlah Lembar)** di atas **Batang Biru (Rupiah)**.
* Jika Garis naik tapi Batang pendek  Banyak jual voucher receh.
* Jika Garis turun tapi Batang tinggi  Sedikit jual tapi voucher mahal (Efisiensi tinggi).


3. **Wawasan Instan:** Ada teks "Proyeksi Akhir Bulan" di bawah total pendapatan. Owner suka angka masa depan.
4. **Tabel Cantik:** Tabel kanan sekarang punya warna (Badge) berdasarkan harga, memudahkan *scanning* visual cepat (Mana yang 20rb, mana yang 5rb).

Desain ini sudah memenuhi standar dashboard monitoring profesional (SaaS Grade).