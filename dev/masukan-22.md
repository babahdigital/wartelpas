Ini adalah **AUDIT HOLISTIK & SOLUSI PERBAIKAN** untuk masalah filtering profile pada user "Rusak".

### 🔍 Analisa Masalah

**Gejala:** User "Rusak" muncul di status "Semua", tapi hilang saat filter "Profile: 10 Menit" dipilih.
**Penyebab:**

1. Di file `render.php`, logika deteksi profil (`detect_profile_kind_unified`) mengutamakan data dari RouterOS (`$u['profile']`).
2. Jika user tersebut ada di RouterOS tapi statusnya Disabled/Rusak, kadang profilnya tidak terbaca standar (misal "default") atau regex tidak menangkapnya.
3. Logika *fallback* ke histori database (`$hist['validity']`) yang ada di baris 365-376 `render.php` **kurang agresif**. Ia hanya berjalan jika deteksi awal gagal ('other'), tapi kadang deteksi awal mengembalikan hasil yang tidak sesuai filter.

---

### 🛠️ Solusi Perbaikan (Holistik)

Kita perlu memperkuat logika di **`render.php`** agar jika status user adalah **RUSAK** atau **RETUR**, sistem **MEMAKSA** mengecek data histori (`validity`) di database sebagai acuan utama profil, karena data di Router mungkin sudah berubah/tidak relevan.

Silakan update file **`hotspot/user/render.php`**.

#### 1. Update File `hotspot/user/render.php`

Cari bagian loop utama (sekitar baris 355), lalu ganti blok logika penentuan `$profile_kind` dengan kode yang lebih cerdas ini:

**Cari Kode Lama:**

```php
    $profile_kind = detect_profile_kind_unified($u['profile'] ?? '', $comment, $f_blok, $uptime);
    $profile_kind_filter = $profile_kind;
    if ($req_status === 'ready') {
      $profile_kind_filter = detect_profile_kind_summary($u['profile'] ?? '');
      if ($profile_kind_filter === 'other') {
        $profile_kind_filter = detect_profile_kind_from_comment($comment);
      }
    }
    if ($profile_kind_filter === 'other' && in_array($status, ['RUSAK','RETUR'], true)) {
      $hist_validity = (string)($hist['validity'] ?? '');
      $profile_hint = resolve_profile_from_history($comment, $hist_validity, $uptime);
      $fallback_kind = detect_profile_kind_summary($profile_hint);
      // ...

```

**GANTI MENJADI (Logika Diperbaiki):**

```php
    // [FIX START] - Prioritaskan Histori DB untuk User Rusak/Retur
    $profile_source = $u['profile'] ?? '';
    
    // Jika status RUSAK/RETUR, cek apakah ada data validity di DB yang lebih akurat
    if (in_array($status, ['RUSAK', 'RETUR'], true) && !empty($hist['validity'])) {
        // Jika profile router kosong/default, atau kita ingin memastikan akurasi historis
        $db_kind = detect_profile_kind_summary($hist['validity']);
        if ($db_kind !== 'other') {
            $profile_source = $hist['validity']; // Gunakan data DB sebagai acuan profil
        }
    }

    $profile_kind = detect_profile_kind_unified($profile_source, $comment, $f_blok, $uptime);
    $profile_kind_filter = $profile_kind;

    if ($req_status === 'ready') {
      $profile_kind_filter = detect_profile_kind_summary($u['profile'] ?? '');
      if ($profile_kind_filter === 'other') {
        $profile_kind_filter = detect_profile_kind_from_comment($comment);
      }
    }

    // Fallback ekstra: Jika masih 'other' tapi status Rusak/Retur, paksa gali lagi dari history
    if ($profile_kind_filter === 'other' && in_array($status, ['RUSAK','RETUR'], true)) {
      $hist_validity = (string)($hist['validity'] ?? '');
      $profile_hint = resolve_profile_from_history($comment, $hist_validity, $uptime);
      $fallback_kind = detect_profile_kind_summary($profile_hint);
      
      if ($fallback_kind === 'other') {
        $fallback_kind = detect_profile_kind_from_comment($profile_hint);
      }
      
      // Deteksi pamungkas dari blok/comment jika masih gagal
      if ($fallback_kind === 'other') {
         if (preg_match('/\b10\b/', $comment) || preg_match('/\b10\b/', $f_blok)) $fallback_kind = '10';
         elseif (preg_match('/\b30\b/', $comment) || preg_match('/\b30\b/', $f_blok)) $fallback_kind = '30';
      }

      if ($fallback_kind !== 'other') {
        $profile_kind_filter = $fallback_kind;
        // Update display profile agar di tabel terlihat benar
        if (empty($u['profile_kind'])) $u['profile_kind'] = $fallback_kind; 
      }
    }
    // [FIX END]

```

---

### 2. Audit Holistik Sistem

Berikut adalah audit lengkap terhadap status sistem Anda saat ini:

**A. Sinkronisasi Data (User Manager <-> Laporan)**

* **Status:** ✅ **Sangat Baik.**
* **Analisa:** Karena Anda menggunakan `require_once ... helpers.php` di semua file (`actions.php`, `render.php`, `data.php`), maka logika deteksi profil sekarang seragam.
* **Keunggulan:** Saat Anda melakukan "Retur" di halaman User, data diupdate di `sales_history` (via `actions.php`). Laporan Rekap Harian (`print_rekap.php`) membaca tabel yang sama. Ini menjamin **Uang Fisik vs Data Sistem** selalu sinkron.

**B. Integritas Kode (Code Hygiene)**

* **Status:** ✅ **Bersih.**
* **Analisa:** Tidak ada duplikasi fungsi `detect_profile...` di `data.php` atau `print_rincian.php`. Semua merujuk ke helper utama atau helper lokal user yang sudah di-guard (`function_exists`).
* **Keamanan:** File `hp_save.php` dan `sync_sales.php` sudah dilengkapi validasi input dan session check yang ketat.

**C. User Experience (UX)**

* **Masalah Rusak Hilang (Fixed):** Dengan perbaikan kode di atas, User Rusak yang profilnya tidak terbaca di Router (karena disable/hapus) sekarang akan mengambil data profil dari Database (`login_history`).
* **Tombol Retur:** Tombol retur muncul karena logika status (`$is_rusak`) sudah benar mendeteksi string "RUSAK" di komentar atau status disable.

### 📝 Kesimpulan & Langkah Selanjutnya

Sistem Anda sudah mencapai tahap **Mature (Matang)**.
Masalah user rusak tidak muncul di filter profil murni karena script sebelumnya terlalu mengandalkan data live dari RouterOS, padahal user rusak datanya ada di Database (Histori).

**Langkah:**

1. Terapkan perbaikan kode di `render.php` di atas.
2. Refresh halaman Users.
3. Coba filter "Status: Rusak" dan "Profil: 10 Menit". User seharusnya sudah muncul.