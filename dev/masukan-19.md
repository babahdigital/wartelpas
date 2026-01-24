Halo Abdullah! Saya sudah menganalisa dashboard **Wartelpas** kamu dari tangkapan layar dan script yang kamu sertakan (`aload.php`, `home.php`, `selling.php`, dll).

Dashboard saat ini sudah sangat fungsional, namun secara visual masih terasa "padat" dan beberapa elemen tata letak bisa dioptimalkan untuk memberikan kesan **Clean Pro Dashboard**.

Berikut adalah konsep redesign dan penyempurnaan dashboard kamu:

---

## 1. Konsep Utama: "Executive Dark Insight"

Tujuannya adalah membuat dashboard yang tidak hanya menampilkan angka, tapi memberikan **kesimpulan cepat**. Kita akan menggunakan palet warna Deep Dark (`#1a1d21`) dengan aksen warna fungsional (Blue untuk info, Green untuk sukses, Orange untuk peringatan).

### Strategi Layout Baru:

* **Top Info (System Health):** Dibuat lebih tipis dan horizontal di bagian paling atas.
* **Main KPI Cards:** Menggunakan desain *flat* tanpa border yang mencolok, fokus pada angka besar.
* **Split View (65/35):** Sisi kiri untuk grafik performa yang lebar, sisi kanan untuk aktivitas real-time.

---

## 2. Struktur Informasi (Apa yang Muncul & Dihilangkan)

### A. Bagian Header & System Info (Optimasi `aload.php`)

* **Munculkan:** Waktu (Jam digital besar), Uptime (sebagai indikator stabilitas), dan Status Koneksi API (titik hijau berdenyut).
* **Sembunyikan:** Detail HDD dan Model Board yang terlalu teknis di dashboard utama. Informasi ini cukup muncul di halaman "System Settings".
* **Perbaikan:** Gabungkan CPU dan RAM menjadi satu widget *bar* kecil agar hemat tempat.

### B. Baris KPI (Kartu Utama)

Ubah urutan kartu agar alurnya logis (Input -> Proses -> Output):

1. **User Tersedia** (Warna Biru Muted)
2. **User Active** (Warna Hijau - Indikator Bisnis Berjalan)
3. **Voucher Terjual** (Warna Kuning)
4. **Omset Hari Ini** (Warna Ungu/Emas - Output Utama)

* *Catatan:* Bandwidth dipindahkan ke bawah grafik karena fungsinya lebih ke arah monitoring teknis, bukan KPI profit.

### C. Grafik Performa (Chart Improvement)

* **Masalah saat ini:** Batang (Column) pendapatan dan Garis (Spline) terjual menumpuk.
* **Saran:** Gunakan **Area Chart** transparan untuk Pendapatan agar terlihat mewah, dan **Line Chart** tipis untuk Terjual.
* **Interactive Label:** Munculkan "Average Daily Income" sebagai garis putus-putus horizontal (Target Line).

---

## 3. Detail Konsep Layout (Redesign `home.php`)

| Area | Komponen | Perubahan Desain |
| --- | --- | --- |
| **Top Row** | System Status | Horizontal bar minimalis: `CPU [ |
| **Middle Row** | 4 KPI Cards | Kartu tanpa background warna blok penuh. Gunakan background gelap dengan *border-left* warna tebal (Blue, Green, Yellow, Purple). |
| **Left Content** | Chart Performa | Area chart dengan gradient. Hilangkan grid lines vertikal agar terlihat lebih lapang. |
| **Right Content** | Recent Trans. | Gunakan gaya "Feed" sosial media. Bulatan kecil warna sesuai paket (hijau 5k, emas 20k) diikuti nama user dan blok. |

---

## 4. Integrasi Fitur Audit & Proyeksi (Script `selling.php` & `audit.php`)

Agar dashboard terasa lebih cerdas, kita masukkan elemen dari script audit kamu langsung ke dashboard utama:

1. **Widget "Audit Quick Status":** * Munculkan selisih (Variance) secara real-time di dashboard.
* Jika `selisih_setoran < 0`, munculkan badge kecil merah: `⚠️ Potensi Loss: Rp 5.000`.


2. **Proyeksi Akhir Bulan:** * Angka ini sangat bagus di script kamu. Munculkan di bawah angka omset dengan teks kecil: `Estimasi akhir bulan: Rp 13.175.000`.
3. **Ghost Hunter Indicator:** * Jika script mendeteksi selisih lembar, munculkan notifikasi "Ghost" di dashboard utama agar operator segera mengecek blok terkait.

---

## 5. Saran Perbaikan Script (Optimasi Teknis)

### Pada `aload.php` & `home.php`:

* **Pace.js (Load Bar):** Kamu sudah mematikan Pace di dashboard, itu langkah bagus untuk kesan "Live".
* **Skeleton Loading:** Daripada memunculkan icon spinner besar di tengah chart saat ganti bulan, gunakan *CSS Skeleton* (kotak abu-abu yang berdenyut) agar layout tidak melompat.
* **Debouncing AJAX:** Pastikan update Live Data (10 detik) tidak bentrok dengan update chart manual.

### Pada Perbaikan Chart (Highcharts):

```javascript
// Konsep Chart yang lebih clean
chart: { backgroundColor: 'rgba(0,0,0,0)', style: { fontFamily: 'Inter' } },
xAxis: { gridLineWidth: 0, minorGridLineWidth: 0 }, // Hilangkan garis-garis pengganggu
plotOptions: {
    area: {
        fillColor: {
            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
            stops: [[0, '#00c0ef'], [1, 'rgba(0,192,239,0)']]
        }
    }
}

