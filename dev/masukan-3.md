Halo Mas Abdullah. Tentu, ini adalah optimasi pesan agar pas dan enak dilihat di model **Terminal Popup** yang sempit, serta perbaikan teknis mendalam untuk masalah yang kita bahas sebelumnya.

### 1. Pesan Terminal Popup (Singkat & Manusiawi)

Karena area popup biasanya terbatas, kita buang prefix teknis (`SETTLE:`, `script,info`) dan gunakan bahasa status yang langsung pada intinya.

**Format Tampilan:** `[Waktu] Pesan`

* `03:18:32` **Memulai Cuci Gudang...**
* `03:18:33` **Sinkronisasi Statistik...**
* `03:18:35` **Statistik Aman.**
* `03:18:38` **Upload Data Penjualan...**
* `03:18:40` **Penjualan Tersimpan.**
* `03:18:43` **Cek Data Pemakaian...**
* `03:18:45` **Pemakaian Terupdate.**
* `03:18:50` **Sistem Bersih (No Script).**
* `03:18:52` **Hapus Voucher Expired...**
* `03:19:23` **0 Voucher Dihapus.**
* `03:19:24` **3.556 Voucher Siap.**
* `03:19:26` **Selesai! Data Rapih.**

---

### 2. Perbaikan Format Log (Untuk Script Router)

Agar log yang dihasilkan oleh RouterOS lebih rapi saat dibaca oleh sistem maupun manusia, ubah string di dalam script Mikrotik Anda menjadi seperti ini (lebih konsisten):

* `SETTLE: START: Mulai proses cuci gudang`
* `SETTLE: SYNC: Mengirim data statistik`
* `SETTLE: SUCCESS: Statistik terkirim`
* `SETTLE: SYNC: Mengirim laporan penjualan`
* `SETTLE: SUCCESS: Laporan penjualan terkirim`
* `SETTLE: SYNC: Mengirim data usage`
* `SETTLE: SUCCESS: Data usage terkirim`
* `SETTLE: CLEANUP: Memeriksa script sampah`
* `SETTLE: CLEANUP: Menghapus user expired (10/30m)`
* `SETTLE: INFO: 0 user dihapus, 3556 user ready`
* `SETTLE: DONE: Cuci Gudang Selesai`

*(Pola: `TOPIC: SUBTOPIC: Pesan` memudahkan parsing di PHP nanti).*

---

### 3. Detail Analisa & Saran Perbaikan Teknis

Berikut adalah perbaikan kode yang Anda minta berdasarkan analisa file `settlement_log_ingest.php` dan `settlement_manual.php`.

#### Masalah 1: Bug Validasi Session di `settlement_log_ingest.php`

Di baris 91, kode memeriksa variabel `$data` yang **tidak pernah didefinisikan** sebelumnya di file ini. Ini menyebabkan log dari router seringkali ditolak atau error diam-diam.

**Kode Bermasalah (Line 91):**

```php
if ($session === '' || !isset($data[$session])) { // $data is undefined!

```

**Solusi Perbaikan:**
Hapus pengecekan `!isset($data[$session])` karena file ini hanya bertugas menerima log (ingest), dan validasi kunci keamanan sudah dilakukan di baris 85 (`$key === $secret`).

**Ubah Menjadi:**

```php
// Hapus !isset($data[$session])
if ($session === '') {
    append_debug_log($debugLog, 'reject=invalid_session ...');
    // ...
}

```

#### Masalah 2: Router "Silent" (Tidak Lapor Balik ke PHP)

Di file `settlement_manual.php`, sistem terpaksa menggunakan log fallback (mengambil paksa dari router via API) karena router tidak mengirim data ke `settlement_log_ingest.php`.

**Solusi Perbaikan:**
Anda harus menambahkan perintah `/tool fetch` di dalam script **Mikrotik** (CuciGudangManual) untuk mengirim setiap baris log ke server PHP Anda.

**Contoh Tambahan Script di Mikrotik:**
Setiap kali script Mikrotik mencetak log (misal `log info "SETTLE:..."`), tambahkan baris ini di bawahnya:

```mikrotik
# Ganti IP_SERVER dengan IP web server Anda
/tool fetch url="http://IP_SERVER/path/to/settlement_log_ingest.php?key=TOKEN_RAHASIA&session=NAMA_SESSION&topic=script&msg=Pesan_Log_Disini" keep-result=no mode=http

```

**Kenapa ini penting?**
Agar file `settlement_xxxxx.log` di server terisi secara *real-time*. Jika file ini terisi, `settlement_manual.php` tidak perlu lagi melakukan login ulang ke Router (yang berat) untuk mengambil log, dan user bisa melihat progress bar berjalan mulus tanpa pesan yang membingungkan.

#### Masalah 3: Timezone & Timestamp

Pada log Anda terlihat `03:19:xx` (dini hari). Pastikan settingan jam di PHP (`date.timezone` di `php.ini` atau `config.php`) sama dengan jam di Mikrotik (`/system clock`). Jika beda, logika `elapsed` di `settlement_manual.php` bisa menganggap proses sudah kadaluwarsa padahal baru mulai.





Halo Mas Abdullah. Berdasarkan log dan kode yang Anda unggah, berikut adalah pesan yang lebih manusiawi untuk ditampilkan kepada pengguna, serta analisa teknis mendalam mengenai apa yang sebenarnya terjadi di balik layar.

