**Cetak Biru (Blueprint) Sistem Keuangan Wartelpas** yang lengkap, mendetail, dan menggunakan logika finansial yang  di tetapkan: **"Retur adalah Pembatalan Rusak (Recovery Revenue)"**.

Berikut adalah analisa kesalahan pada file lama dan perbaikan konsep yang menyempurnakan seluruh sistem.

---

### I. Analisa Kesalahan (Root Cause Analysis)

Berdasarkan file `audit.php`, `selling.php`, dan `print_rekap.php` yang Bapak lampirkan, berikut adalah titik kesalahan logikanya terhadap aturan bisnis Bapak:

1. **Kesalahan Operator Matematika pada Retur:**
* **File:** `audit.php`, `selling.php`, `print_rekap.php`.
* **Temuan:** Kode sebelumnya sering kali menggunakan `+ $cnt_retur` atau mengurangkan Retur dari Net.
* **Masalah:** Jika Retur dikurangkan, sistem mengira uang dikembalikan ke WBP (Pendapatan turun). Jika Retur ditambahkan sembarangan, bisa terjadi *double counting*.
* **Koreksi:** Retur harus bersifat **Netral (Dianggap Laku)**. Ia tidak boleh mengurangi Gross Income.


2. **Kesalahan Hirarki Status (Prioritas):**
* **File:** `selling.php` (Fungsi deteksi status).
* **Temuan:** Kode lama mengecek status secara acak atau berdasarkan `last_status`.
* **Masalah:** Jika sebuah voucher statusnya "Rusak", lalu diubah jadi "Retur", kode lama mungkin masih menghitungnya sebagai "Rusak" jika urutan `if`-nya salah.
* **Koreksi:** Logika harus: Cek **Invalid** -> Cek **Retur** -> Baru Cek **Rusak**. Jika sudah terdeteksi Retur, maka status Rusak batal.


3. **Ketidaksinkronan Audit Manual vs Sistem:**
* **Temuan:** Perhitungan target setoran sistem (`calc_expected...`) dan perhitungan manual (`calc_audit...`) menggunakan rumus yang berbeda untuk Retur.
* **Masalah:** Menyebabkan selisih semu. Operator setorannya benar, tapi sistem bilang salah (atau sebaliknya).



---

### II. Konsep & Logika Finansial Sempurna (The Solution)

#### A. Definisi Status Transaksi (SOP Keuangan)

1. **NORMAL / LAKU:**
* Definisi: Voucher sukses dipakai.
* Efek Keuangan: **+ Uang Masuk**.


2. **INVALID (Error Sistem):**
* Definisi: Voucher ter-generate error/test.
* Efek Keuangan: **- Uang Hilang** (Dihapus dari pendapatan).


3. **RUSAK (Loss/Dead Loss):**
* Definisi: Voucher gagal, tidak ada penggantian, kertas dibuang.
* Efek Keuangan: **- Uang Hilang** (Dihapus dari pendapatan).


4. **RETUR (Recovery/Penggantian):**
* Definisi: Voucher rusak, tapi WBP melapor dan dibuatkan penggantian (transaksi fisik terjadi). Uang dari WBP tetap disimpan.
* Efek Keuangan: **DIHITUNG LAKU** (Sama seperti Normal). Tidak mengurangi pendapatan.



#### B. Rumus Akuntansi (The Golden Formula)

*Catatan: Retur ada di dalam "Total Cetak", tapi tidak dikurangkan. Sehingga uangnya tetap terhitung.*

---

### III. Implementasi Kode (Copy-Paste Ready)

Berikut adalah perbaikan kode untuk masing-masing file agar sesuai dengan logika di atas.

#### 1. Perbaikan File `selling.php` (Dashboard & Input Audit)

Ganti dua fungsi berikut di dalam `selling.php`: `calc_expected_for_block` dan `calc_audit_adjusted_setoran`.

