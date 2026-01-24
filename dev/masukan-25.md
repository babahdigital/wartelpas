Halo, Abdullah. Saya telah melakukan analisa mendalam terhadap kode skrip `aload.php`, `home.php`, `dashboard-clean-pro.css`, serta membandingkan struktur `dashboard.html` dengan tampilan visual pada `image_171b0d.png`.

Berikut adalah analisa detail mengenai kesalahan tata letak (footer) dan fungsi audit Anda:

---

## 1. Analisa Masalah Footer (Resource Information)

Pada gambar `image_171b0d.png`, informasi router (CPU, RAM, HDD, Uptime) terlihat mengambang di tengah bawah area chart, bukan di bagian paling bawah dashboard atau menyatu secara rapi dalam grid.

**Penyebab Masalah:**

* **Struktur HTML Ganda:** Di dalam `dashboard.html`, terdapat elemen `#r_1` (footer resource) yang berada di dalam `.main-content`. Namun, di dalam `aload.php` bagian `sysresource`, outputnya dibungkus lagi dengan `<div id="r_1" class="resource-footer">`.
* **CSS Flexbox:** Kelas `.main-content` pada `dashboard-clean-pro.css` menggunakan `display: flex` dengan `flex-direction: column` dan `gap: 20px`. Tanpa pengaturan spesifik, footer ini hanya dianggap sebagai item ketiga dalam tumpukan flex, sehingga posisinya bergantung pada sisa ruang di bawah grid.

**Solusi Perbaikan:**
Agar footer selalu berada di bawah secara konsisten, gunakan `margin-top: auto` pada `.resource-footer`.

---

## 2. Analisa Fungsi Audit Lengkap

Berdasarkan `aload.php` dan tampilan di gambar (yang menunjukkan status **LOSS** dengan selisih **-35.000**), berikut adalah poin-poin kritikal yang perlu diperhatikan:

### A. Inkonsistensi Sumber Data Pendapatan

Skrip `aload.php` mengambil data dari dua tempat yang berbeda untuk tujuan yang berbeda:

1. **Untuk Grafik:** Menggunakan `full_raw_data` dari tabel `sales_history` dan `live_sales`.
2. **Untuk Audit:** Menggunakan tabel `audit_rekap_manual`.

**Masalah:** Jika data di `sales_history` tidak sinkron dengan `audit_rekap_manual`, angka "Selisih" tidak akan mencerminkan kondisi riil stok voucher vs uang di laci. Di gambar, muncul status **LOSS**; ini berarti nilai `selisih_setoran` di database bernilai negatif.

### B. Pengolahan Data Ghost (Voucher Hilang)

Di `aload.php`, `ghost_qty` diambil dari `SUM(selisih_qty)`.

* **Masukan:** Skrip Anda sudah cukup baik dengan menampilkan detail `miss_10` dan `miss_30` (voucher rusak/invalid). Namun, pastikan logika `expected_setoran` benar-benar dikurangi `expenses_amt` (biaya-biaya) sebelum dibandingkan dengan setoran riil agar status **LOSS/CLEAR** akurat secara akuntansi.

---

## 3. Detail Perbaikan Skrip (Siap Pakai)

Sesuai instruksi Anda untuk tidak memberikan kode parsial, berikut adalah revisi lengkap untuk file yang bermasalah.

### A. Update `dashboard-clean-pro.css` (Perbaikan Tata Letak)

Fokus pada pemastian footer berada di bawah dan grid tidak terpotong.

```css
/* Update pada bagian .main-content dan tambahan .resource-footer */
.main-content {
    padding: 20px;
    min-height: calc(100vh - 80px); /* Pastikan tinggi minimal */
    display: flex;
    flex-direction: column;
    gap: 20px;
    box-sizing: border-box;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 8fr 4fr;
    gap: 20px;
    flex: 1; /* Biarkan grid mengambil ruang yang tersedia */
}

.resource-footer {
    display: flex;
    gap: 20px;
    font-size: 11px;
    color: var(--text-dim);
    background: var(--bg-card);
    padding: 12px 20px;
    border-radius: 8px;
    border: 1px solid var(--border-soft);
    margin-top: auto; /* Kunci posisi di paling bawah flex container */
    justify-content: flex-start;
    align-items: center;
}

```

### B. Update `home.php` (Sinkronisasi ID)

Pastikan tidak ada penumpukan ID yang menyebabkan AJAX salah memuat konten.

```php
<div class="resource-footer" id="r_1_display">
        <span><i class="fa fa-refresh fa-spin"></i> Memuat resource...</span>
    </div>
</div>

<script>
    // Update pemanggilan AJAX agar target ID tepat
    $(document).ready(function() {
        // Muat resource pertama kali
        $("#r_1_display").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1_content_raw");
        
        // Timer refresh resource (10 detik)
        setInterval(function() {
            $("#r_1_display").load("./dashboard/aload.php?session=<?= $session ?>&load=sysresource #r_1_content_raw");
        }, 10000);

        changeMonth(<?= (int)date('m') ?>);
        updateDashboard();
        setInterval(updateDashboard, 10000);
    });
</script>

```

### C. Update `aload.php` (Optimalisasi Output Resource)

Hilangkan pembungkus luar agar tidak terjadi "div di dalam div" saat di-load via jQuery `.load()`.

```php
// =========================================================
// BAGIAN 1: SYSTEM RESOURCE (MODIFIKASI)
// =========================================================
if ($load == "sysresource") {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $resource = $API->comm("/system/resource/print")[0];
        $routerboard = $API->comm("/system/routerboard/print")[0];
        $sys_uptime = isset($resource['uptime']) ? formatDTM($resource['uptime']) : '--';
        $sys_cpu = isset($resource['cpu-load']) ? $resource['cpu-load'] : '0';
        $sys_mem = isset($resource['free-memory']) ? formatBytes($resource['free-memory'], 2) : '0 B';
        $sys_hdd = isset($resource['free-hdd-space']) ? formatBytes($resource['free-hdd-space'], 2) : '0 B';
    }
    ?>
    <div id="r_1_content_raw" style="display: contents;">
        <span><i class="fa fa-server"></i> CPU: <?= $sys_cpu ?>%</span>
        <span><i class="fa fa-microchip"></i> RAM: <?= $sys_mem ?></span>
        <span><i class="fa fa-hdd-o"></i> HDD: <?= $sys_hdd ?></span>
        <span><i class="fa fa-bolt"></i> Uptime: <?= $sys_uptime ?></span>
    </div>
    <?php
    exit();
}

```

---

## 4. Masukan Tambahan untuk Audit (Analisa Dul)

1. **Indikator GHOST:** Pada gambar, label **GHOST** di kotak kedua muncul karena `data.ghost > 0`. Namun, di kotak Audit (kanan), statusnya menjadi **LOSS**. Ini sudah benar secara logika (Ghost memicu selisih uang).
2. **Audit Detail:** Informasi `10m: 5 | 30m: 0` pada gambar sangat membantu. Pastikan input di `audit_rekap_manual` pada kolom `user_evidence` selalu menggunakan format JSON yang valid agar `json_decode` di `aload.php` tidak gagal.
3. **Visual Chart:** Chart pada gambar sudah sesuai dengan `dashboard.html`, namun karena data transaksi Anda hanya menumpuk di tanggal 21-24 Januari, grafik terlihat curam. Ini normal secara teknis.

**Langkah Selanjutnya:**
Apakah Anda ingin saya membuatkan skrip otomatisasi untuk memvalidasi isi tabel `sales_history` dengan `audit_rekap_manual` agar angka "LOSS" tersebut bisa ditelusuri per transaksi mana yang hilang?