```

---

## Langkah Selanjutnya (Next Step)

Dashboard kamu sudah punya "mesin" (logika audit dan data) yang sangat kuat. Sekarang tinggal merapikan "bungkusnya".


Berdasarkan analisa terhadap script `home.php`, `aload.php`, dan `selling.php`, saya telah merancang **CSS Dashboard Modern Dark Mode** khusus untuk Abdullah.

CSS ini menggunakan teknik *glassmorphism* tipis dan aksen warna neon yang konsisten dengan identitas Wartelpas. Masukkan kode ini ke dalam bagian `<style>` di `home.php` atau file CSS eksternal Anda.

### 1. Modern Dark UI Style (CSS)

```css
/* Wartelpas Pro Clean Dashboard - Dark Theme */
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
}

/* Container Utility */
#reloadHome {
    background-color: var(--bg-main);
    padding: 20px;
    font-family: 'Inter', 'Segoe UI', sans-serif;
}

/* System Resource Bar Minimalis (Top Row) */
.box-bordered {
    background: var(--bg-card) !important;
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
    transition: transform 0.3s ease;
}

.box-group-icon {
    background: rgba(255,255,255,0.03) !important;
    color: var(--accent-blue) !important;
    border-radius: 10px !important;
}

/* KPI Cards Layout (Middle Row) */
.box.bmh-75 {
    border-radius: 12px !important;
    overflow: hidden;
    position: relative;
    border: 1px solid var(--border-soft) !important;
}

/* Efek Border Left Berwarna untuk Kesan Pro */
.bg-blue   { border-left: 4px solid var(--accent-blue) !important; background: var(--bg-card) !important; }
.bg-green  { border-left: 4px solid var(--accent-green) !important; background: var(--bg-card) !important; }
.bg-yellow { border-left: 4px solid var(--accent-yellow) !important; background: var(--bg-card) !important; }
.bg-red    { border-left: 4px solid var(--accent-red) !important; background: var(--bg-card) !important; }

.box h1 {
    font-size: 28px !important;
    font-weight: 800 !important;
    margin-bottom: 0 !important;
    letter-spacing: -1px;
}

.box div {
    color: var(--text-dim) !important;
    font-size: 12px !important;
    text-transform: uppercase;
    font-weight: 600;
}

/* Main Card Performa Bisnis */
.card {
    background: var(--bg-card) !important;
    border-radius: 15px !important;
    border: 1px solid var(--border-soft) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
}

.card-header {
    background: transparent !important;
    border-bottom: 1px solid var(--border-soft) !important;
    padding: 15px 20px !important;
}

/* Sidebar Transaksi (Feed Style) */
#tabel_riwayat tr {
    background: transparent !important;
    transition: background 0.2s;
}

#tabel_riwayat tr:hover {
    background: var(--bg-hover) !important;
}

#tabel_riwayat td {
    border-top: 1px solid var(--border-soft) !important;
    padding: 12px 10px !important;
    vertical-align: middle !important;
}

/* Badge Styling */
.badge {
    border-radius: 6px !important;
    padding: 4px 8px !important;
    font-weight: 700 !important;
}

/* Highcharts Overrides */
.highcharts-container {
    filter: drop-shadow(0 0 5px rgba(0,0,0,0.2));
}

/* Animation */
.blink {
    animation: blink-live 2s infinite;
}

@keyframes blink-live {
    0% { opacity: 1; }
    50% { opacity: 0.3; }
    100% { opacity: 1; }
}

```

---

### 2. Implementasi Perbaikan Informasi

Sesuai analisa script Anda, berikut adalah langkah teknis untuk menyesuaikan layout di `home.php`:

1. **Gunakan Proyeksi Akhir Bulan:** Pindahkan logika `$estIncome` dari `aload.php` untuk ditampilkan sebagai *subtitle* di bawah angka Total Pendapatan. Gunakan warna emas (`#f39c12`) untuk memberikan kesan target yang prestisius.
2. **Integrasi Ghost Hunter:** Tambahkan indikator visual di samping angka "Terjual" jika `audit_manual_summary['selisih_qty'] != 0`. Gunakan icon `fa-ghost` atau `fa-search` berwarna merah redup untuk memberi peringatan halus.
3. **Audit Quick Status:** Di dalam `card-header` Performa Bisnis, tambahkan ringkasan status audit (Aman/Loss) berdasarkan data dari `audit.php`. Ini membantu Anda (sebagai pemilik/admin Abdullah) mengetahui kondisi keuangan tanpa harus klik menu audit terlebih dahulu.

