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
  2) RETUR
  3) RUSAK
  4) TERPAKAI
  5) READY
- Jika comment memiliki **Retur Ref** atau tag **(Retur)**, status **RETUR** diprioritaskan meski ada kata “RUSAK” di histori.

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
- **report/laporan/services/sync_stats.php**: sink login_history tanpa mengubah comment RouterOS, WAL aktif, gunakan config/session (tanpa kredensial hardcode).
- **report/laporan/services/sync_sales.php**: simpan blok_name, WAL aktif, gunakan config/session (tanpa kredensial hardcode).
- **Mikrotik-CleanWartel.rsc**: hapus preclean “hantu-sweeper”, **abort cleanup jika sync gagal**, URL fetch wajib membawa `session`.

### 2.12 Laporan Penjualan, HP Blok, & Print Rekap
- **report/selling.php**: gabung data `sales_history` + `live_sales` (pending), perbaikan perhitungan status (normal/rusak/retur/invalid), summary card baru, perhitungan rusak 10/30 menit, dan layout full-height agar pagination tidak terpotong.
- **Rincian Transaksi**: pagination manual (`tx_page`) + kolom **Bandwidth** (dari `login_history.last_bytes`).
- **Input HP Blok**: perbaikan validasi, only TOTAL row punya aksi edit/hapus, breakdown WARTEL/KAMTIB ditampilkan, catatan wrap, total bar HP di bawah tabel.
- **report/laporan/services/hp_save.php**: insert/update aman (tanpa DROP), transaksi, WAL/busy_timeout, validasi WARTEL/KAMTIB, response JSON, redirect date harian.
- **report/print/print_rekap.php**: desain rekap harian diperluas dengan tabel detail per blok (B10/B30 + subtotal), kolom Qty dengan subkolom Total/RS/RT, kolom Device (Total/RS/SP), Unit (WR/KM), Bandwidth, Aktif; parsing blok dari `blok_name`/comment; warna print; note singkatan & catatan settlement (sementara jam 03:00, final jam 04:00); nama file PDF unik via `beforeprint` (timestamp).
- **report/print/print_rincian.php**: halaman print rincian harian dengan print/share.
- **.htaccess**: whitelist endpoint print rekap/rincian.
- **UI Tombol**: tombol Print Rekap/Print Rincian di header laporan.

### 2.13 Realtime Usage & Login/Logout Tracking
- **report/laporan/services/usage_ingest.php**: diperkuat agar selalu `OK`, logging error, dan tidak memicu 500.
- **report/laporan/services/live_ingest.php**: respon aman, log request invalid.
- **MikroTik onlogin/onlogout**: selalu kirim usage ingest (login/logout) + uptime/IP/MAC.
- **login_count & relogin**: pencatatan relogin dan badge di users + print.

### 2.14 Perbaikan Print Rincian (TERPAKAI vs READY)
- **READY disaring**: print rincian hanya menampilkan TERPAKAI/RUSAK, bukan READY.
- **Guard pemakaian**: TERPAKAI hanya jika ada bytes/uptime/login_time/logout_time valid.

### 2.15 Maintenance & Debug Tools
- **tools/db_check.php**: cek schema, row, path DB, dan status writable.
- **tools/clear_logs.php**: bersihkan log ingest.
- **.htaccess**: whitelist endpoint maintenance dan ingest.

### 2.16 Stabilitas Sync Usage
- **report/laporan/services/sync_usage.php**: respons cepat (early response + flush) agar MikroTik tidak timeout.
- Logging **sync_usage.log** untuk audit proses dan durasi.

### 2.17 Relogin Detail, Print, dan Validasi Rusak
- **hotspot/users.php**: tambah popup detail relogin (tanggal, total relogin, waktu login/logout, durasi, bytes, IP/MAC) dan tombol print.
- Perbaiki **sinkronisasi jumlah relogin** antara tabel list dan print.
- **Rusak checklist** ditambahkan agar validasi transparan (kriteria waktu & relogin).
- Kriteria rusak disempurnakan menggunakan **akumulasi uptime** dari `login_events` + **minimum relogin >= 3**.
- Print rusak menampilkan tabel relogin dengan rentang waktu yang selaras dengan perhitungan.

### 2.17.1 Penyempurnaan Users & Print (2026-01-25)
- **Deteksi profil 10/30** ditingkatkan dengan toleransi uptime (9.5–11 menit, 29–31 menit).
- **Tampilan profil** di `users.php` memakai fallback `profile_kind` bila kolom profile kosong.
- **Optimasi simpan DB**: update `login_history` hanya saat ada perubahan signifikan (status/bytes/uptime/waktu) untuk mengurangi I/O.
- **Logout 00:00:00** pada status TERPAKAI diperbaiki (fallback `updated_at` atau login + uptime).
- **Keamanan batch delete**: blok kosong diblokir sebelum proses hapus massal.
- **Print Used/Detail**: fallback uptime/bytes dari DB agar laporan tidak kosong ketika data RouterOS sudah hilang.

### 2.18 Perbaikan Rekap Penjualan (Dedup & Non‑Wartel)
- **report/print/print_rekap.php** dan **report/selling.php**: deduplikasi penjualan berdasarkan `username + sale_date` agar relogin tidak menghitung ganda.
- **report/laporan/services/live_ingest.php** dan **report/laporan/services/sync_sales.php**: menolak data tanpa BLOK (non‑Wartel).
- Rekap hanya menampilkan blok yang memiliki metrik > 0 (mengurangi baris kosong).

### 2.19 Perbaikan Schema & Migrasi Otomatis Penjualan
- Auto‑create/alter kolom **sales_history** dan **live_sales** saat runtime jika belum ada.
- Backfill data historis agar rekap tidak nol setelah migrasi.

### 2.20 Settlement Manual + Log Terkunci
- Tambah **settlement manual** di **report/selling.php** untuk menjalankan scheduler MikroTik.
- Endpoint **report/laporan/services/settlement_manual.php** menjalankan scheduler dan menulis log ke `settlement_log`.
- Modal log bergaya terminal dengan **lock** saat proses berjalan (tidak bisa ditutup sampai selesai).

### 2.21 Perbaikan Input HP Blok
- **report/selling.php**: form HP mengirim `ajax=1` dan dialihkan ke **report/laporan/services/hp_save.php**.
- **report/laporan/services/hp_save.php**: validasi & response JSON konsisten.

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
- **report/laporan/services/settlement_manual.php**:
  - Menjalankan **script CuciGudangManual** langsung (tanpa scheduler).
  - Logging settlement dipersempit: hanya log dengan prefix SETTLE/CLEANUP/SYNC/MAINT/SUKSES.
  - Terminal log dibuat berurutan (typewriter), status **Selesai** muncul setelah log akhir.
  - Tombol tutup dikunci hingga proses selesai, plus tombol **Reset settlement**.
- **Mikrotik-CleanWartel.rsc**:
  - Semua log diberi prefix **SETTLE** agar terfilter rapi.
  - Delay disetel ulang agar urutan log terbaca stabil.
- **report/laporan/services/live_ingest.php**:
### 2.24 Sentralisasi Helper & Code Hygiene (2026-01-28)
- **report/laporan/helpers.php** menjadi *single source of truth* untuk helper audit/rekap.
- **print_rekap.php / print_rekap_bulanan.php / print_rekap_tahunan.php / print_rincian.php / print_audit.php / audit.php**: semua mengarah ke helper terpusat.
- **helpers_audit.php** dihapus dari penggunaan untuk mencegah duplikasi fungsi.
- **print_rincian.php**: fungsi lokal rawan konflik dibersihkan/ditambahkan guard `function_exists`.
- **print_rekap.php**: konsistensi nilai Rupiah (Qty * Harga) dan ghost hint tanpa double-deduction.
- **ghost.php**: defensif (anti-cache, query_only, validasi input, helper fallback).
- **hotspot/user/actions.php**: memuat `hotspot/user/helpers.php` agar fungsi utilitas tersedia saat aksi via AJAX.
- **hotspot/user/data.php**: pembersihan duplikasi deteksi profil; mengandalkan helper user.
  - Validasi session config & hanya izinkan **hotspot_server=wartel**.