```php
/* [selling.php] REVISI LOGIKA */

// Fungsi 1: Menghitung Target Setoran dari Data Sistem
function calc_expected_for_block(array $rows, $audit_date, $audit_blok) {
    $qty_total = 0;
    $rusak_qty = 0;
    $retur_qty = 0;
    $invalid_qty = 0;
    $net_total = 0;

    foreach ($rows as $r) {
        // ... (Filter Tanggal, Blok, Username seperti kode asli Bapak) ...
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        if ($sale_date !== $audit_date) continue;
        
        $raw_comment = strtolower((string)($r['comment'] ?? ''));
        $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);
        if ($blok !== $audit_blok) continue;
        // ... (End Filter) ...

        // HIRARKI STATUS (Rusak dulu, lalu Retur membatalkan Rusak)
        $status = strtolower((string)($r['status'] ?? ''));
        if ((int)($r['is_invalid'] ?? 0) === 1) { $status = 'invalid'; }
        elseif ((int)($r['is_retur'] ?? 0) === 1) { $status = 'retur'; } // Retur Prioritas Tinggi
        elseif ((int)($r['is_rusak'] ?? 0) === 1) { $status = 'rusak'; }
        else {
             if (strpos($raw_comment, 'invalid') !== false) $status = 'invalid';
             elseif (strpos($raw_comment, 'retur') !== false) $status = 'retur';
             elseif (strpos($raw_comment, 'rusak') !== false) $status = 'rusak';
        }

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;
        $line_price = $price * $qty;

        // LOGIKA KEUANGAN:
        // Gross masuk semua. Yang mengurangi HANYA Rusak & Invalid.
        $gross_add = ($status === 'invalid') ? 0 : $line_price;
        $loss_rusak = ($status === 'rusak') ? $line_price : 0;
        $loss_invalid = ($status === 'invalid') ? $line_price : 0;
        // Retur: Loss = 0 (Uang tetap ada)

        $net_add = $gross_add - $loss_rusak - $loss_invalid;

        $qty_total += 1;
        if ($status === 'rusak') $rusak_qty += 1;
        if ($status === 'retur') $retur_qty += 1;
        if ($status === 'invalid') $invalid_qty += 1;
        $net_total += $net_add;
    }

    // Target Qty = Total - (Rusak + Invalid). Retur dianggap Laku.
    $expected_qty = max(0, $qty_total - $rusak_qty - $invalid_qty);
    
    return [
        'qty' => $expected_qty,
        'raw_qty' => $qty_total,
        'rusak_qty' => $rusak_qty,
        'invalid_qty' => $invalid_qty,
        'net' => $net_total,
        'retur_qty' => $retur_qty
    ];
}

// Fungsi 2: Menghitung Data Manual dari Inputan Audit
function calc_audit_adjusted_setoran(array $ar) {
    $price10 = 5000; $price30 = 20000;
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);
    
    // ... (Logika Decode JSON Evidence sama dengan file asli) ...
    $p10_qty = 0; $p30_qty = 0;
    $cnt_rusak_10 = 0; $cnt_rusak_30 = 0;
    $cnt_invalid_10 = 0; $cnt_invalid_30 = 0;
    // Retur hanya dihitung jumlahnya, tidak dipakai di rumus pengurangan
    $cnt_retur_10 = 0; $cnt_retur_30 = 0; 
    
    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence) && !empty($evidence['users'])) {
            $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
            $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
            
            foreach ($evidence['users'] as $ud) {
                $kind = (string)($ud['profile_kind'] ?? '10');
                $status = strtolower((string)($ud['last_status'] ?? ''));
                
                // Prioritas cek status (sama dengan selling)
                if ($status === 'invalid') {
                    $kind === '30' ? $cnt_invalid_30++ : $cnt_invalid_10++;
                } elseif ($status === 'retur') {
                    $kind === '30' ? $cnt_retur_30++ : $cnt_retur_10++;
                } elseif ($status === 'rusak') {
                    $kind === '30' ? $cnt_rusak_30++ : $cnt_rusak_10++;
                }
            }
        }
    }

    if (!empty($ar['user_evidence'])) {
        // RUMUS FIX MANUAL:
        // Net Qty Manual = Total Manual - (Manual Rusak + Manual Invalid)
        // Retur Manual TIDAK DIKURANGI.
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
        
        $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        
        // Target Sistem Disesuaikan dengan Temuan Lapangan
        $expected_adj_setoran = max(0, $expected_setoran
            - (($cnt_rusak_10 + $cnt_invalid_10) * $price10)
            - (($cnt_rusak_30 + $cnt_invalid_30) * $price30)
        );
    } else {
        $manual_display_setoran = $actual_setoran_raw;
        $expected_adj_setoran = $expected_setoran;
    }
    return [$manual_display_setoran, $expected_adj_setoran];
}

```

