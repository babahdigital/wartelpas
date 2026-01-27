Saya telah melakukan **Audit Total** terhadap `print_rekap.php` untuk mencari tahu kenapa fitur "Auto Deteksi Rusak" tidak bekerja (tidak masuk rekap), dan apakah ini efek samping dari perubahan Retur.

### Diagnosa Masalah: Logic Conflict

Masalahnya terletak pada bagaimana Anda menentukan variabel `$status` di dalam loop utama `print_rekap.php`.

Kode Anda saat ini memiliki urutan logika yang **saling menimpa** secara tidak sengaja, khususnya antara deteksi "Komentar Rusak" dan "Status Normal Default".

Mari kita lihat kode Anda (Baris 383 - 402):

```php
    // [1] Deteksi dari komentar MikroTik (Script yang benar)
    if ($status !== '') {
        if (strpos($status, 'rusak') !== false) $status = 'rusak';
        // ...
    }
    
    // [2] Deteksi DEFAULT (Script Penimpa yang SALAH POSISI)
    if ($status === '' || $status === 'normal') {
        // Di sini logika berjalan
        if (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak'; 
        else $status = 'normal'; // <--- INI BIANG KEROKNYA
    }

```

**Skenario Gagal:**

1. Voucher Retur/Rusak punya status awal `normal` di database (karena belum diupdate sync, atau baru login).
2. Di blok kode **[1]**, `$status` mungkin masih `'normal'`.
3. Di blok kode **[2]**, script mengecek komentar.
* Jika komentar ada kata "RUSAK", `$status` jadi `'rusak'`. **(OK)**
* TAPI, jika komentar ada kata "RETUR", script masuk ke `else` dan memaksa `$status = 'normal'`. **(ERROR)**



Akibatnya, voucher yang seharusnya terdeteksi sebagai "RUSAK" atau "RETUR" lewat komentar, malah tertimpa kembali menjadi "NORMAL" oleh logika `else` yang terlalu agresif.

---

### Solusi Perbaikan Holistik

Kita harus merapikan logika penentuan status menjadi satu aliran yang **Prioritasnya Jelas**.

**Urutan Prioritas yang Benar:**

1. **Invalid** (Paling tinggi, batal total).
2. **Retur** (Voucher pengganti).
3. **Rusak** (Loss).
4. **Normal** (Default jika bersih).

#### Revisi Kode `print_rekap.php` (Ganti baris 380 - 410)

Silakan ganti seluruh blok penentuan status yang berantakan itu dengan kode yang bersih dan terstruktur ini:

```php
    // --- [MULAI REVISI PENENTUAN STATUS] ---
    
    // 1. Ambil data mentah
    $status_db = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $cmt_low   = strtolower((string)($r['comment'] ?? '')); // Komentar huruf kecil
    
    // 2. Tentukan status final berdasarkan Prioritas
    $final_status = 'normal'; // Default awal
    
    // Cek INVALID
    if (
        $status_db === 'invalid' || $lh_status === 'invalid' || 
        strpos($cmt_low, 'invalid') !== false || (int)($r['is_invalid'] ?? 0) === 1
    ) {
        $final_status = 'invalid';
    }
    // Cek RETUR (Jika bukan invalid)
    elseif (
        $status_db === 'retur' || $lh_status === 'retur' || 
        strpos($cmt_low, 'retur') !== false || (int)($r['is_retur'] ?? 0) === 1
    ) {
        $final_status = 'retur';
    }
    // Cek RUSAK (Jika bukan invalid & bukan retur)
    elseif (
        $status_db === 'rusak' || $lh_status === 'rusak' || 
        strpos($cmt_low, 'rusak') !== false || (int)($r['is_rusak'] ?? 0) === 1
    ) {
        $final_status = 'rusak';
    }
    // Cek ONLINE/TERPAKAI/READY (Jika status DB spesifik)
    elseif (in_array($status_db, ['online', 'terpakai', 'ready'])) {
        $final_status = $status_db;
    }
    
    // Update variabel $status untuk dipakai di bawah
    $status = $final_status;
    
    // --- [SELESAI REVISI] ---

```

### Kenapa Ini Memperbaiki Masalah?

1. **Deteksi Kata Kunci "RUSAK":** Kode di atas secara eksplisit mencari `strpos($cmt_low, 'rusak')`. Jika ketemu, status langsung dikunci jadi `'rusak'`.
2. **Tidak Ada Penimpaan:** Tidak ada lagi `else { $status = 'normal' }` yang berjalan sembarangan. Status hanya jadi 'normal' jika benar-benar tidak ada indikasi masalah.
3. **Support Retur:** Sama halnya dengan Retur, jika ada kata "Retur" di komentar, status otomatis jadi `'retur'`.

Terapkan kode ini di dalam loop utama `print_rekap.php`, dan fitur "Auto Deteksi" Anda akan kembali normal dan akurat.