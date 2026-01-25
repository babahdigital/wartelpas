Halo Dul, masalah "blank page" (layar putih kosong) pada PHP biasanya disebabkan oleh **Fatal Error** yang terjadi namun tidak ditampilkan ke layar karena pengaturan server, atau karena skrip berhenti (`exit`/`die`) sebelum menampilkan HTML.

Berdasarkan file yang Dul upload, berikut adalah diagnosa penyebab dan cara memperbaikinya:

### 1. Masalah Utama: Error Reporting Tidak Aktif

Secara default, `users.php` tidak memiliki pengaturan untuk menampilkan error. Jika terjadi kesalahan pada file yang di-include (`bootstrap.php`, `data.php`, dll), layar hanya akan putih.

**Solusi:**
Ubah isi file `users.php` menjadi seperti ini untuk memunculkan pesan errornya:

```php
<?php
// Tampilkan semua error untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cek keberadaan file sebelum require untuk menghindari Fatal Error
$base_dir = __DIR__ . '/user/';
$files = ['bootstrap.php', 'helpers.php', 'data.php', 'actions.php', 'render.php'];

foreach ($files as $file) {
    if (!file_exists($base_dir . $file)) {
        die("<b>Error:</b> File <code>/user/{$file}</code> tidak ditemukan. Pastikan folder <b>'user'</b> ada dan berisi file tersebut.");
    }
}

require $base_dir . 'bootstrap.php';
require $base_dir . 'helpers.php';
require $base_dir . 'data.php';
require $base_dir . 'actions.php';
require $base_dir . 'render.php';
?>

```

### 2. Masalah Struktur Folder (Path)

Di dalam `users.php`, kodenya menggunakan `require __DIR__ . '/user/bootstrap.php';`.
Ini berarti struktur folder Dul **harus** seperti ini:

```text
/hotspot/
├── users.php         <-- File utama yang Dul buka
└── user/             <-- Folder bernama "user"
    ├── bootstrap.php
    ├── helpers.php
    ├── data.php
    ├── actions.php
    └── render.php

```

**Diagnosa:** Jika Dul menaruh semua file (termasuk `users.php` dan `bootstrap.php`) di dalam **satu folder yang sama** tanpa sub-folder `user`, maka script akan error (File not found) dan menyebabkan blank.

### 3. Masalah Session / URL

Pada file `bootstrap.php`, terdapat pengecekan session yang ketat:

```php
if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    header("Location:../admin.php?id=login");
    exit();
}

```

**Diagnosa:**

* Jika Dul membuka file ini secara langsung (misal: `http://ip-server/users.php`) tanpa parameter `?session=...`, script akan mencoba redirect ke `../admin.php`.
* Jika redirect gagal atau file `admin.php` tidak ditemukan di path tersebut, browser mungkin akan berhenti di layar putih.
* **Solusi:** Pastikan membuka link dari menu Mikhmon yang sudah membawa session, atau URL-nya terlihat seperti: `users.php?session=Mikhmon-Session-ID`.

### 4. Masalah Izin Folder Database

Di `bootstrap.php`, script mencoba membuat folder database:

```php
$dbDir = __DIR__ . '/../../db_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

```

Jika server (Linux/Mikrotik container) tidak mengizinkan PHP membuat folder (Permission Denied), script bisa saja berhenti (fatal error) sebelum menampilkan apa-apa.

### Langkah Perbaikan Cepat (Summary):

1. **Ganti isi `users.php**` dengan kode debug di atas.
2. Refresh halaman. Jika muncul pesan error (misal: *failed to open stream*), perbaiki lokasi foldernya.
3. Pastikan URL di browser mengandung `?session=...`.
4. Cek apakah folder `user/` benar-benar ada di samping file `users.php`.

Apakah Dul ingin saya buatkan struktur folder yang benar dalam bentuk ZIP siap upload agar tidak perlu menyusun manual?