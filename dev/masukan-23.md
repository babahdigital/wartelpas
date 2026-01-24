Berdasarkan analisa pada file `dashboard.html`, `dashboard-clean-pro.css`, serta tangkapan layar terbaru, berikut adalah laporan audit desain dan solusi teknis untuk menyempurnakan dashboard **Wartelpas** Anda agar tampil proporsional, menyatu, dan profesional.

### 1. Masalah Utama: Layout & Overflow

* **Transaksi Melebar ke Bawah:** Masalah ini terjadi karena pembungkus utama (`.wrapper` atau `#main`) tidak memiliki batasan tinggi yang kaku (viewport height), sehingga konten mendorong halaman memanjang secara vertikal.
* **Chart Kurang Tinggi:** Chart saat ini menggunakan tinggi statis (`height: 320px` di `aload.php`). Agar otomatis mengisi sisa layar, kita perlu menggunakan teknik *Flexbox* pada container utama.
* **Level Warna Latar:** Latar belakang abu-abu pada `#main` tidak menyatu dengan warna dasar tema gelap Anda (`#121417`), menciptakan efek "bertumpuk" yang tidak rapi.

---

### 2. Rekomendasi Penyempurnaan Tampilan (Audit Desain)

#### A. Penyatuan Header & Background

Gunakan satu warna dasar tunggal untuk seluruh latar belakang agar elemen kartu terlihat "mengapung" dengan bersih.

* **Masukan:** Hapus latar abu-abu pada class `.main-container` dan samakan dengan warna latar belakang header.

#### B. Optimasi Informasi Sistem (Header Row)

Informasi seperti "Board Name" dan "Model" bersifat statis dan jarang berubah.

* **Masukan:** Gabungkan informasi ini menjadi satu baris tipis di bawah Navbar atau buang saja dari dashboard utama untuk memberikan ruang vertikal bagi Chart. Cukup tampilkan **Uptime**, **CPU**, dan **RAM** dalam bentuk progres bar kecil.

#### C. Loader Halus (Bukan Spinner)

Spinner kasar memberikan kesan aplikasi berat.

* **Masukan:** Gunakan *Linear Progress Bar* (garis tipis yang bergerak di bagian paling atas kartu) atau *Skeleton Screen* (bayangan kotak yang berdenyut) saat data sedang dimuat.

---

### 3. Solusi Teknis: Perbaikan CSS & HTML

Ganti atau tambahkan kode berikut pada `dashboard-clean-pro.css` untuk mengunci layout agar pas di satu layar (Fullscreen Mode):

```css
/* 1. Kunci Layout Utama agar pas satu layar */
html, body {
    height: 100vh;
    overflow: hidden; /* Mencegah scroll seluruh halaman */
    background-color: var(--bg-main) !important;
}

#main {
    height: calc(100vh - 60px); /* Kurangi tinggi Navbar */
    padding: 15px !important;
    margin-top: 60px !important;
    display: flex;
    flex-direction: column;
}

.main-container {
    display: flex !important;
    flex-direction: column;
    height: 100%;
    background: transparent !important; /* Menghilangkan level abu-abu */
}

#reloadHome {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 0 !important;
}

/* 2. Perbaikan Baris Performa & Transaksi */
.row-main-content {
    display: flex;
    flex: 1; /* Mengambil sisa ruang layar */
    gap: 15px;
    min-height: 0; /* Penting untuk overflow di dalam flex */
}

.col-left { flex: 8; display: flex; flex-direction: column; }
.col-right { flex: 4; display: flex; flex-direction: column; }

/* 3. Kunci Card Transaksi agar masuk kotak */
.card-transaction {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.card-transaction .card-body {
    flex: 1;
    overflow-y: auto; /* Scroll hanya di dalam tabel */
    padding: 0;
}

/* 4. Loader Halus (Linear) */
#loading-halus {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    background: linear-gradient(to right, var(--accent-blue), var(--accent-green));
    animation: loading-bar 2s infinite linear;
    display: none;
}

@keyframes loading-bar {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

```

---

### 4. Update Struktur HTML (`home.php`)

Ubah struktur baris Performa & Transaksi menjadi seperti ini:

```html
<div class="row-main-content">
    <div class="col-left">
        <div class="card" style="height: 100%;">
            <div class="card-header">Performa Bisnis</div>
            <div class="card-body" id="r_2_content" style="height: 100%;">
                </div>
        </div>
    </div>
    <div class="col-right">
        <div class="card card-transaction">
            <div class="card-header">Transaksi Terakhir</div>
            <div class="card-body">
                <table class="table">
                    </table>
            </div>
        </div>
    </div>
</div>

```

### 5. Penyesuaian Highcharts (`aload.php`)

Di dalam script `aload.php`, ubah setting chart agar menyesuaikan tinggi containernya:

* Hapus `height: 320` pada deklarasi `Highcharts.chart`.
* Tambahkan `reflow: true`.
* Dengan CSS di atas, Highcharts akan mendeteksi tinggi otomatis dari `.card-body` yang sudah kita kunci.

### Audit Akhir:

Dengan perubahan ini:

1. **Halaman tidak akan bisa di-scroll** secara vertikal (Fix Transaksi bablas).
2. **Chart akan otomatis meninggi** mengikuti resolusi layar monitor Anda (Fix Chart pendek).
3. **Latar belakang akan bersih** tanpa perbedaan level warna antara Navbar dan Body.

Apakah Anda ingin saya buatkan satu file **CSS Pro Update** yang sudah mencakup perbaikan progress bar RAM/CPU agar tampilan atas lebih tipis?