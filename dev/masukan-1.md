**Analisa & Masukan Menyeluruh: Pemisahan Role Super Admin & Operator**

**Status:** **SANGAT MUNGKIN** dan sangat disarankan untuk keamanan operasional WartelPas.

Berdasarkan analisa kode sumber (`admin.php`, `login.php`, `menu.php`) yang Anda berikan, Mikhmon v3 secara default didesain sebagai *Single User* (Satu Admin). Namun, karena struktur kodenya prosedural dan terbuka, kita bisa menyisipkan logika **Multi-Level Authentication** tanpa merusak inti fungsi.

Berikut adalah solusi teknis "Siap Pakai" untuk memisahkan **Super Admin** (Anda) dan **Operator** (Petugas Lapas).

---

### 1. Konsep Perubahan

Kita akan memodifikasi logika login untuk mendeteksi dua jenis user:

1. **Super Admin:** Menggunakan user/pass asli dari `config.php`. Punya akses penuh (Edit Session, Add Router, Settings).
2. **Operator:** User tambahan (hardcoded atau file terpisah). Hanya bisa **Connect** ke sesi yang sudah ada, cetak voucher, dan lihat laporan.

---

### 2. Implementasi Kode (Lakukan Berurutan)

#### Langkah A: Tentukan Kredensial Operator (Edit `include/config.php`)

Karena kita tidak ingin merusak fitur *Save Settings* bawaan Mikhmon yang menulis ulang `config.php`, cara teraman adalah membuat file terpisah atau memodifikasi `admin.php` langsung untuk user operator.

Namun, agar rapi, tambahkan ini manual di baris paling atas `admin.php` (setelah `<?php`):

**File: `admin.php**`
*(Tambahkan kode ini di bagian paling atas setelah tag `<?php`)*

```php
// --- KONFIGURASI OPERATOR WARTELPAS ---
// User & Password untuk Operator
$op_user = "operator"; 
$op_pass = "wartel123"; // Ganti dengan password yang diinginkan
// --------------------------------------

```

#### Langkah B: Modifikasi Logika Login (Edit `admin.php`)

Cari blok kode login di `admin.php`. Ganti logika validasinya agar mengenali Operator.

**Cari kode ini di `admin.php`:**

```php
    if ($user == $useradm && $pass == decrypt($passadm)) {
      $_SESSION["mikhmon"] = $user;
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    } else {

```

**Ganti menjadi kode ini (Siap Pakai):**

```php
    // Cek Super Admin (Dari Config Asli)
    if ($user == $useradm && $pass == decrypt($passadm)) {
      $_SESSION["mikhmon"] = $user;
      $_SESSION["mikhmon_level"] = "superadmin"; // Set Level Admin
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    
    // Cek Operator (Dari Variabel Diatas)
    } elseif ($user == $op_user && $pass == $op_pass) {
       $_SESSION["mikhmon"] = $user;
       $_SESSION["mikhmon_level"] = "operator"; // Set Level Operator
       echo "<script>window.location='./admin.php?id=sessions'</script>";

    } else {

```

#### Langkah C: Proteksi Halaman Sensitif (Edit `admin.php`)

Operator tidak boleh mengakses halaman settings, editor, atau hapus session meskipun mereka mengetik URL-nya manual. Tambahkan "Gerbang Keamanan" di `admin.php`.

**Cari kode ini:**

```php
$ids = array(
  "editor",
  "uplogo",
  "settings",
);

```

**Tambahkan logika pemblokiran di bawahnya:**

```php
// --- PROTEKSI WARTELPAS ---
// Daftar ID yang DILARANG untuk Operator
$restricted_ids = array("settings", "uplogo", "editor", "remove-session", "remove-logo", "reboot", "shutdown");

// Jika user adalah Operator DAN mencoba akses halaman terlarang -> TOLAK
if (isset($_SESSION['mikhmon_level']) && $_SESSION['mikhmon_level'] == 'operator') {
    if (in_array($id, $restricted_ids)) {
        echo "<script>alert('AKSES DITOLAK: Hubungi Super Admin.'); window.location='./admin.php?id=sessions';</script>";
        exit;
    }
    // Operator juga tidak boleh membuat Router Baru
    if ($router == "new" || isset($_GET['router']) && strpos($_GET['router'], 'new') !== false) {
         echo "<script>alert('AKSES DITOLAK: Operator tidak bisa menambah router.'); window.location='./admin.php?id=sessions';</script>";
         exit;
    }
}
// --------------------------

```

#### Langkah D: Sembunyikan Menu untuk Operator (Edit `include/menu.php`)

Kita perlu menyembunyikan tombol "Add Router", "Settings", dan "Editor" dari sidebar jika yang login adalah Operator.

