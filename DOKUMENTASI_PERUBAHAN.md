# Dokumentasi Perubahan WartelPas (Mikhmon)

Dokumen ini merangkum seluruh perbaikan dan penyempurnaan dari awal sampai akhir, termasuk masalah yang dihadapi, analisa, serta solusi yang diterapkan.

## 1) Masalah Utama yang Dihadapi
1. **Halaman users.php lambat / spinner tidak selesai** setelah perubahan ke SQLite.
2. **Query RouterOS terlalu berat** (tanpa `.proplist`, timeout tidak aman).
3. **Data login/logout/IP/MAC/uptime/bytes tidak konsisten** di tampilan.
4. **Status voucher tidak stabil** (READY/ONLINE/TERPAKAI/RUSAK/RETUR)
5. **Retur/Rusak logic salah**: retur bisa salah status, rusak tidak memerlukan validasi, retur gagal karena DB belum sinkron.
6. **Komentar RouterOS menumpuk IP/MAC** dan menyulitkan parsing data.
7. **Print voucher salah template** / print status kosong.
8. **Aksi hapus/retur/rusak** belum aman (tidak cek aktif, tidak sinkron DB).
9. **UI membingungkan**: print blok muncul di status tidak semestinya, tombol tidak sesuai kondisi, jarak toolbar terlalu renggang.
10. **Download PNG** menggunakan nama file generik.
11. **Login/logout tidak tercatat** karena endpoint ingest gagal (400/500/timeout).
12. **DB schema mismatch** (kolom baru belum ada, ON CONFLICT gagal karena index unik belum ada).
13. **READY bocor ke TERPAKAI** pada tampilan/print karena deteksi pemakaian terlalu longgar.
14. **Sync Usage timeout di MikroTik** karena respons endpoint terlambat (router API call lama).
15. **Data “ready” tetap muncul** setelah DB dikosongkan (dianggap error padahal hasil sync).

## 2) Solusi & Penyempurnaan yang Diterapkan
### 2.1 Optimalisasi RouterOS API
- Menambahkan **timeout** dan **attempts** agar tidak menggantung.
- Menggunakan `.proplist` untuk memperkecil payload query.
- Menambahkan filter **server hotspot** sesuai settings.

### 2.2 Rebuild Users Page (users.php)
- Menyusun ulang `users.php` agar:
  - Menggunakan data RouterOS + SQLite secara konsisten.
  - Menyimpan **login/logout** aktual, **IP/MAC**, **uptime**, **bytes**, **status**.
  - Menampilkan badge status yang konsisten.
- Menambah **filter status**, **search**, **filter blok**, **bulk action**.

### 2.3 Sinkronisasi DB & Logic Status
- `login_history` ditambah kolom:
  - `last_uptime`, `last_bytes`
  - `first_ip`, `first_mac`, `last_ip`, `last_mac`
  - `first_login_real`, `last_login_real`
  - `login_time_real`, `logout_time_real`
  - `last_status`
- Status ditentukan dengan prioritas:
  1) ONLINE
  2) RUSAK
  3) RETUR
  4) TERPAKAI
  5) READY
- Jika comment memiliki **Retur Ref**, status tetap **RETUR** meski ada kata “RUSAK” di ref.

### 2.4 Aturan Rusak/Retur (Business Rules)
- **RUSAK** hanya jika:
  - Tidak aktif, bytes kecil, uptime kecil.
- **RETUR** hanya jika status sudah **RUSAK**.
- **RETUR** memindahkan voucher lama → membuat voucher baru.
- Menyimpan asal retur agar jelas:
  - `Retur dari: <ref>`.
- Menambahkan tombol **Rollback** untuk membatalkan RUSAK.

### 2.5 Perbaikan Komentar RouterOS
- Script `mikrotik-onlogout.rsc`:
  - Menghapus IP/MAC lama agar tidak menumpuk.
  - Menggunakan **timestamp logout saat ini** (bukan tanggal lama).
  - Menyimpan format comment: `YYYY-mm-dd HH:MM:SS | Blok-X | IP:... | MAC:...`
  - Hapus cookie hanya untuk user Wartel (punya marker Blok-).

### 2.6 Retur & Print
- Print status RETUR menggunakan **template-small**.
- Per-user print juga menggunakan template kecil.
- Download PNG sekarang **nama file = username + timestamp**.
- Menampilkan **Retur dari** agar sumber voucher jelas.

