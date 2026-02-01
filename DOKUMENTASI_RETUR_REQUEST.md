# Dokumentasi Fitur Permintaan Retur/Refund Voucher (Retur Request)

## Ringkasan
Fitur Permintaan Retur/Refund memungkinkan user meminta **retur** atau **refund** voucher melalui portal login hotspot. Permintaan disimpan sebagai status `pending` dan **wajib diverifikasi operator** sebelum eksekusi dilakukan. Tujuannya menjaga akurasi audit, memisahkan alur retur vs refund, serta mencegah penyalahgunaan.

## Tujuan & Prinsip
- Retur **tidak otomatis**: harus ada approval operator.
- Refund **mengharuskan** penandaan RUSAK setelah lulus validasi rusak.
- Retur **tidak** menandai RUSAK dan langsung menjalankan aksi retur.
- Validasi rusak/retur tetap mengikuti rule yang sudah ada (status rusak, offline, batas bytes/uptime, dsb).
- Semua keputusan terekam (audit trail).

## Lokasi Fitur (UI)
- **Menu Retur di header** → popup Manajemen Retur (AJAX, realtime)
  - Tab: Pending / Approved / Rejected / Retur / Refund / Semua
  - Kolom jenis (Retur/Refund), nama pemohon, status
  - Tombol aksi hanya untuk status Pending
  - Tombol print: Refund / Retur / Semua (format list)
- **Hotspot → User** tetap terintegrasi untuk aksi data user/voucher.

## Skema Tabel (SQLite)
Nama tabel: `retur_requests`

Kolom minimal:
- `id` INTEGER PRIMARY KEY AUTOINCREMENT
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- `request_date` TEXT (YYYY-MM-DD)
- `voucher_code` TEXT
- `blok_name` TEXT (opsional)
- `request_type` TEXT DEFAULT 'retur' (retur/pengembalian)
- `customer_name` TEXT (wajib untuk refund)
- `reason` TEXT
- `contact_phone` TEXT (opsional)
- `status` TEXT DEFAULT 'pending' (pending/approved/rejected)
- `reviewed_by` TEXT (username operator/admin)
- `reviewed_at` DATETIME
- `review_note` TEXT
- `router_name` TEXT (opsional)
- `source` TEXT DEFAULT 'portal' (portal/operator)

Indeks saran:
- `(status, request_date)` untuk filter cepat

## Alur Data
1) User mengisi form request di halaman login hotspot (retur/refund).
2) Data disimpan ke `retur_requests` dengan status `pending`.
3) Operator membuka popup **Manajemen Retur**.
4) Operator memilih `Approve` atau `Reject`.
5) Jika **Refund**: sistem menjalankan **cek kelayakan rusak** → set RUSAK → tandai request **approved**.
6) Jika **Retur**: sistem **langsung** menjalankan aksi retur → tandai request **approved**.
7) Status permintaan diperbarui dan dicatat `reviewed_by` + `reviewed_at`.

## Perilaku Validasi
- **Refund (pengembalian)** hanya boleh jika:
  - Voucher ditemukan
  - Rule rusak terpenuhi (offline/bytes/uptime sesuai batas)
  - Berhasil set RUSAK
- **Retur**:
  - Voucher ditemukan
  - Menjalankan aksi retur langsung (tanpa set RUSAK)
- **Reject** jika syarat tidak terpenuhi atau data tidak valid

## Integrasi Notifikasi WhatsApp (opsional)
- **Saat request masuk** → notifikasi ke operator/admin
- **Saat approve/reject** → (opsional) notifikasi ke user bila nomor HP tersedia

API yang akan digunakan sesuai dokumentasi `DOKUMENTASI_WHATSAPP_FONNTE.md`.

## Perizinan (ACL)
- Hanya **Operator/Admin** yang dapat melihat dan memproses request.
- User hanya bisa submit request dari portal login (tanpa akses admin).

## Endpoint & Aksi yang Digunakan
1) **Form request** di portal login (HTML login hotspot)
2) **Endpoint simpan**: `hotspot/user/retur_request.php`
3) **Aksi request** (via AJAX):
  - `retur_request_approve`
  - `retur_request_reject`
  - `retur_request_mark_rusak` (refund saja)
4) **Cek kelayakan rusak**: `check_rusak` (sebelum refund disetujui)

## Pengaturan (env.php)
- `retur_request.enabled` → toggle ON/OFF fitur request.
- `retur_request.message` → pesan jika fitur dimatikan.

## Catatan Implementasi
- Popup Manajemen Retur menggunakan UI modern (MikhmonPopup) dan AJAX.
- Badge menu Retur selalu tampil; jika 0 diberi style `is-zero`.
- Kolom “Nama” hanya wajib untuk refund.
- Semua input disanitasi dan kompatibel PHP 7.4+.

## Status Implementasi
- Tabel `retur_requests` beserta kolom `request_type` dan `customer_name` sudah aktif.
- Form request sudah mendukung pilihan Retur/Refund.
- Popup Manajemen Retur sudah berjalan dengan tab filter dan aksi AJAX.
- Print list sudah tersedia untuk Refund/Retur/Semua.
- Toggle fitur via `include/env.php` sudah tersedia.

## Catatan Pembaruan (2026-02-02)
- Fitur **login meta** (Nama/Kamar) tidak mengubah alur retur/refund.
- Validasi rusak/retur tetap mengikuti aturan yang sama.
- Backfill nama/kamar hanya untuk pelaporan, bukan untuk keputusan retur/refund.

---

Jika dokumentasi ini disetujui, tahap implementasi akan mengikuti urutan di atas.