- **report/laporan/services/sync_stats.php**:
  - Filter berdasarkan hotspot server + skip user tanpa marker BLOK agar data non‑Wartel tidak masuk lagi saat settlement.

### 2.25 WhatsApp Laporan & Audit Manual (2026-02-01)
- **WhatsApp Laporan**
  - Upload PDF satu tombol (Pilih → Upload) dan validasi file max 4MB.
  - Daftar PDF dibatasi **2 file terbaru** + tombol **Hapus** per file.
  - Tombol kirim WA dipindah ke halaman **WhatsApp Laporan** (pilih tanggal).
  - Fitur **Kirim 2 PDF Terpilih** (dua pesan, satu file per pesan).
  - Status pengiriman menampilkan waktu `dd-mm-yyyy HH:MM:SS`.
- **Auto kirim laporan setelah settlement**
  - Tetap otomatis saat status settlement `done`, memilih file berdasarkan tanggal (fallback: file terbaru).
- **Audit Manual**
  - Tombol edit/hapus audit manual dibuka untuk **operator** (kecuali audit sudah dikunci).
- **Backup/Restore UI**
  - Backup/Restore disembunyikan untuk non‑superadmin.
  - `backupKey` tidak diekspos ke client jika bukan superadmin.
- **Menu Admin**
  - Menu pengaturan admin dipindah ke kanan (ikon gear di samping logout).
- **Git Hygiene**
  - Update `.gitignore` untuk DB, backup, session, dan PDF report agar tidak ter‑commit.

### 2.26 Penyempurnaan Status Sync & Ghost Hunter (2026-01-27)
- **report/laporan/services/sync_stats.php**:
  - Deteksi status rusak/retur lebih fleksibel dari komentar (tidak hanya prefix).
  - Penentuan status konsisten: online → rusak → retur → terpakai → ready.
- **report/laporan/services/sync_sales.php**:
  - Validasi ulang status dan flag saat settlement berdasarkan komentar terbaru.
- **report/laporan/ghost.php**:
  - Anti-cache untuk data realtime.
  - Threshold ghost dinaikkan ke 200KB.
  - DB read-only (PRAGMA query_only) untuk keamanan.

### 2.27 Refactor Print Standalone + Perbaikan Blank Page (2026-01-27)
- **hotspot/print/print.detail.php** dan **hotspot/print/print.used.php** kini memakai helper terpusat via `include_once ../../hotspot/user/helpers.php`.
- Ditambahkan helper umum di **hotspot/user/helpers.php**: normalisasi blok/profile, format tanggal, konversi waktu/uptime, serta helper DB khusus standalone.
- Menghindari konflik fungsi dengan **hotspot/user/data.php** dengan mengganti nama helper DB menjadi:
  - `get_user_history_from_db`
  - `get_cumulative_uptime_from_events_db`
  - `get_relogin_events_db`
- Perbaikan ini mencegah **halaman users blank** akibat fatal error redeclare, tanpa mengubah hasil print.

### 2.28 Aksi Hapus Total User (Superadmin) (2026-01-27)
- Tambah aksi **Hapus Total** di halaman users untuk **superadmin**.
- Aksi ini menghapus user dari **RouterOS** (user + active) dan menghapus data di **login_history**, **login_events**, **sales_history**, **live_sales**.
- Aktivitas dicatat ke **logs/admin_actions.log**.

### 2.29 Popup Hapus Blok + Hapus Total Blok (2026-01-28)
- Tombol **Hapus Blok** kini menampilkan popup pilihan dengan detail dampak aksi.
- Opsi **Hapus Router Saja**: menghapus user MikroTik per blok (user online di-skip), database tetap.
- Opsi **Hapus Total (Router + DB)** khusus superadmin: menghapus user MikroTik (termasuk active) dan membersihkan `login_history`, `login_events`, `sales_history`, `live_sales`.
- Popup konfirmasi menggunakan style overlay seperti di menu.php.

### 2.30 Tooltip Global (CSS/JS Terpisah) (2026-01-30)
- Tooltip global dipindahkan ke file terpisah:
  - CSS: **css/tooltips.css**
  - JS: **js/tooltips.js**
- Inisialisasi di **include/menu.php** agar konsisten di seluruh halaman.
- Tooltip offset dari kursor agar tidak menumpuk dan tetap mudah dibaca.

### 2.31 Penyempurnaan Pengelola (VIP) & Kuota Harian (2026-01-30)
- **Kuota harian Pengelola** disinkronkan dengan jumlah VIP aktual di RouterOS saat halaman users dibuka.
- **Unvip** kini membersihkan tag **VIP/Pengelola** di comment dan memastikan kuota harian berkurang.
- Perbaikan **upsert** pada tabel kuota harian agar decrement tidak gagal saat baris belum ada.
- **Popup konfirmasi Pengelola** dibedakan untuk set vs batalkan, nama target tampil akurat.
- **Tooltip tombol Pengelola** menampilkan format: **“Batas Perubahan Pengelola x/y”**, dan label saat limit tercapai.
- File terkait:
  - **hotspot/user/actions.php** (logika vip/unvip + kuota)
  - **hotspot/user/helpers.php** (helper kuota: get/increment/decrement/set)
  - **hotspot/user/data.php** & **hotspot/user/render.php** (sinkron kuota + tooltip title)
  - **hotspot/user/js/users.js** (popup konfirmasi Pengelola)

### 2.24 Penyempurnaan Users Modular (2026-01-26)
- Tombol **clear search (X)** stabil saat kehilangan fokus (klik di luar/ke taskbar).
- `Retur dari` kini menampilkan username bersih (tanpa prefix **vc-**), parsing `Retur Ref` lebih fleksibel.
- Status **RETUR** diprioritaskan di data router & history‑only sehingga tidak turun menjadi **RUSAK/READY** akibat histori “Audit: RUSAK”.
- Auto‑print voucher baru saat aksi retur berhasil (response mengirim `new_user`).

### 2.25 Konfigurasi Terpusat env.php (Endpoint Ingest/Sync)
- **include/env.php** menampung token & allowlist untuk endpoint:
  - `report/laporan/services/sync_usage.php`
  - `report/laporan/services/usage_ingest.php`
  - `report/laporan/services/live_ingest.php`
  - `report/laporan/services/sync_sales.php`
  - `report/laporan/services/sync_stats.php`
- Semua endpoint membaca konfigurasi dari **env.php** terlebih dahulu, lalu fallback ke `getenv()` atau default.
 - Token untuk tools maintenance dan backup UI juga dipusatkan di **env.php** (dipakai oleh tools dan menu backup/restore).

### 2.30 Penyempurnaan Akuntansi & Audit Harian (2026-01-28)
- **Retur** menambah **net** tetapi **tidak menambah qty** pada rincian penjualan harian.
- **Rusak yang sudah diretur** dianggap **0** di kolom rusak (agar tidak double loss).
- **Audit Sistem** tidak menghitung baris dari `login_history` sebagai transaksi (hindari dobel).
- **Voucher Aktual** di audit mengikuti **jumlah user manual (raw)**.
- **Setoran Sistem** dihitung dari **net**.
- **Setoran Aktual** kini **bisa diedit manual** (input tidak di-override oleh profil 10/30).
- **Harga per profil** dipusatkan di `env.php` (`pricing.profile_prices`) dan dipakai sebagai fallback jika harga transaksi kosong.
- **User rusak yang sudah diretur** disembunyikan dari list audit agar tidak membingungkan.

### 2.32 Popup Global & Tooltip Global (2026-01-30)
#### Popup Global
- Sumber desain: **dev/design-popup.html** (diubah menjadi komponen global).
- File global:
  - CSS: **css/popup.css**
  - JS: **js/popup.js**
  - Injeksi: **include/headhtml.php**
