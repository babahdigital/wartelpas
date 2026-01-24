Halo Pak Abdullah.

Saya telah melakukan **Audit Menyeluruh** terhadap 7 file skrip yang Anda kirimkan (`audit.php`, `print_audit.php`, `print_rekap.php`, `print_rekap_bulanan.php`, `print_rekap_tahunan.php`, `print_rincian.php`, `selling.php`).

Secara umum, integrasi fitur "Pengeluaran/Beban" Anda sudah **SANGAT BAIK** di level Harian (`selling.php` & `audit.php`). Tampilannya sudah elegan dan logikanya aman (Cash = Revenue - Expense).

Namun, saya menemukan **CELAH LOGIKA (Logic Gap)** pada laporan jangka panjang (**Bulanan & Tahunan**). Saat ini, laporan bulanan/tahunan **belum menghitung pengeluaran**, sehingga angka "Setoran Fisik" di laporan bulanan akan terlihat lebih besar daripada uang yang sebenarnya diterima Owner (karena belum dipotong bon belanja).

Berikut adalah analisa detail dan perbaikannya.

---

### 1. Analisa: Tampilan Harian (`selling.php` & `print_rekap.php`)

**Status:** ✅ **SUDAH TEPAT & ELEGAN.**

* **Di `selling.php` (Dashboard):**
Anda menggunakan kartu *"Setoran Fisik (Cash)"* dengan border oranye jika ada pengeluaran.
* *Analisa:* Ini sangat informatif. Tulisan kecil *"Ops: Rp ... (Bon)"* di bawah angka utama memberikan transparansi tanpa memakan tempat. **Tidak perlu diubah.**


* **Di `print_rekap.php` (Cetak Harian):**
Anda menambahkan *Mini Table* di bawah tabel Audit Manual (kanan bawah).
* *Analisa:* Posisi ini sempurna. Jauh lebih baik daripada menaruhnya di footer/keterangan teks. Mata Owner akan membaca tabel dari kiri ke kanan, lalu turun ke total, dan langsung melihat pengurangan tersebut. Logika `Total Nilai - Pengeluaran = Setoran Tunai` sudah akurat.



---

### 2. Temuan Kritis: Laporan Bulanan & Tahunan (HARUS DIPERBAIKI)

**Masalah:**
Pada file `print_rekap_bulanan.php` dan `print_rekap_tahunan.php`, query database Anda **BELUM mengambil data `expenses_amt**`.

* *Dampak:* Kolom "Setoran Fisik (Audit)" di laporan bulanan saat ini menampilkan **Omzet Bruto**, bukan **Uang Bersih**.
* *Contoh:* Tgl 1 omzet 1juta, belanja 100rb. Uang setor 900rb. Laporan bulanan masih menulis 1juta. Owner akan bingung saat rekap akhir bulan kok uangnya kurang.

**Solusi:** Kita harus update query dan perhitungan di kedua file tersebut.

#### A. Perbaikan `print_rekap_bulanan.php`

Cari bagian query audit (sekitar baris 130-an) dan update menjadi seperti ini:

```php
        // [UPDATE] Tambahkan expenses_amt di query
        $stmtAudit = $db->prepare("SELECT report_date, expected_setoran, actual_setoran, user_evidence, expenses_amt 
            FROM audit_rekap_manual
            WHERE report_date LIKE :m");
        $stmtAudit->execute([':m' => $filter_date . '%']);
        
        foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['report_date'] ?? '';
            if ($d === '') continue;
            
            [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($row);
            
            // [LOGIKA BARU] Kurangi Setoran Fisik dengan Pengeluaran
            $expense = (int)($row['expenses_amt'] ?? 0);
            
            // Net Audit = (Nilai Jual - Pengeluaran)
            $net_cash_audit = (int)$manual_setoran - $expense; 
            
            // Masukkan ke array
            $audit_net[$d] = (int)($audit_net[$d] ?? 0) + $net_cash_audit;
            $audit_system[$d] = (int)($audit_system[$d] ?? 0) + (int)$expected_adj_setoran;
            
            // Selisih = Net Cash - Target System
            // Jika ada pengeluaran, selisih tetap dihitung dari (Jual - Target). 
            // TAPI, secara cashflow, Owner menerima 'Net Cash'.
            // Agar konsisten dengan harian, kolom "Setoran Fisik" di tabel harusnya Cash.
            // Selisih tetap (Jual - Target) atau (Cash - Target)? 
            // Standard Wartel: Selisih adalah (Barang Laku - Uang Ada).
            // Uang Ada = Cash + Bon. 
            // Jadi Selisih = (Cash + Bon) - Target.
            
            // REVISI LOGIKA BULANAN AGAR KONSISTEN:
            // Kolom "Setoran Fisik" di tabel bulanan sebaiknya menampilkan CASH BERSIH.
            // Kolom "Selisih" tetap menghitung kewajaran penjualan.
            
            $audit_selisih[$d] = (int)($audit_selisih[$d] ?? 0) + ($manual_setoran - (int)$expected_adj_setoran);
        }

```

