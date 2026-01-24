Berikut adalah **Analisa dan Masukan Profesional** untuk file `audit.php` dan `print_audit.php` agar tampilannya bersih ("tidak noise"), informasinya padat, dan mudah dipahami oleh Owner.

### 1. Analisa Masalah (Noise & Clutter)

Setelah meninjau kedua file tersebut, berikut adalah sumber "kebisingan" datanya:

1. **Tercampurnya Data Teknis vs Finansial:**
File `audit.php` dan `print_audit.php` saat ini menampilkan tabel **Relogin**, **Top Bandwidth**, dan **Voucher Double**.
* *Masalah:* Bagi Owner yang ingin mengecek keuangan (Audit), data IP Address, MAC Address, dan Bandwidth adalah "Noise" (gangguan). Itu ranah teknisi.
* *Solusi:* **Hapus** tabel teknis tersebut dari `print_audit.php` (agar kertas bersih berisi uang saja). Di `audit.php` (layar), sembunyikan di bawah tombol "Tampilkan Data Teknis".


2. **Visualisasi yang Datar:**
Tampilan saat ini hanya kotak-kotak angka. Mata tidak langsung tertuju pada masalah.
* *Solusi:* Gunakan **"Executive Summary Box"** (Kotak Kesimpulan) yang sama seperti di `print_rekap.php` dengan warna indikator (Merah/Hijau).


3. **Konsistensi Logika:**
Logika `$expected_adj_setoran = $expected_setoran;` (Target Tetap) sudah benar ada di file ini. Kita hanya perlu memoles cara penyajiannya.

---

### 2. Solusi Perbaikan Code (Copy-Paste Ready)

Berikut adalah revisi kode yang sudah disederhanakan dan dipercantik.

#### A. Perbaikan `audit.php` (Tampilan Layar)

**Fokus:** Menambahkan "Kesimpulan Audit" yang cerdas di bagian atas, dan menyembunyikan tabel teknis agar tidak *crowded*.

Cari bagian setelah `</form>` dan sebelum `<div class="summary-grid">`. Ganti atau sesuaikan tampilannya dengan kode di bawah ini.

Tapi saran terbaik saya, **Ganti seluruh isi `<body>...</body>` di `audit.php**` dengan struktur ini agar bersih:

```php
<div class="card card-solid">
    <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-shield"></i> Audit Keuangan & Voucher</h3>
        <div class="card-tools">
             <a class="btn btn-primary btn-sm" target="_blank" href="report/print_audit.php?session=<?= urlencode($session_id) ?>&show=<?= urlencode($req_show) ?>&date=<?= urlencode($filter_date) ?>"><i class="fa fa-print"></i> Print Laporan Keuangan</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar" action="?">
            </form>

        <div class="section-title" style="margin-top:0;">Ringkasan Keuangan (Audit Manual vs Sistem)</div>
        
        <?php if ($audit_manual_summary['rows'] === 0): ?>
            <div class="summary-card" style="border:1px solid #3a4046;background:#1f2327; text-align:center; padding:20px;">
                <span style="color:#f39c12;"><i class="fa fa-exclamation-triangle"></i> Belum ada data Audit Manual yang diinput hari ini.</span>
            </div>
        <?php else: ?>
            <?php 
                $selisih = $audit_manual_summary['selisih_setoran'];
                $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih); 
                $color_status = $selisih < 0 ? '#c0392b' : ($selisih > 0 ? '#2ecc71' : '#3498db');
                $text_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'AMAN / SESUAI');
            ?>
            
            <div style="background: <?= $selisih < 0 ? '#381818' : ($selisih > 0 ? '#1b3a24' : '#1e2a36') ?>; border: 1px solid <?= $color_status ?>; padding: 15px; border-radius: 6px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:12px; color:#aaa; text-transform:uppercase;">Status Keuangan</div>
                    <div style="font-size:24px; font-weight:bold; color:<?= $color_status ?>;"><?= $text_status ?></div>
                    <?php if ($selisih != 0): ?>
                        <div style="font-size:18px; color:#fff;">Selisih: Rp <?= number_format($selisih, 0, ',', '.') ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ghost_hint)): ?>
                    <div style="text-align:right; max-width:50%;">
                        <div style="font-size:11px; color:#fca5a5; font-weight:bold; text-transform:uppercase;">GHOST HUNTER (DETEKSI OTOMATIS)</div>
                        <div style="font-size:14px; color:#fff;"><?= htmlspecialchars($ghost_hint) ?></div>
                        <div style="font-size:10px; color:#aaa;">*Kemungkinan voucher ini lupa diinput atau hilang.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-title">Uang Fisik (Manual)</div>
                    <div class="summary-value" style="color:#fff;">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Target Sistem (Net)</div>
                    <div class="summary-value" style="color:#fff;">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-title">Selisih Qty</div>
                    <div class="summary-value" style="color:<?= $audit_manual_summary['selisih_qty'] != 0 ? '#f39c12' : '#2ecc71' ?>;">
                        <?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?> Lembar
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-title">Statistik Sistem</div>
        <div class="summary-grid">
            <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Voucher Rusak</div><div class="summary-value" style="color:#e74c3c;"><?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Pending (Live)</div><div class="summary-value"><?= number_format($sales_summary['pending'],0,',','.') ?></div></div>
        </div>

        <?php if (!empty($dup_raw) || !empty($dup_user_date)): ?>
            <div class="section-title" style="color:#e74c3c;"><i class="fa fa-exclamation-circle"></i> Potensi Masalah (Double Data)</div>
            <table class="table-dark-solid">
                <tbody>
                    <?php if (!empty($dup_raw)): ?>
                        <?php foreach ($dup_raw as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['sale_date']) ?></td>
                                <td><?= htmlspecialchars($r['username']) ?></td>
                                <td><span class="pill pill-bad">Duplikat Raw Data</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <details style="margin-top:20px; background:#23272b; padding:10px; border-radius:6px;">
            <summary style="cursor:pointer; color:#3498db; font-weight:bold;">Klik untuk melihat Data Teknis (Relogin & Bandwidth)</summary>
            
            <div style="margin-top:15px;">
                <div class="section-title">Top Relogin (Indikasi Sharing Account)</div>
                <table class="table-dark-solid">
                    <tbody>
                        <?php foreach ($relogin_rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['username']) ?></td>
                                <td><?= (int)$r['cnt'] ?>x Login</td>
                            </tr>
                        <?php endforeach; ?>
                     </tbody>
                </table>
            </div>

            <div style="margin-top:15px;">
                <div class="section-title">Top Penggunaan Bandwidth</div>
                <table class="table-dark-solid">
                    <tbody>
                        <?php foreach ($bandwidth_rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['username']) ?></td>
                                <td><?= format_bytes_short($r['last_bytes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                     </tbody>
                </table>
            </div>
        </details>

    </div>
</div>

```

