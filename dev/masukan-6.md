Berikut adalah **Analisa Holistik dan Menyeluruh** terhadap seluruh ekosistem script pelaporan dan sinkronisasi WartelPas yang Anda lampirkan (`live_ingest`, `usage_ingest`, `sync_sales`, `sync_stats`, `settlement_*`, `audit`, `print_*`).

Secara umum, arsitektur sistem Anda sudah **Sangat Matang (Mature)**. Anda telah menerapkan pola *Real-time vs Final Consistency* dengan sangat baik, di mana data masuk secara *live* (ingest) namun divalidasi ulang saat *settlement* (sync).

Berikut adalah audit mendalam per bagian dan rekomendasi perbaikannya:

---

### 1. Audit Kepatuhan Akuntansi (Accounting Logic)

**Status: VALID & KONSISTEN**

Saya memeriksa logika di `data.php`, `print_rekap.php`, dan `audit.php` terhadap dokumen `DOKUMENTASI_LAPORAN.md`. Logika Anda sudah tepat:

* **Gross (Omzet):** Normal + Rusak. (Retur tidak menambah Gross).
* **Net (Setoran):** Normal + Retur. (Rusak membuat Net 0).
* **Retur:** Dianggap sebagai metode pembayaran pengganti (Non-tunai/Recovery).
* **Invalid:** Void (Gross 0, Net 0).

**Observasi Penting pada `sync_sales.php`:**
Anda melakukan langkah cerdas di baris 265-275:

```php
if ($final_status === 'normal') {
    if (stripos($final_comment, 'invalid') !== false) { ... }
    elseif (stripos($final_comment, 'rusak') !== false) { ... }
    // ...
}

```

**Mengapa ini bagus?** Ini menangani kasus di mana user login (masuk `live_sales` sebagai normal), lalu 1 jam kemudian admin menandainya sebagai "Rusak" di MikroTik. Saat settlement (`sync_sales`), sistem membaca ulang komentar terbaru dan mengoreksi statusnya di `sales_history`. **Ini menjaga integritas akuntansi.**

---

### 2. Audit Alur Data & Komunikasi Antar Script

#### A. Ingest Real-time (`live_ingest.php` & `usage_ingest.php`)

* **Keamanan:** Anda sudah menerapkan IP Allowlist dan Token Check. Ini sangat krusial karena endpoint ini terekspos ke internet/jaringan publik agar bisa diakses MikroTik.
* **Duplikasi:** `live_ingest.php` memiliki pengecekan duplikasi ke `sales_history` dan `live_sales` sebelum insert. Ini mencegah *double counting* jika script `on-login` di MikroTik tereksekusi ganda (hal yang sering terjadi pada koneksi tidak stabil).
* **Masukan:**
* Di `live_ingest.php`, pastikan regex `preg_match` untuk tanggal juga menangani format tanggal MikroTik yang terkadang berubah tergantung versi RouterOS (misal: `jan/02/2026` vs `2026-01-02`). Script Anda saat ini sudah menangani 3 format utama, sudah aman.



#### B. Sinkronisasi & Settlement (`sync_sales.php` & `sync_stats.php`)

* **`sync_sales.php`:** Memindahkan data dari MikroTik Script & `live_sales` ke `sales_history`.
* **Kritikal:** Anda menggunakan `INSERT OR IGNORE` dan kemudian menghapus script di MikroTik. Ini berisiko jika `INSERT` gagal tapi script `remove` tetap jalan.
* **Perbaikan:** Logika Anda sudah memeriksa `$stmt->execute()` sebelum menghapus: `if ($stmt->execute()) { $API->write('/system/script/remove'...`. **Ini Aman.**


* **`sync_stats.php`:**
* Script ini melakukan *update* status user di `login_history` (ready/online/terpakai).
* **Fitur Bagus:** Anda melakukan `log_ready_skip_stats` untuk user yang bytes/uptime 0 agar database tidak penuh sampah user yang belum dipakai.



#### C. Settlement Manual (`settlement_manual.php` & `settlement_log_ingest.php`)

