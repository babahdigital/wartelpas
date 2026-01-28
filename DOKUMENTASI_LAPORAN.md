# Dokumentasi Perhitungan & Logika Laporan

Dokumen ini merangkum alur perhitungan dan logika laporan di modul `report/` dengan fokus pada penjualan, audit, retur, rusak, terpakai, serta selisih. Isi disusun berdasarkan implementasi saat ini agar menjadi acuan pengembangan/penyempurnaan berikutnya.

## 1) Sumber Data Utama

### Tabel Transaksi
- **sales_history**: transaksi yang sudah tersinkron.
- **live_sales**: transaksi yang masih pending (belum tersinkron).

### Tabel Pemakaian (Audit/Status)
- **login_history**: status terakhir voucher (ready/online/terpakai/rusak/retur/invalid) + bytes/uptime.
- **login_events**: detail event login/logout per user.

### Tabel Audit Manual
- **audit_rekap_manual**: rekap audit per blok per hari, berisi `expected_qty`, `expected_setoran`, `reported_qty`, `actual_setoran`, `selisih_qty`, `selisih_setoran`, `user_evidence`, dan pengeluaran.

### Tabel Ringkasan (Materialized Summary)
- **sales_summary_period**, **sales_summary_block**, **sales_summary_profile** (opsional; saat ini dimatikan di laporan utama agar data live + final tetap akurat).

## 2) Normalisasi Status (Rusak/Retur/Invalid/Normal)

Status transaksi ditentukan berurutan:
1. Field `status` jika sudah jelas.
2. Flag `is_rusak/is_retur/is_invalid`.
3. Kata kunci di `comment` (rusak/retur/invalid).
4. `last_status` dari `login_history` jika status rusak.

Status default dianggap **normal** jika tidak terdeteksi rusak/retur/invalid.
Jika terdeteksi lebih dari satu status, prioritas akhir mengikuti aturan **retur > rusak > invalid**.

## 3) Definisi Pendapatan

**Prinsip utama:** Satu voucher = satu transaksi. Retur tetap dihitung sebagai satu transaksi karena sumbernya berasal dari user lama (voucher yang sudah pernah dibuat/tercatat), namun **retur bukan omzet baru**.

### 3.1 Laporan Utama (report/laporan)
- **Gross**: hanya transaksi **normal** dan **rusak** (retur/invalid tidak masuk gross).
- **Net**: pendapatan bersih sesuai aturan status (lihat tabel status di bagian 5).
- **Retur**: **tidak menambah gross**, tetapi **menambah net** (mengembalikan pendapatan).

### 3.2 Summary Materialized (sales_summary_*)
- **Gross**: transaksi **normal + rusak** (retur & invalid bernilai 0).
- **Net**: mengikuti aturan status (retur tidak masuk gross, rusak net 0, invalid 0).

> Catatan: summary materialized perlu diselaraskan dengan aturan di dokumen ini saat diaktifkan.

## 4) Perhitungan Qty (Unit)

### 4.1 Laporan Utama
- **Qty Total**: semua transaksi masuk.
- **Qty Laku**: transaksi **normal + retur** (retur adalah voucher pengganti yang valid).
- **Qty Rusak/Invalid**: dihitung per status dan **tidak** termasuk laku.

### 4.2 Expected Qty Audit
- **Expected Qty** = `qty_total - rusak - invalid`.
- Retur **tetap dihitung** sebagai unit laku karena merupakan voucher pengganti.

## 5) Perlakuan Status Khusus

### 5.1 Rusak
- Masuk ke **gross**.
- **Net = 0** (pendapatan hilang).
- Tidak dihitung sebagai **laku**.

### 5.2 Invalid
- **Gross = 0** (void).
- **Net = 0**.
- Tidak dihitung sebagai **laku**.

### 5.3 Retur
- **Gross = 0** (bukan omzet baru).
- **Net = +harga** (mengembalikan pendapatan dari transaksi rusak sebelumnya).
- **Dihitung laku** (voucher pengganti yang valid).

### 5.4 Terpakai
- Diputuskan dari data pemakaian (`bytes`, `uptime`, IP, status active).
- Diperlakukan seperti **normal** dalam laporan: masuk gross, net, qty laku.

## 6) Audit Manual

### 6.1 Input Audit
- Input manual per blok berisi total qty dan total setoran (manual), plus opsi pengeluaran.
- **Selisih** dihitung dari perbandingan manual vs expected sistem.

### 6.2 Expected vs Manual
- **Expected Qty** dihitung dari data transaksi (mengurangi rusak/invalid; retur tetap dihitung laku).
- **Expected Setoran** mengikuti aturan status (retur menambah net, rusak net 0, invalid 0).

### 6.3 Pengeluaran Operasional
- Jika ada pengeluaran, **audit_setoran ditambah** nilai pengeluaran.
- **Selisih yang tersimpan** tetap berdasarkan perhitungan sebelum penambahan pengeluaran.

