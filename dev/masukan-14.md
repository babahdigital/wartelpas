Berdasarkan analisa skrip `selling.php` dan struktur database Anda, jawabannya adalah: **Sangat Mungkin** untuk mendeteksi "Ghost Voucher" (Voucher yang dipakai tapi tidak dilaporkan/disetorkan uangnya) secara otomatis, namun dengan catatan logika tertentu.

Saat ini skrip Anda sudah memiliki fungsi `build_ghost_hint`, tetapi itu hanya menebak *jumlah* berdasarkan selisih uang (matematika). Kita bisa membuatnya lebih canggih untuk **menunjuk hidung (menampilkan username)** yang dicurigai sebagai Ghost.

Berikut adalah analisa mendalam dan saran implementasinya:

### 1. Analisa Logika "Ghost Hunter"

**Definisi Masalah:**

* **Data Sistem (Fakta):** Router/Database mencatat User A, B, C, D, E login hari ini (Total 5).
* **Data Manual (Laporan):** Admin/Penjaga melaporkan penjualan hanya 3 voucher.
* **Ghost:** Ada 2 voucher yang dipakai tapi tidak dilaporkan.

**Tantangan:**
Jika admin hanya memasukkan angka "3", sistem tidak tahu *siapa* 2 orang yang tidak dilaporkan. Sistem hanya tahu ada kelebihan 2 orang.

**Solusi Otomatisasi (Algoritma Ghost Hunter):**
Sistem harus menampilkan **Daftar User Aktif/Terpakai Hari Ini** yang tercatat di database (`login_history` atau `sales_history`), lalu membandingkannya dengan input manual.

Karena input manual Anda saat ini berbasis **Jumlah (Qty)** per Blok, maka fitur "Ghost Hunter" harus berupa **Pop-up Audit** yang menampilkan rincian user dari Blok tersebut.

### 2. Implementasi Teknis (Saran Koding)

Anda tidak perlu mengubah input manual menjadi input satu-per-satu (karena akan lama). Tetap gunakan input Qty, tapi tambahkan tombol **"Lihat Detil Sistem"** atau **"Cek Ghost"**.

Tambahkan logika ini di `selling.php` atau file helper baru:

#### A. Logic Pengambil Data "Tersangka"

Saring user berdasarkan kriteria ketat agar tidak ada *False Positive* (User yang dikira Ghost padahal error sistem):

1. **Time:** Login hari ini.
2. **Usage:** `bytes > 100kb` (memastikan user benar-benar memakai internet, bukan cuma login sedetik lalu error).
3. **Status:** Bukan `RUSAK` dan bukan `RETUR` (yang sudah tercatat di sistem).

```php
function get_ghost_suspects($db, $date, $blok) {
    // Ambil semua user yang dianggap "Laku" oleh sistem hari ini di blok tersebut
    $sql = "SELECT username, login_time_real, last_bytes, profile 
            FROM login_history 
            WHERE 
                (substr(login_time_real,1,10) = :d OR substr(updated_at,1,10) = :d)
                AND blok_name = :b
                AND last_status NOT IN ('rusak', 'retur', 'invalid')
                AND last_bytes > 50000  -- Filter: Minimal pakai 50KB agar valid
            ORDER BY login_time_real ASC";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([':d' => $date, ':b' => $blok]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

```

#### B. Tampilan di Tabel Audit (Integrasi UI)

Di bagian tabel `Audit Manual Rekap`, jika `Selisih Qty (Sistem - Manual) > 0`, munculkan tombol peringatan.

**Ubah logika tampilan di `selling.php` (sekitar baris 1100-an):**