* Mekanisme ini sangat cerdik untuk menghindari *PHP Time Limit*. Anda memicu script di MikroTik, lalu MikroTik yang "melapor balik" (push logs) ke server.
* **Masukan Kecil:** Di `settlement_manual.php`, pastikan file log dibersihkan (`truncate`) dengan benar sebelum memulai proses baru agar log hari kemarin tidak muncul di terminal hari ini. (Kode Anda sudah melakukan ini di baris 260: `@file_put_contents($latestLogFile, "");`).

---

### 3. Temuan Teknis & Rekomendasi (Actionable Insights)

Berikut adalah masukan detail untuk menyempurnakan sistem:

#### 1. Masalah Konsistensi "Ghost Hunter" (`audit.php` vs `ghost.php`)

* **Analisa:** Di `audit.php`, Anda mendeteksi selisih Qty/Rp. Di `ghost.php`, Anda mencari kandidat user hantu.
* **Masalah:** `ghost.php` menggunakan threshold `204800` (200KB) secara hardcoded, sedangkan logika status "Terpakai" di `sync_stats.php` menggunakan `bytes > 50`.
* **Risiko:** Ada kemungkinan user dengan penggunaan kecil (misal 100KB - cuma login WA) dianggap "Terpakai" di laporan audit (selisih muncul), tapi tidak muncul di daftar Ghost Hunter karena threshold terlalu tinggi.
* **Rekomendasi:** Samakan threshold bytes. Jika di `sync_stats` batasnya 50 bytes, sebaiknya di `ghost.php` juga rendah, atau setidaknya di level 50KB, jangan 200KB.

#### 2. Optimasi Performa Laporan (`data.php`)

* **Analisa:** Script `data.php` melakukan `UNION ALL` antara `sales_history` dan `live_sales`. Saat ini oke, tapi jika data mencapai jutaan baris, ini akan lambat.
* **Temuan:** Anda memiliki kode `$use_summary = false;` (dimatikan) di `data.php`.
* **Rekomendasi:** Aktifkan logika *Materialized View* (`sales_summary_*`).
* Saat `sync_sales.php` selesai (Settlement), jalankan fungsi `rebuild_sales_summary($db)`.
* Di `data.php`, jika user meminta laporan bulan lalu (data historis), baca langsung dari tabel summary, jangan scan raw data. Gunakan raw data hanya untuk laporan "Hari Ini".



#### 3. Redundansi Kode Audit (`print_audit.php` vs `audit.php`)

* **Analisa:** Fungsi `calc_audit_adjusted_totals` dan `build_ghost_hint` didefinisikan ulang (copy-paste) di kedua file.
* **Risiko:** Jika Anda mengubah rumus ghost hunter di satu file, file lain bisa lupa diupdate.
* **Rekomendasi:** Pindahkan fungsi-fungsi kalkulasi logika bisnis ini ke `helpers.php` agar terpusat.

#### 4. Penanganan "User Anomali" di Print Audit

* **Analisa:** Di `print_audit.php`, Anda mewarnai baris tabel user.
* **Masukan:** Tambahkan logika visual untuk **User Tanpa Status**. Jika ada user masuk dalam evidence audit (dilaporkan operator) tapi di database statusnya 'ready' (belum login/tidak ada data), beri warna abu-abu atau tanda tanya. Ini indikasi operator salah input username atau user tersebut memang belum dipakai (Ghost Input).

---

### 4. Kesimpulan & Langkah Selanjutnya

Sistem ini **Sangat Layak Produksi**. Logika pertukaran data antara MikroTik dan Server (PHP) sangat robust, menangani kegagalan koneksi, duplikasi data, dan integritas akuntansi dengan baik.

**Apakah script sudah sesuai dokumen?**
**YA.** Script `sync_sales.php` dan `data.php` secara akurat mencerminkan aturan Gross/Net/Retur yang ada di `DOKUMENTASI_LAPORAN.md`.

**Satu-satunya tindakan perbaikan kode (Refactoring) yang saya sarankan sebelum finalisasi:**
Memindahkan logika duplikat kalkulasi Audit ke `helpers.php`.

Apakah Anda ingin saya buatkan update untuk **`helpers.php`** yang memuat fungsi kalkulasi audit tersebut, lalu mengupdate `audit.php` dan `print_audit.php` agar kode lebih bersih? Atau kita lanjut ke pengujian fitur lain?