**Perubahan Utama di `audit.php`:**

1. **Visual Status:** Ada kotak besar berwarna di atas. Jika Kurang Setor (Merah), jika Pas (Hijau). Owner langsung tahu kondisi tanpa baca angka kecil.
2. **Ghost Hunter:** Ditampilkan mencolok di dalam kotak status jika ada selisih.
3. **Anti-Noise:** Tabel Relogin dan Bandwidth disembunyikan dalam `<details>`, karena itu urusan teknis, bukan urusan setoran uang.

---

#### B. Perbaikan `print_audit.php` (Versi Cetak)

**Fokus:** Hanya menampilkan data Keuangan. Hapus semua data teknis (Relogin/Bandwidth) agar kertas hasil print bersih dan fokus pada duit.

Ganti seluruh isi `<body>` di `print_audit.php` dengan yang ini:

```php
<body>
  <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
      <h1 style="margin:0;">Laporan Audit Keuangan</h1>
      <div class="sub" style="margin-top:5px;">
          Periode: <?= htmlspecialchars(format_date_dmy($filter_date)) ?> | 
          Mode: <?= strtoupper(htmlspecialchars($req_show)) ?>
      </div>
  </div>

  <?php 
      $selisih = $audit_manual_summary['selisih_setoran'];
      $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
      
      // Warna untuk Print (Lebih soft agar hemat tinta tapi jelas)
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
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      <?= $label_status ?>
                  </div>
              </td>
              <td style="border:none; padding:0; text-align:right;">
                  <div style="font-size:10px; color:#555; text-transform:uppercase;">Total Selisih</div>
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      Rp <?= number_format($selisih, 0, ',', '.') ?>
                  </div>
              </td>
          </tr>
      </table>
      
      <?php if (!empty($ghost_hint)): ?>
          <div style="margin-top:10px; padding-top:10px; border-top:1px dashed <?= $border_status ?>; font-size:12px; color:<?= $text_color ?>;">
              <strong>Indikasi (Ghost Hunter):</strong> <?= htmlspecialchars($ghost_hint) ?>
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
        <div class="summary-value" style="color:<?= $text_color ?>;"><?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?></div>
    </div>
  </div>

  <div class="section-title">Data Sistem</div>
  <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Kotor</div><div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Potongan Rusak</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
  </div>

  <?php if ($sales_summary['pending'] > 0): ?>
      <div style="margin-top:15px; padding:10px; border:1px solid #ffcc00; background:#fffbe6; font-size:11px;">
          <strong>Catatan Teknis:</strong> Terdapat <?= number_format($sales_summary['pending']) ?> transaksi status "Pending" (Live Sales) yang belum masuk rekap final.
      </div>
  <?php endif; ?>

  <div style="margin-top:30px; font-size:10px; color:#999; text-align:center;">
      Dicetak oleh Sistem Wartelpas pada <?= date('d-m-Y H:i:s') ?>
  </div>
</body>

```

### Rangkuman Perubahan

1. **Fokus Keuangan:** Saya menghapus tabel Relogin, Bandwidth, dan Double Data yang terlalu teknis dari versi cetak (`print_audit.php`). Jika owner ingin lihat itu, dia bisa lihat di layar (versi `audit.php`). Kertas print sekarang hanya berisi: **Status (Aman/Kurang), Jumlah Uang, dan Statistik Transaksi**.
2. **Status Visual:** Menambahkan kotak besar dengan border berwarna di bagian paling atas. Owner cukup lihat warnanya:
* **Merah:** Masalah (Uang Kurang).
* **Hijau:** Kelebihan.
* **Abu-abu/Putih:** Aman.


3. **Ghost Hunter:** Fitur deteksi otomatis ("Kemungkinan 1 voucher 10rb hilang") sekarang ditampilkan jelas di dalam kotak status jika ada selisih.

Silakan terapkan perubahan ini. Ini akan membuat sistem audit Anda sangat profesional, bebas noise, dan langsung pada intinya (Money Oriented).