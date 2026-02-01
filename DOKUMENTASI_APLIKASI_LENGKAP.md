# Dokumentasi Lengkap Aplikasi WartelPas (Mikhmon)

Dokumen ini merangkum **alur aplikasi**, **cara penggunaan**, serta **audit holistik** atas modul utama. Disusun sebagai referensi operasional dan teknis agar konsisten dengan logika bisnis yang berlaku (terutama laporan, audit, retur/rusak, dan settlement).

---

## 1) Gambaran Umum
WartelPas adalah aplikasi berbasis PHP untuk manajemen hotspot MikroTik dengan fokus pada:
- Manajemen voucher (buat, cetak, aktif, rusak, retur)
- Pelaporan penjualan harian/bulanan/tahunan
- Audit manual harian berbasis blok
- Sinkronisasi data RouterOS → SQLite
- Proses settlement harian dan pengiriman laporan

**Komponen utama:**
- UI admin dan operator (web)
- RouterOS API (RouterOSAPI class)
- Database SQLite untuk log dan ringkasan
- Endpoint ingest/sync untuk pemakaian & transaksi

---

## 2) Arsitektur & Struktur Modul
### 2.1 Entry & Session
- **Entry point:** `index.php` → redirect ke `admin.php` untuk login.
- **Session router:** `?session=session_id` menentukan router aktif.
- File session disimpan di `mikhmon_session/`.

### 2.2 Konfigurasi
- Router & profil disimpan di `include/config.php`.
- Konfigurasi lingkungan di `include/env.php` (token, harga, endpoint, fitur toggle).

### 2.3 Database
- SQLite: `db_data/mikhmon_stats.db`.
- Tabel penting:
  - `sales_history`, `live_sales`
  - `login_history`, `login_events`
  - `audit_rekap_manual`
  - `whatsapp_recipients`, `whatsapp_logs`
  - `retur_requests`