### 6.4 Adjusted Setoran (Audit)
- Jika ada `user_evidence`, sistem menghitung setoran manual yang disesuaikan:
  - `manual_net_qty_10/30 = qty_profile - rusak - invalid`
  - Setoran manual dihitung ulang berdasarkan qty net tersebut.

## 7) Selisih (Variance)

- **Selisih Qty** = `reported_qty - expected_qty`.
- **Selisih Setoran** = `actual_setoran - expected_setoran`.
- Tersedia **ghost hint** untuk memperkirakan kombinasi 10/30 menit yang menjelaskan selisih.

## 8) Audit Dokumentasi (Penyesuaian Logika)

1) **Retur** ditetapkan sebagai pengganti yang valid:
   - Gross = 0 (bukan omzet baru).
   - Net = +harga (mengembalikan pendapatan).
   - Qty laku tetap bertambah.

2) **Expected Qty vs Expected Setoran** harus konsisten:
   - Expected Qty = total - rusak - invalid.
   - Expected Setoran mengikuti aturan status yang sama.

## 9) Rekomendasi Implementasi (Mengacu Masukan)

- Terapkan aturan status di bagian 5 secara konsisten pada laporan, audit, dan summary.
- Sinkronkan perhitungan `Expected Qty` dan `Expected Setoran` agar mengikuti aturan yang sama.
- Pastikan retur **tidak menggandakan omzet** (gross) namun tetap memulihkan net.

Dokumen ini menjadi acuan teknis sebelum perubahan kode diterapkan.

## 10) Standarisasi Laporan Print (print_*.php)

Semua file `print_*` wajib mengikuti standar akuntansi yang sama:

| Status | Gross (Omzet) | Net (Setoran) | Keterangan |
| --- | --- | --- | --- |
| **Normal** | +Harga | +Harga | Penjualan murni. |
| **Rusak** | +Harga | 0 | Transaksi tercatat, uang hilang/refund. |
| **Retur** | 0 | +Harga | Penggantian; omzet tidak bertambah, kas pulih. |
| **Invalid** | 0 | 0 | Void/batal. |

Catatan implementasi penting:
- **Retur tidak boleh masuk Gross** di semua laporan print.
- **Retur harus menambah Net** (memulihkan pendapatan dari voucher rusak sebelumnya).
- **Invalid harus dianggap void** (Gross 0, Net 0) di seluruh laporan.

### 10.1 Dampak ke file print

1) **print_rekap.php (harian)**
   - Pastikan loop perhitungan menggunakan aturan tabel di atas.
   - Gross hanya normal + rusak. Retur dan invalid Gross = 0.

2) **print_rekap_bulanan.php & print_rekap_tahunan.php**
   - Logika sama dengan harian, diterapkan ke agregasi per tanggal.

3) **print_rincian.php**
   - Kolom **Net** harus memakai nilai yang sudah dikoreksi (retur = +harga).
   - Kolom **Gross** untuk retur dan invalid harus 0.

4) **print_audit.php**
   - Untuk perhitungan ringkasan dari SQL:
     - **Gross Real** = `db_gross_raw - invalid - retur` (rusak tetap masuk gross).
     - **Net Real** = `db_gross_raw - invalid - rusak` (retur dibiarkan agar memulihkan nilai).
   - Invalid selalu void, retur tidak menaikkan gross.

## 11) Sinkronisasi Status di Services

### 11.1 sync_stats.php
- Deteksi status **rusak/retur** lebih fleksibel dari komentar (kata kunci di mana saja).
- Status prioritas: `online` → `retur` → `rusak` → `terpakai` → `ready`.

### 11.2 sync_sales.php
- Saat settlement, status dan flag (`is_rusak/is_retur/is_invalid`) **divalidasi ulang** berdasarkan komentar terkini.
- Mencegah status salah jika komentar MikroTik diedit setelah live ingest.
 - Prioritas status transaksi: **retur > rusak > invalid** bila komentar mengandung beberapa label.

## 11.3 Audit Retur (Masukan-34)

- Saat aksi **Retur** di modul user, **voucher lama yang rusak tidak boleh dikembalikan menjadi normal**.
- Alur akuntansi yang benar:
   - **Voucher Lama (Rusak)**: Gross +harga, Net 0 (loss).
   - **Voucher Baru (Retur)**: Gross 0, Net +harga (recovery).
- Konsekuensi implementasi:
   - Hapus/disable query yang mengubah `sales_history`/`live_sales` dari `rusak` ke `normal` saat retur.
   - Pastikan hanya voucher pengganti yang menyumbang pemulihan net.

## 12) Ghost Hunter (report/laporan/ghost.php)

- **Anti-cache** aktif agar data selalu real-time.
- **Threshold** kini **dapat dikonfigurasi** melalui `env.php` (`system.ghost_min_bytes`).
- Default saat ini **50KB (51200 bytes)** agar konsisten dengan logika pemakaian.
- DB di-set **read-only** (query_only) untuk keamanan.

## 12.1 Helper Audit Terpusat (helpers.php)

