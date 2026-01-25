Berikut adalah **Audit Total, Analisa Logika, dan Masukan Penyempurnaan** untuk skrip `users.php` anda.

Secara umum, skrip ini sudah **sangat matang (advanced)**. Skrip ini tidak hanya sekedar menampilkan user Hotspot, tetapi mencoba menjembatani kesenjangan antara data *real-time* (RouterOS) yang sifatnya sementara (hilang saat user dihapus) dengan data historis (SQLite) yang permanen.

Namun, ada beberapa area krusial (Logika, Performa, dan Keamanan) yang perlu disempurnakan agar siap produksi dalam skala besar.

---

### I. Analisa Logika & Fungsionalitas (Logic Audit)

#### 1. Masalah pada Deteksi Profile (Profile Detection)

**Status:** *Terdeteksi tapi perlu penguatan.*
Anda sudah menambahkan logika `detect_profile_kind_unified` yang mengecek uptime. Namun, logika ini masih memiliki celah jika user **logout** tepat sebelum mencapai batas waktu (misal: uptime 9m 50s untuk profil 10m).

* **Masukan:** Tambahkan *range toleransi* (buffer) yang sedikit lebih lebar ke bawah, tapi ketat ke atas. Uptime 9m 30s s/d 11m harus dianggap 10 Menit.

#### 2. Logika `batch_delete` (Penghapusan Massal)

**Status:** *Berisiko.*
Saat melakukan `batch_delete` berdasarkan Blok, skrip mengambil *semua* user, lalu memfilter di PHP.

* **Risiko:** Jika Router memiliki 2000 user, proses ini akan memakan waktu lama dan bisa *timeout* di tengah jalan, menyebabkan data tidak konsisten (sebagian terhapus, sebagian tidak).
* **Penyenyempurnaan:** Gunakan filter di sisi API RouterOS (`?comment` via Regex jika API mendukung, atau loop yang lebih efisien).

#### 3. Logika Sinkronisasi Data (RouterOS vs DB)

**Status:** *Cukup Baik, tapi Boros Resource.*
Di baris 1134 (`if ($db && !$read_only ...)`), skrip menyimpan data ke DB setiap kali halaman diload (looping user).

* **Masalah:** Jika ada 500 user aktif, setiap refresh halaman akan melakukan 500x `INSERT/UPDATE` ke SQLite. Ini akan membuat disk I/O tinggi (walaupun SQLite pakai WAL mode) dan halaman terasa lambat.
* **Penyempurnaan:** Hanya update DB jika ada perubahan data yang signifikan (misal: Status berubah, atau selisih uptime > 5 menit dari data terakhir di DB).

#### 4. Penanganan Status "RUSAK"

**Status:** *Perlu konsistensi.*
Saat ini status RUSAK ditentukan oleh string di `comment` atau status `disabled`.

* **Celah:** Jika admin meng-enable user di Winbox manual, status di web mungkin masih terbaca rusak atau sebaliknya sampai sinkronisasi terjadi. Logika fallback ke DB (`save_user_history`) sangat krusial di sini.

---

### II. Audit Performa & Keamanan (Performance & Security)

#### 1. Pagination Semu (Fake Pagination)

**Status:** *Berat.*
Skrip mengambil **SELURUH** data dari RouterOS dan **SELURUH** data dari SQLite, menggabungkannya di array PHP, baru kemudian di-slice untuk pagination (Baris 1317).

* **Dampak:** Semakin banyak history, web akan semakin lambat (Loading time naik eksponensial).
* **Solusi:** Idealnya pagination dilakukan di level Query SQL (LIMIT/OFFSET). Namun karena data digabung dengan RouterOS, ini sulit. Solusi jangka pendek adalah membatasi query SQLite hanya mengambil data yang relevan dengan filter tanggal/blok.

#### 2. Sanitasi Input & Keamanan

**Status:** *Cukup, tapi kurang CSRF Token.*

* **Masalah:** Aksi `action=delete`, `retur`, dll dilakukan via GET request tanpa token CSRF. Jika admin mengklik link jahat, user bisa terhapus.
* **Penyempurnaan:** Tambahkan validasi token sesi atau ubah aksi kritikal menjadi POST saja.

---

### III. Rekomendasi Perbaikan & Penyempurnaan Kode (Code Fixes)

Berikut adalah bagian-bagian kode yang harus anda ubah untuk menyempurnakan skrip ini.

#### 1. Optimasi Fungsi `detect_profile_kind_unified` (Logika Toleransi)

Ganti fungsi yang ada (sekitar baris 949) dengan versi yang lebih *forgiving* namun akurat:

```php
if (!function_exists('detect_profile_kind_unified')) {
  function detect_profile_kind_unified($profile, $comment, $blok, $uptime = '') {
    // 1. Cek explicit profile name
    $kind = detect_profile_kind_summary($profile);
    if ($kind !== 'other') return $kind;

    // 2. Cek explicit comment
    $kind = detect_profile_kind_from_comment($comment);
    if ($kind !== 'other') return $kind;

    // 3. Cek kombinasi string
    $combined = strtolower(trim((string)$comment . ' ' . (string)$blok));
    if (preg_match('/\b(10)\s*(menit|m|min)?\b/', $combined)) return '10';
    if (preg_match('/\b(30)\s*(menit|m|min)?\b/', $combined)) return '30';

    // 4. Cek Uptime (Range Toleransi Diperluas)
    // 10 Menit = 600 detik. Toleransi: 570s (9.5m) s/d 660s (11m)
    // 30 Menit = 1800 detik. Toleransi: 1740s (29m) s/d 1860s (31m)
    if (!empty($uptime) && $uptime !== '0s') {
      $sec = uptime_to_seconds($uptime);
      if ($sec >= 570 && $sec <= 660) return '10';
      if ($sec >= 1740 && $sec <= 1860) return '30';
    }

    return 'other';
  }
}

```