### 2.4 Modul Utama
- **dashboard/**: ringkasan KPI, grafik.
- **hotspot/**: user, profil, generate, aktif, host, cookies.
- **report/**: laporan penjualan, audit, print.
- **process/**: aksi backend (add user, retur, rusak, hapus).
- **tools/**: maintenance DB, backup/restore.

---

## 3) Alur Utama Aplikasi (End-to-End)
### 3.1 Alur Voucher (Normal)
1. Operator membuat voucher (generate/user).
2. Voucher dicetak (QR/PNG) dan dibagikan.
3. User login ke hotspot.
4. Router mengirim data login/logout ke endpoint ingest.
5. Data pemakaian masuk ke `login_history` dan `login_events`.
6. Transaksi masuk ke `live_sales` (pending).
7. Saat settlement: data dipindah ke `sales_history`.

### 3.2 Alur Rusak
1. Voucher dinilai rusak (offline + bytes/uptime rendah).
2. Operator set **RUSAK**.
3. **Akuntansi:**
   - Gross +harga
   - Net 0
   - Tidak dihitung sebagai laku

### 3.3 Alur Retur (Pengganti)
1. Voucher rusak yang valid diretur.
2. Sistem membuat voucher pengganti (retur).
3. **Akuntansi retur:**
   - Gross 0
   - Net +harga (mengembalikan pendapatan)
   - Dihitung laku (voucher pengganti valid)
4. Voucher rusak asal **tidak dikembalikan menjadi normal**.

### 3.4 Alur Refund (Permintaan Retur)
1. User mengajukan refund di portal hotspot.
2. Request masuk `retur_requests` (pending).
3. Operator approve/reject.
4. Jika **refund**: sistem cek kelayakan rusak, set RUSAK, lalu approved.
5. Jika **retur**: langsung retur (tanpa set RUSAK).

### 3.5 Alur Settlement
1. Operator menjalankan settlement (manual/terjadwal).
2. Endpoint `sync_sales` memindahkan data dari live ke history.
3. Status & flag transaksi divalidasi ulang dari komentar terkini.
4. Laporan harian siap dicetak/kirim.

---

## 4) Cara Menggunakan Aplikasi (Panduan Operasional)
### 4.1 Login & Pilih Router
1. Buka `admin.php`.
2. Login sebagai operator/admin.
3. Pilih router melalui parameter `?session=...`.

### 4.2 Membuat Voucher
1. Masuk **Hotspot → Generate User**.
2. Pilih profil (10/30 menit) dan blok.
3. Generate dan cetak voucher.

### 4.3 Memantau User
1. Buka **Hotspot → Users**.
2. Gunakan filter status (READY/ONLINE/TERPAKAI/RUSAK/RETUR).
3. Lihat detail relogin/bytes/uptime dari popup detail.

### 4.4 Menangani Voucher Rusak
1. Buka detail user yang diduga rusak.
2. Pastikan kriteria rusak terpenuhi.
3. Klik **Set Rusak**.
4. Sistem menyimpan status dan menyesuaikan laporan.

### 4.5 Retur Voucher
1. Pastikan voucher sudah RUSAK.
2. Klik **Retur**.
3. Voucher baru dibuat, voucher lama tetap rusak.
4. Cetak voucher pengganti.

### 4.6 Laporan Penjualan
1. Buka **Report → Laporan Penjualan**.
2. Pilih tanggal.
3. Cek ringkasan gross/net/retur/rusak/invalid.
4. Gunakan tombol **Print Rekap** atau **Print Rincian**.

### 4.7 Audit Manual Harian
1. Buka **Report → Audit**.
2. Pilih tanggal audit (dari dashboard).
3. Pilih user checklist (terpakai + retur).
4. Sistem mengunci qty sesuai pilihan.
5. Input/setoran aktual & pengeluaran.
6. Simpan audit untuk rekap harian.

### 4.8 Settlement Manual
1. Masuk **Report → Laporan Penjualan**.
2. Klik **Settlement Manual**.
3. Tunggu log proses (popup terkunci).
4. Setelah selesai, status settlement `done`.

### 4.9 WhatsApp Laporan
1. Masuk **System → WhatsApp**.
2. Upload PDF laporan harian (maks 4MB).
3. Pilih tanggal settlement `done`.
4. Kirim laporan ke penerima terdaftar.
5. Cek log pengiriman.

---

## 5) Aturan Akuntansi (Wajib Konsisten)
| Status | Gross | Net | Qty Laku | Catatan |
| --- | --- | --- | --- | --- |
| Normal | +Harga | +Harga | Ya | Penjualan murni |
| Rusak | +Harga | 0 | Tidak | Loss |
| Retur | 0 | +Harga | Ya | Pengganti |
| Invalid | 0 | 0 | Tidak | Void |

**Catatan:**
- Retur **tidak masuk gross**.
- Invalid **selalu 0**.
- Rusak tetap masuk gross tapi net 0.

---

## 6) Audit Holistik (Ringkas & Menyeluruh)
### 6.1 Konsistensi Data
- Semua laporan menggunakan aturan status **retur > rusak > invalid**.
- Helper audit terpusat di `report/laporan/helpers.php`.
- Retur tidak mengubah status rusak asal.

### 6.2 Data Sumber
- Transaksi: `sales_history` + `live_sales`.
- Pemakaian: `login_history` + `login_events`.
- Audit manual: `audit_rekap_manual`.

### 6.3 Risiko & Mitigasi
- **Risiko data ganda:** dedup `username + sale_date`.
- **Non‑Wartel:** filter BLOK wajib pada ingest/sync.
- **Timeout RouterOS:** gunakan `.proplist` dan early response.
- **Schema mismatch:** auto‑migrate kolom saat runtime.

### 6.4 Audit Kas
- **Setoran Sistem** = net (normal + retur, rusak/invalid = 0).
- **Setoran Aktual** bisa diinput manual.
- **Selisih** = aktual − sistem.

### 6.5 Audit Voucher Rusak/Retur
- Rusak tidak langsung otomatis, harus validasi uptime/bytes.
- Retur hanya setelah rusak (kecuali request retur yang valid).
- Retur tidak menambah gross.

---

## 7) Referensi File Penting
- Konfigurasi: `include/config.php`, `include/env.php`
- Router API: `lib/routeros_api.class.php`
- Report & Audit: `report/` dan `report/laporan/helpers.php`
- Hotspot Users: `hotspot/users.php` + `hotspot/user/`
- Print: `report/print/` dan `hotspot/print/`
- Services (sync/ingest): `report/laporan/services/`
- WhatsApp: `system/whatsapp/`

---

## 8) Checklist Operasional Harian
1. Generate voucher sesuai kebutuhan blok.
2. Pastikan login/logout terpantau.
3. Tandai rusak bila memenuhi syarat.
4. Jalankan retur jika diperlukan.
5. Lakukan settlement.
6. Cetak rekap/rincian.
7. Audit manual & simpan rekap.
8. Kirim laporan via WhatsApp.

---

## 9) Catatan Kepatuhan & Keamanan
- Selalu sanitasi input `$_GET`/`$_POST`.
- Jangan tampilkan session ID di UI.
- Gunakan token dari `env.php` untuk endpoint sensitif.
- Aktifkan whitelist endpoint pada `.htaccess`.

---

## 10) Status Dokumen
Dokumen ini menyatukan **alur aplikasi**, **cara penggunaan**, dan **audit holistik** dalam satu referensi. Jika ada perubahan logika bisnis (terutama status, laporan, audit), dokumen ini **wajib diperbarui** agar konsisten.

---

## 11) Pembaruan Terbaru (2026-02-02)
### 11.1 Kurang Bayar
- Audit manual mendukung input **kurang bayar** (nilai + keterangan).
- Rekap harian/bulanan/tahunan serta print audit menampilkan kurang bayar.
- Setoran bersih memperhitungkan kurang bayar (net + kurang bayar).

### 11.2 Login Meta (Nama & Kamar)
- Form login hotspot mengirim meta **nama/kamar/blok/profil/harga** ke endpoint `login_meta.php`.
- Data disimpan di `login_meta_queue` untuk fallback laporan/print.
- **Backfill** tersedia via `report/laporan/services/backfill_meta.php` untuk mengisi data historis ke `login_history/sales_history/live_sales`.

### 11.3 Print Rincian (Revisi)
- Sumber transaksi sesuai laporan: `sales_history` + `live_sales` pending.
- Deduplikasi berdasarkan `full_raw_data/sale_datetime`.
- Ringkasan print rincian menampilkan **Total QTY, Total Omset, V10, V30, Total Rusak**.

### 11.4 Rekap Bulanan/Tahunan
- Normalisasi tanggal agar bulan aktif terbaca walau format raw_date berbeda.
- Urutan tanggal dari muda ke tua, baris tanggal invalid disaring.
