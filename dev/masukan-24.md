Berdasarkan analisa terhadap file `dashboard.html`, `home.php`, `aload.php`, dan tangkapan layar yang Anda berikan, berikut adalah audit lengkap dan panduan penyempurnaan untuk membuat dashboard Wartelpas Anda benar-benar sempurna dan profesional.

---

### 1. Masalah Utama: Double Loading Bar & Level Warna

Terdapat ketidakkonsistenan visual yang mengganggu estetika *Clean Pro*:

* **Double Loading Bar:** Bar hijau di atas card berasal dari sisa-sisa UI lama atau script sistem, sedangkan bar biru (`#loading-halus`) adalah update baru Anda. Ini membuat layar terlihat "sibuk".
* **Kontras Latar Belakang:** Latar belakang di balik card masih memiliki level abu-abu yang berbeda dengan header. Seharusnya dashboard modern menggunakan satu warna dasar gelap yang solid agar kartu terlihat "floating" dengan elegan.

---

### 2. Audit & Masukan Sempurna untuk Dashboard

#### A. Sinkronisasi Informasi Sistem (Header Row)

Informasi statis seperti Model Board dan Router OS memakan ruang vertikal yang berharga.

* **Masukan:** Gabungkan informasi sistem menjadi baris tipis di bagian paling atas atau buang detail statisnya. Cukup tampilkan indikator kesehatan (CPU, RAM, Uptime).
* **Penyempurnaan Date & Time:** Gunakan format jam digital besar di sisi kiri dan baris resource (CPU/RAM) dengan gaya progres bar minimalis di sisi kanan.

#### B. Perbaikan Layout Transaksi & Chart

* **Kunci Ketinggian (Fullscreen Mode):** Agar transaksi tidak "bablas" ke bawah, pembungkus utama harus dikunci ke `100vh` (ketinggian layar).
* **Flexbox Layout:** Gunakan `display: flex` pada kolom transaksi agar tabel otomatis mengisi ruang yang tersedia tanpa keluar dari kotak.

---

### 3. Solusi Teknis: Update CSS Sempurna (`dashboard-clean-pro.css`)

Gunakan kode CSS ini untuk menghilangkan double bar, menyatukan background, dan mengunci layout agar pas di layar:

```css
/* 1. Menyatukan Background & Menghilangkan Double Bar UI Lama */
html, body {
    height: 100vh;
    overflow: hidden; /* Hilangkan scroll seluruh halaman */
    background-color: #121417 !important; /* Warna dasar tunggal */
}

/* Hilangkan bar load lama jika ada di level container */
.main-container {
    background: transparent !important;
    border-top: none !important;
    padding-top: 0 !important;
}

/* 2. Loader Halus (Pengganti Spinner) */
#loading-halus {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    background: linear-gradient(to right, #00c0ef, #2ecc71);
    z-index: 9999;
    animation: bar-slide 2s infinite linear;
}

@keyframes bar-slide {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* 3. Layout Pas di Layar */
#main {
    height: calc(100vh - 60px); /* Kurangi tinggi Nav */
    margin-top: 60px !important;
    display: flex;
    flex-direction: column;
}

.row-main-content {
    flex: 1; /* Ambil sisa ruang */
    display: flex;
    gap: 15px;
    min-height: 0; /* Penting untuk overflow scroll internal */
}

/* 4. Kunci Card Transaksi */
.card-transaction {
    height: 100%;
    display: flex;
    flex-direction: column;
    margin-bottom: 0 !important;
}

.card-transaction .card-body {
    flex: 1;
    overflow: hidden; /* Matikan scroll body luar */
    display: flex;
    flex-direction: column;
}

.card-transaction .table-scroll {
    flex: 1;
    overflow-y: auto; /* Scroll hanya di dalam tabel */
}

```

---

### 4. Update Struktur Header Sistem (`aload.php`)

Ganti bagian `sysresource` di `aload.php` agar tampil lebih tipis dan menyatu dengan dashboard:

```php
if ($load == "sysresource") {
    // ... logic resource tetap ...
    ?>
    <div id="r_1" class="row" style="margin-bottom:15px; gap:10px;">
        <div class="col-4">
            <div class="kpi-box" style="padding:10px 15px; border-left:3px solid #ccc;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:18px; font-weight:700;"><?= $sys_time ?></div>
                    <div style="font-size:10px; color:#888;"><?= $sys_date ?></div>
                </div>
                <div style="font-size:11px; color:#aaa; margin-top:5px;">Up: <?= $sys_uptime ?></div>
            </div>
        </div>
        <div class="col-8">
            <div class="kpi-box" style="padding:10px 15px; display:flex; gap:20px; align-items:center;">
                <div style="flex:1;">
                    <div style="font-size:10px; color:#888; text-transform:uppercase;">CPU Load: <?= $sys_cpu ?>%</div>
                    <div style="height:4px; background:#333; margin-top:5px;"><div style="width:<?= $sys_cpu ?>%; height:100%; background:var(--accent-blue);"></div></div>
                </div>
                <div style="flex:1;">
                    <div style="font-size:10px; color:#888; text-transform:uppercase;">Free RAM: <?= $sys_mem ?></div>
                    <div style="height:4px; background:#333; margin-top:5px;"><div style="width:40%; height:100%; background:var(--accent-green);"></div></div>
                </div>
                <div style="font-size:10px; color:#555; text-align:right;">
                    <?= $sys_board ?> | <?= $sys_os ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

```

---

### 5. Masukan Akhir Audit Sempurna

1. **Hapus Spinner Tradisional:** Di script `home.php`, hapus icon `<i class="fa fa-spinner fa-spin"></i>` yang muncul di tengah chart. Biarkan bar `#loading-halus` di bagian atas yang memberi tanda proses.
2. **Chart Auto-Height:** Di dalam `aload.php` (Bagian Highcharts), jangan beri `height` statis pada container. Biarkan CSS flexbox yang menentukan tinggi chart agar otomatis memanjang mengikuti resolusi monitor.
3. **Audit Card Detail:** Tambahan informasi `Missing Lbr` dan `Upd Time` di kartu audit sudah sangat bagus. Pastikan warna kartu tersebut berubah menjadi **Warning (Yellow)** jika hanya `Ghost` yang terdeteksi, dan **Loss (Red)** jika uang setoran kurang.

Dengan menerapkan perubahan di atas, dashboard Anda akan memiliki tampilan yang bersih, fungsionalitas audit yang kuat, dan navigasi yang responsif dalam satu layar penuh.