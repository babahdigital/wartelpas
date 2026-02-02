# Wartelpas (Hotspot Manager)

Wartelpas adalah aplikasi berbasis PHP untuk manajemen hotspot MikroTik (voucher, profile, laporan, settlement, dan audit). Aplikasi terintegrasi dengan RouterOS API dan SQLite untuk pencatatan transaksi serta monitoring penggunaan.

## Fitur Utama
- Manajemen voucher & profile hotspot.
- Laporan penjualan harian/bulanan/tahunan.
- Audit transaksi & settlement manual.
- Cetak voucher, rekap, dan rincian.
- Retur/Refund request (portal login hotspot).
- Dashboard monitoring (trafik, status online, statistik).

## Arsitektur Singkat
- **Frontend**: PHP + HTML/CSS/JS.
- **API RouterOS**: `lib/routeros_api.class.php`.
- **Database**: SQLite di `db_data/mikhmon_stats.db`.
- **Konfigurasi**: `include/config.php` dan `include/env.php`.
- **Session**: `mikhmon_session/`.
- **Reports**: `report/` dan `report/laporan/services/`.

## Persyaratan
- PHP 7.4+ (disarankan 8.1+).
- SQLite3 & PDO SQLite.
- Akses API ke MikroTik (RouterOS).

## Menjalankan dengan Docker
Proyek ini sudah menyiapkan Dockerfile dan docker-compose.

- Build & run:
  - `docker-compose up -d --build`
- Restart (setelah perubahan config/php):
  - `docker-compose restart`

> Catatan: perubahan PHP tidak akan terbaca jika OPcache diset `validate_timestamps=0` tanpa restart.

## Konfigurasi Utama
- **Router**: `include/config.php`
- **Environment**: `include/env.php`
- **Custom PHP**: `custom.ini` (di-mount ke container)

## Reverse Proxy (Nginx)
Jika memakai Nginx di container lain, letakkan konfigurasi di server block Nginx (lihat referensi: `dev/nginx-utama.conf`). Kompresi dan cache header sebaiknya di Nginx, bukan di .htaccess.

## Integrasi MikroTik
- Walled Garden harus mengizinkan domain aplikasi.
- Pastikan URL di login hotspot menunjuk ke domain yang benar.
- Endpoint penting:
  - `report/laporan/services/sync_usage.php`
  - `report/laporan/services/sync_sales.php`
  - `hotspot/user/retur_request.php`

## Keamanan (Ringkas)
- Konfigurasi sensitif di-*ignore* dari Git.
- Endpoint tertentu dibatasi IP/allowlist.
- Header keamanan aktif via `.htaccess` (jika Apache).

## Troubleshooting
- **Error 500**: cek log Apache/PHP dan log aplikasi di `logs/`.
- **Perubahan tidak aktif**: restart container karena OPcache.
- **Refund/Retur gagal**: pastikan URL login hotspot benar dan Walled Garden mengizinkan domain.

## Struktur Folder Penting
- `hotspot/` : modul hotspot & user.
- `report/` : laporan dan print.
- `voucher/` : template voucher.
- `tools/` : utilitas admin.
- `logs/` : file log runtime.

## Lisensi
Mengikuti lisensi proyek (lihat file LICENSE).