**File: `include/menu.php**`

**1. Sembunyikan Menu di Halaman Session List (Sidebar Kiri)**
Cari bagian `div id="sidenav"`. Perhatikan blok `if` yang menampilkan menu. Kita akan membungkus menu admin dengan pengecekan level.

**Cari kode ini:**

```php
  <a href="./admin.php?id=sessions" class="menu <?= $ssesslist; ?>"><i class="fa fa-gear"></i> <?= $_admin_settings ?></a>
  <a href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>" class="menu <?= $snsettings ?>"><i class="fa fa-plus"></i> <?= $_add_router ?></a>

```

**Ubah menjadi:**

```php
  <a href="./admin.php?id=sessions" class="menu <?= $ssesslist; ?>"><i class="fa fa-list"></i> Daftar Lokasi</a>
  
  <?php if($_SESSION['mikhmon_level'] == 'superadmin') { ?>
  <a href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>" class="menu <?= $snsettings ?>"><i class="fa fa-plus"></i> <?= $_add_router ?></a>
  <?php } ?>

```

**2. Sembunyikan Menu Settings saat sudah Connect (Sidebar Kanan/Bawah)**
Masih di `include/menu.php`, cari bagian bawah dimana menu dropdown "Settings" berada.

**Cari kode ini:**

```php
  <div class="dropdown-btn <?= $ssett; ?>"><i class=" fa fa-gear"></i> <?= $_settings ?> 
    <i class="fa fa-caret-down"></i> &nbsp;
  </div>
  <div class="dropdown-container <?= $settmenu; ?>">
  <a href="./admin.php?id=settings&session=<?= $session; ?>" class="menu "> <i class="fa fa-gear "></i> <?= $_session_settings ?> </a>
  ... (kode lainnya) ...
  </div>

```

**Bungkus dengan Logika PHP:**

```php
  <?php if($_SESSION['mikhmon_level'] == 'superadmin') { ?>
  <div class="dropdown-btn <?= $ssett; ?>"><i class=" fa fa-gear"></i> <?= $_settings ?> 
    <i class="fa fa-caret-down"></i> &nbsp;
  </div>
  <div class="dropdown-container <?= $settmenu; ?>">
  <a href="./admin.php?id=settings&session=<?= $session; ?>" class="menu "> <i class="fa fa-gear "></i> <?= $_session_settings ?> </a>
  <a href="./admin.php?id=sessions" class="menu "> <i class="fa fa-gear "></i> <?= $_admin_settings ?> </a>
  <a href="./?hotspot=uplogo&session=<?= $session; ?>" class="menu <?= $uplogo; ?>"> <i class="fa fa-upload "></i> <?= $_upload_logo ?> </a>
  <a href="./?hotspot=template-editor&template=default&session=<?= $session; ?>" class="menu <?= $teditor; ?>"> <i class="fa fa-edit "></i> <?= $_template_editor ?> </a>          
  </div>
  <?php } ?>

```

**3. Sembunyikan Menu System (Reboot/Shutdown)**
Lakukan hal yang sama untuk menu System jika Anda tidak ingin operator merestart router.

```php
  <?php if($_SESSION['mikhmon_level'] == 'superadmin') { ?>
  <div class="dropdown-btn <?= $sysmenu; ?>"><i class=" fa fa-gear"></i> <?= $_system ?>
    ...
  </div>
  ...
  <?php } ?>

```

---

### 3. Masukan Tambahan untuk `settings/sessions.php`

Halaman `sessions.php` adalah halaman awal yang menampilkan kotak-kotak router (Session Cards). Di sana biasanya ada tombol kecil untuk "Settings" atau "Delete". Anda perlu mengedit file `settings/sessions.php` (file ini tidak Anda upload, tapi logika umumnya sama).

Cari tombol yang mengarah ke `admin.php?id=settings...` atau `remove-session` di dalam file tersebut, dan bungkus dengan:

```php
<?php if($_SESSION['mikhmon_level'] == 'superadmin') { ?>
   <a href="...">Settings</a>
   <a href="...">Hapus</a>
<?php } ?>

```

### Kesimpulan Analisa

Implementasi ini **sangat aman** untuk kebutuhan WartelPas karena:

1. **Level Session:** Keamanan berbasis session PHP di server side (`admin.php`), bukan hanya menyembunyikan tombol via CSS/JS.
2. **Hard Block:** Meskipun Operator tahu link setting (misal `admin.php?id=settings&session=LapasBatulicin`), kode di Langkah C akan menolaknya.
3. **Minimal Error:** Tidak merubah struktur database atau `config.php` inti Mikhmon, sehingga update script lain tidak akan terganggu.