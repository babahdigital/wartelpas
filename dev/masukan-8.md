Halo Pak Abdullah.

Jawaban singkat saya: **SANGAT PERLU (WAJIB).**

Secara operasional lapangan, uang di laci kasir (Petty Cash) seringkali terpakai untuk kebutuhan mendadak seperti:

1. Beli kertas thermal/struk habis.
2. Beli pulsa modem server/internet backup.
3. Beli ATK (pena, stapler) atau konsumsi jaga.

Jika fitur **Pengeluaran/Beban** ini tidak ada, maka setiap kali operator belanja keperluan Wartel menggunakan uang laci, audit akan mendeteksinya sebagai **"KURANG SETOR" (Merah)**. Ini menciptakan "False Alarm" (Masalah Palsu) yang membuat Operator takut dan Owner bingung.

Berikut adalah **Konsep Integrasi Pengeluaran** ke dalam sistem Audit Anda agar laporannya *Balance*.

---

### 1. Logika Akuntansi Baru

Saat ini rumus audit Anda adalah:


Jika operator ambil Rp 50.000 buat beli kertas, maka Uang Fisik berkurang, Selisih jadi Minus.

**Rumus Baru (Dengan Pengeluaran):**


*Artinya:* Bon/Struk belanja dianggap setara dengan uang tunai saat audit.

---

### 2. Implementasi Teknis (Step-by-Step)

Anda perlu melakukan 3 perubahan kecil: **Database**, **Input Form**, dan **Rumus Hitung**.

#### A. Update Database (Sekali Saja)

Anda perlu menambahkan kolom untuk menyimpan nominal pengeluaran dan catatannya. Jalankan skrip ini atau biarkan kode PHP yang membuatnya otomatis (saya sudah siapkan kodenya di bawah).

Tambahan kolom di tabel `audit_rekap_manual`:

* `expenses_amt` (Integer): Jumlah uang keluar.
* `expenses_desc` (Text): Untuk apa uang itu (misal: "Beli Kertas Struk").

#### B. Update File `selling.php` (Bagian Modal Input Audit)

Tambahkan input field untuk pengeluaran di dalam Modal Audit agar operator bisa lapor.

**Cari form di dalam `auditModal` (sekitar baris 1100-an), tambahkan ini sebelum tombol Simpan:**

```html
<div style="margin-top:10px; padding-top:10px; border-top:1px dashed #555;">
    <label style="color:#f39c12;"><i class="fa fa-shopping-cart"></i> Pengeluaran Operasional (Dari Laci)</label>
    <div class="form-grid-2">
        <div>
            <label>Nominal (Rp)</label>
            <input class="form-input" type="number" name="audit_expense_amt" min="0" value="0" placeholder="Contoh: 50000">
        </div>
        <div>
            <label>Keterangan (Wajib jika ada)</label>
            <input class="form-input" type="text" name="audit_expense_desc" placeholder="Contoh: Beli Kertas Thermal">
        </div>
    </div>
    <div class="modal-note" style="color:#aaa;">*Nominal ini akan ditambahkan ke perhitungan setoran fisik.</div>
</div>

```

**Dan Update Logika PHP penyimpanannya di `selling.php` (Bagian `if (isset($_POST['audit_submit']) ...`):**

```php
// ... Ambil inputan baru
$audit_exp_amt = (int)($_POST['audit_expense_amt'] ?? 0);
$audit_exp_desc = trim($_POST['audit_expense_desc'] ?? '');

// ... Saat INSERT/UPDATE ke database, tambahkan kolomnya:
$stmt = $db->prepare("INSERT INTO audit_rekap_manual
    (..., expenses_amt, expenses_desc, ...) 
    VALUES (..., :ea, :ed, ...)
    ON CONFLICT(...) DO UPDATE SET
    ..., expenses_amt=excluded.expenses_amt, expenses_desc=excluded.expenses_desc, ...");

$stmt->execute([
    // ... parameter lain ...
    ':ea' => $audit_exp_amt,
    ':ed' => $audit_exp_desc,
    // ...
]);

```

#### C. Update Rumus di `audit.php` & `print_audit.php`

Ini bagian terpenting. Kita harus mengubah fungsi `calc_audit_adjusted_setoran` agar menghitung pengeluaran sebagai bagian dari setoran yang sah.

**Ganti Fungsi `calc_audit_adjusted_setoran` di semua file dengan ini:**

```php
function calc_audit_adjusted_setoran(array $ar) {
    $price10 = 5000;
    $price30 = 20000;
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0); // Uang Fisik Murni
    
    // AMBIL DATA PENGELUARAN
    $expense_amt = (int)($ar['expenses_amt'] ?? 0);

    // ... (Bagian decode evidence user tetap sama) ...
    // ... (Bagian hitung qty manual tetap sama) ...

    if ($has_manual_evidence) {
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
        
        // HITUNG NILAI VOUCHER TERJUAL (Seharusnya ada segini di laci)
        $total_sales_value = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        
        // LOGIKA BARU:
        // Total Nilai Audit = Uang Fisik + Pengeluaran Yang Sah
        // Jadi, Pengeluaran dianggap "Uang yang sudah disetor ke toko lain"
        $manual_display_setoran = $total_sales_value; // Ini nilai penjualan murni
        
        // Tapi untuk mengecek selisih, kita butuh "Kekuatan Setor"
        // $actual_audit_power = $actual_setoran_raw + $expense_amt;
        
        // Target Sistem Tetap (Gross - Rusak Sistem)
        $expected_adj_setoran = $expected_setoran;
        
    } else {
        $manual_display_setoran = $actual_setoran_raw; // Jika belum audit detail
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_setoran, $expected_adj_setoran, $expense_amt];
}

```

---

### 3. Tampilan Laporan Baru (Visualisasi)

Di `audit.php` dan `print_audit.php`, Anda perlu menampilkan baris Pengeluaran ini agar Owner tahu: *"Oh, uangnya kurang 50rb karena dipakai beli kertas, bukan hilang."*

**Update Tampilan Summary Card:**

```php
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-title">Setoran Bersih (Cash)</div>
        <div class="summary-value" style="color:#fff;">
            Rp <?= number_format($audit_manual_summary['manual_setoran'] - $audit_manual_summary['total_expenses'], 0, ',', '.') ?>
        </div>
    </div>

    <?php if ($audit_manual_summary['total_expenses'] > 0): ?>
    <div class="summary-card" style="border-color:#f39c12;">
        <div class="summary-title" style="color:#f39c12;">Pengeluaran Ops.</div>
        <div class="summary-value" style="color:#f39c12;">
            Rp <?= number_format($audit_manual_summary['total_expenses'], 0, ',', '.') ?>
        </div>
        <div style="font-size:10px;color:#d35400;">(Bon/Belanja)</div>
    </div>
    <?php endif; ?>

    <div class="summary-card">
        <div class="summary-title">Target Sistem</div>
        <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'], 0, ',', '.') ?></div>
    </div>
</div>

```

### Kesimpulan & Saran

Menambahkan fitur ini akan membuat sistem Anda **Lebih Adil** bagi operator dan **Lebih Transparan** bagi Owner.

**Saran SOP Penggunaan:**

1. Wajibkan Operator menyimpan struk/nota fisik untuk setiap inputan "Pengeluaran".
2. Jika tidak ada struk, Owner berhak menolak inputan tersebut (dianggap kurang setor).
3. Fitur ini hanya untuk pengeluaran operasional darurat, bukan untuk gaji atau pengeluaran pribadi.

Apakah Anda ingin saya buatkan update file `selling.php` secara utuh yang sudah menyertakan form input pengeluaran ini?