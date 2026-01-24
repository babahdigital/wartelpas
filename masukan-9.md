Halo Pak Abdullah.

Saya telah menganalisa ke-7 file yang Anda kirimkan. Integrasi fitur **Pengeluaran (Expenses)** sudah masuk dalam logika database dan perhitungan, namun **VISUALISASI-nya belum muncul** di halaman utama (`selling.php`) maupun cetakan (`print_rekap.php`).

Ini berbahaya karena Owner bisa melihat "Setoran Audit" tinggi, tapi uang di laci tidak sebanyak itu (karena terpotong belanja), yang bisa menimbulkan kecurigaan.

Berikut adalah masukan profesional untuk menampilkannya dengan **Elegan, Tidak Berlebihan, namun Transparan**.

---

### 1. Analisa & Solusi untuk `selling.php` (Tampilan Dashboard)

**Masalah:** Saat ini kartu ringkasan hanya menampilkan **Net Income** (Pendapatan Bersih Sistem). Tidak ada info berapa uang fisik yang sebenarnya harus disetor (Cashflow).

**Solusi Elegan:**
Jangan buat kartu baru yang memakan tempat. **Modifikasi Kartu "Net Audit" (atau Net Income)** untuk menampilkan pecahan *Cash* vs *Bon*.

Cari bagian `` di `selling.php` (sekitar baris 1700-an), dan ubah logika tampilannya.

**Kode Perbaikan (Gantikan bagian Summary Grid):**

```php
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title">Gross Income</div>
                <div class="summary-value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
            </div>
            
            <div class="summary-card">
                <div class="summary-title">Total Loss</div>
                <div class="summary-value" style="color:#c0392b;">
                    <?= $cur ?> <?= number_format($kerugian_display,0,',','.') ?>
                </div>
                <div style="font-size:11px;color:var(--txt-muted)">
                    Vc: <?= number_format($voucher_loss_display,0,',','.') ?> | 
                    Selisih: <?= number_format($setoran_loss_display,0,',','.') ?>
                </div>
            </div>

            <?php 
                // Hitung total pengeluaran dari semua audit block hari ini
                $total_expenses_today = 0;
                foreach ($audit_rows as $ar) {
                    $total_expenses_today += (int)($ar['expenses_amt'] ?? 0);
                }
                
                // Uang Fisik = (Net Audit Total) - (Pengeluaran)
                // Catatan: $audit_total_actual_setoran di script Anda sudah termasuk expenses (karena logic += expense di func calc).
                // Maka kita kurangi lagi untuk dapat Cash Murni.
                $real_cash = $audit_total_actual_setoran - $total_expenses_today;
            ?>
            <div class="summary-card" style="border:1px solid <?= $total_expenses_today > 0 ? '#f39c12' : '#3a4046' ?>;">
                <div class="summary-title">Setoran Fisik (Cash)</div>
                <div class="summary-value" style="color:#fff;">
                    <?= $cur ?> <?= number_format($real_cash,0,',','.') ?>
                </div>
                <?php if ($total_expenses_today > 0): ?>
                    <div style="font-size:11px;color:#f39c12; margin-top:2px;">
                        <i class="fa fa-minus-circle"></i> Ops: <?= $cur ?> <?= number_format($total_expenses_today,0,',','.') ?> (Bon)
                    </div>
                <?php else: ?>
                    <div style="font-size:11px;color:#555;">Murni Tunai</div>
                <?php endif; ?>
            </div>

            <div class="summary-card">
                <div class="summary-title">Voucher Terjual</div>
                <div class="summary-value"><?= number_format($total_qty_laku,0,',','.') ?></div>
                <div style="font-size:11px;color:var(--txt-muted);">Rusak: <?= $total_qty_rusak ?> | Retur: <?= $total_qty_retur ?></div>
            </div>
            
            <div class="summary-card">
                <div class="summary-title">Total Device</div>
                <div class="summary-value" style="color:#f39c12;"><?= number_format($hp_total_units,0,',','.') ?></div>
                <div style="font-size:11px;color:var(--txt-muted);">Aktif: <span class="text-green"><?= $hp_active_units ?></span> | Rusak: <?= $hp_rusak_units ?></div>
            </div>
        </div>

```

**Analisa Perubahan:**

* Info **Setoran Fisik (Cash)** sekarang terlihat jelas.
* Jika ada belanja (Ops), muncul tulisan kecil warna oranye: *"- Ops: Rp 50.000 (Bon)"*.
* Ini tidak memakan tempat tambahan tapi informasinya sangat vital.

---

### 2. Analisa & Solusi untuk `print_rekap.php` (Cetak Harian)

**Masalah:** Anda benar, halaman ini sudah sangat padat tabel. Menaruh di footer ("Keterangan: ...") **SANGAT TIDAK DISARANKAN** karena itu area teks statis yang sering diabaikan mata. Uang harus di tempat angka.

**Solusi Elegan:**
Manfaatkan **Kotak "Audit Manual Rekap Harian"** (Tabel kedua).
Tambahkan satu baris rekapitulasi pengeluaran **tepat di bawah tabel Audit Manual**, sebelum kotak "Kesimpulan Audit".

**Kode Perbaikan (Sisipkan di `print_rekap.php` setelah `</table>` Audit Manual, baris ~688):**