### 2.7 UI/UX
- **Print Blok** hanya muncul saat status READY + blok dipilih.
- Hapus Blok hanya muncul di status **Semua** dan blok dipilih.
- Print Status dan Hapus Retur ditukar urutannya, jarak toolbar dirapatkan.
- Retur/Rusak tombol hanya muncul sesuai status.
- RUSAK tidak ada tombol Print.

### 2.8 Pagination
- Menambahkan pagination di bawah tabel users agar data tidak dimuat semua sekaligus.

### 2.9 Penyempurnaan UI Modern (Generate/User Profile)
- **generateuser.php**: tombol cetak/QR dihapus, ringkasan stok per blok + total **RUSAK/RETUR** ditampilkan, layout full‑width dua kolom, style modern selaras dengan users.php.
- **userprofile.php**: tampilan modern, tombol **Tambah Baru**, table lebih rapi, routing aman (tidak redirect dashboard).
- **adduserprofile.php**: style modern, form **2 kolom** agar tidak panjang ke bawah.
- **userprofilebyname.php**: style disamakan dengan adduserprofile (card modern + form 2 kolom).

### 2.10 Keamanan & Aksi Hapus
- **users.php**: proteksi agar **user online tidak ikut terhapus**, tombol hover pointer, input search diperlebar.

### 2.11 Sinkronisasi & Maintenance
- **report/sync_stats.php**: sink login_history tanpa mengubah comment RouterOS, WAL aktif, gunakan config/session (tanpa kredensial hardcode).
- **report/sync_sales.php**: simpan blok_name, WAL aktif, gunakan config/session (tanpa kredensial hardcode).
- **Mikrotik-CleanWartel.rsc**: hapus preclean “hantu-sweeper”, **abort cleanup jika sync gagal**, URL fetch wajib membawa `session`.

### 2.12 Laporan Penjualan, HP Blok, & Print Rekap
- **report/selling.php**: gabung data `sales_history` + `live_sales` (pending), perbaikan perhitungan status (normal/rusak/retur/invalid), summary card baru, perhitungan rusak 10/30 menit, dan layout full-height agar pagination tidak terpotong.
- **Rincian Transaksi**: pagination manual (`tx_page`) + kolom **Bandwidth** (dari `login_history.last_bytes`).
- **Input HP Blok**: perbaikan validasi, only TOTAL row punya aksi edit/hapus, breakdown WARTEL/KAMTIB ditampilkan, catatan wrap, total bar HP di bawah tabel.
- **report/hp_save.php**: insert/update aman (tanpa DROP), transaksi, WAL/busy_timeout, validasi WARTEL/KAMTIB, response JSON, redirect date harian.
- **report/print_rekap.php**: desain rekap harian diperluas dengan tabel detail per blok (B10/B30 + subtotal), kolom Qty dengan subkolom Total/RS/RT, kolom Device (Total/RS/SP), Unit (WR/KM), Bandwidth, Aktif; parsing blok dari `blok_name`/comment; warna print; note singkatan & catatan settlement (sementara jam 03:00, final jam 04:00); nama file PDF unik via `beforeprint` (timestamp).
- **report/print_rincian.php**: halaman print rincian harian dengan print/share.
- **.htaccess**: whitelist endpoint print rekap/rincian.
- **UI Tombol**: tombol Print Rekap/Print Rincian di header laporan.

### 2.13 Realtime Usage & Login/Logout Tracking
- **process/usage_ingest.php**: diperkuat agar selalu `OK`, logging error, dan tidak memicu 500.
- **report/live_ingest.php**: respon aman, log request invalid.
- **MikroTik onlogin/onlogout**: selalu kirim usage ingest (login/logout) + uptime/IP/MAC.
- **login_count & relogin**: pencatatan relogin dan badge di users + print.

### 2.14 Perbaikan Print Rincian (TERPAKAI vs READY)
- **READY disaring**: print rincian hanya menampilkan TERPAKAI/RUSAK, bukan READY.
- **Guard pemakaian**: TERPAKAI hanya jika ada bytes/uptime/login_time/logout_time valid.

### 2.15 Maintenance & Debug Tools
- **db_check.php**: cek schema, row, path DB, dan status writable.
- **report/clear_logs.php**: bersihkan log ingest.
- **report/clear_block.php**: hapus `login_history` berdasarkan blok.
- **.htaccess**: whitelist endpoint maintenance dan ingest.

### 2.16 Stabilitas Sync Usage
- **process/sync_usage.php**: respons cepat (early response + flush) agar MikroTik tidak timeout.
- Logging **sync_usage.log** untuk audit proses dan durasi.
- Script MikroTik diubah ke **port 8081** agar sesuai container.

