Halo Pak Abdullah.

Saya telah menganalisa skrip `users.php` Anda secara mendalam. Masalah yang Anda hadapi adalah **Filter Tanggal tidak bekerja** saat Anda memfilter data berdasarkan status (Terpakai/Used, Rusak, dll) atau saat Anda hanya ingin melihat data tanggal tertentu. Data yang muncul malah "keseluruhan" (campur aduk tanggal).

### 🔍 Analisa Masalah (Root Cause)

Masalah utamanya ada pada logika **Filtering Tanggal** di dalam loop foreach `users.php`.

Saat ini, logika filter tanggal Anda bergantung pada `req_status !== 'used'`. Artinya:
**"Jika status ADALAH 'used', MAKA JANGAN filter tanggal."**

Lihat baris kode ini di `users.php` (sekitar baris 859):

```php
// Filter tanggal (harian/bulanan/tahunan) memakai last_used
if ($req_status !== 'used' && $req_show !== 'semua' && !empty($filter_date)) {
    // ... logic filter tanggal ...
}

```

Akibatnya:

1. Saat Anda pilih status **"Sudah Terpakai"** (Used)  Tanggal diabaikan  Semua user terpakai muncul.
2. Saat Anda pilih status **"Semua"** tapi ada tanggal  Filter tanggal jalan, TAPI logika tanggalnya menggunakan `last_used_filter` yang mungkin tidak akurat untuk user yang belum login (Ready).

### 🛠️ Solusi Perbaikan (Logic Fix)

Kita harus mengubah logika filter tanggal agar **SELALU AKTIF** jika user memilih mode `Harian/Bulanan/Tahunan`, terlepas dari apa status vouchernya (Used, Ready, Rusak, dll).

Namun, tantangannya adalah:

* **User READY:** Belum punya tanggal login. Tanggal filternya harus berdasarkan apa? (Biasanya Tanggal Buat/Komentar).
* **User USED/ONLINE:** Punya tanggal login.

**Rekomendasi Strategi Filter Tanggal:**

1. **Jika User LOGIN/LOGOUT (Used/Online/Rusak/Retur):** Gunakan tanggal `last_used` (login terakhir).
2. **Jika User READY (Belum Login):**
* Opsi A: Abaikan filter tanggal (Tampilkan semua Ready).
* Opsi B: Gunakan tanggal dari komentar "Valid ..." jika ada.
* *Saran Saya:* Untuk User READY, sebaiknya **TETAP TAMPIL** jika filter tanggal adalah Hari Ini (agar kasir bisa lihat stok hari ini). Tapi jika filter tanggal kemarin, User Ready (yang belum laku) sebaiknya disembunyikan agar laporan kemarin bersih.



Berikut adalah kode perbaikan untuk `users.php`.

---

### Kode Perbaikan (Ganti Blok Filter Tanggal)

Cari bagian kode ini di `users.php` (Sekitar baris 858 - 868):

```php
    // Filter tanggal (harian/bulanan/tahunan) memakai last_used
    if ($req_status !== 'used' && $req_show !== 'semua' && !empty($filter_date)) {
      if ($status !== 'READY') {
        $date_key = normalize_date_key($last_used_filter, $req_show);
        if ($date_key === '' || $date_key !== $filter_date) {
          continue;
        }
        if ($filtering_by_date) {
          $has_transactions_in_filter = true;
        }
      }
    }

```

**GANTI DENGAN KODE INI:**

```php
    // --- PERBAIKAN LOGIKA FILTER TANGGAL (FIX) ---
    // Aturan: Jika mode Harian/Bulanan/Tahunan dipilih, filter tanggal WAJIB dijalankan.
    if ($req_show !== 'semua' && !empty($filter_date)) {
        
        // 1. User READY (Belum dipakai)
        if ($status === 'READY') {
            // Logika: User Ready biasanya ditampilkan HANYA jika filter tanggal adalah HARI INI
            // Tujuannya agar kasir bisa lihat stok. Tapi kalau lihat laporan kemarin, stok ready tidak perlu muncul.
            $is_today = ($filter_date === date('Y-m-d') && $req_show === 'harian');
            
            // Jika Anda ingin User Ready SELALU MUNCUL apapun tanggalnya, hapus blok 'if' ini.
            // Tapi idealnya, laporan tanggal masa lalu hanya berisi transaksi yang terjadi.
            if (!$is_today && $req_status === 'all') {
               continue; // Sembunyikan Ready jika melihat laporan masa lalu
            }
            // Jika filter khusus status 'ready', biarkan lolos.
        } 
        
        // 2. User TERPAKAI / ONLINE / RUSAK / RETUR (Sudah ada tanggal)
        else {
            // Ambil tanggal acuan (Login Terakhir atau Logout Terakhir)
            $ref_date = $last_used_filter; 
            
            // Normalisasi ke format filter (Y-m-d, Y-m, atau Y)
            $date_key = normalize_date_key($ref_date, $req_show);
            
            // CEK KECOCOKAN TANGGAL
            if ($date_key === '' || $date_key !== $filter_date) {
                continue; // Skip user ini karena tanggalnya tidak cocok
            }
            
            // Tandai bahwa ada transaksi valid di tanggal ini
            if ($filtering_by_date) {
                $has_transactions_in_filter = true;
            }
        }
    }
    // ----------------------------------------------

```

### Penjelasan Perubahan

1. **Hapus Pengecualian `used`:** Saya menghapus syarat `$req_status !== 'used'`. Sekarang, meskipun statusnya "Terpakai", sistem tetap akan mengecek tanggalnya.
2. **Filter yang Ketat:** User yang login tanggal 20 tidak akan muncul saat Anda memfilter tanggal 21. Ini yang Anda inginkan (informasi tidak tergabung).
3. **Handling User Ready:**
* Jika Anda melihat laporan **Hari Ini**, User Ready tetap muncul (agar bisa dipantau/dijual).
* Jika Anda melihat laporan **Kemarin** (masa lalu), User Ready disembunyikan (karena laporan masa lalu fokus pada apa yang *terjadi/terjual*).



### Tambahan Kecil (Optional)

Agar `last_used_filter` lebih akurat, pastikan variabel ini mengambil data yang benar sebelum masuk ke filter di atas. Kode Anda yang sekarang sudah cukup baik untuk ini:

```php
// Kode Anda (Baris 854):
$last_used_filter = $hist['last_login_real'] ?? ($hist['logout_time_real'] ?? ($hist['login_time_real'] ?? ($hist['first_login_real'] ?? '-')));

```

Ini sudah oke. Dia akan mencari tanggal Login Terakhir  Logout Terakhir  Login Pertama.

Silakan terapkan perubahan blok kode di atas, lalu coba filter lagi berdasarkan tanggal hari ini dan status "Terpakai". Seharusnya datanya sekarang akurat sesuai tanggal.