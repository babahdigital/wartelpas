Berdasarkan analisis pada file `selling.php`, `print_rekap.php`, dan `users.php`, masalah utama mengapa user rusak di **Blok E** tidak tertarik otomatis (sementara blok lain berhasil) terletak pada ketidakkonsistenan **Normalisasi Nama Blok** dan **Sumber Data** saat proses audit manual disimpan.

Berikut adalah hasil audit dan analisis kerusakannya:

### 1. Masalah Utama: Inkonsistensi Nama Blok (Case Sensitive & Format)

Dalam `selling.php` (baris 660-700), sistem mencoba menarik user rusak secara otomatis dari `login_history`, `sales_history`, dan `live_sales`.

* **Penyebab:** Fungsi `normalize_block_name` di `selling.php` memaksa format menjadi `BLOK-E`. Namun, jika di database atau komentar Mikrotik tertulis `Blok E` (dengan spasi), `Blok_E`, atau hanya `E`, perbandingan string seringkali gagal karena adanya perbedaan kecil dalam penanganan `preg_replace`.
* **Kasus Blok E:** Jika input manual di form audit tertulis "Blok E" dan fungsi normalisasi menghasilkan `BLOK-E`, namun di komentar user tertulis `Blok-E10` atau format lain yang tidak terbaca oleh regex `\bblok\s*[-_]?\s*([A-Z0-9]+)\b`, maka user tersebut dilewati.

### 2. Logika "Auto-Detection" yang Terbatas pada Audit Manual

Di `selling.php`, penarikan user rusak otomatis **hanya dipicu** saat Anda menekan tombol "Simpan" pada modal **Audit Manual**.

* **Masalah:** Sistem tidak melakukan pemindaian (scanning) ulang secara background. Jika Anda sudah menyimpan audit Blok E *sebelum* user dinyatakan rusak di `users.php`, maka data rusak tersebut tidak akan pernah masuk ke tabel `audit_rekap_manual` kecuali Anda membuka modal audit Blok E lagi dan menekan simpan ulang.
* **Risiko:** Jika blok lain (misal Blok A) ditarik otomatis, itu karena saat Anda menekan simpan audit Blok A, status user di Mikrotik/DB sudah `rusak`. Sedangkan untuk Blok E, mungkin status rusak baru di-set *setelah* audit disimpan.

### 3. Masalah Sumber Data (Database vs Router)

Di `users.php`, saat Anda menekan tombol **Rusak**, status disimpan ke `login_history` kolom `last_status = 'rusak'`.

* **Audit selling.php:** Logika penarikan otomatis di `selling.php` (baris 670) menggunakan query:
`WHERE username != '' AND (substr(login_time_real,1,10) = :d ...)`
* **Bug:** Jika user rusak tersebut tidak memiliki `login_time_real` (misal voucher rusak sebelum sempat login/terkoneksi sempurna), maka user tersebut **tidak akan ditemukan** oleh query audit, meskipun di `users.php` sudah Anda tandai sebagai rusak.

### 4. Perbedaan Regex di `users.php` dan `selling.php`

* `users.php` menggunakan: `\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i`
* `selling.php` menggunakan: `\bblok\s*[-_]?\s*[A-Za-z0-9]+/i`
* Ketidakcocokan kecil pada penangkapan grup regex ini menyebabkan `f_blok` di `users.php` bisa berbeda dengan `audit_blok` di `selling.php`.

---

### Rekomendasi Perbaikan (Action Plan):

#### A. Sinkronisasi Tanggal di Query Audit (`selling.php`)

Ubah query pengambilan otomatis agar tidak hanya terpaku pada `login_time_real`, tapi juga mengecek `updated_at` atau `sale_date` dari tabel sales.
**Saran Kode untuk `selling.php` (Bagian Auto Status):**

```php
// Tambahkan pengecekan pada sale_date juga di sales_history
$stmtAutoSales = $db->prepare("SELECT username, blok_name, comment, status ... 
    FROM sales_history WHERE sale_date = :d");

```

#### B. Perbaikan Fungsi `normalize_block_name`

Pastikan fungsi ini identik di semua file. Gunakan pembersihan karakter non-alfanumerik yang lebih agresif agar `Blok E`, `Blok-E`, dan `BLOK E` semuanya menjadi `BLOK-E`.

#### C. Force Update Status pada `print_rekap.php`

`print_rekap.php` saat ini hanya membaca apa yang ada di tabel `audit_rekap_manual`. Jika data di tabel tersebut "ketinggalan" (belum terupdate dari aksi di `users.php`), maka laporan akan salah.

* **Solusi:** Tambahkan fungsi *Sync* otomatis saat halaman `selling.php` atau `print_rekap.php` dibuka, khusus untuk menyisir user yang memiliki status `rusak` di `login_history` pada tanggal tersebut namun belum masuk ke rincian audit manual.

#### D. Penanganan User Tanpa Login

Di `users.php`, pastikan saat klik "Rusak", jika `login_time_real` kosong, isi dengan tanggal hari ini agar query di `selling.php` (yang memfilter berdasarkan tanggal login) bisa menemukannya.

---