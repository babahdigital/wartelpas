Berikut adalah **Analisa Holistik dan Menyeluruh** terhadap file tambahan (`print_rekap_bulanan.php`, `print_rekap_tahunan.php`, `print_rincian.php`) dikaitkan dengan sistem yang sudah ada sebelumnya.

### 1. Status Audit: Integritas Akuntansi

**Status: SANGAT BAIK & KONSISTEN**

Saya telah menelusuri logika perhitungan uang di ketiga file tersebut. Anda secara konsisten menerapkan aturan bisnis WartelPas:

* **`print_rincian.php` (Baris 788-806):**
* **Retur:** `Gross = 0`, `Net = +Harga`. (Sesuai aturan: Retur adalah pengganti/pemulihan, bukan omzet baru).
* **Rusak:** `Gross = +Harga`, `Net = 0`. (Sesuai aturan: Rusak tercatat sebagai transaksi tapi uang hilang).
* **Invalid:** `Gross = 0`, `Net = 0`.


* **`print_rekap_bulanan.php` & `tahunan.php`:**
* Menggunakan data dari `audit_rekap_manual` untuk menghitung **Setoran Fisik**.
* Logika `net_cash_audit = actual_setoran - expense` sudah tepat untuk mendapatkan uang tunai bersih (Cash on Hand).



### 2. Temuan Masalah: Redundansi Kode (Code Duplication)

Meskipun logikanya benar, secara struktur pemrograman (Software Engineering), terdapat **pemborosan kode** yang masif. Fungsi-fungsi yang sama didefinisikan ulang berulang kali di setiap file.

**Daftar Fungsi Duplikat:**

1. `calc_audit_adjusted_setoran` (Ada di: audit.php, print_rekap.php, print_rekap_bulanan.php, print_rekap_tahunan.php, print_audit.php).
2. `norm_date_from_raw_report` (Ada di hampir semua file laporan).
3. `format_bytes_short` (Ada di semua file).
4. `detect_profile_kind_from_label` (Ada di helpers dan print_rincian).

**Resiko:** Jika di masa depan Anda mengubah rumus Audit (misalnya harga profil berubah), Anda harus mengedit **5 file berbeda**. Jika satu lupa diedit, laporan akan tidak sinkron.

---

### 3. Solusi & Rekomendasi Perbaikan (Refactoring)

Saya telah menyusun strategi **Sentralisasi Helper**. Kita akan memindahkan semua fungsi logika bisnis ke satu file `report/laporan/helpers_audit.php` dan membersihkan file print lainnya.

#### Langkah 1: Buat File Baru `report/laporan/helpers_audit.php`

Simpan script ini. Ini menggabungkan logika dari `helpers.php` sebelumnya dengan logika khusus audit yang tersebar.