- API global:
  - `window.MikhmonPopup.open({...})` untuk membuka popup.
  - `window.MikhmonPopup.close()` untuk menutup popup.
- Parameter utama `open`:
  - `title`: judul popup.
  - `iconClass`: ikon font-awesome (contoh `fa fa-database`).
  - `statusIcon`: ikon status di body.
  - `statusColor`: warna ikon status.
  - `message`: teks utama (plain).
  - `messageHtml`: teks utama (HTML).
  - `alert`: `{ type: info|warning|danger, text|html, iconClass }`.
  - `buttons`: array tombol `{ label, className, onClick, close }`.
  - Menu **Backup/Restore** kini menggunakan popup global ini (konfirmasi + status proses).

#### Tooltip Global
- File global:
  - CSS: **css/tooltips.css**
  - JS: **js/tooltips.js**
  - Injeksi: **include/headhtml.php** dan **include/menu.php**
- Cara pakai:
  - Tambahkan atribut `title` pada elemen apa pun.
  - Tooltip otomatis offset dari kursor dan menyesuaikan posisi di tepi layar.

### 2.31 Penyelarasan Audit Summary & Prioritas Status (2026-01-28)
- **report/audit.php** dan **report/print/print_audit.php** kini menghitung:
  - **Qty manual = raw** (total voucher),
  - **Setoran manual = net** (rusak/invalid mengurangi; retur tidak mengurangi).
- Agregasi SQL untuk **gross/net/retur/rusak/invalid** memakai prioritas status **retur > rusak > invalid** agar tidak double-count.
- Layanan ingest/sync berikut memakai prioritas status yang sama:
  - `report/laporan/services/sync_sales.php`
  - `report/laporan/services/live_ingest.php`
  - `report/laporan/services/sync_stats.php`
  - `report/laporan/services/sync_usage.php`

### 2.33 Auto‑isi HP Harian + Resolver DB Dashboard (2026-01-30)
- **Auto‑isi HP harian**: jika tanggal hari ini belum ada data, sistem menyalin otomatis dari tanggal terakhir yang tersedia dan langsung menyimpan, namun tetap **bisa diedit** kapan saja.
  - File: [report/laporan/render.php](report/laporan/render.php)
- **Dashboard memakai DB dari env**: `dashboard/aload.php` kini membaca `env.php` dan memakai `resolve_stats_db_file()` agar path DB mengikuti konfigurasi.
  - File: [dashboard/aload.php](dashboard/aload.php)
- **Hitung online sesuai server aktif**: jumlah user online mengikuti `hotspot_server` (bukan hardcode wartel).
  - File: [dashboard/aload.php](dashboard/aload.php)
- **Draft UI admin**: menambahkan desain admin/pengaturan sebagai referensi UI.
  - File: [dev/admin.html](dev/admin.html)
  - File: [dev/pengaturan-admin-1.html](dev/pengaturan-admin-1.html)
  - File: [dev/pengaturan-admin-2.html](dev/pengaturan-admin-2.html)

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
- **Akar masalah**: `report/laporan/services/sync_stats.php` menarik semua user hotspot tanpa filter `server` dan tanpa cek BLOK.
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
- **Solusi**: kirim `ajax=1`, arahkan ke `report/laporan/services/hp_save.php`, respons JSON konsisten.

## 4) Penyempurnaan Terbaru (Tahap Lanjutan)
### 4.1 Filter Hotspot (Active/Hosts/Cookies/DHCP)
- **hotspot/active.php**: filter user aktif hanya **server wartel** (tanpa server-profile) agar data tidak kosong.
- **hotspot/hosts.php**: filter host hanya **server wartel**.
- **hotspot/cookies.php**: filter cookie hanya **server wartel**; fallback dari pola user (6 huruf kecil+angka) bila server kosong.
- **dhcp/dhcpleases.php**: filter DHCP leases hanya **server wartelpas** (case-insensitive).

### 4.2 Pembersihan Komentar Active/Host
- Komentar voucher seperti `Blok-F30 | IP:... | MAC:...` diringkas menjadi **“Blok-F - Profile 30 Menit”**.
- Berlaku di **hotspot/active.php** dan **hotspot/hosts.php**.

### 4.3 Print Rusak & Used (Rincian Detail)
- **hotspot/print.detail.php**: print khusus rusak dengan:
  - Detail User, Kelayakan Untuk Rusak (status), dan Rincian Relogin (hanya jika >1 & beda dari first/login/logout).
  - Tambahan IP/MAC dari login_history atau comment.
  - Blok tanpa prefix “BLOK-”, Profile format “10 Menit/30 Menit”.
- **hotspot/print.used.php**: print khusus **TERPAKAI/ONLINE** (tanpa Kelayakan Rusak).
- Tombol aksi di **hotspot/users.php**:
  - TERPAKAI/ONLINE → print.used.php
  - RUSAK → print.detail.php
  - RETUR → tetap print voucher (barcode/PNG).

### 4.4 Menu Kesehatan DB + Backup/Restore
- Menu kanan menampilkan ikon heartbeat status DB (hijau/merah) dengan cek berkala **30 detik**.
- Tombol **Restore** disembunyikan jika DB sehat.
- Tombol **Backup** disembunyikan jika sudah ada backup **valid** hari ini.
- Endpoint baru: **tools/backup_status.php**.

### 4.5 Perbaikan Backup/Restore
- **tools/backup_db.php**: backup via file temp + validasi ukuran + quick_check sebelum rename.
- **tools/restore_db.php**: restore dari backup terbaru/tertentu + quick_check + VACUUM.

### 4.6 Tooltip Global
- Tooltip global floating dengan transisi halus.
- Dikecualikan untuk chart (Highcharts).
- Posisi tooltip dijaga agar tidak keluar layar.

### 4.7 Dashboard (Chart & KPI)
- Chart “Performa Bisnis” dirapatkan ke kiri/kanan (min/max, padding, pointPlacement).
- KPI **Pendapatan** kini **harian (today)**.
- **Gross income** dihitung di backend (harian) untuk kebutuhan berikutnya.

### 4.8 HTACCESS
- Whitelist endpoint baru:
  - **hotspot/print.detail.php**
  - **hotspot/print.used.php**
  - Pattern disesuaikan agar bekerja di subfolder.

### 4.9 Login (Warning Browser)
- Tambah `autocomplete="username"` dan `autocomplete="current-password"`.
- Tambah placeholder DOM untuk mencegah error JS global di halaman login.

## 5) Rencana Pengembangan (Diskusi WhatsApp)
- Target: setelah audit/settlement selesai, **kirim PDF laporan harian** ke beberapa nomor via WhatsApp.
- Dipilih gateway **Fonnte**.
- Rencana komponen:
  1) Manajemen daftar nomor (CRUD, validasi format 62xxx).
  2) Template pesan + attachment PDF.
  3) Queue/log pengiriman (status sukses/gagal).
  4) Trigger: otomatis setelah settlement selesai atau jadwal tertentu.

## 4) Update Terbaru (Audit & Penyesuaian 2026-01-22)
### 4.1 Filter tanggal & ketepatan laporan penjualan
- **Masalah**: Data hari ini muncul kosong atau bercampur dengan hari lain.
- **Akar masalah**: `sale_date` kosong dan `raw_date` memakai format `YYYY-MM-DD` (kadang dengan jam) yang sebelumnya tidak terbaca.
- **Solusi**:
  - `report/selling.php` dan `report/print_rekap.php` kini mengenali `YYYY-MM-DD` + jam.
  - `report/laporan/services/sync_sales.php` disesuaikan agar `sale_date` terisi untuk format `YYYY-MM-DD`.
  - Backfill `sale_date` dari `raw_date` dilakukan via perbaikan runtime di sync.

