# Changelog

Semua perubahan penting yang ter-track untuk repository WartelPas dicatat di file ini.

## 2026-03-05

### Ringkasan
- Menyelaraskan total Gross pada print periodik agar konsisten dengan laporan utama untuk periode bulanan dan tahunan.
- Menjaga status settlement/pending tetap tampil sebagai informasi operasional tanpa menggeser total Gross periodik.
- Menguatkan script deploy agar lebih aman pada lingkungan bind-mount, termasuk sinkronisasi ownership dan permission handling best-effort.
- Menambahkan warning visual pada editor Popup Informasi ketika jadwal selesai sudah lewat, agar operator langsung tahu popup sedang kadaluarsa.

### Dampak
- Parity laporan vs print untuk harian/bulanan/tahunan sudah konsisten.
- Proses deploy lebih tahan terhadap kegagalan non-fatal terkait `chmod/chown` di host produksi.
- Troubleshooting popup login lebih cepat karena status jadwal expired terlihat langsung di UI admin sebelum simpan.

### Verifikasi Produksi
- Restart service dilakukan sebelum validasi akhir.
- Hasil parity final:
  - DAILY: total=36 ok=36 fail=0 parse_fail=0
  - MONTHLY: total=3 ok=3 fail=0 parse_fail=0
  - YEARLY: total=1 ok=1 fail=0 parse_fail=0
  - AUDIT: total=0 ok=0 fail=0 parse_fail=0
- Status: ALL_PARITY_CHECKS_PASSED.

### Referensi Commit
- 2e77818 — Align monthly and yearly print gross totals with main reports
- 8626cae — Harden deploy script permission handling and bind-mount ownership sync
