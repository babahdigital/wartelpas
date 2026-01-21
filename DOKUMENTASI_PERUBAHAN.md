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
16. **Relogin & detail pemakaian tidak transparan** (tidak ada detail popup + print).
17. **Pendapatan ganda** karena relogin masuk ke rekap penjualan.
18. **Data non‑Wartel** (tanpa BLOK) ikut terbawa ke rekap dan live sales.
19. **Schema sales_history/live_sales tidak lengkap**, membuat rekap nol dan insert gagal.
20. **Konfirmasi hapus/edit masih pakai alert/confirm**, tidak konsisten dengan UI design.
21. **Proses settlement manual belum ada** dan perlu log proses yang terkunci.
22. **Input HP Blok error “Respon tidak valid dari server”.**

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
- **tools/db_check.php**: cek schema, row, path DB, dan status writable.
- **tools/clear_logs.php**: bersihkan log ingest.
- **tools/clear_block.php**: hapus data blok lintas tabel.
- **tools/delete_user.php**: hapus data user spesifik dari DB (login_history/login_events/sales_history/live_sales).
- **.htaccess**: whitelist endpoint maintenance dan ingest.

### 2.16 Stabilitas Sync Usage
- **process/sync_usage.php**: respons cepat (early response + flush) agar MikroTik tidak timeout.
- Logging **sync_usage.log** untuk audit proses dan durasi.
- Script MikroTik diubah ke **port 8081** agar sesuai container.

### 2.17 Relogin Detail, Print, dan Validasi Rusak
- **hotspot/users.php**: tambah popup detail relogin (tanggal, total relogin, waktu login/logout, durasi, bytes, IP/MAC) dan tombol print.
- Perbaiki **sinkronisasi jumlah relogin** antara tabel list dan print.
- **Rusak checklist** ditambahkan agar validasi transparan (kriteria waktu & relogin).
- Kriteria rusak disempurnakan menggunakan **akumulasi uptime** dari `login_events` + **minimum relogin >= 3**.
- Print rusak menampilkan tabel relogin dengan rentang waktu yang selaras dengan perhitungan.

### 2.18 Perbaikan Rekap Penjualan (Dedup & Non‑Wartel)
- **report/print_rekap.php** dan **report/selling.php**: deduplikasi penjualan berdasarkan `username + sale_date` agar relogin tidak menghitung ganda.
- **report/live_ingest.php** dan **report/sync_sales.php**: menolak data tanpa BLOK (non‑Wartel).
- Rekap hanya menampilkan blok yang memiliki metrik > 0 (mengurangi baris kosong).

### 2.19 Perbaikan Schema & Migrasi Otomatis Penjualan
- Auto‑create/alter kolom **sales_history** dan **live_sales** saat runtime jika belum ada.
- Backfill data historis agar rekap tidak nol setelah migrasi.

### 2.19.1 Tool Pembersih Data
- **tools/cleanup_duplicate_sales.php**: dedup penjualan berdasarkan `username + sale_date`, rebuild summary.
- **tools/cleanup_non_wartel_login_history.php**: bersihkan `login_history` tanpa BLOK.
- **tools/cleanup_ready_login_history.php**: hapus entry `READY` di `login_history`.

### 2.20 Settlement Manual + Log Terkunci
- Tambah **settlement manual** di **report/selling.php** untuk menjalankan scheduler MikroTik.
- Endpoint **report/settlement_manual.php** menjalankan scheduler dan menulis log ke `settlement_log`.
- Modal log bergaya terminal dengan **lock** saat proses berjalan (tidak bisa ditutup sampai selesai).

### 2.21 Perbaikan Input HP Blok
- **report/selling.php**: form HP mengirim `ajax=1` dan dialihkan ke **report/hp_save.php**.
- **report/hp_save.php**: validasi & response JSON konsisten.

### 2.22 UI Konfirmasi Tanpa Alert
- Konfirmasi hapus/edit diganti menjadi **modal** bergaya design.html (tanpa `alert/confirm`).

### 2.23 Penyempurnaan Settlement, Rekap, dan Filter Wartel
- **report/selling.php**:
  - Konfirmasi hapus HP memakai format tanggal **dd-mm-yyyy**.
  - Perbaikan modal HP (tutup/konfirmasi), gaya modal mengikuti design.html.
  - Auto-carry over data HP harian dari tanggal terakhir jika kosong.
  - Perhitungan pendapatan kini **qty-aware** (price * qty).
  - Normalisasi nama blok (BLOK-X) agar konsisten antara data comment/blok_name.
- **report/print_rekap.php**:
  - Perhitungan pendapatan **qty-aware**.
  - Definisi “laku” disamakan dengan selling (berdasarkan status, bukan bytes).
  - Whitelist blok harian berdasar **phone_block_daily** agar blok “uji coba” tidak ikut terhitung.
