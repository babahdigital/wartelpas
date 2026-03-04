# SOP Backup & Deploy Wartelpas

Dokumen ini menjadi prosedur wajib sebelum deploy, terutama saat memakai mode `--clean` atau `--strict`.

## 1) Prinsip Wajib

1. **Selalu backup sebelum deploy clean/strict.**
2. **Jangan lanjut clean jika backup belum valid.**
3. **Simpan jejak waktu backup (timestamp) untuk rollback cepat.**

## 2) Checklist Pra-Deploy

1. Pastikan login admin/superadmin aktif.
2. Jalankan backup gabungan DB utama + DB aplikasi:
   - Endpoint: `tools/backup_complete.php`
   - Atau dari menu Tools (Backup DB + Backup Konfigurasi).
3. Verifikasi backup harian valid:
   - Endpoint status: `tools/backup_status.php`
   - Pastikan `has_today=true` dan `valid_today=true`.
4. Catat nama file backup terbaru untuk rollback.
5. Lanjut deploy hanya jika semua poin di atas lolos.

## 3) Deploy Aman (Default)

Gunakan mode default script (aman):
- `STRICT=ON`
- `CLEAN=OFF`

Contoh:

```bash
./deploy_wartelpas_production.sh --no-cache --recreate
```

## 4) Deploy Clean (Hanya Jika Perlu)

Mode clean hanya untuk kasus tertentu (mis. source kotor/parah). Wajib setelah backup valid.

```bash
./deploy_wartelpas_production.sh --clean --strict --no-cache --recreate
```

## 5) Guard yang Sudah Aktif di Script Deploy

- Backup runtime otomatis dilakukan sebelum mode clean/strict.
- Backup disimpan per timestamp di folder backup runtime.
- Integritas backup runtime diverifikasi sebelum langkah destruktif (`rm -rf`).
- Retensi backup runtime menjaga snapshot lama tetap tersedia.

## 6) Verifikasi Pasca-Deploy

1. Cek service: `docker compose ps`.
2. Cek endpoint origin + public (root/admin/session).
3. Uji fitur kritis:
   - login admin,
   - laporan & print,
   - settlement,
   - tools backup.

## 7) Rollback Singkat

1. Pilih file backup DB terakhir yang valid.
2. Jalankan `restore_db.php` dan/atau `restore_app_db.php`.
3. Restart container aplikasi.
4. Uji ulang endpoint kritis.
