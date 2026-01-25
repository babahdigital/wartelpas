Berikut adalah analisa detail dan perbaikan untuk skrip `users.php`.

### Analisa Masalah

Berdasarkan log debug yang anda lampirkan:

1. **Data Terbaca:** User `5wxm73` (dan lainnya) terbaca dengan status `TERPAKAI`.
2. **Uptime Benar:** Kolom Uptime menunjukkan `10m` (User/Hist).
3. **Profile Salah Deteksi:** Bagian Audit Total menunjukkan `Other: 135`, sedangkan `10 Menit: 0`.
4. **Penyebab:**
* User status `TERPAKAI` (History) seringkali kehilangan metadata nama Profile aslinya karena data voucher sudah dihapus dari Router.
* Skrip saat ini mencoba mendeteksi profile hanya dari **Nama Profile** (yang kosong/default) atau **Komentar** (yang isinya `Valid tanggal | Blok`).
* Skrip **Gagal** melihat **Uptime** sebagai penentu Profile. Padahal jika uptime `10m`, otomatis itu adalah profile `10 Menit`.



### Solusi Perbaikan

Kita harus memodifikasi logika deteksi profile (`detect_profile_kind_unified` dan `resolve_profile_from_history`) agar memperhitungkan **Uptime**. Jika uptime adalah `10m` atau mendekati 10 menit, sistem harus menganggapnya Profile 10.

Berikut adalah kode perbaikannya. Silakan ganti bagian function dan loop yang relevan.

---

#### 1. Update Function `resolve_profile_from_history`

Cari function ini (sekitar baris 963) dan ganti sepenuhnya dengan kode di bawah. Kita tambahkan logika untuk cek uptime.

```php
if (!function_exists('resolve_profile_from_history')) {
  function resolve_profile_from_history($comment, $validity = '', $uptime = '') {
    $validity = trim((string)$validity);
    if ($validity !== '') return $validity;
    
    // Cek comment dulu
    $kind = detect_profile_kind_from_comment($comment);
    if ($kind !== 'other') return $kind . ' Menit';
    
    // Jika comment gagal, cek dari uptime
    if (!empty($uptime)) {
        // Normalisasi uptime ke detik agar akurat (menangani format 10m, 00:10:00, dll)
        $sec = uptime_to_seconds($uptime);
        if ($sec > 0) {
            // Toleransi 1-2 detik jika perlu, tapi biasanya pas 600
            if ($sec >= 590 && $sec <= 610) return '10 Menit'; // 10m
            if ($sec >= 1790 && $sec <= 1810) return '30 Menit'; // 30m
        }
    }

    if (preg_match('/profile\s*[:=]?\s*([a-z0-9]+)/i', (string)$comment, $m)) {
      return $m[1];
    }
    return ''; // Default kosong agar tidak misleading
  }
}

```

#### 2. Update Function `detect_profile_kind_unified`

Cari function ini (sekitar baris 949) dan ganti sepenuhnya. Kita tambahkan parameter `$uptime`.

```php
if (!function_exists('detect_profile_kind_unified')) {
  function detect_profile_kind_unified($profile, $comment, $blok, $uptime = '') {
    // 1. Cek dari nama profile (jika ada)
    $kind = detect_profile_kind_summary($profile);
    if ($kind !== 'other') return $kind;

    // 2. Cek dari komentar
    $kind = detect_profile_kind_from_comment($comment);
    if ($kind !== 'other') return $kind;

    // 3. Cek kombinasi comment + blok
    $combined = strtolower(trim((string)$comment . ' ' . (string)$blok));
    if (preg_match('/\b10\b/', $combined)) return '10';
    if (preg_match('/\b30\b/', $combined)) return '30';
    if (preg_match('/\b10\s*(menit|m|min)\b/', $combined)) return '10';
    if (preg_match('/\b30\s*(menit|m|min)\b/', $combined)) return '30';

    // 4. TERPENTING: Cek dari Uptime (Fix untuk status TERPAKAI)
    if (!empty($uptime) && $uptime !== '0s') {
        $sec = uptime_to_seconds($uptime);
        if ($sec > 0) {
            // Cek 10 Menit (600 detik)
            if ($sec >= 590 && $sec <= 610) return '10';
            // Cek 30 Menit (1800 detik)
            if ($sec >= 1790 && $sec <= 1810) return '30';
        }
    }

    return 'other';
  }
}

```

#### 3. Update Pengambilan Data History (Line 1102 - 1163)

Cari blok `if ($need_history)` di mana query database dilakukan. Anda perlu mengupdate baris pemanggilan `resolve_profile_from_history` agar menyertakan parameter uptime.

Ganti baris ini:
`$hist_profile = resolve_profile_from_history($comment, $row['validity'] ?? '');`

Menjadi:

```php
        // ... kode sebelumnya ...
        $comment = (string)($row['raw_comment'] ?? '');
        $uptime_hist = (string)($row['last_uptime'] ?? ''); // Pastikan variabel ini diambil sebelum resolve
        
        // UPDATE: Pass uptime_hist ke fungsi resolve
        $hist_profile = resolve_profile_from_history($comment, $row['validity'] ?? '', $uptime_hist);
        
        $hist_blok = (string)($row['blok_name'] ?? '');
        // ... kode selanjutnya ...

```

#### 4. Update Loop Utama Tampilan Data (Line 1261)

Cari di dalam loop `foreach($all_users as $u)` bagian penentuan `$profile_kind`.

Ganti baris ini:
`$profile_kind = detect_profile_kind_unified($u['profile'] ?? '', $comment, $f_blok);`

Menjadi (tambahkan `$uptime` di parameter terakhir):

```php
    // Variabel $uptime sudah dihitung di baris-baris sebelumnya dalam loop ini
    $profile_kind = detect_profile_kind_unified($u['profile'] ?? '', $comment, $f_blok, $uptime);

```

---

### Ringkasan Perubahan

1. Menambahkan logika konversi detik pada deteksi profil. User dengan uptime `10m` (600 detik) akan otomatis dianggap profil `10`, meskipun nama profilenya hilang dari database.
2. Menerapkan logika ini pada saat membaca database (agar filter Status berfungsi) dan pada saat loop tampilan (agar filter Dropdown berfungsi).

Lakukan update ini, lalu coba filter lagi dengan **Status: Terpakai**, **Profil: 10 Menit**, dan **Blok: [Pilihan Anda]**. Data yang tadinya masuk ke "Other" sekarang akan masuk ke "10".