- **report/settlement_manual.php**:
  - Menjalankan **script CuciGudangManual** langsung (tanpa scheduler).
  - Logging settlement dipersempit: hanya log dengan prefix SETTLE/CLEANUP/SYNC/MAINT/SUKSES.
  - Terminal log dibuat berurutan (typewriter), status **Selesai** muncul setelah log akhir.
  - Tombol tutup dikunci hingga proses selesai, plus tombol **Reset settlement**.
- **Mikrotik-CleanWartel.rsc**:
  - Semua log diberi prefix **SETTLE** agar terfilter rapi.
  - Delay disetel ulang agar urutan log terbaca stabil.
- **tools/clear_block.php**:
  - Hapus data blok lintas tabel (login_history, sales_history, live_sales).
  - Support hapus varian **BLOK-X10/BLOK-X30** saat input BLOK-X.
  - Data HP **tidak dihapus** default (opsional `delete_hp=1`).
- **tools/check_block.php** (baru):
  - Endpoint audit untuk mengecek keberadaan blok pada semua tabel.
- **report/live_ingest.php**:
  - Validasi session config & hanya izinkan **hotspot_server=wartel**.
- **report/sync_stats.php**:
  - Filter berdasarkan hotspot server + skip user tanpa marker BLOK agar data non‑Wartel tidak masuk lagi saat settlement.

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

### 3.6 Relogin tidak sinkron & tidak ada detail
- **Gejala**: jumlah relogin di list berbeda dengan hasil print.
- **Akar masalah**: perhitungan belum diseragamkan antara list/print.
- **Solusi**: standar perhitungan di satu sumber, dan tabel print relogin diselaraskan rentang waktunya.

### 3.7 Pendapatan ganda (relogin terhitung ulang)
- **Gejala**: rekap penjualan membengkak.
- **Akar masalah**: relogin dari user yang sama masuk berulang.
- **Solusi**: dedup berdasarkan `username + sale_date` di selling/print + ingest/sync.

### 3.8 Data non‑Wartel ikut terhitung
- **Gejala**: rekap berisi transaksi tanpa BLOK.
- **Akar masalah**: tidak ada filter data non‑Wartel saat ingest/sync.
- **Solusi**: skip data tanpa BLOK di `live_ingest` dan `sync_sales`.

### 3.12 Data non‑Wartel muncul lagi setelah settlement
- **Gejala**: setelah cleanup, data non‑Wartel (contoh nomor 08) muncul kembali saat settlement.
- **Akar masalah**: `report/sync_stats.php` menarik semua user hotspot tanpa filter `server` dan tanpa cek BLOK.
- **Solusi**: filter `?server=$hotspot_server` dan **skip user tanpa BLOK**.

### 3.13 Blok “uji coba” tetap muncul di rekap
- **Gejala**: BLOK-A tetap tampil walau sudah dihapus dari MikroTik.
- **Akar masalah**: data historis tersimpan sebagai BLOK-A10/BLOK-A30 di DB.
- **Solusi**: whitelist blok harian berdasar `phone_block_daily`, dan tool `clear_block`/`check_block` mendukung pola BLOK-X[0-9]*.

### 3.9 Rekap nol karena schema tidak lengkap
- **Gejala**: rekap kosong, insert gagal.
- **Akar masalah**: kolom `sale_date`/`blok_name` belum ada.
- **Solusi**: auto‑migrate kolom + backfill data historis.

### 3.10 Error input HP Blok
- **Gejala**: “Respon tidak valid dari server”.
- **Akar masalah**: endpoint tidak mengirim JSON yang diharapkan.
- **Solusi**: kirim `ajax=1`, arahkan ke `report/hp_save.php`, respons JSON konsisten.

### 3.11 Konfirmasi masih pakai alert/confirm
- **Gejala**: UX tidak konsisten dengan design.html.
- **Akar masalah**: masih menggunakan `alert/confirm` bawaan browser.
- **Solusi**: ganti ke modal konfirmasi custom.

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
- report/settlement_manual.php
- tools/cleanup_duplicate_sales.php
- tools/cleanup_non_wartel_login_history.php
- tools/cleanup_ready_login_history.php
- tools/check_block.php
- tools/clear_all.php
- tools/build_sales_summary.php
- tools/migrate_sales_reporting.php
- report/hp_save.php
- report/print_rekap.php
- report/print_rincian.php
- process/usage_ingest.php
- process/sync_usage.php
- report/live_ingest.php
- tools/clear_logs.php
- tools/clear_block.php
- tools/delete_user.php
- tools/db_check.php
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
- Rekap penjualan akurat (dedup relogin, filter non‑Wartel).
- Settlement manual tersedia dengan log proses.
- Konfirmasi aksi menggunakan modal (tanpa alert/confirm).

## 7) Catatan Operasional
- Pastikan script `mikrotik-onlogout.rsc` sudah dipasang pada server profile hotspot.
- Gunakan URL port **8081** pada semua script MikroTik sesuai container.
- Jika ada data yang masih kosong, pastikan voucher pernah login agar DB terisi.
- Jika diperlukan debug, gunakan parameter `?debug=1` pada users.php.

---

Jika ada tambahan perubahan atau aturan bisnis baru, dokumentasi ini akan diperbarui.