### 4.2 Penyelarasan logika “voucher laku”
- **Masalah**: Nilai “Total Voucher Laku” membingungkan karena menghitung transaksi per baris.
- **Keputusan bisnis**: **Voucher laku = 1x per username per hari**.
- **Solusi**:
  - `report/selling.php` dan `report/print_rekap.php` menghitung **unik per username per tanggal**.
  - Label/angka transaksi per baris dihapus agar tidak menimbulkan kebingungan.

## 6) Update Terbaru (Audit Holistik 2026-01-28)
### 6.1 Hapus Blok Total (Router + DB + Script)
- **hotspot/user/actions.php**:
  - Hapus total blok kini menyapu **RouterOS user + active + system script** terkait blok.
  - Penghapusan DB menggunakan pola **prefix BLOK-X** dan pencarian **raw_comment** agresif (format `-|-` ikut terhapus).
  - Retur/child voucher ikut terhapus dengan **parent/child detection** lintas Router + DB.
  - Log admin_actions mencatat jumlah user/script terhapus.

### 6.2 Dropdown Blok & Profil (Filter Kombinasi)
- **hotspot/user/helpers.php**:
  - `extract_blok_name` kini **bersih**: hanya huruf blok (A/B/C), buang angka profil.
  - Deteksi blok tahan format `-|-` agar dropdown tidak tercemar string panjang.
- **hotspot/user/data.php**:
  - Dropdown blok dibangun dari semua data (router + history) dengan **fallback ke history** jika comment kosong.
  - Profil kosong/default diberi **fallback** dari komentar/blok agar filter profil bekerja.
  - READY **tidak tampil** saat status=Semua (sesuai permintaan terbaru).

### 6.3 Popup Aksi & Anti‑Blink
- **hotspot/user/js/users.js**:
  - Overlay kini **anti‑blink** (timer fade dibatalkan jika ada popup baru).
  - Aksi hapus blok memakai **modal sukses khusus** + tombol “Tutup & Reload” (tanpa banner kecil & tanpa auto refresh).
  - Auto‑refresh AJAX disuspend selama popup aksi aktif.

### 6.4 Rollback Rusak → Status Transaksi
- **hotspot/user/actions.php**:
  - Rollback RUSAK kini **mengubah status** di `sales_history` dan `live_sales` ke `ready`.

### 6.5 Tools Clear Block (Audit Manual)
- **tools/clear_block.php**:
  - Dukungan `delete_audit=1` untuk menghapus **audit_rekap_manual**.
  - Output sekarang melaporkan jumlah audit yang terhapus.

### 6.6 Perbaikan Retur & Delete Pair
- **hotspot/user/actions.php**:
  - `delete_user_full` otomatis menghapus **pasangan retur** (parent/child) dalam satu aksi.
  - Penghapusan retur juga membaca **raw_comment** DB jika data router kosong.

### 4.3 Users.php filter tanggal lebih akurat
- **Masalah**: Filter tanggal di users.php tercampur karena memakai display time yang bisa dipengaruhi `updated_at`.
- **Solusi**: Filter memakai timestamp mentah (`last_login_real`, `login_time_real`, `logout_time_real`) agar hasil tepat.

### 4.4 Settlement log real-time & terminal style
- **Masalah**: Log settlement muncul setelah proses selesai (tidak real-time).
- **Akar masalah**: Session lock & proses synchronous.
- **Solusi**:
  - `session_write_close()` agar polling bisa paralel.
  - Eksekusi settlement via scheduler sekali jalan dengan log awal `SETTLE: MANUAL: Mulai`.
  - Tampilan log terminal ditambah prompt `> `.

### 4.5 Audit & Print terpisah
- **report/print_audit.php** dibuat untuk print audit yang bersih tanpa gangguan CSS report.
- `.htaccess` diupdate untuk whitelist endpoint print baru.

### 4.6 Ringkasan perubahan file utama
- `report/selling.php`: parsing tanggal, logika unik voucher, perbaikan filter harian, ringkasan disesuaikan.
- `report/print_rekap.php`: logika unik voucher, label tabel disesuaikan, parsing tanggal.
- `report/laporan/services/sync_sales.php`: dukungan format `YYYY-MM-DD`.
- `hotspot/users.php`: filter tanggal lebih akurat (timestamp mentah).

## 4.7 Update Terbaru (Settlement Log & Perbaikan Real-time 2026-01-23)
### 4.7.1 Log settlement real-time berbasis server
- **Masalah**: log di popup sering tertunda/terbaca dari RouterOS saja, dan tidak stabil bila proses lama.
- **Solusi**:
  - **report/laporan/services/settlement_log_ingest.php**: endpoint baru menerima log dari MikroTik dan menulis ke file `logs/settlement_<session>_<date>.log`.

## 6) Update Terbaru (ACL, Rekap Audit, dan UI — 2026-01-27)
### 6.1 Pemisahan Role Superadmin vs Operator
- Menambah helper ACL (`include/acl.php`) dan menerapkan pembatasan akses di halaman admin, settings, tools, system, dan proses kritikal.
- Operator hanya bisa akses laporan/rekap, settlement, dan fitur operasional terbatas.
- Superadmin mempertahankan akses penuh (manajemen sesi, pengaturan, audit lock, shutdown/reboot).

### 6.2 Perbaikan Menu & Header Admin
- Header admin diseragamkan dengan halaman utama (logo/brand konsisten).
- Menu editor template dan pilihan bahasa dihapus.
- Status DB + tombol backup/restore ditampilkan dengan aturan: superadmin selalu tampil, operator hanya muncul saat DB error.

### 6.3 Perbaikan Session & Config
- Normalisasi suffix sesi (contoh `~wartel`) agar tidak loop.
- Listing sesi bersih (menggunakan `$data` config, bukan baca file per baris).
- Perbaikan permission `include/config.php` dan `include/quickbt.php` via entrypoint + volume docker.

### 6.4 Settlement & Audit
- Operator boleh menjalankan settlement, namun audit lock/edit/delete tetap khusus superadmin.
- Fallback settlement script: bila `CuciGudangManual` tidak ada, gunakan `CleanWartel`.

### 6.5 Rekap Print & Audit Manual (Auto Inject)
- Normalisasi status `rusak/retur/invalid` agar variasi teks (contoh `RUSAK (DIGANTI)`) tetap terbaca.
- Auto-inject user insiden ke tabel audit bila operator tidak input manual.
- Tabel audit menampilkan **Username/Up/Byte** dengan warna penuh per baris:
  - Merah: rusak/invalid
  - Hijau: retur
  - Kuning: anomali (manual input tapi status normal)
- Uptime/bytes diambil dari `login_history` agar tidak kosong.

### 6.6 Perhitungan Qty Audit (Anti Double Count)
- Saat Qty manual kosong, hitung otomatis **tanpa** memasukkan status rusak agar tidak dobel (rusak tidak menambah qty jual).
- Retur dan anomali tetap dihitung sebagai qty valid.

### 6.7 Catatan Harian (Laporan ke Owner)
- Modal catatan dipastikan bisa diketik dengan memaksa fokus dan menghapus state disabled/readonly.

### 6.8 Sentralisasi Helper Audit & Refactor Print
- Helper audit dipusatkan di **report/laporan/helpers_audit.php**.
- **print_rekap.php**, **print_rekap_bulanan.php**, **print_rekap_tahunan.php**, dan **print_rincian.php** memakai helper terpusat untuk menghilangkan duplikasi fungsi.

### 6.9 Bug Fix Rekap Tahunan (Expenses)
- Memperbaiki akumulasi pengeluaran bulanan di **print_rekap_tahunan.php** dengan buffer sementara (`$temp_expenses`).
- Mencegah error variabel belum terdefinisi dan memastikan kolom pengeluaran tampil akurat.

