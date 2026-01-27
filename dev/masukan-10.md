Berikut adalah **Analisa Holistik dan Audit Menyeluruh** terhadap ekosistem modul User (`actions.php`, `bootstrap.php`, `data.php`, `render.php`, `helpers.php`, `users.js` dan file print terkait) serta integrasinya dengan sistem Laporan Keuangan yang telah kita bahas sebelumnya.

Secara umum, Anda telah membangun sistem yang **Sangat Matang**. Logika bisnis Wartel (Retur, Rusak, Pengganti) tertangani dengan sangat detail hingga ke level validasi byte/uptime.

Namun, ada **SATU CELAH KRITIKAL** di `actions.php` yang akan menyebabkan Laporan Keuangan tidak akurat secara *real-time* saat terjadi Retur, dan beberapa potensi konflik kode (Redundansi).

Berikut rincian audit dan solusinya:

---

### 1. 🛑 TEMUAN KRITIKAL: `actions.php` (Wajib Diperbaiki)

**Masalah:**
Pada blok `elseif ($act == 'retur')` (sekitar baris 420-470), script melakukan:

1. Menyimpan log ke `login_history` (status 'retur').
2. Menghapus user lama dari MikroTik.
3. Membuat user baru.
4. **TAPI**, script **LUPA mengupdate status** di tabel `sales_history` dan `live_sales`.

**Dampak:**
Jika operator melakukan Retur jam 10:00, voucher lama masih dianggap "Terjual Normal" di Laporan Harian. Uang di laporan akan terlihat lebih banyak dari fisik (karena Retur seharusnya Gross 0, Net +Harga, tapi karena belum diupdate, sistem menganggapnya Gross +Harga, Net +Harga). Data baru akan benar hanya setelah *sync scheduler* berjalan.

**Solusi:**
Anda wajib menyisipkan query update database di dalam blok `retur`.

**Cari kode ini di `actions.php` (sekitar baris 417):**

```php
          save_user_history($name, $save_data);

          if ($name != '') {
            // ... (KODE UPDATE KEUANGAN HILANG DISINI) ...
          }
        }

        // Hapus voucher lama

```

**GANTI/TAMBAHKAN menjadi:**

```php
          save_user_history($name, $save_data);

          // [FIX INTEGRASI LAPORAN] Update status voucher LAMA agar laporan keuangan akurat
          if ($name != '') {
            try {
              // Update sales_history (Final)
              $stmt = $db->prepare("UPDATE sales_history SET status='retur', is_rusak=0, is_retur=1, is_invalid=0 WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
            
            try {
              // Update live_sales (Pending) - Penting agar Laporan Harian Realtime 'Net' tidak double
              $stmt = $db->prepare("UPDATE live_sales SET status='retur', is_rusak=0, is_retur=1, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }
        }

        // Hapus voucher lama

```

---

### 2. Audit Konflik Fungsi (Redundansi Kode)

**Masalah:**
Beberapa fungsi dideklarasikan berulang kali di file berbeda tanpa pembungkus `if (!function_exists(...))`. Ini berpotensi menyebabkan **Fatal Error: Cannot redeclare function** jika di masa depan Anda menggabungkan include file.

**Temuan Duplikasi:**

1. `formatBytes` ada di `bootstrap.php` dan `print.detail.php`.
2. `uptime_to_seconds` ada di `helpers.php`, `users.js` (versi JS), `print.detail.php`, `print.used.php`.
3. `detect_profile_kind_...` ada di `users.php` (inline) dan `helpers.php`.

**Rekomendasi:**
Di file `bootstrap.php` dan `helpers.php`, pastikan semua fungsi dibungkus.
Contoh perbaikan di `bootstrap.php`:

```php
if (!function_exists('formatBytes')) { // TAMBAHKAN INI
    function formatBytes($size, $precision = 2) {
        // ... kode ...
    }
} // TUTUP

```

Lakukan hal yang sama untuk fungsi di `helpers.php`. Untuk file `print.*.php` yang bersifat *standalone* (dibuka di tab baru), duplikasi tidak masalah, tapi lebih baik jika meng-include `helpers.php` saja.

---

### 3. Audit Validasi Bisnis (`helpers.php` & `users.js`)

**Status: SANGAT BAIK**

Logic validasi "Kelayakan Rusak" Anda sangat aman:

```php
// helpers.php
function resolve_rusak_limits($profile) {
  // ...
  if (10 menit) limits['uptime'] = 180 (3 menit);
  // ...
}

```

Dan di `users.js`, validasi ini dijalankan di sisi klien sebelum request dikirim.

* **Kelebihan:** Mencegah operator curang atau salah klik "Rusak" padahal voucher sudah dipakai download 1GB.
* **Masukan:** Pastikan limit `5 * 1024 * 1024` (5MB) di `helpers.php` dan `users.js` sesuai dengan kebijakan bisnis Anda. Jika Anda ingin lebih ketat, turunkan ke 1MB.

---

### 4. Audit Tampilan & UX (`render.php` & `users.js`)

**Status: BAIK**

* **Action Banner:** Feedback visual (`window.showActionPopup`) sangat membantu operator mengetahui aksi berhasil/gagal.
* **Modal Relogin:** Fitur ini sangat bagus untuk forensik jika ada user komplain kuota habis tapi merasa tidak pakai.
* **Filter Tanggal:**
Di `render.php`, Anda menggunakan logika:
```php
if ($req_show !== 'semua' && !empty($filter_date)) { ... }

```


Ini bagus untuk performa, membatasi data yang ditampilkan hanya untuk hari/bulan yang relevan.

**Masukan Kecil di `render.php`:**
Pada baris 108 (Summary Modal), Anda menampilkan:

```php
<div class="summary-stat">
  <div class="stat-value"><?= (int)$summary_ready_total ?></div>
  <div class="stat-label">Sisa Ready</div>
</div>

```

Pastikan `$summary_ready_total` benar-benar menghitung hanya status `READY` yang belum terjual. Logika di atasnya (baris 225-240) sudah melakukan iterasi RouterOS user dan mengecek status, jadi **angkanya seharusnya akurat**.

---

### 5. Kesimpulan & Langkah Selanjutnya

Sistem ini sudah **95% Siap Produksi**.

**Langkah Terakhir Anda:**

1. **TERAPKAN PATCH `actions.php**` (Poin 1 di atas). Ini wajib agar Laporan Keuangan sinkron.
2. **Bungkus Fungsi Helper** di `bootstrap.php` dan `helpers.php` dengan `if (!function_exists(...))` untuk keamanan jangka panjang.
3. **Deploy & Test:**
* Coba buat voucher.
* Login-kan voucher (biarkan jalan 1 menit).
* Lakukan aksi **RUSAK** -> Cek Laporan Harian (Harus ada di kolom Rusak, Net berkurang).
* Lakukan aksi **RETUR** -> Cek Laporan Harian (Harus ada di kolom Retur, Net bertambah kembali, Voucher lama status Retur, Voucher baru Ready).



Jika tes di atas berhasil, sistem Manajemen User + Laporan Keuangan Anda sudah sempurna dan terintegrasi penuh.