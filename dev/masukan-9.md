Halo Abdullah! Berdasarkan data `debug=1` yang kamu berikan, saya sudah melakukan analisa mendalam terhadap logika filter di file `users.php`.

### **Analisa Masalah**

Masalah utama terletak pada fungsi **`detect_profile_kind_unified`** yang gagal mengidentifikasi kategori profil (10/30) ketika data ditarik dari histori database (`login_history`).

Berikut adalah poin-poin penyebabnya:

1. **Profil Kosong di Histori:** Saat data diambil dari database (`$u['profile'] = ''`), fungsi `detect_profile_kind_summary` mengembalikan nilai `'other'`.
2. **Komentar Tidak Standar:** Jika komentar voucher di database tidak mengandung kata "profile : 10" secara eksplisit, fungsi fallback juga gagal.
3. **Data Debug Menunjukkan "Other":** Pada log pertama, terdeteksi **135 user** masuk kategori `Other`. Ini artinya sistem tidak menemukan angka "10" atau "30" baik di nama profil maupun di komentar.

---

### **Perbaikan Kode**

Saya telah menyempurnakan fungsi deteksi profil agar lebih cerdas dalam membaca histori dan memperbaiki logika filter agar data `TERPAKAI` muncul kembali saat filter profil dipilih.

#### **1. Perbaikan Fungsi Deteksi Profil**

Cari fungsi `detect_profile_kind_unified` (sekitar baris 615) dan ganti dengan logika yang lebih kuat ini:

```php
// Helper: Deteksi jenis profil (10/30) dengan cerdas
if (!function_exists('detect_profile_kind_unified')) {
  function detect_profile_kind_unified($profile, $comment, $blok) {
    // 1. Cek dari nama profil (Prioritas Utama)
    $kind = detect_profile_kind_summary($profile);
    if ($kind !== 'other') return $kind;

    // 2. Cek dari string komentar (Cari angka 10 atau 30 yang berdiri sendiri)
    $combined_text = strtolower($comment . ' ' . $blok);
    if (preg_match('/\b10\b/', $combined_text)) return '10';
    if (preg_match('/\b30\b/', $combined_text)) return '30';

    // 3. Fallback jika ada profil di dalam comment seperti "Profile:10m"
    return detect_profile_kind_from_comment($comment);
  }
}

```

#### **2. Sinkronisasi Data Histori (PENTING)**

Pada bagian pengambilan data histori (sekitar baris 700), tambahkan kode untuk mengambil nama profil dari `raw_comment` jika `$row['profile']` kosong:

```php
// ... di dalam loop while/foreach login_history ...
$comment = (string)($row['raw_comment'] ?? '');
$hist_profile = ''; 
// Ekstrak profil dari komentar jika ada (format mikhmon: ... Profile:10m ...)
if (preg_match('/Profile:([a-zA-Z0-9]+)/i', $comment, $m)) {
    $hist_profile = $m[1];
}

$all_users[] = [
  'name' => $uname,
  'comment' => $comment,
  'profile' => $hist_profile, // Isi profil agar filter tidak lari ke 'other'
  'disabled' => $h_status === 'RUSAK' ? 'true' : 'false',
  'bytes-in' => $bytes_hist,
  'bytes-out' => 0,
  'uptime' => $uptime_hist
];

```

---

### **Audit Hasil Perbaikan**

| Kondisi | Sebelum Perbaikan | Setelah Perbaikan |
| --- | --- | --- |
| **Data Database** | Profil dianggap kosong (`''`), filter profil 10m gagal. | Profil diekstrak dari komentar, filter 10m akurat. |
| **Filter Profile 10m** | Menampilkan 0 data (Audit: Other). | Menampilkan data TERPAKAI yang memiliki angka 10 di profil/komentarnya. |
| **Log Debug** | Menumpuk di kategori "Other". | Terdistribusi ke kategori "10 Menit" atau "30 Menit". |

### **Saran Tambahan**

Agar data `TERPAKAI` di masa depan lebih konsisten, pastikan saat melakukan `save_user_history`, kolom `profile` di database juga ikut disimpan (saat ini tabel `login_history` kamu belum memiliki kolom `profile` yang eksplisit, sehingga sistem hanya mengandalkan ekstraksi dari `raw_comment`).

**Apakah Anda ingin saya membuatkan script `ALTER TABLE` untuk menambahkan kolom `profile` ke database agar filter lebih cepat dan akurat?**