### 6.10 Ghost Hunter Threshold (Konfigurabel)
- Ambang deteksi ghost kini bisa diatur melalui `env.php` (`system.ghost_min_bytes`).
- Default diturunkan ke **50KB (51200 bytes)** agar konsisten dengan logika pemakaian.

  ## 6) Update Terbaru (Audit & Penyempurnaan 2026-01-27)
  Bagian ini merangkum pekerjaan dari awal sesi hingga akhir, khususnya untuk laporan, print, retur, dan perbaikan data hotspot.

  ### 6.1 Laporan, Status, dan Akuntansi
  - Penegasan logika **gross/net**: gross hanya **normal+rusak**, net **normal+retur**, invalid **0**.
  - Penyesuaian tampilan status di laporan dengan label visual:
    - **RUSAK (DIGANTI)** untuk rusak yang sudah ada pengganti retur.
    - **RETUR (PENGGANTI)** untuk voucher hasil retur.
  - Penambahan **Ref (user asal)** di bawah username pada laporan transaksi.
  - Penghapusan **voucher asal retur** dari list transaksi (tetap masuk total).

  ### 6.2 Print Rincian & Rekap
  - **report/print/print_rincian.php**:
    - Menampilkan **Retur Ref** dari komentar dan DB untuk menjaga konsistensi referensi.
    - Status retur/rusak diprioritaskan agar tidak tertukar.
    - Tampilan label **RUSAK (DIGANTI)** dan **RETUR (PENGGANTI)**.
  - Print rincian tidak lagi menampilkan kolom yang tidak relevan untuk kebutuhan audit (disederhanakan sesuai kebutuhan wartel).

  ### 6.3 Hotspot Print List (List Voucher)
  - Membuat ulang **hotspot/print/print_list.php** sebagai basis print list dinamis.
  - Print list mengikuti filter status/blok/profil/tanggal dari halaman users.
  - Status **READY** di list print memiliki layout khusus:
    - Kolom detail (MAC/IP/Login/Logout/Uptime/Bytes) disembunyikan.
    - Kolom tambahan **Nama/Tujuan/Hubungan** hanya tampil saat READY.
  - Perbaikan label default agar konsisten dengan konteks (Terpakai/Ready/Retur/Rusak).

  ### 6.4 Filter Profil & Kode Voucher
  - **voucher/print.php** ditambah parameter `profile=10|30` agar print kode tidak campur.
  - Judul file PDF print voucher mengikuti profil yang dipilih.
  - Tombol print di users membawa parameter profil ke print voucher.

  ### 6.5 Retur User (RouterOS)
  - Aksi retur sekarang **mengisi limit-uptime** sesuai profil (10m/30m) saat membuat user baru di RouterOS.

  ### 6.6 Pembersihan Duplikasi & Tampilan
  - Retur asal tidak lagi ditampilkan ganda di list **terpakai/retur/all**.
  - Status dan ref retur diselaraskan antara data router dan DB.

  ### 6.7 Sticky Notifikasi Stok Rendah
  - Notifikasi stok rendah dibuat **sticky** dan hanya muncul via JS agar tidak mengganggu layout.
  - Data stok dibawa dari backend ke JS untuk tampilan realtime yang konsisten.
  - Validasi `key` + `session` untuk mencegah spam.
  - Debug file `logs/settlement_ingest_debug.log` untuk audit request.

### 4.7.2 Rotasi & retensi log
- **Masalah**: log makin besar dan membebani disk.
- **Solusi**:
  - Retensi log harian (keep 14 hari).
  - Arsip log lama (60 hari), debug log dipotong jika >1MB.
  - Pembersihan diperluas di **tools/clear_logs.php** (`scope=all`, `purge=1`).

### 4.7.3 Stabilitas script MikroTik
- **Masalah**: error `replace/urlencode` dan nilai kosong saat fetch.
- **Solusi**:
  - Tambah `tostr` + fallback, `on-error` guards.
  - Penguncian auto-unlock, ringkasan skip-ready, urutan log stabil.
  - Nama script konsisten: **FIXED BY ABDULLAH**.

### 4.7.4 Settlement popup terminal (UI)
- **Masalah**: log tampil terlalu cepat, status akhir kadang belum muncul.
- **Solusi**:
  - Typing effect diperlambat + fast mode jika log sangat banyak.
  - Polling log tetap berjalan walau request start lambat.
  - Pesan final sistem ditampilkan setelah selesai: “Semua proses selesai. Silakan tutup terminal.”

### 4.7.5 Settlement backend & fallback log
- **Masalah**: response OK menutupi error trigger dan log kadang kosong.
- **Solusi**:
  - **report/laporan/services/settlement_manual.php** membaca log server lebih dulu (clearstatcache), fallback RouterOS.
  - Response tidak dipercepat agar error trigger terlihat.
  - Regex pencarian script diperbaiki.

### 4.7.6 Whitelist & keamanan endpoint
- **.htaccess** diperbarui untuk allowlist endpoint ingest dan IP MikroTik tambahan (termasuk 10.10.83.2).

## 4.8 Update Terbaru (Audit Manual & Print Rekap 2026-01-23)
### 4.8.1 Otomatisasi perhitungan audit manual (laku vs rusak/retur)
- **Tujuan**: Admin cukup input **jumlah voucher laku per profil** (10m/30m). Sistem menghitung otomatis.
- **Aturan bisnis**:
  - **RUSAK** mengurangi pendapatan.
  - **RETUR** mengembalikan pendapatan (menambah jumlah bersih).
  - **INVALID** tidak dihitung (mengurangi pendapatan).
- **Perhitungan otomatis** di `report/selling.php`:
  - `net_qty_10 = qty_10 - rusak_10 - invalid_10 + retur_10`
  - `net_qty_30 = qty_30 - rusak_30 - invalid_30 + retur_30`
  - `audit_qty = net_qty_10 + net_qty_30`
  - `audit_setoran = (net_qty_10 * 5000) + (net_qty_30 * 20000)`
- **Expected Qty** diselaraskan dengan aturan pendapatan:
  - Mengurangi **RUSAK** dan **INVALID**.
  - **RETUR tidak mengurangi** (pendapatan tetap).

### 4.8.2 Audit evidence & profile qty
- Jika admin tidak mengisi qty 10/30, sistem mengambil **auto count** dari daftar user audit.
- Evidence audit menyimpan:
  - `profile_qty` (qty_10/qty_30)
  - daftar user + `profile_kind`, `price`, `last_status`, `last_bytes`, `last_uptime`.

### 4.8.3 Print Rekap Audit (warna & ringkasan)
- **Highlight username** di tabel audit:
  - **Merah** = Rusak
  - **Hijau** = Retur
  - **Kuning** = User tidak dilaporkan (status normal/selain rusak/retur)
- **Legenda warna** ditambah di bawah tabel.
- **Status Voucher Global** ditampilkan sejajar (inline) untuk Rusak/Retur/Invalid.
- **Kesimpulan Audit Harian** per blok memuat:
  - Profil 10/30 (qty dan nominal)
  - **Voucher Rusak** per profil (10/30)
  - **Voucher Retur** per profil (10/30)
  - **User Tidak Dilaporkan** per profil (10/30)

### 4.8.4 Dampak terhadap data lama
- Data audit lama tetap tampil sesuai nilai tersimpan.
- Untuk mengikuti logika otomatis terbaru, audit lama perlu **disimpan ulang**.

## 4.9 Update Terbaru (Audit, Pengeluaran, Catatan Harian, Keamanan) – 2026-01-24
### 4.9.1 Audit menjadi fokus keuangan (Finance-only)
- **report/audit.php** dan **report/print_audit.php** disederhanakan menjadi tampilan keuangan saja.
- Tabel teknis non-keuangan dihapus, diganti ringkasan eksekutif + status box.
- **Ghost Hunter** diperbarui agar lebih mudah dipahami (analisa selisih voucher 10/30 menit).

