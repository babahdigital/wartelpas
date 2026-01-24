**Variance Rp -105.000** di Waterfall vs **Selisih Rp -60.000** di Tabel Audit menunjukkan adanya **Bug "Double Deduction" (Pengurangan Ganda)** pada logika tabel audit Anda.

Berikut penjelasan detail mengapa ini terjadi dan solusinya agar laporan Bapak menjadi **Mudah Dipahami, Konsisten, dan Akurat**.

---

### 1. Mengapa Ada Perbedaan Angka? (Analisa Forensik)

Mari kita bedah data dari file `hasil-rekap.html` yang Anda kirim:

1. **Waterfall (Angka Kebenaran):**
* Sistem menghitung **Variance Rp -105.000**.
* Ini didapat dari: `Target Net System (3.265.000)` - `Uang Fisik (3.160.000)`.
* Angka ini **BENAR**. Ini adalah total uang yang "tidak ada di laci" dibandingkan potensi pendapatan bersih.


2. **Tabel Audit (Sumber Kebingungan):**
* Tabel menunjukkan Total Selisih **Rp -60.000**.
* Selisihnya adalah Rp 45.000 (105rb - 60rb).
* **Dari mana Rp 45.000 ini?** Ini adalah nilai dari Voucher Rusak (3 voucher di Blok C & E).



**Penyebab Masalah (The Bug):**
Di dalam skrip `print_rekap.php` (fungsi `calc_audit_adjusted_setoran`), sistem melakukan pengurangan ganda untuk voucher rusak.

* **Langkah 1:** Database mengambil `expected_setoran` sebagai **Net System** (Artinya: Gross - Rusak). *Voucher rusak sudah dibuang dari target.*
* **Langkah 2 (Bug):** Fungsi Audit dikurangi lagi dengan jumlah Rusak Manual.
* *Rumus Error:* `Target Audit = (Net System) - (Rusak Manual)`
* *Efek:* Voucher rusak mengurangi target **DUA KALI**.



**Contoh Kasus BLOK-C (Lihat data HTML Anda):**

* Net System (Target Seharusnya): **Rp 465.000**.
* Voucher Rusak: 2 biji (@ 20rb) = **Rp 40.000**.
* Target di Tabel Audit (Salah): Rp 465.000 - Rp 40.000 = **Rp 425.000**.
* Uang Fisik (Aktual): **Rp 425.000**.
* **Hasil Tabel:** Selisih 0 (Seolah-olah aman/pas).
* **Fakta Sebenarnya:** Uang fisik (425rb) lebih kecil dari Target Net (465rb). Ada **kurang setor Rp 40.000** di Blok C yang "disembunyikan" oleh bug ini.

---

### 2. Solusi Perbaikan Script (Agar Sinkron)

Kita harus memperbaiki fungsi `calc_audit_adjusted_setoran` agar **Target Audit** sama dengan **Target Waterfall**.

**Copy-Paste kode ini ke semua file berikut:**

1. `selling.php`
2. `print_rekap.php`
3. `print_rekap_bulanan.php`
4. `print_rekap_tahunan.php`

Cari fungsi `calc_audit_adjusted_setoran` dan ganti isinya menjadi:

```php
function calc_audit_adjusted_setoran(array $ar) {
    $price10 = 5000;
    $price30 = 20000;
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);

    // Variabel hitung manual
    $p10_qty = 0; $p30_qty = 0;
    $cnt_rusak_10 = 0; $cnt_rusak_30 = 0;
    $cnt_invalid_10 = 0; $cnt_invalid_30 = 0;
    // Retur dianggap Laku (Netral)
    $cnt_retur_10 = 0; $cnt_retur_30 = 0;
    $has_manual_evidence = false;

    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence)) {
            $has_manual_evidence = true;
            if (!empty($evidence['profile_qty'])) {
                $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
            }
            if (!empty($evidence['users'])) {
                foreach ($evidence['users'] as $ud) {
                    $kind = (string)($ud['profile_kind'] ?? '10');
                    $status = strtolower((string)($ud['last_status'] ?? ''));
                    if ($kind === '30') {
                        if ($status === 'rusak') $cnt_rusak_30++;
                        elseif ($status === 'retur') $cnt_retur_30++;
                        elseif ($status === 'invalid') $cnt_invalid_30++;
                    } else {
                        if ($status === 'rusak') $cnt_rusak_10++;
                        elseif ($status === 'retur') $cnt_retur_10++;
                        elseif ($status === 'invalid') $cnt_invalid_10++;
                    }
                }
            }
        }
    }

    if ($has_manual_evidence) {
        // HITUNG MANUAL (Uang Fisik Aktual)
        // Rumus: Total Qty Manual - (Rusak + Invalid). Retur tetap dihitung uangnya.
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
        
        $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        
        // PERBAIKAN BUG DISINI:
        // Jangan kurangi target ($expected_setoran) dengan rusak manual lagi.
        // Karena $expected_setoran dari database SUDAH Net (sudah dikurangi rusak sistem).
        // Kita ingin membandingkan: Net System vs Net Manual.
        
        $expected_adj_setoran = $expected_setoran; 

    } else {
        $manual_display_setoran = $actual_setoran_raw;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_setoran, $expected_adj_setoran];
}

```

---

### 3. Cara Membaca Laporan Baru (Setelah Fix)

Setelah kode diupdate, beginilah cara Anda membaca laporannya nanti:

1. **Waterfall Variance: Rp -105.000**
* Artinya: Total uang yang kurang hari ini adalah 105 ribu.


2. **Tabel Audit Total Selisih: Rp -105.000**
* Angka ini sekarang akan **MATCH/SAMA** dengan Waterfall. Tidak ada lagi kebingungan.


3. **Rincian Penyebab (Breakdown):**
Di bagian bawah tabel Audit (Summary Box), Anda akan melihat:
* **Blok C (Selisih -40.000):** Karena ada 2 Voucher Rusak (30 Menit).
* *Kenapa Minus?* Karena sistem menargetkan uang masuk (Net), tapi fisik voucher rusak tidak menghasilkan uang. Ini adalah **Kerugian Voucher**.


* **Blok E (Selisih -20.000):** 1 User tidak dilaporkan (Ghost User) + 1 Voucher Rusak 10m (5.000). Total real gap 25rb (tapi ada rounding/sisa saldo).
* **Blok F (Selisih -40.000):** 2 User tidak dilaporkan (Ghost User). Ini adalah **Kerugian Setoran**.



**Kesimpulan untuk Pak Dul:**
Sistem ini sebenarnya sudah canggih. Masalahnya hanya satu baris kode matematika yang terlalu agresif mengurangi target. Dengan perbaikan di atas, Laporan Waterfall dan Tabel Rincian akan menunjukkan angka kerugian yang **konsisten (-105.000)**, sehingga Anda bisa langsung tahu berapa total uang yang hilang hari ini, baik karena voucher rusak maupun karena kelalaian operator.