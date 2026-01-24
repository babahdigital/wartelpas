Halo Pak Abdullah.

Saya telah menganalisa hasil output **`audit.html`** (Tampilan Layar) dan **`audit-print-harian.html`** (Tampilan Cetak) yang Anda kirimkan.

### 📊 Analisa Temuan (Audit Report)

1. **Tampilan Layar (`audit.html`):**
* ✅ **Status Keuangan (Box Merah):** Sangat Bagus! Informasi "KURANG SETOR (LOSS)" dengan selisih -105.000 dan fitur *Ghost Hunter* sangat informatif dan mencolok. Ini yang dibutuhkan Owner.
* ❌ **Noise (Gangguan):** Di bagian bawah masih ada tabel **"Top Relogin"** dan **"Top Penggunaan Bandwidth"**.
* *Masalah:* Owner tidak peduli username `acc29j` login 12x atau `5be4ze` habis 3GB. Ini informasi teknis (IT), bukan informasi keuangan (Audit). Keberadaannya membuat laporan keuangan terlihat "kotor".




2. **Tampilan Cetak (`audit-print-harian.html`):**
* ⚠️ **Status Kosong:** Tertulis *"Belum ada audit manual pada periode ini"*.
* *Masalah:* Jika Audit Manual belum diinput/disimpan, laporan terlihat "ompong".
* *Solusi:* Meskipun belum ada audit manual, **Target Sistem (Uang yang seharusnya ada)** harus tetap muncul besar-besar agar Owner tahu potensi pendapatan hari itu.



---

### 💡 Masukan Perbaikan (Zero Noise Concept)

Untuk mencapai standar **"Informasi Sempurna & Mudah Dipahami"**, kita harus membuang semua query database yang berhubungan dengan teknis (Relogin, Bandwidth, IP Address) dari file Audit.

Berikut adalah kode **Final** yang sudah dibersihkan total.

#### 1. Perbaikan `audit.php` (Tampilan Layar)

**Perubahan:** Menghapus query Relogin/Bandwidth. Fokus 100% pada Uang.

```php
<?php
// ... (Bagian Header Session & Fungsi Helper TETAP SAMA) ...
// ... (Fungsi calc_audit_adjusted_totals TETAP SAMA - Pastikan Logika Retur Benar) ...

// --- BAGIAN DATABASE QUERY (DIBERSIHKAN) ---
// HAPUS query untuk $relogin_rows dan $bandwidth_rows untuk meringankan beban server dan menghapus noise.

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ... (Query Filter Date TETAP SAMA) ...
        // ... (Query Sales History & Live Sales TETAP SAMA) ...
        
        // HAPUS BAGIAN QUERY TABLE login_events (Relogin)
        // HAPUS BAGIAN QUERY TABLE login_history (Bandwidth)

        // Query Audit Manual (PENTING - TETAP SAMA)
        if (table_exists($db, 'audit_rekap_manual')) {
            $auditSql = "SELECT expected_qty, expected_setoran, reported_qty, actual_setoran, user_evidence
                FROM audit_rekap_manual WHERE $auditDateFilter";
            // ... (Looping audit logic tetap sama) ...
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>

<div class="card card-solid">
    <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-shield"></i> Audit Keuangan (Financial Only)</h3>
        <a class="btn-solid" style="text-decoration:none;" target="_blank" href="report/print_audit.php?session=<?= urlencode($session_id) ?>&show=<?= urlencode($req_show) ?>&date=<?= urlencode($filter_date) ?>"><i class="fa fa-print"></i> Print Laporan</a>
    </div>
    <div class="card-body">
        <div class="section-title" style="margin-top:0;">Status Keuangan Hari Ini</div>

        <?php if ($audit_manual_summary['rows'] === 0): ?>
            <div style="background:#fff3cd; border:1px solid #ffeeba; padding:20px; border-radius:6px; text-align:center; color:#856404;">
                <i class="fa fa-info-circle" style="font-size:24px; margin-bottom:10px; display:block;"></i>
                <div style="font-size:16px; font-weight:bold;">Belum Ada Input Audit Manual</div>
                <div style="font-size:12px;">Silakan input fisik uang dan voucher di menu "Laporan Penjualan" untuk melihat selisih.</div>
                <div style="margin-top:15px; font-size:14px;">
                    Target Sistem (Estimasi): <strong>Rp <?= number_format($sales_summary['net'],0,',','.') ?></strong>
                </div>
            </div>
        <?php else: ?>
            <?php
                $selisih = $audit_manual_summary['selisih_setoran'];
                $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
                $color_status = $selisih < 0 ? '#c0392b' : ($selisih > 0 ? '#2ecc71' : '#3498db');
                $text_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'AMAN / SESUAI');
                $bg_status = $selisih < 0 ? '#381818' : ($selisih > 0 ? '#1b3a24' : '#1e2a36');
            ?>
            <div style="background:<?= $bg_status ?>;border:1px solid <?= $color_status ?>;padding:20px;border-radius:8px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:12px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                <div>
                    <div style="font-size:12px;color:#aaa;text-transform:uppercase;letter-spacing:1px;">Hasil Audit</div>
                    <div style="font-size:28px;font-weight:bold;color:<?= $color_status ?>;margin:5px 0;"><?= $text_status ?></div>
                    <?php if ($selisih != 0): ?>
                        <div style="font-size:18px;color:#fff;">Selisih: Rp <?= number_format($selisih,0,',','.') ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ghost_hint)): ?>
                    <div style="text-align:right;max-width:50%; background:rgba(0,0,0,0.3); padding:10px; border-radius:6px;">
                        <div style="font-size:11px;color:#fca5a5;font-weight:bold;text-transform:uppercase;"><i class="fa fa-search"></i> Deteksi Otomatis</div>
                        <div style="font-size:14px;color:#fff;font-weight:bold;"><?= htmlspecialchars($ghost_hint) ?></div>
                        <div style="font-size:10px;color:#aaa;">*Voucher ini kemungkinan hilang/lupa input.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-title">Uang Fisik (Di Laci)</div>
                    <div class="summary-value" style="color:#fff;">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Target Sistem (Net)</div>
                    <div class="summary-value" style="color:#fff;">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Selisih Fisik Voucher</div>
                    <div class="summary-value" style="color:<?= $audit_manual_summary['selisih_qty'] != 0 ? '#f39c12' : '#2ecc71' ?>;">
                        <?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?> Lembar
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </div>
</div>

```