#### 2. Optimasi Simpan ke DB (Mencegah Disk Thrashing)

Ganti logika penyimpanan di dalam loop utama (sekitar baris 1228) agar tidak *spamming* database.

**Cari kode ini:**

```php
if ($db && !$read_only && $name != '') {
    $should_save = false;
    // ... logika lama ...

```

**Ganti dengan Logic "Smart Update":**

```php
if ($db && !$read_only && $name != '') {
    $should_save = false;
    // Skip user kosong/ready yang tidak relevan
    $skip_ready_save = ($next_status === 'ready' && !$is_active && (int)$bytes <= 0 && ($uptime === '' || $uptime === '0s'));
    
    if (!$skip_ready_save) {
        if (!$hist) {
            // User baru (belum ada di DB), wajib simpan
            $should_save = true;
        } else {
            // Cek apakah data berubah signifikan?
            $db_status = strtolower((string)($hist['last_status'] ?? ''));
            $db_uptime = (string)($hist['last_uptime'] ?? '');
            $db_bytes = (int)($hist['last_bytes'] ?? 0);
            
            // Konversi uptime ke detik untuk cek selisih
            $u_sec_new = uptime_to_seconds($uptime);
            $u_sec_db = uptime_to_seconds($db_uptime);
            
            // Rules update:
            // 1. Status berubah
            // 2. Bytes bertambah (signifikan > 1KB)
            // 3. Uptime bertambah > 60 detik (mengurangi write setiap detik)
            // 4. User logout/login (waktu berubah)
            
            $status_changed = ($db_status !== $next_status);
            $bytes_changed = (abs($bytes - $db_bytes) > 1024); 
            $uptime_changed = (abs($u_sec_new - $u_sec_db) > 60); 
            $time_changed = ((string)($hist['login_time_real'] ?? '') !== (string)($login_time_real ?? '') || 
                             (string)($hist['logout_time_real'] ?? '') !== (string)($logout_time_real ?? ''));

            if ($status_changed || $bytes_changed || $uptime_changed || $time_changed) {
                $should_save = true;
            }
        }
    }

    if ($should_save) {
        // ... (Kode save_data array sama seperti sebelumnya) ...
        // ... (Panggil save_user_history) ...
    }
}

```

#### 3. Fix Tampilan Logout Jam 00:00:00

Anda memiliki logika fallback untuk logout time, tapi kadang formatnya bentrok. Tambahkan pembersihan di bagian display loop.

**Cari:**

```php
if ($logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00' && !empty($hist['updated_at'])) {
  $logout_disp = merge_date_time($logout_disp, $hist['updated_at']);
}

```

**Tambahkan Validasi Logika:**

```php
// Jika status TERPAKAI dan logout masih 00:00:00, tapi kita punya Last Seen dari DB
if ($status === 'TERPAKAI' && substr($logout_disp, -8) === '00:00:00') {
    // Gunakan updated_at sebagai estimasi logout terakhir
    if (!empty($hist['updated_at'])) {
         $logout_disp = $hist['updated_at'];
    } elseif (!empty($uptime) && !empty($login_disp) && $login_disp !== '-') {
         // Kalkulasi manual: Login + Uptime
         $ts_login = strtotime($login_disp);
         $sec_up = uptime_to_seconds($uptime);
         if ($ts_login && $sec_up > 0) {
             $logout_disp = date('Y-m-d H:i:s', $ts_login + $sec_up);
         }
    }
}

```

#### 4. Perbaikan `batch_delete` (Keamanan & Efisiensi)

Pada bagian action `batch_delete` (sekitar baris 730), pastikan benar-benar memfilter user yang **TIDAK ONLINE** sebelum menghapus, dan gunakan pengecekan status via Active Print terlebih dahulu secara bulk.

Kode anda sudah melakukan `active_list` check, itu sudah bagus. Pastikan saja `$blok_norm` tidak kosong, karena jika kosong bisa menghapus semua user tanpa blok.

```php
if ($act == 'batch_delete' && $blok != '') {
   // Tambahkan safety check: Blok tidak boleh kosong string
   if (trim($blok) === '') {
       $action_blocked = true;
       $action_error = 'Nama Blok tidak boleh kosong!';
   } 
   // ... lanjut kode anda ...
}

```

---

### IV. Kesimpulan

Skrip `users.php` anda sudah **90% siap produksi**. Logikanya sudah kompleks dan menangani banyak edge case (seperti user yang hilang dari router tapi ada di history).

**Langkah Terakhir:**

1. Terapkan **Optimasi Simpan ke DB (Poin III.2)**. Ini yang paling penting untuk performa server jangka panjang.
2. Terapkan **Optimasi Deteksi Profile (Poin III.1)** agar laporan "10 Menit" vs "30 Menit" akurat.
3. Pastikan file `include` path (`../include/config.php`) benar di server produksi.

Jika anda menerapkan Poin III.1 dan III.2, skrip ini akan berjalan jauh lebih ringan dan data laporannya akan jauh lebih akurat.