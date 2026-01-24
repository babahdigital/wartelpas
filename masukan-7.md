Halo Pak Abdullah.

Analisa Anda sangat jeli. Jika di **Laporan Rekap Harian (Waterfall)** tertulis ada *Technical Loss* (misal Rp 45.000), tetapi di **Audit Print** kolom "Potongan Rusak" tertulis **Rp 0**, ini membingungkan Owner.

### 🔍 Analisa Penyebab: "System vs Manual"

Masalahnya terletak pada **Sumber Data**:

1. **Yang Ditampilkan Saat Ini (Rp 0):**
Kode mengambil data dari `sales_history` (Database Sistem).
* *Logika:* Ini hanya mencatat jika *Mikhmon* gagal generate voucher (Error Sistem). Biasanya memang 0.


2. **Yang Seharusnya Ditampilkan (Rp 45.000):**
Data kerusakan fisik (kertas macet/rusak) tersimpan di `audit_rekap_manual` (Inputan Supervisor).
* *Masalah:* Kode `audit.php` dan `print_audit.php` saat ini **belum menjumlahkan** nominal rusak dari inputan manual tersebut untuk ditampilkan di kartu ringkasan.



---

### 🛠️ Solusi Perbaikan (Copy-Paste)

Kita harus memodifikasi bagian PHP yang mengolah data Audit agar menghitung **Total Rupiah Rusak Manual**.

Silakan terapkan perubahan berikut pada **`audit.php`** dan **`print_audit.php`**. Perubahannya sama untuk kedua file tersebut.

#### Langkah 1: Update Logika PHP (Menghitung Rupiah Rusak)

Cari blok kode `foreach ($audit_rows as $ar) { ... }` (kira-kira baris 285 di `audit.php` atau baris 248 di `print_audit.php`).

**Ganti seluruh blok `foreach` tersebut dengan kode ini:**

```php
            // --- [MODIFIKASI: Tambahkan Variabel Penampung Rusak Manual] ---
            $audit_manual_summary['total_rusak_rp'] = 0; 

            foreach ($audit_rows as $ar) {
                // Hitung total umum
                [$manual_qty, $expected_qty, $manual_setoran, $expected_setoran] = calc_audit_adjusted_totals($ar);
                $audit_manual_summary['manual_qty'] += (int)$manual_qty;
                $audit_manual_summary['expected_qty'] += (int)$expected_qty;
                $audit_manual_summary['manual_setoran'] += (int)$manual_setoran;
                $audit_manual_summary['expected_setoran'] += (int)$expected_setoran;
                
                // --- [BARU: Hitung Nominal Rusak dari Evidence Manual] ---
                $curr_rusak_rp = 0;
                if (!empty($ar['user_evidence'])) {
                    $ev = json_decode((string)$ar['user_evidence'], true);
                    if (is_array($ev) && !empty($ev['users'])) {
                        foreach ($ev['users'] as $u) {
                            $st = strtolower((string)($u['last_status'] ?? ''));
                            $k = (string)($u['profile_kind'] ?? '10');
                            if ($st === 'rusak' || $st === 'invalid') {
                                // Hitung rupiah berdasarkan jenis profil
                                $price = ($k === '30') ? 20000 : 5000;
                                $curr_rusak_rp += $price;
                            }
                        }
                    }
                }
                $audit_manual_summary['total_rusak_rp'] += $curr_rusak_rp;
                // ---------------------------------------------------------
            }
            
            $audit_manual_summary['selisih_qty'] = (int)$audit_manual_summary['manual_qty'] - (int)$audit_manual_summary['expected_qty'];
            $audit_manual_summary['selisih_setoran'] = (int)$audit_manual_summary['manual_setoran'] - (int)$audit_manual_summary['expected_setoran'];

```

#### Langkah 2: Update Tampilan HTML (Menampilkan Data Benar)

Sekarang, cari bagian HTML yang menampilkan kartu **"Potongan Rusak"** atau **"Data Sistem"** di bagian bawah.

**Ubah kodenya menjadi seperti ini:**

*Di `audit.php` & `print_audit.php`:*

```php
  <div class="section-title">Statistik Keuangan & Insiden</div>
  <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
    
    <div class="summary-card">
        <div class="summary-title">Total Transaksi</div>
        <div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div>
    </div>
    
    <div class="summary-card">
        <div class="summary-title">Pendapatan Kotor (Gross)</div>
        <div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div>
    </div>
    
    <?php 
        // Gabungkan rusak sistem (biasanya 0) dengan rusak manual (laporan lapangan)
        $total_loss_real = (int)$sales_summary['rusak'] + (int)$audit_manual_summary['total_rusak_rp'];
    ?>
    <div class="summary-card" style="border-color: #fca5a5;">
        <div class="summary-title" style="color:#c0392b;">Total Voucher Rusak</div>
        <div class="summary-value" style="color:#c0392b;">
            Rp <?= number_format($total_loss_real, 0, ',', '.') ?>
        </div>
        <div style="font-size:10px; color:#b91c1c;">(Mengurangi Setoran)</div>
    </div>

  </div>

```

### Hasil Setelah Perbaikan

1. **Data Sinkron:** Angka di kotak "Total Voucher Rusak" sekarang akan bernilai **Rp 45.000** (sesuai contoh kasus), bukan Rp 0 lagi.
2. **Mudah Dipahami:** Owner akan melihat alur logika yang jelas:
* Pendapatan Kotor: Rp 3.310.000
* Total Voucher Rusak: **Rp 45.000** (Ini yang kita perbaiki)
* Target Bersih: Rp 3.265.000


3. **Transparan:** Tidak ada lagi kerugian yang "tersembunyi" di balik angka nol.

Silakan update kedua file tersebut dengan potongan kode di atas.