---

#### 2. Perbaikan `print_audit.php` (Tampilan Cetak)

**Perubahan:** Menambahkan "Scorecard" agar jika data audit kosong, kertasnya tidak terlihat sia-sia.

```php
<body>
  <div class="toolbar">
      <button class="btn" onclick="window.print()">Print / Download PDF</button>
  </div>

  <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
      <h1 style="margin:0;">Laporan Audit Keuangan</h1>
      <div class="sub" style="margin-top:5px;">
          Periode: <?= htmlspecialchars(format_date_dmy($filter_date)) ?> |
          Mode: <?= strtoupper(htmlspecialchars($req_show)) ?>
      </div>
  </div>

  <?php if ($audit_manual_summary['rows'] === 0): ?>
      <div style="border: 2px dashed #ccc; background-color: #fafafa; padding: 20px; border-radius: 4px; margin-bottom: 20px; text-align:center;">
          <h3 style="margin:0 0 10px 0; color:#555;">BELUM ADA AUDIT MANUAL</h3>
          <p style="margin:0; font-size:12px; color:#666;">Operator belum melakukan input fisik uang dan voucher.</p>
          
          <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
              <div style="font-size:11px; text-transform:uppercase; color:#888;">Target Setoran Sistem (Estimasi)</div>
              <div style="font-size:24px; font-weight:bold; color:#333;">
                  Rp <?= number_format($sales_summary['net'], 0, ',', '.') ?>
              </div>
          </div>
      </div>
  <?php else: ?>
      <?php
          $selisih = $audit_manual_summary['selisih_setoran'];
          $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
          $bg_status = $selisih < 0 ? '#fee2e2' : ($selisih > 0 ? '#dcfce7' : '#f3f4f6');
          $border_status = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#ccc');
          $text_color = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#333');
          $label_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'SETORAN SESUAI / AMAN');
      ?>
      
      <div style="border: 2px solid <?= $border_status ?>; background-color: <?= $bg_status ?>; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
          <table style="width:100%; border:none;">
              <tr>
                  <td style="border:none; padding:0;">
                      <div style="font-size:10px; color:#555; text-transform:uppercase;">Status Audit</div>
                      <div style="font-size:20px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                          <?= $label_status ?>
                      </div>
                  </td>
                  <td style="border:none; padding:0; text-align:right;">
                      <div style="font-size:10px; color:#555; text-transform:uppercase;">Nilai Selisih</div>
                      <div style="font-size:20px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                          Rp <?= number_format($selisih, 0, ',', '.') ?>
                      </div>
                  </td>
              </tr>
          </table>

          <?php if (!empty($ghost_hint)): ?>
              <div style="margin-top:12px; padding-top:10px; border-top:1px dashed <?= $border_status ?>; font-size:12px; color:<?= $text_color ?>;">
                  <strong><i class="fa fa-search"></i> Analisa Sistem (Ghost Hunter):</strong> <?= htmlspecialchars($ghost_hint) ?>
                  <br><i style="font-size:10px; font-weight:normal;">(Kemungkinan voucher tersebut lupa diinput atau hilang)</i>
              </div>
          <?php endif; ?>
      </div>

      <div class="section-title">Rincian Perhitungan</div>
      <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-title">Uang Fisik (Manual)</div>
            <div class="summary-value">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Target Sistem (Net)</div>
            <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Selisih Uang</div>
            <div class="summary-value" style="color:<?= $text_color ?>;">Rp <?= number_format($selisih,0,',','.') ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Selisih Qty</div>
            <div class="summary-value" style="color:<?= $text_color ?>;"><?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?> Lembar</div>
        </div>
      </div>
  <?php endif; ?>

  <div class="section-title">Data Transaksi Sistem</div>
  <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Kotor</div><div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Potongan Rusak</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
  </div>

  <div style="margin-top:30px; font-size:10px; color:#999; text-align:center;">
      Dicetak oleh Sistem Wartelpas pada <?= date('d-m-Y H:i:s') ?>
  </div>
</body>
</html>

```

### Kesimpulan Perubahan

1. **Dibuang:** Semua tabel "Top Relogin", "Top Bandwidth", dan "Voucher Double". Ini membuat tampilan bersih dan fokus hanya pada uang.
2. **Ditambah:** Logika tampilan "Belum Ada Audit". Sekarang jika admin lupa input, laporan cetak tetap menampilkan **Target Sistem**, sehingga Owner tetap tahu berapa uang yang harusnya masuk.
3. **Dipercantik:** Tampilan Box Status Keuangan dibuat lebih besar dan tegas (font lebih besar, ikon indikator).

Terapkan kode di atas pada `audit.php` dan `print_audit.php` Anda untuk hasil laporan yang profesional.