### 4.9.2 Perbaikan filter tanggal & pending real-time
- Filter audit manual menggunakan `report_date` (bukan kolom lain).
- Data harian mengenali `raw_date` format `YYYY-MM-DD` + jam.
- Pending stats ditampilkan **real-time final + pending** dengan label rentang tanggal (disembunyikan jika hari ini).

### 4.9.3 Logika rusak/invalid lebih akurat
- Status rusak kini dihitung dari kombinasi `status`, `is_*`, dan `comment`.
- Mengurangi kasus **rusak Rp 0** karena data status tidak konsisten.

### 4.9.4 Integrasi Pengeluaran Operasional (Bon/Belanja)
- Tabel **audit_rekap_manual** menambahkan kolom `expenses_amt`, `expenses_desc`.
- **report/selling.php**: modal audit menambahkan input pengeluaran + validasi.
- **report/audit.php** dan **report/print_audit.php**: menampilkan **Setoran Bersih (Cash) = Setoran Fisik - Pengeluaran**.
- **report/print_rekap.php**: menambahkan mini cashflow di bawah tabel audit.
- **report/print_rekap_bulanan.php**:
  - Kartu summary pengeluaran bulanan.
  - Kolom pengeluaran per hari.

## 4.10 Update Terbaru (Dashboard Clean Pro & Layout Presisi) – 2026-01-25
### 4.10.1 Refactor total tampilan dashboard
- **dashboard/home.php** disusun ulang mengikuti **design-dashboard.html**:
  - KPI grid, layout card chart dan transaksi, serta footer resource mini.
  - Struktur tabel transaksi dibuat fixed layout agar stabil saat zoom.
  - Ditambah indikator jumlah transaksi (row count) di bawah tabel.

### 4.10.2 Layout & CSS stabil untuk zoom/resolusi
- **css/dashboard-clean-pro.css** diperbarui penuh:
  - Reset box-sizing global, konsistensi font **Inter**.
  - Struktur flex + grid yang tidak bentrok, menjaga tinggi card saat zoom in/out.
  - Kontainer chart dan tabel dibuat responsif (min-height, overflow terkendali).
  - Breakpoint tablet & mobile agar layout tetap presisi.
  - Table fixed layout + column width agar header/row tidak bergeser.
  - Month tabs bisa scroll horizontal pada layar kecil.

### 4.10.3 Perbaikan chart agar tidak terpotong
- **dashboard/aload.php** (highcharts):
  - `height: null` + `reflow: true` untuk menyesuaikan ukuran container.
- **dashboard/home.php**:
  - `reflow()` dipicu setelah AJAX selesai dan saat resize agar grafik selalu pas.

### 4.10.4 Riwayat transaksi dibatasi dan scroll terkontrol
- **dashboard/aload.php**:
  - Limit riwayat transaksi menjadi **10** baris (sebelumnya 30/50).
- **dashboard/home.php**:
  - Perhitungan row count hanya menghitung baris transaksi (bukan placeholder), sehingga saat kosong tampil **0 transaksi**.

### 4.10.5 Resource footer dan warning CPU
- **dashboard/aload.php**:
  - Output sysresource diringkas dan di-load ke footer tanpa wrapper ganda.
  - CPU > 90% diberi class **text-danger** untuk peringatan.

### 4.10.6 Mode uji tampilan menggunakan tanggal tertentu
- **dashboard/home.php**:
  - Menambahkan parameter **test_date** untuk mengambil data KPI & riwayat pada tanggal tertentu.
- **dashboard/aload.php**:
  - Menambahkan dukungan **test_date** untuk filter `live_data` dan `logs`.

### 4.10.7 Perapihan error & stabilitas parse
- Menangani error kurung/else-if yang menyebabkan `Unmatched '}'` atau `unexpected token` di `dashboard/aload.php`.
- Struktur `if` dipastikan rapi dan tidak menimpa output lain.
  - Lampiran **Rincian Pengeluaran Operasional** (tanggal, blok, keterangan, nominal).
- **report/print_rekap_tahunan.php**:
  - Kolom pengeluaran per bulan dan catatan YTD.

## 4.11 Update Terbaru (Audit Manual Konsisten & Setoran Manual) – 2026-01-29
### 4.11.1 Override setoran manual (tetap ada auto kalkulasi)
- **Tujuan**: Auto kalkulasi setoran tetap berjalan sebagai bantuan input, namun **nominal manual** yang diketik pengguna tetap dihormati.
- **Perubahan**:
  - Form audit menyimpan flag `audit_setoran_manual` untuk menandai input manual.
  - Jika user mengetik setoran berbeda dari auto, sistem otomatis menganggap **manual override**.
  - Auto kalkulasi tetap aktif jika user tidak mengetik setoran.

### 4.11.2 Konsistensi target net (audit.php vs print audit)
- **Masalah**: Target net pada print audit sempat berbeda dari audit.php.
- **Solusi**:
  - Print audit kini memakai **expected_setoran** yang sama dengan audit.php.
  - Potongan rusak/invalid pada print audit diselaraskan dengan gross/retur/net.

### 4.11.3 Total Rusak/Invalid tidak double count
- **Masalah**: Ringkasan “Total Voucher Rusak/Invalid” sempat menjumlah **rusak sistem + rusak manual**, sehingga **retur ikut terhitung**.
- **Solusi**:
  - Loss kini dihitung berdasarkan model: **Loss = (Gross + Retur) − Expected Net**.
  - Fallback memakai total rusak sistem jika data expected belum tersedia.
  - Audit dan print audit kini konsisten.

## 4.12 Update Terbaru (Audit Manual User Checklist & UI) – 2026-01-29
### 4.12.1 Popup audit disederhanakan dan berurutan
- Urutan form: **Blok → Verifikasi User → Fisik Voucher → Kalkulasi/Pengeluaran → Setoran Bersih**.
- Input **tanggal** disembunyikan (mengikuti tanggal yang dipilih di dashboard).

### 4.12.2 Verifikasi user berbasis checklist
- Tombol verifikasi membuka popup daftar user **terpakai + retur**.
- **Rusak/invalid tidak ditampilkan** (dianggap loss).
- Item menampilkan **uptime & bytes**.
- Status badge jelas (TERPAKAI/RETUR) + ringkasan **Tidak dilaporkan** di footer.

### 4.12.3 Qty otomatis terkunci saat checklist aktif
- Jika user dipilih, **qty terkunci** mengikuti jumlah user terpilih.
- Jika tidak ada user dipilih, qty per profil **dapat diinput manual**.

### 4.12.4 Setoran bersih dari pengeluaran
- Setoran kotor tetap dihitung dari qty (auto), namun dapat di-edit manual.
- **Setoran bersih** dihitung: kotor − pengeluaran.

### 4.12.5 Endpoint data audit user
- Endpoint baru: **report/laporan/services/audit_users.php**.
- Menggabungkan **sales_history + live_sales (pending)** untuk tanggal terpilih.
- Menarik status, profil, uptime/bytes dari `login_history` bila ada.

### 4.9.5 Catatan Harian / Insiden (Supervisor → Owner)
- Tabel baru: **daily_report_notes** (1 catatan per tanggal).
- **report/selling.php**: tombol **Catatan/Insiden** di toolbar harian dan input via popup (edit/hapus tetap dari popup, tidak tampil di dashboard).
- **report/print_rekap.php**: catatan harian tampil sebagai blok merah di footer rekap harian.
- **report/print_rekap_bulanan.php**: kolom **Keterangan/Insiden** (teks dipotong agar tabel rapi).
- **report/audit.php**: catatan ditampilkan di atas ringkasan keuangan.
- **report/print_audit.php**: catatan ditampilkan di bawah header cetak.

### 4.9.6 Keamanan endpoint ingest
- **report/laporan/services/live_ingest.php** menambahkan **IP allowlist** (env `WARTELPAS_SYNC_ALLOWLIST`, localhost tetap diizinkan).

