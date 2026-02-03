```markdown
# WartelPas (Mikhmon Custom) - Dokumentasi Lengkap

Dokumen ini merupakan referensi teknis, operasional, dan logika bisnis lengkap untuk aplikasi **WartelPas**. Dokumen ini mencakup arsitektur sistem, aturan akuntansi, alur kerja (voucher, retur, refund), integrasi pihak ketiga (WhatsApp), serta panduan audit dan *troubleshooting*.

---

## 1. Gambaran Umum

WartelPas adalah aplikasi manajemen hotspot berbasis PHP yang dikembangkan dari basis Mikhmon v3, yang dimodifikasi secara ekstensif untuk kebutuhan operasional khusus (Lapas Batulicin). Fokus utama aplikasi ini adalah manajemen voucher yang ketat, pelaporan keuangan yang akurat (audit/settlement), dan integrasi dengan RouterOS MikroTik.

### Fitur Utama
* **Manajemen Voucher:** Generate, cetak (QR/PNG), monitoring aktif, penanganan voucher rusak, dan retur.
* **Keuangan & Audit:** Laporan penjualan harian/bulanan/tahunan, settlement manual, audit selisih kas, dan pencatatan "Kurang Bayar".
* **Integrasi RouterOS:** Sinkronisasi data real-time (API), manajemen user hotspot, dan skrip pembersihan otomatis.
* **Portal User:** Fitur permintaan Retur/Refund langsung dari halaman login hotspot.
* **Notifikasi:** Pengiriman laporan PDF harian otomatis via WhatsApp (Fonnte).
* **Monitoring:** Dashboard *real-time* untuk trafik, status online, dan statistik penjualan.

---

## 2. Arsitektur & Teknologi

### 2.1 Stack Teknologi
* **Backend:** PHP 7.4+ (Disarankan PHP 8.1+ untuk performa OPcache).
* **Database:** SQLite 3 (`db_data/babahdigital_main.db`) untuk log transaksi, histori login, dan audit.
* **Router Communication:** RouterOS API (`lib/routeros_api.class.php`).
* **Frontend:** HTML5, CSS3, JavaScript (jQuery), Bootstrap (Mikhmon base).
* **Containerization:** Docker & Docker Compose.

### 2.2 Struktur Modul Utama
* **`dashboard/`**: Ringkasan KPI, grafik trafik, dan tabel transaksi *live*.
* **`hotspot/`**: Manajemen user, profil, generate voucher, status aktif, hosts, cookies, dan fitur retur request.
* **`report/`**:
    * `laporan/`: Logika perhitungan laporan, helper audit, dan layanan sinkronisasi.
    * `print/`: Template cetak (rekap, rincian, struk voucher).
    * `laporan/services/`: Endpoint backend untuk *ingest* data (sync sales, usage, settlement).
* **`process/`**: Pemrosesan aksi backend (add user, hapus, eksekusi retur).
* **`tools/`**: Utilitas maintenance (backup/restore DB, clear logs, db check).
* **`include/`**: Konfigurasi inti (`config.php`, `env.php`, `acl.php`).

### 2.3 Skema Database (SQLite)
Tabel kunci dalam `babahdigital_main.db`:
* `sales_history`: Data transaksi penjualan yang sudah di-*settle* (final).
* `live_sales`: Data transaksi penjualan berjalan (pending settlement).
* `login_history`: Riwayat sesi login user (menyimpan uptime, bytes, status terakhir).
* `login_events`: Detail *event* login/logout per sesi.
* `audit_rekap_manual`: Data hasil audit fisik harian operator (qty, setoran, selisih).
* `retur_requests`: Data permintaan retur/refund dari portal user.
* `whatsapp_recipients` & `whatsapp_logs`: Konfigurasi penerima dan log pengiriman WA.
* `login_meta_queue`: Antrian data meta (Nama/Kamar) dari form login.

---

## 3. Standar Akuntansi & Logika Laporan

Bagian ini adalah acuan mutlak untuk perhitungan keuangan dalam aplikasi.

### 3.1 Definisi Status Transaksi
Status ditentukan berdasarkan urutan prioritas: **RETUR > RUSAK > INVALID > NORMAL**.

| Status | Gross (Omzet Kotor) | Net (Setoran Bersih) | Qty Laku | Keterangan |
| :--- | :--- | :--- | :--- | :--- |
| **Normal** | +Harga | +Harga | Ya | Penjualan sukses murni. |
| **Rusak** | +Harga | 0 | Tidak | Transaksi tercatat, namun uang hilang/tidak diterima (Loss). |
| **Retur** | 0 | +Harga | Ya | Voucher pengganti. Tidak menambah omzet baru, tapi memulihkan kas. |
| **Invalid** | 0 | 0 | Tidak | Transaksi batal/void. |

**Catatan Penting:**
1.  **Satu Voucher = Satu Transaksi.** Retur tetap dihitung sebagai satu entitas transaksi karena berasal dari user yang berbeda (pengganti).
2.  **Retur bukan Omzet Baru.** Retur bernilai Gross 0 agar pendapatan tidak tercatat ganda, namun bernilai Net +Harga karena menggantikan posisi uang dari voucher yang Rusak (Net 0).
3.  **Voucher Rusak Asal.** Voucher yang digantikan (rusak) tetap tercatat dengan Gross +Harga dan Net 0.
4.  **Kurang Bayar.** Nilai kekurangan bayar diperhitungkan dalam setoran bersih akhir (Net + Kurang Bayar).

### 3.2 Alur Audit & Settlement
1.  **Expected Qty (Sistem):** `Total Transaksi - Rusak - Invalid`. (Retur tetap dihitung sebagai unit laku).
2.  **Expected Setoran (Sistem):** Mengikuti tabel status di atas (Net).
3.  **Setoran Aktual (Manual):** Diinput oleh operator berdasarkan fisik uang.
4.  **Setoran Bersih:** `Setoran Aktual - Pengeluaran Operasional`.
5.  **Selisih:** `Setoran Aktual - Expected Setoran`.
    * Jika Aktual = Sistem: **Sesuai**.
    * Jika Aktual > Sistem: **Lebih Setor**.
    * Jika Aktual < Sistem: **Kurang Setor**.

---

## 4. Alur Bisnis (Business Logic)

### 4.1 Alur Voucher Normal
1.  Operator membuat voucher (*Generate*).
2.  User login. RouterOS mengirim data ke endpoint ingest.
3.  Transaksi masuk ke `live_sales` (status pending).
4.  Pemakaian tercatat di `login_history`.
5.  Saat **Settlement**, data dipindah ke `sales_history` dan status difinalisasi.

### 4.2 Alur Voucher Rusak
Syarat Status Rusak: Voucher tidak aktif, bytes rendah (di bawah threshold), uptime rendah, dan memenuhi kriteria durasi.
1.  Operator memverifikasi data di menu **Hotspot -> Users**.
2.  Operator menekan tombol **Set Rusak**.
3.  Sistem mengubah status menjadi **RUSAK**.
4.  Akuntansi: Gross tetap, Net menjadi 0.

### 4.3 Alur Retur (Voucher Pengganti)
Berlaku untuk mengganti voucher rusak dengan voucher baru secara langsung.
1.  Pastikan voucher asal sudah berstatus **RUSAK**.
2.  Operator menekan tombol **Retur**.
3.  Sistem membuat user baru (Voucher Pengganti) di RouterOS dan DB.
4.  Voucher asal tetap status RUSAK. Voucher baru berstatus RETUR.
5.  Akuntansi Voucher Baru: Gross 0, Net +Harga.

### 4.4 Alur Permintaan Retur/Refund (Portal)
Fitur bagi user untuk mengajukan komplain mandiri.
1.  User mengisi form di halaman login hotspot (Pilih: Retur atau Refund).
2.  Data masuk ke tabel `retur_requests` (Status: Pending).
3.  Operator membuka menu **Manajemen Retur** (Popup).
4.  **Opsi Aksi:**
    * **Approve Refund:** Sistem melakukan validasi kelayakan rusak otomatis. Jika lolos, status user diubah jadi RUSAK, request disetujui.
    * **Approve Retur:** Sistem langsung menjalankan fungsi Retur (buat voucher baru), request disetujui.
    * **Reject:** Permintaan ditolak.

---

## 5. Fitur Integrasi Khusus

### 5.1 WhatsApp Laporan (Fonnte)
Mengirimkan laporan PDF harian ke nomor owner/supervisor.
* **Trigger:** Otomatis setelah status Settlement `done`, atau manual via menu WhatsApp.
* **File:** Mengambil file PDF terbaru dari folder `report/pdf/` yang sesuai tanggal laporan.
* **Konfigurasi:**
    * Token disimpan di `include/env.php`.
    * Manajemen nomor penerima (CRUD) di menu **System -> WhatsApp**.
    * Mendukung pengiriman ke Personal Chat atau Group ID (`@g.us`).
    * Validasi nomor aktif menggunakan API Fonnte sebelum disimpan.

### 5.2 Login Meta (Nama & Kamar)
Untuk kebutuhan identifikasi user yang lebih detail.
* Form login hotspot mengirimkan data `nama`, `kamar`, `blok`, `profil` ke endpoint `login_meta.php`.
* Data disimpan sementara di `login_meta_queue`.
* Saat laporan digenerate, sistem melakukan *fallback* ke antrian ini jika data `customer_name` di `login_history` kosong.
* Tersedia skrip `backfill_meta.php` untuk sinkronisasi data historis.

---

## 6. Instalasi & Konfigurasi

### 6.1 Persyaratan Sistem
* Docker & Docker Compose.
* Akses jaringan ke RouterOS (Port API, default 8728).
* Web Server (Nginx) sebagai Reverse Proxy (Disarankan).

### 6.2 Deployment dengan Docker
1.  Clone repositori.
2.  Konfigurasi awal router/admin dapat dimigrasikan otomatis dari `include/config_legacy.php` ke SQLite.
3.  Sesuaikan `include/env.php` (Token, Harga Profil, Fitur Toggle).
4.  Jalankan perintah:
    ```bash
    docker-compose up -d --build
    ```
5.  Akses via browser (default port 80 atau sesuai konfigurasi Nginx).

### 6.3 Konfigurasi `include/env.php`
File ini menyimpan variabel sensitif dan *toggles*. Contoh parameter penting:
* `app.debug`: Mode debug (true/false).
* `pricing.profile_prices`: Mapping nama profil ke harga (JSON).
* `system.ghost_min_bytes`: Ambang batas bytes untuk deteksi voucher 'hantu'.
* `fonnte.token`: Token API WhatsApp.
* `retur_request.enabled`: Mengaktifkan fitur request di portal.
* `system.app_db_file`: Lokasi DB konfigurasi aplikasi (default: `db_data/babahdigital_app.db`).

### 6.4 Nginx Reverse Proxy
Disarankan menggunakan Nginx di depan container untuk menangani kompresi dan *caching*.
* Aktifkan Gzip/Brotli pada level Nginx.
* Atur *Cache-Control* untuk aset statis (CSS/JS).
* Pastikan *Walled Garden* di MikroTik mengizinkan domain aplikasi agar fitur Retur Request berjalan.

---

## 7. Panduan Operasional Harian

1.  **Generate Voucher:** Lakukan di menu **Hotspot -> Generate User** sesuai permintaan blok.
2.  **Monitoring:** Pantau user aktif dan trafik di Dashboard atau menu **Hotspot -> Users**.
3.  **Verifikasi Rusak:** Cek user yang komplain, validasi uptime/bytes, lalu set status **RUSAK**.
4.  **Cek Permintaan Retur:** Buka menu notifikasi Retur, proses permintaan (Approve/Reject).
5.  **Settlement Harian:**
    * Buka **Report -> Laporan Penjualan**.
    * Klik **Settlement Manual**. Tunggu proses sinkronisasi dan skrip router selesai.
6.  **Audit Manual:**
    * Buka **Report -> Audit**.
    * Isi jumlah voucher fisik (User Checklist).
    * Input uang tunai aktual dan pengeluaran operasional.
    * Simpan Audit.
7.  **Kirim Laporan:** Pastikan laporan PDF terkirim via WhatsApp (Cek menu **System -> WhatsApp**).

---

## 8. Troubleshooting & Maintenance

### 8.1 Isu Umum
* **Data Laporan Kosong/Tidak Sesuai:**
    * Cek apakah Settlement sudah dijalankan (`status: done`).
    * Pastikan jam/tanggal di RouterOS sinkron dengan server aplikasi.
* **Sync Usage Timeout:**
    * MikroTik mungkin lambat merespons. Aplikasi sudah menggunakan mekanisme *early response*, namun pastikan koneksi API stabil.
* **Perubahan Kode Tidak Efektif:**
    * Aplikasi menggunakan OPcache dengan `validate_timestamps=0` di production. **Wajib restart container** setiap ada perubahan kode PHP (`docker-compose restart`).

### 8.2 Log System
Lokasi file log untuk diagnosa:
* `logs/settlement_*.log`: Log proses settlement dan skrip router.
* `logs/sync_usage.log`: Log sinkronisasi penggunaan data.
* `logs/whatsapp_logs.log`: Log status pengiriman pesan WA.
* `logs/backup_db.log` & `logs/restore_db.log`: Log aktivitas database tool.
* `logs/backup_app_db.log` & `logs/restore_app_db.log`: Log backup/restore DB konfigurasi aplikasi.

### 8.3 Tools Database
Akses menu **Tools** (ikon Admin) untuk:
* **Backup Database:** Download file `.db` yang aman (WAL checkpointed).
* **Restore Database:** Mengembalikan data dari backup.
* **Backup Konfigurasi:** Mencadangkan DB konfigurasi aplikasi.
* **Restore Konfigurasi:** Mengembalikan konfigurasi dari backup.
* **Clear Logs:** Membersihkan file log lama untuk menghemat ruang disk.
* **Sync Rusak Audit:** Menyelaraskan status rusak antara `login_history` dan `sales_history` jika terjadi anomali data bulanan.

---

## 9. Lisensi
Aplikasi ini bersifat *proprietary* dan dikhususkan untuk penggunaan internal. Dilarang menyebarluaskan kode sumber tanpa izin.

```