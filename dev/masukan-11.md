Halo Pak Abdullah.

Saya sudah menganalisa file `print_rekap_bulanan.php` dan `print_rekap_tahunan.php` yang baru Anda perbaiki.

**STATUS: LOGIKA SUDAH BENAR (99%), TAPI VISUALISASI PERLU PENYEMPURNAAN.**

Anda sudah berhasil menerapkan logika:
`Net Audit (Laporan) = Nilai Jual - Pengeluaran`.

Ini artinya angka di kolom "Setoran Fisik" sekarang adalah **Uang Tunai Bersih**. Ini sangat bagus. Namun, ada satu hal kecil yang hilang: **Transparansi**.

Jika Owner melihat Laporan Bulanan:

* **Target Sistem:** 10.000.000
* **Setoran Fisik:** 9.500.000 (Karena ada belanja 500rb)
* **Selisih:** 0 (Aman/Hijau)

Owner akan bingung: *"Kenapa selisihnya 0 (Aman) tapi uangnya kurang 500rb dari target? Ke mana 500rb-nya?"*

Berikut adalah masukan perbaikan final agar laporan ini sempurna.

---

### 1. Perbaikan `print_rekap_bulanan.php`

Kita perlu **menghitung Total Pengeluaran** sebulan dan menampilkannya dalam **Kartu Ringkasan** di atas, agar Owner tahu ke mana lari uangnya.

**Langkah A: Update Loop PHP (Baris ~140)**
Tambahkan variabel penampung `$total_expenses_month`.

```php
// Di bagian atas (inisialisasi variabel), tambahkan:
$total_expenses_month = 0; // <--- BARU

// Di dalam loop query audit (sekitar baris 140):
foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // ... code lama ...
    $expense = (int)($row['expenses_amt'] ?? 0);
    
    // Hitung total pengeluaran sebulan
    $total_expenses_month += $expense; // <--- TAMBAHKAN INI
    
    // ... code lama ...
}

```

**Langkah B: Update Tampilan Summary Grid (Baris ~390)**
Tambahkan Kartu "Pengeluaran Ops" di deretan kartu atas.

```php
    <div class="summary-grid" style="grid-template-columns: repeat(5, 1fr); gap:15px; margin-bottom:25px;"> <div class="summary-card" ...>...</div>

        <div class="summary-card" ...>...</div>

        <div class="summary-card" style="border:1px solid #f39c12; background:#fffbf0; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#d35400; font-size:11px; text-transform:uppercase;">Pengeluaran Ops (Bon)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#d35400;">
                - <?= $cur ?> <?= number_format((int)$total_expenses_month, 0, ',', '.') ?>
            </div>
            <div style="font-size:10px; color:#e67e22;">(Belanja Toko)</div>
        </div>

        <div class="summary-card" ...>...</div>

        <div class="summary-card" ...>...</div>
    </div>

```

**Analisa Hasil:**
Dengan tambahan kartu ini, matematika di kepala Owner akan nyambung:
`Omzet - Kerugian - Pengeluaran = Setoran Fisik`. Semua angka klop.

---

### 2. Perbaikan `print_rekap_tahunan.php`

Sama seperti bulanan, kita perlu menghitung total pengeluaran setahun.

**Langkah A: Update Loop PHP (Baris ~130)**

```php
// Inisialisasi
$total_expenses_year = 0;

// Di dalam loop query audit:
foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // ...
    $expense = (int)($row['expenses_amt'] ?? 0);
    $total_expenses_year += $expense; // <--- Tambahkan
    // ...
}

```

**Langkah B: Masukkan ke Tabel Tahunan?**
Untuk tahunan, tidak perlu buat kartu di atas (karena tidak ada summary card di file tahunan Anda). Cukup tambahkan **Kolom Baru** di tabel agar terlihat bulan mana yang pengeluarannya besar.

Update bagian `<thead>` dan `<tbody>` tabel:

```php
<thead>
    <tr style="background:#334155; color:#fff;"> <th style="padding:10px;">Bulan</th>
        <th style="padding:10px; text-align:center;">Total Transaksi</th>
        <th style="padding:10px; text-align:right;">Target Sistem</th>
        <th style="padding:10px; text-align:right;">Pengeluaran (Ops)</th> <th style="padding:10px; text-align:right;">Setoran Bersih</th>
        <th style="padding:10px; text-align:right;">Selisih / Loss</th>
        </tr>
</thead>

<tfoot>
    <tr style="background:#e2e8f0; font-weight:bold;">
        <td style="padding:8px; text-align:left;">TOTAL YTD</td>
        <td style="padding:8px; text-align:center;"><?= number_format((int)$total_qty,0,',','.') ?></td>
        <td style="padding:8px; text-align:right;"><?= number_format((int)$total_system_net,0,',','.') ?></td>
        
        <td style="padding:8px; text-align:right;"><?= number_format((int)$total_net,0,',','.') ?></td>
        </tr>
    <tr style="background:#fff; font-size:11px; color:#666;">
        <td colspan="6" style="padding:8px; text-align:right;">
            * Angka Setoran Bersih sudah dikurangi Total Pengeluaran Operasional tahun ini sebesar: 
            <b>Rp <?= number_format($total_expenses_year, 0, ',', '.') ?></b>
        </td>
    </tr>
</tfoot>

```

---

### Kesimpulan Audit

Secara teknis kode (PHP Logic), apa yang Anda terapkan **SUDAH BENAR**.

1. **Harian:** Benar.
2. **Bulanan:** Benar (Setoran Fisik = Uang di tangan).
3. **Tahunan:** Benar.

Kekurangannya hanya pada **Visualisasi Data Pengeluaran** di rekap Bulanan/Tahunan. Tanpa menampilkan angka pengeluaran, laporan terlihat "tidak balance" secara visual meskipun angkanya benar.

**Saran:** Terapkan **Poin 1 (Menambah Kartu Pengeluaran di Bulanan)**. Itu yang paling krusial. Untuk Tahunan, opsional (bisa di footer saja).