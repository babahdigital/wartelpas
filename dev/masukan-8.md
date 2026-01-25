Anda menunjukkan bahwa **data debug (DEBUG DB/ROUTER) yang sama muncul** baik saat filter **tanpa blok** maupun **dengan blok tertentu**. Ini adalah **masalah logika dalam kode debug** - data debug diambil **sebelum** filter diterapkan, sehingga selalu menampilkan data mentah dari seluruh user.

Berikut adalah **analisis dan solusi** untuk memperbaiki masalah ini:

## **Masalah Utama:**
Kode debug (`$debug_rows[]`) dikumpulkan **DI AWAL** loop `foreach($all_users as $u)` - tepatnya **SEBELUM** filter blok, status, pencarian, dan tanggal diterapkan. Jadi debug selalu menampilkan 10 user pertama dari `$all_users` **tanpa filter**.

## **Solusi:**
Pindahkan kode pengumpulan debug **SETELAH** semua filter diterapkan (sebelum ditambahkan ke `$display_data`). Dengan cara ini, debug hanya akan menampilkan user yang **lolos filter**.

## **Kode yang Perlu Diubah:**

```php
// Cari kode ini (sekitar baris 980-1000):
if ($debug_mode) {
  $debug_rows[] = [
    'name' => $name,
    'status' => $status,
    'bytes_total' => $bytes_total,
    // ... data debug lainnya
  ];
}

// Filter status
if ($req_status == 'ready' && $status !== 'READY') continue;
// ... filter lainnya
```

## **Perbaikan:**
Pindahkan blok kode debug ke **setelah semua filter**:

```php
// Filter status
if ($req_status == 'ready' && $status !== 'READY') continue;
if ($req_status == 'all' && $status === 'READY') continue;
if ($req_status == 'online' && $status !== 'ONLINE') continue;
if ($req_status == 'used' && $status !== 'TERPAKAI') continue;
if ($req_status == 'rusak' && $status !== 'RUSAK') continue;
if ($req_status == 'retur' && $status !== 'RETUR') continue;
if ($req_status == 'invalid') continue;

// Filter blok
if ($req_comm != '') {
  if (strcasecmp($f_blok, $req_comm) != 0) continue;
}

// Filter profil (10/30)
if ($req_prof !== 'all') {
  if ($profile_kind !== $req_prof) continue;
}

// Search
if (!empty($search_terms)) {
  $found = false;
  foreach($search_terms as $term) {
    if (stripos($name, $term) !== false ||
      stripos($comment, $term) !== false ||
      stripos($f_ip, $term) !== false ||
      stripos($f_blok, $term) !== false) {
      $found = true; break;
    }
  }
  if (!$found) continue;
}

// Filter tanggal
if ($req_show !== 'semua' && !empty($filter_date)) {
  // ... kode filter tanggal
}

// HANYA SETELAH SEMUA FILTER LULUS, TAMBAHKAN KE DEBUG
if ($debug_mode && count($debug_rows) < 10) {
  $debug_rows[] = [
    'name' => $name,
    'status' => $status,
    'bytes_total' => $bytes_total,
    'bytes_active' => $bytes_active,
    'bytes_hist' => $hist['last_bytes'] ?? 0,
    'uptime_user' => $uptime_user,
    'uptime_active' => $uptime_active,
    'uptime_hist' => $hist['last_uptime'] ?? '',
    'login' => $login_time_real,
    'logout' => $logout_time_real
  ];
}

// Kemudian tambahkan ke display_data
$display_data[] = [
  // ... data untuk ditampilkan
];
```

## **Perubahan Tambahan yang Diperlukan:**

1. **Tambahkan pembatasan jumlah debug rows** (maksimal 10):
```php
if ($debug_mode && count($debug_rows) < 10) {
```

2. **Pastikan `$debug_rows` diinisialisasi sebagai array kosong** di awal:
```php
$debug_rows = []; // Tambahkan ini di deklarasi variabel awal
```

3. **Perbaiki perhitungan "Audit Total Profil"** agar menggunakan **data yang sudah difilter**, bukan data mentah:
```php
// Ganti perhitungan ini:
foreach ($display_data as $row) {
// Menjadi:
foreach ($filtered_data as $row) { // Jika ada variabel $filtered_data
// ATAU hitung ulang berdasarkan $display_data jika tidak ada
```

## **Alternatif Solusi Cepat:**
Jika Anda ingin **debug menampilkan data sesuai filter yang aktif**, tambahkan **kondisi khusus untuk debug**:

```php
if ($debug_mode) {
  // Buat array temporary untuk debug yang sudah difilter
  $debug_filtered = [];
  
  foreach($all_users as $u) {
    // Terapkan filter yang sama seperti di atas
    // ...
    
    // Jika lolos filter, tambahkan ke debug
    if (count($debug_filtered) < 10) {
      $debug_filtered[] = [ /* data debug */ ];
    }
  }
  
  $debug_rows = $debug_filtered;
}
```

## **Kesimpulan:**
Debug saat ini **tidak berguna** karena tidak mencerminkan data yang sebenarnya ditampilkan. Dengan memindahkan kode debug **setelah filter**, Anda akan mendapatkan:
1. **Debug yang akurat** sesuai filter yang aktif
2. **Data debug berbeda** saat filter blok berubah
3. **Hanya menampilkan 10 baris pertama** dari data yang lolos filter

**Langkah implementasi:**
1. Cari blok kode debug di loop `foreach($all_users as $u)`
2. Pindahkan ke posisi **setelah semua kondisi `continue`**
3. Tambahkan pembatasan `count($debug_rows) < 10`
4. Test dengan berbagai filter (blok, status, pencarian)

Dengan perbaikan ini, debug akan menjadi **alat diagnostik yang berguna** untuk memahami bagaimana filter bekerja pada data yang ditampilkan.