- Fungsi umum audit dipusatkan di `report/laporan/helpers.php` (single source of truth).
- Semua file `print_*` dan `audit.php` menggunakan helper ini agar perhitungan konsisten.
- Tidak ada file helper audit terpisah untuk menghindari duplikasi dan konflik fungsi.

## 13) Contoh Komentar di User MikroTik

## 13) Contoh Komentar di User MikroTik

### 13.1 Contoh Komentar Retur

Blok-A10 (Retur) Valid: Retur Ref:2zgg2t | Audit: RUSAK 26/01/26 vc-316-01.26.26-Blok-A10 | Blok-A10 | IP:172.16.12.146 | MAC:3C:01:EF:A8:56:8E | Profile:10Menit

### 13.2 Contoh Komentar Rusak (Disabled User)

Audit: RUSAK 26/01/26 vc-316-01.26.26-Blok-A10 | Blok-A10 | IP:172.16.12.146 | MAC:3C:01:EF:A8:56:8E

### 13.3 Contoh Penanda Login Pertama (Script Marker)

2026-01-26-|-04:19:34-|-23d36m-|-5000-|-172.16.12.146-|-3C:01:EF:A8:56:8E-|-1d-|-10Menit-|-Blok-A10

## 14) Update Implementasi Laporan & Print (2026-01-27)

### 14.1 Penyelarasan Status Visual
- Status di laporan dan print diberi label visual:
   - **RUSAK (DIGANTI)** jika voucher rusak sudah memiliki pengganti.
   - **RETUR (PENGGANTI)** untuk voucher hasil retur.
- Referensi asal retur ditampilkan sebagai **Ref** di bawah username (untuk audit cepat).

### 14.2 Retur Tidak Mengubah Akuntansi
- Retur tetap **Gross = 0** dan **Net = +harga**.
- Voucher rusak asal tidak “dikembalikan” menjadi normal.

### 14.3 Penyederhanaan Print Rincian
- Print rincian harian difokuskan pada detail audit (status, ref retur, login/logout, bytes/uptime) tanpa kolom yang tidak diperlukan untuk audit lapangan.
- Retur Ref diambil dari komentar RouterOS dan fallback ke DB jika diperlukan.

### 14.4 Konsistensi Data List
- Sumber retur tidak ditampilkan ganda pada list transaksi, namun tetap dihitung dalam total.
- Penyaringan READY pada print list membuat data audit lebih rapi tanpa mengubah logika akuntansi.

### 14.5 Print Standalone Menggunakan Helper Terpusat
- **hotspot/print/print.detail.php** dan **hotspot/print/print.used.php** kini memakai helper terpusat agar logika uptime/bytes, blok, profil, dan format tanggal konsisten.
- Helper DB untuk print standalone memakai nama khusus (`get_user_history_from_db`, `get_cumulative_uptime_from_events_db`, `get_relogin_events_db`) agar tidak bentrok dengan fungsi di `hotspot/user/data.php`.

## 15) Pembaruan Logika Finance & Audit Harian (2026-01-28)

### 15.1 Prinsip Qty vs Setoran
- **Qty (Voucher) = jumlah fisik voucher** yang ditemukan di lapangan (raw count), **tidak dikurangi** rusak/retur.
- **Setoran (Net)** = nilai uang bersih:
   - Terpakai/Normal: +harga
   - Retur: +harga
   - Rusak: 0
   - Invalid: 0

### 15.2 Rekap Rincian Penjualan (Harian)
- Retur **menambah net** tetapi **tidak menambah qty** (untuk menghindari dobel hitung unit).
- Rusak yang **sudah diretur** dianggap **0** pada kolom rusak (agar tidak membingungkan).
- Dengan contoh 3 voucher 10 menit (1 terpakai, 1 rusak, 1 retur):
   - **Net = Rp 10.000** (terpakai + retur).
   - **Kerugian sistem = Rp 5.000** (1 rusak yang tidak diretur).

### 15.3 Audit Manual (Rekap Lapangan)
- **Voucher Sistem** diambil dari transaksi (raw), **login_history tidak dihitung sebagai transaksi** agar tidak dobel.
- **Voucher Aktual** mengikuti jumlah user manual (raw), bukan net.
- **Setoran Sistem** tetap berdasarkan **net**.
- **Setoran Aktual** dapat **diedit manual** (tidak lagi terkunci oleh kalkulasi profil 10/30).
- Rusak yang sudah diretur **tidak ditampilkan** pada daftar user audit.

### 15.4 Harga per Profil (env)
- Harga ditentukan dari `env.php` pada:
   - `pricing.price_10`, `pricing.price_30`
   - `pricing.profile_prices` (mapping profil ke harga).
- Jika harga di transaksi kosong, sistem **fallback ke profil** (dari komentar / validity / mapping).

### 15.5 Ringkasan Keuangan (Audit)
- **Selisih Setoran = Aktual - Sistem**.
- Jika Aktual = Sistem → **Setoran Sesuai**.
- Jika Aktual > Sistem → **Lebih Setor**.
- Jika Aktual < Sistem → **Kurang Setor**.