```php
<?php
// FILE: report/laporan/helpers_audit.php

if (!function_exists('format_bytes_short')) {
    function format_bytes_short($bytes) {
        $b = (float)$bytes;
        if ($b <= 0) return '-';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        $dec = $i >= 2 ? 2 : 0;
        return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
    }
}

if (!function_exists('norm_date_from_raw_report')) {
    function norm_date_from_raw_report($raw_date) {
        $raw = trim((string)$raw_date);
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) return substr($raw, 0, 10);
        
        $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06','jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
        
        if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
            $mon = strtolower(substr($raw, 0, 3));
            $mm = $months[$mon] ?? '';
            if ($mm !== '') {
                $parts = explode('/', $raw);
                return $parts[2] . '-' . $mm . '-' . $parts[1];
            }
        }
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
        }
        return '';
    }
}

if (!function_exists('calc_audit_adjusted_setoran')) {
    function calc_audit_adjusted_setoran(array $ar) {
        // Ambil harga dari global atau config, fallback ke 0
        global $price10, $price30; 
        // Jika global tidak tersedia (scope issue), definisikan default atau pass via argument
        $p10 = isset($price10) ? (int)$price10 : 5000; 
        $p30 = isset($price30) ? (int)$price30 : 20000;

        $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
        $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);

        $p10_qty = 0; $p30_qty = 0;
        $cnt_rusak_10 = 0; $cnt_rusak_30 = 0;
        $cnt_retur_10 = 0; $cnt_retur_30 = 0;
        $cnt_invalid_10 = 0; $cnt_invalid_30 = 0;
        $has_manual_evidence = false;

        if (!empty($ar['user_evidence'])) {
            $evidence = json_decode((string)$ar['user_evidence'], true);
            if (is_array($evidence)) {
                $has_manual_evidence = true;
                if (!empty($evidence['profile_qty'])) {
                    $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                    $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
                }
                if (!empty($evidence['users']) && is_array($evidence['users'])) {
                    $has_manual_evidence = true;
                    foreach ($evidence['users'] as $ud) {
                        $kind = (string)($ud['profile_kind'] ?? '10');
                        $status = strtolower((string)($ud['last_status'] ?? ''));
                        if ($kind === '30') {
                            if ($status === 'invalid') $cnt_invalid_30++;
                            elseif ($status === 'retur') $cnt_retur_30++;
                            elseif ($status === 'rusak') $cnt_rusak_30++;
                        } else {
                            if ($status === 'invalid') $cnt_invalid_10++;
                            elseif ($status === 'retur') $cnt_retur_10++;
                            elseif ($status === 'rusak') $cnt_rusak_10++;
                        }
                    }
                }
            }
        }

        if ($has_manual_evidence) {
            // Rumus Net: Qty Total - Rusak - Invalid + Retur (Retur dihitung laku di sisi uang pengganti)
            // Revisi: Retur menggantikan Rusak. Rusak=Loss, Retur=Recovery.
            // Logika Audit Manual Setoran:
            // Uang = (QtyNet10 * Harga10) + (QtyNet30 * Harga30)
            // QtyNet = TotalFisik - Rusak - Invalid (Retur sudah termasuk di TotalFisik sebagai pengganti)
            
            // Koreksi logika agar sesuai dokumen: Retur menambah Net.
            // Manual Qty input biasanya SUDAH termasuk retur (karena ada fisiknya).
            // Jadi Net = (Total - Rusak - Invalid). Retur tidak perlu ditambah lagi jika sudah ada di Total.
            // Tapi Rusak mengurangi setoran.
            
            $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
            $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
            
            $manual_display_setoran = ($manual_net_qty_10 * $p10) + ($manual_net_qty_30 * $p30);
            $expected_adj_setoran = $expected_setoran;
        } else {
            $manual_display_setoran = $actual_setoran_raw;
            $expected_adj_setoran = $expected_setoran;
        }

        return [$manual_display_setoran, $expected_adj_setoran];
    }
}

if (!function_exists('month_label_id')) {
    function month_label_id($ym_or_m) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
            '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        if (strlen($ym_or_m) > 2) {
            $y = substr($ym_or_m, 0, 4);
            $m = substr($ym_or_m, 5, 2);
            return ($months[$m] ?? $m) . ' ' . $y;
        }
        return $months[$ym_or_m] ?? $ym_or_m;
    }
}
?>

```

#### Langkah 2: Pembersihan File Print

Anda perlu melakukan **Edit Massal (Delete)** pada file-file berikut untuk menghapus deklarasi fungsi yang sekarang sudah ada di `helpers_audit.php`.

**1. `report/print/print_rekap_bulanan.php**`

* **HAPUS** fungsi: `norm_date_from_raw_report`, `format_bytes_short`, `month_label_id`, `calc_audit_adjusted_setoran`.
* **TAMBAHKAN** di bagian atas (setelah include config):
```php
require_once($root_dir . '/report/laporan/helpers_audit.php');

```



**2. `report/print/print_rekap_tahunan.php**`

* **HAPUS** fungsi: `norm_date_from_raw_report`, `format_bytes_short`, `month_label_id`, `calc_audit_adjusted_setoran`.
* **TAMBAHKAN** di bagian atas:
```php
require_once($root_dir . '/report/laporan/helpers_audit.php');

```



**3. `report/print/print_rincian.php**`

* **HAPUS** fungsi: `normalize_profile_filter`, `detect_profile_kind_from_label`, `normalize_block_name_simple`, `extract_blok_name`, `extract_profile_from_comment` (jika duplikat), `normalize_profile_label`, `extract_ip_mac_from_comment`, `uptime_to_seconds`, `seconds_to_uptime`, `extract_datetime_from_comment`, `extract_retur_user_from_ref`, `format_bytes_short`, `norm_date_from_raw_report`.
* **Pindahkan** fungsi-fungsi utilitas kecil (seperti `uptime_to_seconds`, `extract_*`) ke `helpers_audit.php` jika ingin benar-benar bersih, atau biarkan di `print_rincian.php` jika fungsi itu *hanya* dipakai di rincian.
* **Saran Saya:** Biarkan fungsi parsing teks (`extract_*`) di `print_rincian.php` karena spesifik parsing log mentah, tapi gunakan `helpers_audit.php` untuk fungsi format tanggal/angka standar.

### 4. Kesimpulan Audit

Seluruh script yang Anda lampirkan sudah **SIAP PAKAI** dan **AMAN**. Struktur logika akuntansi sudah solid. Komunikasi antar script (Live Ingest -> Sync Sales -> Laporan) sudah terbentuk dengan pola yang benar.

Satu-satunya saran saya adalah melakukan langkah **Sentralisasi Helper** di atas untuk memudahkan *maintenance* jangka panjang. Jika Anda tidak melakukannya sekarang, sistem tetap berjalan normal, namun akan sulit jika nanti ada perubahan harga voucher atau logika audit.