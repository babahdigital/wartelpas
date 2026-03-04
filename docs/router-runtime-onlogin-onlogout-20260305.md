# Router Runtime Fix Report (2026-03-05)

Dokumen ini mencatat error produksi yang ditemukan pada alur script profile MikroTik (`on-login` / `on-logout`), perbaikannya, dan hasil smoke test deploy.

## 1) Error yang Ditemukan

### A. `onlogout` hardcoded (tidak dinamis)
- Gejala: file template `tools/onlogout` berisi nilai tetap (`base_url`, `local_base_url`, token, session) dari hasil copy router.
- Dampak: saat environment/session berubah, script profile tidak mengikuti konfigurasi aktif.

### B. `onlogin` tidak konsisten membentuk script log `mikhmon`
- Gejala: data fallback dari `/system script` kadang tidak terbentuk.
- Akar masalah:
  - nama script raw terlalu panjang/rentan gagal,
  - payload transaksi disimpan di `name` saja,
  - parser sinkronisasi lama hanya baca `name`.
- Dampak: fallback sinkronisasi penjualan ke settlement bisa miss jika ingest realtime terganggu.

### C. Deploy smoke test terhenti karena kondisi host
- Gejala 1: warning `tar: .: file changed as we read it` saat backup `db_data` aktif.
- Gejala 2: host deploy tidak punya `php` CLI, sehingga smoke test runtime gagal dieksekusi.

## 2) Perbaikan yang Diterapkan

### A. Rewrite `onlogout`
- File: `tools/onlogout`
- Perubahan:
  - ditulis ulang berbasis placeholder dinamis:
    - `{{BASE_URL}}`
    - `{{LOCAL_BASE_URL}}`
    - `{{USAGE_KEY}}`
    - `{{SESSION}}`
  - alur selaras dengan `onlogin`: update comment/mac user + kirim `usage_ingest` logout (local URL + fallback URL).

### B. Hardening `onlogin`
- File: `tools/onlogin`
- Perubahan:
  - payload transaksi tetap lengkap `date/time/user/price/ip/mac/validity/profile/blok`,
  - nama script dibuat aman dan pendek (`mk-...`) agar tidak gagal karena panjang nama,
  - payload raw disimpan di `source` script, tetap bertag `comment="mikhmon"`.

### C. Hardening parser settlement/sync
- File: `report/laporan/services/sync_sales.php`
- Perubahan:
  - parser baru membaca payload transaksi dari urutan fallback:
    1. `script.name`
    2. `script.source`
    3. `script.comment`
  - query API script ditambah `source` pada `proplist`.

### D. Hardening deploy + smoke test runtime
- File: `deploy_wartelpas_production.sh`
- Perubahan:
  - backup `db_data` live dibuat toleran terhadap warning perubahan file saat dibaca (best-effort snapshot, tidak memutus deploy),
  - smoke test runtime MikroTik otomatis setelah deploy:
    - `router_apply_profiles_runtime.php`
    - `router_audit_runtime.php`
  - jika audit menghasilkan `PROFILE_MISSING_COUNT > 0`, sistem otomatis kirim notifikasi WA melalui `tools/wa_runtime_profile_alert.php` sebelum deploy dihentikan,
  - jika host tidak punya `php`, otomatis fallback menjalankan via container:
    - `docker exec wartelpas php ...`

## 3) Hasil Smoke Test Produksi

Deploy verifikasi produksi (`--strict --no-build --no-recreate`) lulus dengan hasil utama:

- Health endpoint:
  - `ORIGIN_ROOT`: OK
  - `ORIGIN_SESSION`: OK
  - `ORIGIN_ADMIN`: OK
  - `PUBLIC_ROOT`: OK
  - `PUBLIC_ADMIN`: OK
- Runtime script apply:
  - `HOOK_SCRIPT onlogin`: `status=OK`
  - `HOOK_SCRIPT onlogout`: `status=OK`
  - marker valid (`HOOK_MARKER onlogin=1 onlogout=1`)
- Audit profile runtime:
  - target profile `10Menit` dan `30Menit` tervalidasi,
  - `PROFILE_MISSING_COUNT=0`.

## 4) Commit Referensi

- `7226472` — stabilisasi hook `onlogin/onlogout` + parser `sync_sales`
- `74a6218` — hardening backup deploy untuk `db_data` aktif
- `8cb561e` — fallback smoke test deploy via `php` container

## 5) Kesimpulan

Alur script profile MikroTik untuk live pendapatan + settlement sudah distabilkan dari sisi:
- template runtime,
- pembentukan log fallback script,
- parser sinkronisasi,
- dan smoke test otomatis saat deploy.