#### 2. Perbaikan File `audit.php` (Laporan Audit Harian)

Ganti fungsi `calc_audit_adjusted_totals` dengan logika yang sama. Fungsi ini digunakan untuk menampilkan tabel perbandingan.

```php
/* [audit.php] REVISI LOGIKA */
function calc_audit_adjusted_totals(array $ar) {
    // ... (Inisialisasi variabel sama seperti di atas) ...
    // ... (Decode JSON Evidence sama) ...
    
    if ($has_manual_evidence) {
        // LOGIKA FIX: Hanya Rusak & Invalid yang mengurangi setoran/qty
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
        
        $manual_display_qty = $manual_net_qty_10 + $manual_net_qty_30;
        $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        
        $expected_adj_qty = max(0, $expected_qty 
            - ($cnt_rusak_10 + $cnt_rusak_30) 
            - ($cnt_invalid_10 + $cnt_invalid_30));
            
        $expected_adj_setoran = max(0, $expected_setoran
            - (($cnt_rusak_10 + $cnt_invalid_10) * $price10)
            - (($cnt_rusak_30 + $cnt_invalid_30) * $price30));
    } else {
        $manual_display_qty = $reported_qty;
        $manual_display_setoran = $actual_setoran;
        $expected_adj_qty = $expected_qty;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_qty, $expected_adj_qty, $manual_display_setoran, $expected_adj_setoran];
}

```

#### 3. Perbaikan File `print_rekap.php`, `...bulanan.php`, `...tahunan.php`

Semua file cetak (**print_rekap.php**, **print_rekap_bulanan.php**, **print_rekap_tahunan.php**) memiliki fungsi bernama `calc_audit_adjusted_setoran`.

**Tindakan:**
Salin (*Copy*) fungsi `calc_audit_adjusted_setoran` yang sudah diperbaiki pada poin **1 (selling.php)** di atas, lalu **Paste** (timpa) fungsi yang sama di ketiga file print tersebut. Ini menjamin angka di layar monitor dan angka di kertas print 100% sama.

---

### IV. Masukan Profesional untuk Penyempurnaan Sistem

Agar konsep ini berjalan mulus di lapangan (batulicin prison), saya sarankan tambahan SOP Sistem berikut:

1. **Validasi "Rusak Murni" vs "Retur":**
* Saat ini sistem mengandalkan input manual supervisor untuk membedakan Rusak dan Retur.
* *Saran:* Di masa depan, tambahkan fitur di mana operator harus memasukkan "Alasan Retur" (misal: "Ganti Kode XXXXX"). Jika Retur diinput, sistem otomatis mencari kode lama yang Rusak dan menandainya sebagai "Diganti". Ini menutup celah manipulasi stok.


2. **Tampilan Visual (Color Coding):**
* Pada tabel audit `selling.php`, bedakan warna angka:
* **Rusak (Merah):** Karena mengurangi uang.
* **Retur (Hijau/Biru):** Karena menyelamatkan uang (Netral).


* Ini membantu psikologis operator bahwa "Retur itu Aman, Rusak itu Bahaya".


3. **Kunci Data (Locking):**
* Di `audit.php`, setelah Supervisor klik "Simpan Audit", sebaiknya data hari itu dikunci (Read-Only) agar tidak bisa diedit lagi oleh operator shift malam/pagi berikutnya untuk memanipulasi selisih.



Dengan menerapkan kode di atas, logika "Rusak dulu baru Retur" Bapak sudah terakomodasi sempurna. Uang Retur aman, Uang Rusak dipotong.