### 4.9.7 Penyesuaian tombol audit di selling
- Tombol **Kunci Audit** disembunyikan dari toolbar harian agar fokus ke audit + catatan harian.
- Status “Audit terkunci” tetap ditampilkan jika sudah terkunci.

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
- report/laporan/services/sync_stats.php
- report/laporan/services/sync_sales.php
- report/selling.php
- report/laporan/services/settlement_manual.php
- report/laporan/services/settlement_log_ingest.php
- report/laporan/services/hp_save.php
- report/print_rekap.php
- report/print_rincian.php
- report/laporan/services/usage_ingest.php
- report/laporan/services/sync_usage.php
- report/laporan/services/live_ingest.php
- tools/clear_logs.php
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
- Jika ada data yang masih kosong, pastikan voucher pernah login agar DB terisi.
- Jika diperlukan debug, gunakan parameter `?debug=1` pada users.php.

---

Jika ada tambahan perubahan atau aturan bisnis baru, dokumentasi ini akan diperbarui.

## 6) Ringkasan Implementasi Terbaru (2026-01-25)
### 6.1 Dashboard – Tabel Transaksi (Stabil di Zoom)
- Perbaikan layout tabel transaksi agar kolom UPTIME tidak hilang saat zoom, termasuk:
  - Penyesuaian proporsi kolom dan padding.
  - Kontrol scroll horizontal yang adaptif.
  - Fallback grid untuk kondisi ekstrem (zoom tinggi/mobile).
- Refactor HTML tabel transaksi agar lebih konsisten dan modular (class CSS khusus kolom).
- Penambahan indikator “LIVE UPDATE” dan waktu update terakhir.

**File terkait:**
- [css/dashboard-clean-pro.css](css/dashboard-clean-pro.css)
- [dashboard/home.php](dashboard/home.php)
- [dashboard/aload.php](dashboard/aload.php)

### 6.2 Dashboard – Optimasi JS & Status Live Berdasar Jam
- Optimasi layout tabel pada resize/refresh.
- Indikator LIVE hanya muncul pada jam 08:00–18:15 WITA (Asia/Makassar).

**File terkait:**
- [dashboard/home.php](dashboard/home.php)

### 6.3 Backup DB – Hardening Keamanan & Kebersihan File
- Endpoint backup kini mendukung key via GET/POST/header `X-Backup-Key` dengan `hash_equals`.
- Allowlist IP ditambahkan (termasuk 10.10.83.1 dan 172.19.0.1).
- Rate limit sederhana (1 request/5 menit).
- Logging backup ke logs/backup_db.log.
- Cleanup file samping WAL/SHM/TMP di folder backup.
- Whitelist endpoint backup di htaccess untuk MikroTik.

**File terkait:**
- [tools/backup_db.php](tools/backup_db.php)
- [htaccess](htaccess)

### 6.4 Restore DB – Hardening & Validasi
- Endpoint restore kini mendukung key via GET/POST/header `X-Backup-Key` dengan `hash_equals`.
- Allowlist IP + rate limit.
- Restore dilakukan via file temp, diverifikasi `quick_check` + `VACUUM`, lalu replace atomik.
- Logging restore ke logs/restore_db.log.

**File terkait:**
- [tools/restore_db.php](tools/restore_db.php)

### 6.5 Laporan Penjualan – Rincian Transaksi
- Kolom “Efektif” di bagian Rincian Transaksi dihapus agar tabel lebih ringkas.

**File terkait:**
- [report/selling.php](report/selling.php)

### 6.6 Users – Modularisasi, Asset Eksternal, dan Perbaikan Path (2026-01-26)
- **Modularisasi users.php** menjadi loader yang memanggil:
  - `hotspot/user/bootstrap.php`
  - `hotspot/user/helpers.php`
  - `hotspot/user/data.php`
  - `hotspot/user/actions.php`
  - `hotspot/user/render.php`
- **Backup** versi lama disimpan sebagai `hotspot/user.old.php`.
- **Pemisahan CSS/JS**:
  - CSS dipindah ke `hotspot/user/css/users.css`.
  - JS dipindah ke `hotspot/user/js/users.js`.
- **Perbaikan path file** agar konsisten dengan struktur folder baru:
  - Print detail/used sekarang di `hotspot/print/`.
  - `aload_users` berada di `hotspot/user/`.
  - Semua include menggunakan `__DIR__` agar aman di Docker.
- **Perbaikan path DB/logs** supaya mengarah ke root proyek:
  - `db_data` dan `logs` tidak lagi mengarah ke `hotspot/`.
- **Debug loader** di `hotspot/users.php` untuk menampilkan error saat `?debug=1`.
- **Session guard**: `session_start()` dibungkus `session_status()` agar tidak notice saat session sudah aktif.

**File terkait:**
- [hotspot/users.php](hotspot/users.php)
- [hotspot/user/bootstrap.php](hotspot/user/bootstrap.php)
- [hotspot/user/helpers.php](hotspot/user/helpers.php)
- [hotspot/user/data.php](hotspot/user/data.php)
- [hotspot/user/actions.php](hotspot/user/actions.php)
- [hotspot/user/render.php](hotspot/user/render.php)
- [hotspot/user/css/users.css](hotspot/user/css/users.css)
- [hotspot/user/js/users.js](hotspot/user/js/users.js)
- [hotspot/user/aload_users.php](hotspot/user/aload_users.php)
- [hotspot/print/print.detail.php](hotspot/print/print.detail.php)
- [hotspot/print/print.used.php](hotspot/print/print.used.php)

### 6.7 Users – Retur, Tombol Clear, dan Print Retur (2026-01-26)
- **Retur**:
  - Menampilkan label **“Retur dari”** pada baris RETUR (bukan First login).
  - Menjaga list RETUR tetap tampil meski hanya ada di history (tidak hilang saat filter).
- **Print RETUR**:
  - Aksi print RETUR dipindah ke endpoint baru **hotspot/print/print.retur.php**.
  - Template memakai style kecil agar konsisten dengan voucher kecil.
- **Search Clear**:
  - Tombol `X` dibuat stabil dengan class `is-visible` dan tidak hilang saat filter.

**File terkait:**
- [hotspot/print/print.retur.php](hotspot/print/print.retur.php)
- [hotspot/user/data.php](hotspot/user/data.php)
- [hotspot/user/render.php](hotspot/user/render.php)
- [hotspot/user/js/users.js](hotspot/user/js/users.js)
- [hotspot/user/css/users.css](hotspot/user/css/users.css)