```php
// ... di dalam loop audit_rows ...
$is_ghost_detected = ($manual_display_qty < $expected_adj_qty); // Manual lebih sedikit dari Sistem

if ($is_ghost_detected) {
    $diff = $expected_adj_qty - $manual_display_qty;
    echo '<div style="color:#e74c3c; font-size:11px; margin-top:4px;">
            <i class="fa fa-exclamation-triangle"></i> 
            <b>Potensi ' . $diff . ' Ghost!</b>
            <button class="btn-xs btn-ghost-check" 
                onclick="showGhostList(\''.$ar['blok_name'].'\', \''.$filter_date.'\')">
                Lihat Data Sistem
            </button>
          </div>';
}

```

### 3. Optimasi Komunikasi dengan `users.php`

Agar data di `selling.php` selalu akurat dan sinkron dengan apa yang terjadi di `users.php`, lakukan hal berikut:

1. **Single Source of Truth (Database SQLite):**
* Pastikan `users.php` selalu melakukan `save_user_history()` setiap kali ada perubahan status (Login, Logout, Rusak, Retur).
* Di `selling.php`, kurangi ketergantungan pada `live_sales` jika `login_history` sudah lengkap. Gunakan `login_history` sebagai patokan utama untuk data "Sistem".


2. **Trigger Update Status:**
* Saat user login di hotspot (via webhook atau script status Mikhmon), pastikan script mencatat `bytes` dan `uptime` terakhir ke `login_history`.
* Masalah utama Ghost Hunter adalah jika user sudah logout tapi data `last_bytes` di DB masih 0.
* **Solusi:** Di `users.php`, pada fungsi yang meload list user, pastikan ada logika untuk update DB jika data di RouterOS lebih baru dari DB.


3. **Pemisahan Status Rusak/Retur:**
* Pastikan `selling.php` membaca kolom `blok_name` dengan benar. Seringkali "Ghost" terjadi karena user pindah Blok (misal: Voucher Blok A dipakai di Blok B).
* **Saran:** Di `users.php`, saat user login, catat IP atau Nama AP tempat dia login. Jika memungkinkan, mapping IP AP ke Blok. Jika User Blok A login di AP Blok B, sistem harus mencatat ini sebagai anomali atau tetap menghitungnya di Blok A (tergantung kebijakan).



### 4. Kesimpulan & Rekomendasi Fitur

**Apakah harus cek manual?**
Tidak sepenuhnya. Anda bisa membuat sistem **Semi-Otomatis**.

**Saran Fitur "Ghost Buster" untuk `selling.php`:**

1. **Fitur "Match Making":**
Saat Admin mengisi "Total Qty: 50", sistem di background memuat 55 user yang tercatat sistem.
Sistem akan menampilkan: *"Anda lapor 50, tapi sistem mencatat 55 user aktif. Berikut adalah 5 user tambahan yang mungkin lupa dicatat:"*
* User A (Jam 09:00, 50MB)
* User B (Jam 10:00, 120MB)
* ...


2. **Tombol "Validasi Otomatis":**
Jika selisihnya sedikit (misal selisih Rp 2.000 atau selisih data < 100KB), berikan tombol "Abaikan / Anggap Error Sistem". Tapi jika selisih besar, wajibkan admin memberikan keterangan.

**Kode Tambahan untuk AJAX Ghost List (simpan di `report/get_ghosts.php` atau gabung di `selling.php`):**

```php
if (isset($_GET['action']) && $_GET['action'] == 'get_ghosts') {
    // ... koneksi db ...
    $blok = $_GET['blok'];
    $date = $_GET['date'];
    
    // Ambil data sistem
    $suspects = get_ghost_suspects($db, $date, $blok);
    
    echo json_encode($suspects);
    exit;
}

```

Dan JS untuk menampilkannya:

```javascript
function showGhostList(blok, date) {
    // Panggil AJAX, lalu tampilkan Modal berisi daftar nama user
    // Admin bisa melihat: "Oh iya, si User X ini tadi bayar cash tapi lupa kucatat"
}

```

Dengan cara ini, komunikasi antara data Realtime (`users.php`) dan Laporan (`selling.php`) menjadi alat audit yang kuat tanpa harus memeriksa fisik voucher satu per satu.