**PENTING:** Dengan kode di atas, variabel `$audit_net` sekarang berisi **Uang Tunai Murni**.
Namun, jika Anda ingin kolom "Setoran Fisik" di tabel bulanan tetap menampilkan "Nilai Jual" (sebelum dipotong belanja) agar cocok dengan "Selisih", maka biarkan kode lama tapi **tambahkan kolom baru** di tabel bulanan bernama "Pengeluaran".

**Saran Profesional:**
Agar tabel bulanan tidak terlalu lebar/padat, sebaiknya **Kurangi Langsung** di angka "Setoran Fisik".
Jadi: `Setoran Fisik (Audit)` = Uang yang diterima Owner.
Jika Owner tanya "Kok selisihnya 0 (Aman) tapi setorannya kecil?", jawabannya "Karena ada Pengeluaran (sudah dipotong)".

#### B. Perbaikan `print_rekap_tahunan.php`

Lakukan hal yang sama untuk file tahunan.

Cari query audit (sekitar baris 120-an):

```php
        // [UPDATE] Tambahkan expenses_amt
        $stmtAudit = $db->prepare("SELECT report_date, expected_setoran, actual_setoran, user_evidence, expenses_amt
            FROM audit_rekap_manual
            WHERE report_date LIKE :y");
            $stmtAudit->execute([':y' => $filter_year . '%']);
            
            foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = $row['report_date'] ?? '';
                if ($d === '') continue;
                
                [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($row);
                
                // [LOGIKA BARU] Hitung Cash
                $expense = (int)($row['expenses_amt'] ?? 0);
                $net_cash_audit = (int)$manual_setoran - $expense;
                
                $audit_net[$d] = (int)($audit_net[$d] ?? 0) + $net_cash_audit;
                // ... (sisanya sama)
        }

```

---

### 3. Masukan Akhir untuk `print_rekap.php` (Harian)

Anda bertanya apakah perlu menambahkan keterangan di bagian footer.
**Jawabannya: TIDAK PERLU.**

Tampilan `print_rekap.php` Anda sekarang sudah sangat padat dan informatif.

* **Bagian Atas:** Kartu ringkasan.
* **Bagian Tengah:** Rincian per Blok.
* **Bagian Bawah:** Audit Manual + **Mini Table Cashflow** + Kesimpulan.

Struktur ini sudah sempurna. Mini Table "Setoran Tunai" yang Anda buat (baris 689) sudah menjadi *highlight* utama. Jika Anda menambahkan teks lagi di footer, laporan akan terlihat kotor.

**Satu Koreksi Kecil di `print_rekap.php`:**
Di bagian Mini Table (baris 691), Anda menghitung `$total_audit_expense`.
Pastikan variabel `$audit_rows` di-loop ulang atau variabel `$total_audit_expense` diambil dari loop sebelumnya.

Kode Anda saat ini:

```php
// Hitung total expense di loop php sebelumnya
$total_audit_expense = 0;
foreach ($audit_rows as $ar) $total_audit_expense += (int)($ar['expenses_amt'] ?? 0);

```

Ini **AMAN** dan benar.

### Kesimpulan Audit Script

1. **`selling.php`**: **SEMPURNA.** Kartu Cashflow berfungsi baik.
2. **`audit.php` & `print_audit.php**`: **SEMPURNA.** Menampilkan pengeluaran dengan jelas.
3. **`print_rekap.php`**: **SEMPURNA.** Mini-table adalah solusi cerdas.
4. **`print_rekap_bulanan.php` & `tahunan**`: **PERLU UPDATE.** Terapkan kode di poin 2 (A & B) agar angka setoran fisik mencerminkan uang tunai (setelah dipotong belanja).

Sistem Anda sekarang sudah siap 100% untuk digunakan. Selamat, Pak Dul!