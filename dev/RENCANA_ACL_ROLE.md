# Rencana Pengembangan ACL & Pemisahan Role (Superadmin vs Operator)

Dokumen ini menjadi checklist dan panduan implementasi ACL di WartelPas (basis Mikhmon v3). Semua langkah di bawah akan menjadi acuan eksekusi perubahan.

## Tujuan Utama
1) Memisahkan peran **Superadmin** dan **Operator**.
2) **Hanya Superadmin** yang boleh mengedit/menambah/menghapus **Session/Router**.
3) Operator hanya untuk operasional harian (monitoring, cetak voucher, lihat laporan yang diizinkan).
4) Semua pembatasan harus **server-side**, bukan hanya UI.

---

## Definisi Role & Hak Akses

### Superadmin (Full Access)
- Semua fitur tanpa pembatasan.
- Akses penuh ke Settings, Tools, System, Editor, Upload Logo.

### Operator (Limited Access)
- Boleh: dashboard, daftar user, status/aktif, cetak voucher, lihat laporan/print non-sensitif.
- Tidak boleh: 
  - Edit/tambah/hapus session/router
  - Akses tools (backup/restore/cleanup)
  - System (reboot/shutdown)
  - Template editor & upload logo
  - Endpoint ingest/sync/settlement

---

## Checklist Implementasi (Akan Dikerjakan)

### 1) Tambah Helper ACL (Server-Side)
- [x] Buat file `include/acl.php`.
- [x] Fungsi `isSuperAdmin()`.
- [x] Fungsi `isOperator()`.
- [x] Fungsi `requireSuperAdmin()` (redirect + exit).
- [x] Fungsi `requireLogin()` (fallback umum jika belum login).

### 2) Login & Session Role
- [x] Update login di `admin.php` untuk set `$_SESSION['mikhmon_level']`.
- [x] Superadmin dari config asli.
- [x] Operator dari kredensial khusus (via `include/env.php`).

### 3) Guard Routing Utama
- [x] `admin.php`: blok akses `id=settings`, `id=editor`, `id=remove-session`, `id=uplogo`, `id=reboot/shutdown` untuk Operator.
- [x] `index.php`: blok akses `hotspot=template-editor`, `hotspot=uplogo`, dan `system` untuk Operator.

### 4) Batasi Folder Sensitif
- [x] `settings/` (semua halaman edit session/router).
- [x] `tools/` (backup/restore/cleanup/clear/delete).
- [x] `report/laporan/services/` (operator diblok saat login; token/allowlist tetap berjalan).
- [x] `system/` (scheduler, reboot/shutdown via admin).

### 5) Sembunyikan Menu (UI Layer)
- [x] `include/menu.php`: hanya tampilkan Settings, Add Router, Editor, Upload Logo, Backup/Restore untuk Superadmin.
- [x] Operator hanya lihat menu yang aman.

### 6) Audit & Validasi
- [ ] Uji akses langsung via URL (operator tidak bisa bypass).
- [ ] Uji workflow operator (login, lihat users, print).
- [ ] Uji workflow superadmin (semua fitur tetap aman).

---

## Daftar File yang Akan Diubah

### File Baru
- `include/acl.php`

### File Utama (Wajib)
- `admin.php`
- `index.php`
- `include/menu.php`

### Folder Sensitif (Tambah guard minimal di entry)
- `settings/`
- `tools/`
- `report/laporan/services/`
- `system/`

---

## Rencana Teknis Detail

### A) File `include/acl.php`
Isi fungsi:
- `requireLogin()` → cek `$_SESSION['mikhmon']`.
- `isSuperAdmin()` → cek `$_SESSION['mikhmon_level'] === 'superadmin'`.
- `isOperator()` → cek `$_SESSION['mikhmon_level'] === 'operator'`.
- `requireSuperAdmin()` → redirect ke `admin.php?id=sessions` jika bukan superadmin.

### B) File `admin.php`
- Setelah validasi login, set role di session.
- Tambahkan gate sebelum load halaman berdasarkan parameter `id`.
- Operator diblok untuk:
  - settings, sessions (edit/hapus), editor, uplogo, system.

### C) File `index.php`
- Tambahkan gate berbasis `$_GET['hotspot']`, `$_GET['system']`, `$_GET['tools']`.
- Operator tidak boleh akses template editor & upload logo.

### D) Folder Sensitif
- Tambah include `include/acl.php` dan panggil `requireSuperAdmin()` di entry point masing-masing folder (minimal di index/entry file).

### E) UI Menu
- Bungkus menu sensitif dengan `if (isSuperAdmin())`.

---

## Catatan Implementasi
- Semua validasi dilakukan **server-side**.
- UI hanya mengikuti role untuk kenyamanan.
- Semua redirect harus `exit;` agar tidak lanjut render.
- Hindari hardcode di `config.php` agar tidak bentrok dengan auto-save.

---

## Status Eksekusi
- [ ] Belum mulai
- [ ] Sedang dikerjakan
- [x] Selesai