## 3) Masalah Khusus dan Fix Terkait
### 3.1 Waktu/Bytes/Uptime kosong saat RUSAK
- Parsing comment diperluas untuk format:
  - `Audit: RUSAK dd/mm/yy YYYY-mm-dd HH:MM:SS`
  - `Valid dd-mm-YYYY [HH:MM:SS]`
- Jika bytes/uptime di RouterOS kosong, fallback ke **database** (`last_bytes`, `last_uptime`).

### 3.2 RETUR tidak berubah status
- Retur sekarang boleh jika DB tidak sinkron asal comment/disabled menunjukkan RUSAK.
- Pastikan `uid` bisa dicari otomatis bila kosong.

### 3.3 Retur Ref berantai
- `gen_user()` dibersihkan agar **tidak nested** Retur Ref.
- Rollback menghapus `(Retur)` dan `Retur Ref:` agar status tidak salah.
- Rollback juga menghapus jejak RUSAK di comment agar kembali READY.

### 3.4 Database terlalu sulit (schema mismatch & conflict)
- **Gejala**: login/logout tidak tercatat, ON CONFLICT gagal, kolom baru tidak ada.
- **Akar masalah**: DB lama tidak memiliki kolom baru + tidak ada unique index `username`.
- **Solusi**:
  - Auto-migrate kolom via `PRAGMA table_info` + `ALTER TABLE` saat runtime.
  - Tambah **unique index** `idx_login_history_username` untuk ON CONFLICT.
  - Sinkronisasi aman tanpa menimpa data login/logout yang sudah terkunci.

### 3.5 Sync Usage timeout (MikroTik)
- **Gejala**: fetch timeout waiting data / receiving content.
- **Akar masalah**: proses router API lama, respons endpoint telat.
- **Solusi**: endpoint mengirim respons JSON **lebih dulu**, lanjut proses di belakang.

## 4) File yang Dibersihkan (Dihapus)
File diagnostik & migrasi sementara yang sudah tidak diperlukan:
- performance_analysis.php
- check_db_schema.php
- emergency_db_migrate.php
- fix_db_permission.php
- db_migration_v2.7.sql
- hotspot/profiler.php
- hotspot/debug_performance.php
- hotspot/test_connection.php
- hotspot/cache_manager.php
- hotspot/fix_old_data.php
- hotspot/cleanup_database.php
- hotspot/users_rebuild.php
- lib/config_db_migration.php
- lib/mikrotik_cache.php
- db_diagnostic.php

## 5) File Utama yang Dimodifikasi
- hotspot/users.php
- voucher/print.php
- voucher/template-small.php
- voucher/template.php
- voucher/template-thermal.php
- mikrotik-onlogout.rsc
- index.php (routing users)
- hotspot/generateuser.php
- hotspot/userprofile.php
- hotspot/adduserprofile.php
- hotspot/userprofilebyname.php
- report/sync_stats.php
- report/sync_sales.php
- report/selling.php
- report/hp_save.php
- report/print_rekap.php
- report/print_rincian.php
- process/usage_ingest.php
- process/sync_usage.php
- report/live_ingest.php
- report/clear_logs.php
- report/clear_block.php
- db_check.php
- .htaccess
- Mikrotik-CleanWartel.rsc
- mikrotik-onlogin-fixed.rsc
- mikrotik-onlogout.rsc
- voucher/template perubahan label dan blok parsing
- DOKUMENTASI_PERUBAHAN.md

## 6) Ringkasan Hasil Akhir
- UI users cepat dan responsif.
- Data login/logout/usage/IP/MAC konsisten.
- Status rusak/retur sesuai aturan bisnis.
- Retur jelas asalnya, rollback tersedia.
- Print dan download sesuai kebutuhan.
- Cookie dibersihkan aman tanpa mengganggu klien non‑Wartel.
- Login/logout tercatat real‑time dan relogin terlihat.
- Sync Usage tidak timeout di MikroTik.

## 7) Catatan Operasional
- Pastikan script `mikrotik-onlogout.rsc` sudah dipasang pada server profile hotspot.
- Gunakan URL port **8081** pada semua script MikroTik sesuai container.
- Jika ada data yang masih kosong, pastikan voucher pernah login agar DB terisi.
- Jika diperlukan debug, gunakan parameter `?debug=1` pada users.php.

---

Jika ada tambahan perubahan atau aturan bisnis baru, dokumentasi ini akan diperbarui.