```php
            </table> <?php 
                // Hitung total expense di loop php sebelumnya
                $total_audit_expense = 0;
                foreach ($audit_rows as $ar) $total_audit_expense += (int)($ar['expenses_amt'] ?? 0);
                
                if ($total_audit_expense > 0): 
                    $total_cash_on_hand = $audit_total_actual_setoran_adj - $total_audit_expense;
            ?>
            <div style="margin-top:10px; display:flex; justify-content:flex-end;">
                <table style="width:300px; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <td style="padding:4px; text-align:right; color:#666;">Total Nilai Audit:</td>
                        <td style="padding:4px; text-align:right; font-weight:bold; border-bottom:1px solid #ddd;">
                            Rp <?= number_format($audit_total_actual_setoran_adj,0,',','.') ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:4px; text-align:right; color:#d35400;">(-) Pengeluaran Ops:</td>
                        <td style="padding:4px; text-align:right; color:#d35400; border-bottom:1px solid #000;">
                            Rp <?= number_format($total_audit_expense,0,',','.') ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 4px; text-align:right; font-weight:bold;">SETORAN TUNAI:</td>
                        <td style="padding:6px 4px; text-align:right; font-weight:bold; font-size:13px;">
                            Rp <?= number_format($total_cash_on_hand,0,',','.') ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <div class="audit-summary-box" ... > ```

**Analisa Perubahan:**
* Muncul tabel kecil "mini-kalkulator" di kanan bawah tabel audit.
* Alurnya jelas: **Total Audit** -> dikurangi **Pengeluaran** -> **Uang Tunai**.
* Owner langsung lihat angka paling bawah: *"Oh, ini uang yang harus saya terima di amplop"*.

---

### 3. Audit Kode & Koreksi Bug (Important)

Saya menemukan satu **Logic Flaw (Celah Logika)** pada file `selling.php` dan `audit.php` terkait fungsi `calc_audit_adjusted_setoran`.

**Kode Saat Ini:**
```php
$manual_display_setoran = ($manual_net_qty_10 * $price10) + ... ;
$manual_display_setoran += $expense_amt; // Ditambah Expense

```

**Masalahnya:**
Jika Operator input Qty Manual = 10 (Rp 50.000) dan Pengeluaran = Rp 10.000.

* System Hitung: 50.000 + 10.000 = **60.000**.
* Target System: 50.000.
* Selisih: **+10.000 (Lebih Setor)**.

Padahal maksud operator: *"Saya jual 50rb, tapi 10rb dipake belanja"*. Uang fisik sisa 40rb + Struk 10rb. Nilainya pas 50rb.

**Perbaikan Logika (Logic Fix):**
Pengeluaran itu **BUKAN** menambah nilai jual, tapi **MENJELASKAN KEKURANGAN UANG**.

**Revisi Fungsi `calc_audit_adjusted_setoran` (Terapkan di `selling.php`, `print_rekap.php`, dll):**

```php
function calc_audit_adjusted_setoran(array $ar) {
    // ... setup vars ...
    $expense_amt = (int)($ar['expenses_amt'] ?? 0);

    // ... hitung qty ...

    if ($has_manual_evidence) {
        // Nilai Voucher yang terjual (Revenue Murni)
        $voucher_value = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        
        // Manual Display Setoran = NILAI AUDIT (Bukan cuma uang cash)
        // Nilai Audit = Uang Tunai + Bon Pengeluaran
        // Di database, 'actual_setoran' biasanya diisi total nilai oleh script simpan.
        // TAPI, kita harus konsisten.
        
        // LOGIKA BENAR:
        // Setoran Audit = Nilai Voucher.
        // Jika uang fisik kurang, itu urusan Cashflow, bukan Revenue.
        
        $manual_display_setoran = $voucher_value; 
        
        // PENTING: Jangan ditambah $expense_amt di sini jika $manual_display_setoran adalah Revenue.
        // KECUALI jika definisi 'actual_setoran' di database Anda adalah UANG FISIK SAJA.
        
        // Cek input form Anda di selling.php:
        // Input 'audit_setoran' adalah readonly total (Qty * Harga).
        // Jadi database menyimpan REVENUE, bukan CASH.
        
        // KESIMPULAN: Hapus baris "$manual_display_setoran += $expense_amt;"
        // Pengeluaran tidak menambah Revenue. Pengeluaran mengurangi Cash.
    } 
    // ...
}

```

**Tapi tunggu**, di `selling.php` (form save), input `audit_setoran` adalah `readonly` (dihitung JS dari qty). Jadi yang disimpan di DB kolom `actual_setoran` adalah **Total Revenue (Gross)**.

Maka, untuk menampilkan **Cashflow**, rumusnya adalah:


**Jadi:**

1. **Jangan ubah** fungsi `calc_audit_adjusted_setoran` untuk menambah expense. Biarkan ia menghitung Revenue.
2. **Gunakan Kode Visualisasi** di poin 1 dan 2 di atas yang melakukan pengurangan (`$row['actual_setoran'] - $row['expenses_amt']`) untuk menampilkan "Setoran Tunai".

### Kesimpulan Akhir

1. **Script Anda aman** secara logika dasar (Pendapatan vs Target).
2. **Pengeluaran** sebaiknya ditampilkan sebagai **Pengurang Cash**, bukan penambah pendapatan.
3. Implementasikan **Tabel Mini** di `print_rekap.php` dan **Modifikasi Kartu** di `selling.php` sesuai kode di atas agar laporan terlihat profesional, bersih, dan akurat secara akuntansi.