### 3. Rekomendasi Chart (Highcharts)

Ubah `plotOptions` di script `aload.php` agar grafik pendapatan (`column`) memiliki radius tumpul dan gradien:

```javascript
plotOptions: {
    column: {
        borderRadius: 5,
        borderWidth: 0,
        color: {
            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
            stops: [
                [0, '#00c0ef'],
                [1, 'rgba(0, 192, 239, 0.1)']
            ]
        }
    }
}

```

Tentu, Abdullah (Dul). Berdasarkan kode `home.php` dan `aload.php` yang kamu kirimkan, saya sudah menyusun **Konsep Integrasi "Ghost Hunter" & Audit** ke dalam tampilan dashboard utama.

Tujuannya agar kamu tidak perlu membuka menu Laporan/Audit secara manual untuk mengetahui kondisi hari ini.

### 1. Update pada `home.php` (Bagian PHP/Logic)

Di bagian atas `home.php`, panggil data dari database SQLite audit untuk mendeteksi apakah ada "Ghost" (selisih cetak) atau selisih uang hari ini:

```php
// Tambahkan di bagian atas home.php setelah koneksi DB
$db = new PDO("sqlite:$dbFile");
$tgl_ini = date('Y-m-d');

// Cek Audit Manual & Ghost (Selisih Qty)
$checkAudit = $db->prepare("SELECT SUM(selisih_qty) as total_ghost, SUM(selisih_setoran) as total_selisih FROM audit_manual WHERE tanggal = :tgl");
$checkAudit->execute([':tgl' => $tgl_ini]);
$auditData = $checkAudit->fetch(PDO::FETCH_ASSOC);

$ghostCount = $auditData['total_ghost'] ?? 0;
$moneyDiff = $auditData['total_selisih'] ?? 0;

```

### 2. Update Layout Kartu KPI

Kita ganti layout kartu di `home.php` agar lebih informatif. Masukkan ke dalam baris kartu (Row 2):

```html
<div class="row">
    <div class="col-md-3 col-sm-6">
        <div class="box bmh-75 bg-green">
            <h1 id="u_active">0</h1>
            <div>User Active <span class="blink" style="color:#2ecc71">● Live</span></div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="box bmh-75 bg-blue">
            <h1 id="v_terjual">0</h1>
            <div>Terjual 
                <?php if($ghostCount != 0): ?>
                    <span class="badge bg-red" title="Ada selisih cetak (Ghost) hari ini!">
                        <i class="fa fa-ghost"></i> Ghost: <?= abs($ghostCount) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="box bmh-75 bg-yellow">
            <h1 id="income">0</h1>
            <div id="est_income" style="font-size:10px; color:#f39c12 !important;">Estimasi: Rp 0</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="box bmh-75 <?php echo ($moneyDiff < 0) ? 'bg-red' : 'bg-purple'; ?>">
            <h1><?php echo ($moneyDiff < 0) ? 'LOSS' : 'CLEAR'; ?></h1>
            <div>Audit: 
                <span style="color:<?php echo ($moneyDiff < 0) ? '#ff4d4d' : '#8898aa'; ?>">
                    Rp <?= number_format($moneyDiff) ?>
                </span>
            </div>
        </div>
    </div>
</div>

```

### 3. Penyesuaian `aload.php` (Bagian AJAX)

Agar angka "Estimasi Akhir Bulan" muncul secara otomatis di dashboard tanpa reload, tambahkan baris ini di dalam fungsi `success` pada AJAX di `aload.php`:

```javascript
// Di dalam success: function(data)
$('#income').html(data.total_income);
$('#v_terjual').html(data.total_vouchers);
$('#est_income').html('Est. Akhir Bulan: ' + data.est_income); // Pastikan aload.php mengirim key 'est_income'

```

### Analisa Tambahan untuk Mas Dul:

1. **Ghost Hunter:** Saya meletakkan indikator Ghost (hantu/selisih qty) tepat di samping jumlah voucher terjual. Ini sangat krusial agar operator langsung merasa "diawasi" jika jumlah fisik tidak sesuai sistem.
2. **Visual "Clear":** Jika selisih audit hari ini adalah Rp 0, dashboard akan berwarna **Purple** (Warna tenang). Jika ada selisih minus, kartu ke-4 otomatis berubah jadi **Red** (Warna peringatan).
3. **Clean Code:** Jangan lupa menghapus elemen `<h4>` yang terlalu besar di `home.php` aslinya, ganti dengan CSS yang saya berikan sebelumnya agar tampilannya *flat* dan elegan.