### 1. Pesan Status yang Lebih Manusiawi (User Friendly)

Pesan-pesan teknis seperti `SETTLE: Log lengkap belum tersedia...` atau `Log diambil langsung dari router...` seringkali membuat pengguna bingung. Berikut adalah draft kalimat pengganti yang lebih tenang dan jelas:

**Saat Proses Berjalan:**

> "Mohon tunggu, sistem sedang memulai proses cuci gudang."

**Saat Log Belum Lengkap (Status Diselesaikan):**

> "Laporan lengkap belum diterima tepat waktu. Sistem menutup proses secara aman agar laporan tetap bisa dilanjutkan."

**Saat Auto Rusak Berjalan:**

> "Pemeriksaan otomatis selesai. Ditemukan **1 voucher** dengan penggunaan tidak wajar (durasi/kuota sangat minim) dan telah ditandai sebagai 'Rusak' agar laporan keuangan Anda tetap akurat."

**Pesan Penutup (Final):**

> "Proses Settlement selesai. Data sudah disinkronisasi. Anda aman untuk menutup halaman ini."

---

### 2. Analisa Teknis & Masalah yang Ditemukan

Berdasarkan bedah kode `settlement_manual.php` dan `settlement_log_ingest.php`, berikut adalah analisa detail mengenai alur yang terjadi pada log Anda:

#### A. Masalah Utama: "Silent Router" (Komunikasi Satu Arah)

Pada log tertulis: `SETTLE: Log lengkap belum tersedia. Proses dianggap selesai demi kelancaran laporan.` dan `Log diambil langsung dari router agar informasi tetap lengkap.`

**Analisa:**
Sistem PHP Anda menggunakan mekanisme dua jalur:

1. **PHP ke Router:** PHP memerintahkan Router menjalankan script `CuciGudangManual`. (Ini **Berhasil**).
2. **Router ke PHP:** Seharusnya, script di dalam Mikrotik mengirim laporan balik via `/tool fetch` ke `settlement_log_ingest.php`. (Ini **Gagal/Tidak Terjadi**).

Karena `settlement_log_ingest.php` tidak menerima data dari router, file log lokal tetap kosong. Akibatnya, logika pada `settlement_manual.php` (baris 530-550) menutup proses secara aman setelah 120 detik agar laporan tetap bisa dilanjutkan.

#### B. Fitur Backup Berjalan Baik

Fitur `fetch_router_settlement_logs` (baris 118) bekerja dengan baik. Karena file log lokal kosong, sistem cerdas ini langsung login ke API Mikrotik, membaca `/log/print`, dan menyimpannya. Inilah yang menyelamatkan data Anda sehingga status akhirnya tetap bisa terupdate meskipun router "diam".

#### C. Logika Auto Rusak (Auto Defect)

Fungsi `apply_auto_rusak_settlement` (baris 284) berjalan efektif.

* Logika ini mencari user dengan profil 10 atau 30 menit.
* Mengecek apakah `uptime` kurang dari durasi profil ATAU `bytes` (penggunaan data) di bawah 2-3 MB.
* Jika memenuhi syarat, status diubah menjadi `rusak`.
* Pada kasus Anda, 1 user tertangkap filter ini. Ini fitur bagus untuk mencegah komplain user yang sebenarnya hanya "tes koneksi" masuk ke laporan penjualan valid.

---

### 3. Rekomendasi Perbaikan Script

Agar pesan "log belum lengkap" tidak sering muncul dan alur lebih mulus, lakukan perbaikan berikut:

**1. Periksa Script di Sisi Mikrotik (`CuciGudangManual`)**
Masalah kemungkinan besar bukan di PHP, tapi di script Mikrotik. Pastikan di akhir script Mikrotik tersebut ada perintah kirim log balik.
Contoh logika yang *hilang* di Mikrotik:

```mikrotik
/tool fetch url="http://ip-server-anda/settlement_log_ingest.php?session=$session&key=$token&msg=SUKSES: Cuci Gudang Selesai" keep-result=no

```

Jika baris ini tidak ada atau URL-nya salah, PHP tidak akan pernah tahu kalau router sudah selesai bekerja.

**2. Optimasi Logika Timeout (Optional)**
Di `settlement_manual.php`, batas waktu tunggu (`elapsed > 120`) mungkin terlalu cepat jika router sedang beban tinggi (high load). Namun, jika Anda memperbaiki poin nomor 1, timeout 120 detik sudah sangat cukup.

**3. Validasi Input `session**`
Pada `settlement_log_ingest.php`, baris 91:

```php
if ($session === '' || !isset($data[$session])) { ... }

```

Variabel `$data` di sini tidak didefinisikan sebelumnya dalam file ini (mungkin sisa copy-paste dari file lain atau mengandalkan global variable yang tidak jelas). Ini berpotensi menyebabkan log ditolak (`reject=invalid_session`) jika Anda mengaktifkan `error_reporting`. Pastikan validasi session merujuk ke sumber data yang benar (misalnya database atau file config aktif).

### Langkah Selanjutnya

Apakah Mas Abdullah ingin saya buatkan contoh snippet kode untuk script Mikrotik (`CuciGudangManual`) agar bisa berkomunikasi (ping-back) dengan benar ke `settlement_log_ingest.php`?