## 7) Update Terbaru (Refactor Laporan & Perapihan Print 2026-01-27)
### 7.1 Refactor Struktur Layanan Laporan
- Semua endpoint layanan laporan dipusatkan ke **report/laporan/services/**.
- **report/selling.php** disederhanakan: hanya entrypoint ke laporan (`data.php` + `render.php`).
- Endpoint legacy dihapus untuk menghilangkan 404/410:
  - report/sync_sales.php, report/sync_stats.php, report/live_ingest.php, report/hp_save.php, report/settlement_manual.php
  - process/usage_ingest.php, process/sync_usage.php
  - wrapper print lama di report/laporan/print/*
- **tools/settlement_log_ingest.php** dipindahkan ke **report/laporan/services/settlement_log_ingest.php**.
- **report/sales_summary_helper.php** dipindahkan ke **report/laporan/sales_summary_helper.php**.

### 7.2 Perapihan & Sinkronisasi Rute MikroTik
- Script MikroTik (CleanWartel) diarahkan ke endpoint baru **report/laporan/services/**.
- URL fetch dipastikan membawa `session` yang valid.

### 7.3 Perbaikan Allowlist & Docker
- .htaccess ditambahkan allowlist IP baru:
  - **172.18.0.1**
  - **10.0.0.6**
- docker-compose.yml dirapikan:
  - mapping layanan baru **report/laporan/services/** dan **report/print/**
  - mapping endpoint yang sudah dihapus ikut dibersihkan.

### 7.4 Audit & Perhitungan Retur (Masukan-26)
- **Retur dihitung sebagai uang masuk (net)**, tetapi **tidak dihitung sebagai qty laku**.
- Perhitungan audit manual setoran/qty dipisahkan agar tidak tercampur antar status.
- Summary blok & profil di rekap disesuaikan agar konsisten dengan logika baru.

### 7.5 Penyempurnaan Print Rekap & Rincian
- **report/print/print_rekap.php**:
  - Retur masuk ke total uang, qty laku hanya dari status laku.
  - Rekap blok lebih stabil dan konsisten dengan audit manual.
- **report/print/print_rincian.php**:
  - Perbaikan include path root agar tidak blank.
  - Normalisasi nama blok/profile agar konsisten.
  - Filter status diperluas: **ready, online, terpakai, rusak, retur**.
  - View READY disederhanakan (tanpa kolom waktu/IP/MAC/uptime/bytes).
  - Judul print disesuaikan:
    - Online → **List Pemakaian Online**
    - Terpakai → **List Pemakaian Voucher**
    - Rusak → **List Voucher Rusak**
    - Retur → **List Voucher Yang Retur**
    - Ready → **List Username Ready**
- Disediakan kompatibilitas wrapper **report/print_rincian.php**.

### 7.6 Sinkronisasi Status “Terpakai” & Retur
- Status **TERPAKAI** mencakup retur untuk kebutuhan list/print pemakaian.
- Data per blok/profil hanya menghitung qty **laku** (bukan retur).

### 7.7 Perubahan UI/Label pada Users
- Tombol:
  - **Print Status** → **Print Kode**
  - **Print Blok** → **Print Kode**
  - **Retur** → **Print List**
- Dropdown filter READY diganti menjadi **Voucher Baru**.
- Print button diarahkan ke rute baru di **report/print/print_rincian.php**.

### 7.8 Template Print Retur Disamakan
- **hotspot/print/print.retur.php** disamakan gaya dan struktur dengan **voucher/print.php**:
  - Fallback DB untuk **comment/validity/profile**
  - Tag **(Retur)** wajib tampil
  - Dukungan **Download PNG** (html2canvas) + link unduh di toolbar

### 7.9 Popup “Konfirmasi Tindakan” (Print)
- Perbaikan JS/CSS agar tombol print di popup selalu bisa diklik:
  - `data-url` disimpan konsisten.
  - `window.open` fallback ke redirect jika popup diblokir.
  - Z-index & pointer-events dipastikan aktif.

### 7.10 Tombol Restore Selalu Tampil
- Menu backup/restore disesuaikan agar **tombol Restore selalu muncul** (sesuai permintaan uji coba).

### 7.11 Pembersihan Endpoint & File Tak Terpakai
- **hotspot/save_logout_time.php** dihapus karena tidak dipakai lagi.
- Stubs/endpoint lama dihapus agar tidak ada 404/410.

### 7.12 Ringkasan Akhir
- Struktur layanan rapi, rute konsisten, dan dokumen ter-update.
- Logika audit & retur konsisten di laporan dan print.
- UI/print lebih jelas dan minim kebingungan.

### 7.13 Penyempurnaan Print List (Blok & Profil)
- **Print List** menggantikan label **Print Bukti** pada tombol saat blok dipilih.
- Header print menampilkan **Blok tanpa prefix** (contoh: `BLOK-A` → `A`).
- Jika filter profil dipilih, header menampilkan **Profile 10/30 Menit**.
- Filter **profil** diteruskan ke print dan dipakai untuk penyaringan data:
  - Jika profil 30 dipilih dan data kosong, print tampil kosong (tidak bocor ke profil 10).
- Saat status **Semua** dan blok dipilih (tanpa filter lain), **Ready disembunyikan** dan **Rekap (Status/Blok/Profile)** tidak ditampilkan.

**File terkait:**
- [report/print/print_rincian.php](report/print/print_rincian.php)
- [hotspot/user/render.php](hotspot/user/render.php)

### 7.14 Penyempurnaan Settlement, Audit, dan Sinkronisasi Rusak (2026-01-30)
- **Settlement manual (log & status):**
  - Log hanya membaca **tanggal yang dipilih** (tidak otomatis memakai log tanggal lain).
  - Menyimpan **tanggal aktif settlement per session** agar log router selalu masuk ke tanggal yang benar.
  - Pesan log dibuat lebih **sopan & manusiawi** (tanpa kata “dipaksa”).
  - Fallback log router tetap ada jika file lokal minim.
- **Ingest log router:**
  - Tanggal log **dikunci** ke tanggal settlement aktif (atau `force_date`), bukan tanggal router.
- **Auto rusak settlement:**
  - Auto rusak berjalan setelah status selesai, tanpa menggantung pada log router yang terlambat.
- **Audit & Print Audit:**
  - **Potongan (Rusak/Invalid)** selalu konsisten dengan **Total Voucher Rusak/Invalid** (nilai sistem).
  - Net/ Potongan tidak lagi mengambil nilai manual yang dapat membuat potongan menjadi 0.
- **Sinkronisasi rusak bulanan/tahunan:**
  - Tool baru untuk menyelaraskan **login_history → sales_history/live_sales** agar angka bulanan/tahunan akurat.
- **UI & popup restore:**
  - Popup restore dibuat **modal dengan dim overlay** dan gaya disamakan dengan popup backup.

**File terkait:**
- [report/laporan/services/settlement_manual.php](report/laporan/services/settlement_manual.php)
- [report/laporan/services/settlement_log_ingest.php](report/laporan/services/settlement_log_ingest.php)
- [report/laporan/js/laporan.js](report/laporan/js/laporan.js)
- [report/audit.php](report/audit.php)
- [report/print/print_audit.php](report/print/print_audit.php)
- [tools/sync_rusak_audit.php](tools/sync_rusak_audit.php)
- [include/menu.php](include/menu.php)

### 7.15 Penyempurnaan Retur/Refund Request & Popup Manajemen (2026-01-31)
- **Popup Retur terpusat (CSS/JS dipisah)**:
  - CSS dipusatkan di **css/popup.css**, JS di **js/popup.js**.
  - Popup backup/restore diperhalus dan konsisten.
  - Reset CSS diperbaiki agar tidak merusak popup dashboard.
- **Konfirmasi/aksi tanpa alert**:
  - Semua konfirmasi retur/refund memakai **MikhmonPopup** (AJAX) tanpa `alert/confirm`.
- **Alur Retur vs Refund dipisah**:
  - **Refund**: wajib **cek kelayakan rusak** → set **RUSAK** → approve request.
  - **Retur**: langsung **approve → retur** tanpa set RUSAK.
  - Aksi `retur_request_mark_rusak` dibatasi untuk refund saja.
- **Kelayakan rusak transparan**:
  - Popup menampilkan kriteria (offline/bytes/uptime/first login) dan alasan.
- **Tabel & badge Retur**:
  - Badge menu Retur selalu tampil; `is-zero` saat 0.
  - Kolom **Jenis** (Retur/Refund) + **Nama** (wajib untuk refund).
- **Tab & filter lengkap**:
  - Pending / Approved / Rejected / Retur / Refund / Semua.
- **Print list**:
  - Print **Refund**, **Retur**, dan **Semua** dalam format list.
  - Tombol print rapi sejajar dengan tab.
- **Tipe request & nama**:
  - `request_type` dan `customer_name` ditambahkan ke schema dan migrasi otomatis.
- **Toggle fitur**:
  - `include/env.php` menambah `retur_request.enabled` dan `retur_request.message`.

**File terkait:**
- [include/menu.php](include/menu.php)
- [css/popup.css](css/popup.css)
- [js/popup.js](js/popup.js)
- [hotspot/user/retur_request.php](hotspot/user/retur_request.php)
- [hotspot/user/actions.php](hotspot/user/actions.php)
- [hotspot/user/helpers.php](hotspot/user/helpers.php)
- [include/env.php](include/env.php)