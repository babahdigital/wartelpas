Berikut adalah analisa mendalam dan perbaikan lengkap untuk masalah yang anda hadapi.

### Analisa Masalah

1. **Di `users.php` (Tampilan Utama):**
* **Masalah:** User `2jr39m` terdeteksi secara logika sebagai "30 Menit" (karena masuk filter), tapi di kolom "Profile" tampilannya kosong.
* **Penyebab:** Skrip saat ini hanya menampilkan apa yang ada di kolom `profile` database/router. Jika user sudah dihapus di Router dan hanya tersisa history tanpa nama profile, kolom tersebut akan kosong, meskipun skrip *sudah tahu* itu 30 menit berdasarkan Uptime.
* **Solusi:** Memaksa tampilan kolom Profile menggunakan hasil deteksi (`profile_kind`) jika nama profile aslinya kosong.


2. **Di `print.used.php` & `print.detail.php`:**
* **Masalah:** Saat dicetak, uptime kosong (`0s`).
* **Penyebab:** File print mengambil uptime utamanya dari RouterOS (`$urow['uptime']`). Jika user statusnya `TERPAKAI` (artinya sudah tidak ada di RouterOS list active/user), maka `$urow` kosong. Skrip lupa mengambil fallback data dari database history (`last_uptime`).
* **Solusi:** Menambahkan logika fallback ke database history untuk Uptime dan Bytes.



---

### 1. Perbaikan `users.php` (Memperbaiki Tampilan Kolom Profile)

Cari baris di dalam loop tampilan tabel (sekitar baris 1335) di mana kolom Profile dicetak.

**Cari kode ini:**

```php
<td><span class="badge badge-dark border border-secondary p-1"><?= htmlspecialchars($u['profile']) ?></span></td>

```

**Ganti dengan kode ini:**
(Logika: Jika nama profile kosong, gunakan `profile_kind` yang sudah dideteksi via uptime).

```php
                    <td>
                      <?php 
                        $display_profile = $u['profile'];
                        // JIKA profile kosong TAPI kind terdeteksi (10/30), gunakan kind
                        if (empty($display_profile) && isset($u['profile_kind']) && $u['profile_kind'] !== 'other') {
                          $display_profile = $u['profile_kind'] . ' Menit';
                        }
                      ?>
                      <span class="badge badge-dark border border-secondary p-1"><?= htmlspecialchars($display_profile) ?></span>
                    </td>

```

---

### 2. Perbaikan `print.used.php` (Memperbaiki Uptime Kosong saat Print)

Buka file `print.used.php`. Masalah utamanya ada di logika penentuan variabel `$bytes` dan `$uptime`. Kita harus memprioritaskan data terbesar antara RouterOS (jika ada) dan Database History.

**Cari blok kode ini (sekitar baris 168-173):**

```php
$bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
$bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
$bytes = max((int)$bytes_total, (int)$bytes_active);
$uptime = $urow['uptime'] ?? ($arow['uptime'] ?? '0s');

```

**Ganti TOTAL dengan kode Audit Lengkap ini:**

```php
// --- AUDIT FIX: Fallback ke History Database ---
$bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
$bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
$bytes_hist = (int)($hist['last_bytes'] ?? 0);

// Ambil nilai bytes terbesar (mengatasi jika user sudah hapus di router)
$bytes = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);

$uptime_user = $urow['uptime'] ?? '';
$uptime_active = $arow['uptime'] ?? '';
$uptime_hist = $hist['last_uptime'] ?? '';

// Konversi ke detik untuk perbandingan
$sec_user = uptime_to_seconds($uptime_user);
$sec_active = uptime_to_seconds($uptime_active);
$sec_hist = uptime_to_seconds($uptime_hist);

// Ambil uptime dengan detik terbesar
$max_sec = max($sec_user, $sec_active, $sec_hist);

if ($max_sec == $sec_active && $sec_active > 0) {
    $uptime = $uptime_active;
} elseif ($max_sec == $sec_user && $sec_user > 0) {
    $uptime = $uptime_user;
} elseif ($max_sec == $sec_hist && $sec_hist > 0) {
    $uptime = $uptime_hist;
} else {
    $uptime = '0s';
}

// Fix Tampilan Profile di Print Used (Sama seperti users.php)
if (empty($profile)) {
    // Coba deteksi dari Uptime
    if ($max_sec >= 590 && $max_sec <= 610) $profile = '10 Menit';
    elseif ($max_sec >= 1790 && $max_sec <= 1810) $profile = '30 Menit';
    // Coba deteksi dari History Comment
    elseif (!empty($hist['raw_comment']) && preg_match('/\b(10|30)\s*(menit|m)\b/i', $hist['raw_comment'], $m)) {
        $profile = $m[1] . ' Menit';
    }
}

```

---

### 3. Perbaikan `print.detail.php` (Pencegahan Error Sama)

File ini juga memiliki logika yang sama. Lakukan perbaikan serupa agar saat cetak detail rusak, data uptime tidak hilang.

**Cari blok kode ini (sekitar baris 199-204):**

```php
$bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
$bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
$bytes = max((int)$bytes_total, (int)$bytes_active);
$uptime = $urow['uptime'] ?? ($arow['uptime'] ?? '0s');
$uptime_sec = uptime_to_seconds($uptime);

```

**Ganti TOTAL dengan kode ini:**

```php
// --- AUDIT FIX: Fallback ke History Database ---
$bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
$bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
$bytes_hist = (int)($hist['last_bytes'] ?? 0);
$bytes = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);

$uptime_user = $urow['uptime'] ?? '';
$uptime_active = $arow['uptime'] ?? '';
$uptime_hist = $hist['last_uptime'] ?? '';

$sec_user = uptime_to_seconds($uptime_user);
$sec_active = uptime_to_seconds($uptime_active);
$sec_hist = uptime_to_seconds($uptime_hist);
$max_sec = max($sec_user, $sec_active, $sec_hist);

if ($max_sec == $sec_active && $sec_active > 0) {
    $uptime = $uptime_active;
} elseif ($max_sec == $sec_user && $sec_user > 0) {
    $uptime = $uptime_user;
} elseif ($max_sec == $sec_hist && $sec_hist > 0) {
    $uptime = $uptime_hist;
} else {
    $uptime = '0s';
}
$uptime_sec = $max_sec;

// Fix Profile Detection untuk Print Detail
if (empty($profile)) {
    if ($uptime_sec >= 590 && $uptime_sec <= 610) $profile = '10 Menit';
    elseif ($uptime_sec >= 1790 && $uptime_sec <= 1810) $profile = '30 Menit';
}

```

### Ringkasan Hasil Perbaikan

1. **Tampilan Tabel User:** Kolom profile tidak akan kosong lagi untuk user `2jr39m`. Skrip akan otomatis menulis "30 Menit" jika uptimenya valid (sekitar 30 menit), meskipun data router hilang.
2. **Print Bukti Pemakaian:** Tidak akan muncul `0s` lagi. Skrip sekarang cerdas: jika data router hilang (karena voucher expired/terhapus), ia akan mengambil data `last_uptime` dari database SQLite.
3. **Konsistensi:** Data antara halaman utama dan halaman print sekarang sinkron menggunakan logika fallback yang sama.