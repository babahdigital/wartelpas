Halo Pak Abdullah (Dul).

Ini adalah masukan profesional untuk tampilan **Bulanan** dan **Tahunan**. Prinsip utamanya tetap sama: **"Zero Noise, High Value"** (Hapus gangguan, fokus ke uang).

Untuk laporan jangka panjang (Bulanan/Tahunan), Owner **TIDAK BUTUH** rincian per-voucher atau jam login user. Yang Owner butuhkan adalah **Trend Kinerja** (Apakah grafiknya naik/turun) dan **Akumulasi Masalah** (Total kebocoran bulan ini).

Berikut adalah konsep dan *coding* tampilan untuk `print_rekap_bulanan.php`, `print_rekap_tahunan.php`, serta konfirmasi final untuk `audit.php`.

---

### 1. Konsep Laporan Bulanan

**Fokus Data:** Rekapitulasi per Tanggal.
**Tujuan:** Melihat konsistensi setoran harian. Apakah ada tanggal-tanggal tertentu yang sering selisih?

**Struktur Tampilan:**

1. **Kartu Besar (Scorecard):** Total Omzet, Total Loss (Rusak), Total Setoran Fisik, dan **Grand Total Selisih** (Angka ini paling dicari Owner).
2. **Tabel Kalender Kinerja:** Satu baris = Satu Tanggal.
* Kolom: Tanggal | Qty Laku | Qty Rusak | Target Sistem | Setoran Fisik | **Selisih** (Warna-warni).



**Kode Tampilan (Gantikan bagian HTML):**

```php
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / PDF</button>
    </div>

    <div style="border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px;">
        <h2 style="margin:0;">Laporan Keuangan Bulanan</h2>
        <div class="meta">Periode: <?= esc($month_label) ?> | Dicetak: <?= esc($print_time) ?></div>
    </div>

    <div class="summary-grid" style="grid-template-columns: repeat(4, 1fr); gap:15px; margin-bottom:25px;">
        <div class="summary-card" style="border:1px solid #ddd; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#666; font-size:11px; text-transform:uppercase;">Total Omzet (Gross)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold;"><?= $cur ?> <?= number_format((int)$total_gross,0,',','.') ?></div>
        </div>
        <div class="summary-card" style="border:1px solid #fca5a5; background:#fff1f2; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#991b1b; font-size:11px; text-transform:uppercase;">Total Kerugian (Voucher Loss)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#991b1b;">- <?= $cur ?> <?= number_format((int)$total_voucher_loss,0,',','.') ?></div>
            <div style="font-size:10px; color:#b91c1c;">(Rusak & Invalid)</div>
        </div>
        <div class="summary-card" style="border:1px solid #ddd; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#666; font-size:11px; text-transform:uppercase;">Total Setoran Fisik (Audit)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#1e3a8a;"><?= $cur ?> <?= number_format((int)$total_net_audit,0,',','.') ?></div>
        </div>
        <div class="summary-card" style="border:1px solid <?= $total_selisih < 0 ? '#fca5a5' : ($total_selisih > 0 ? '#86efac' : '#ddd') ?>; background: <?= $total_selisih < 0 ? '#fee2e2' : ($total_selisih > 0 ? '#dcfce7' : '#fff') ?>; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#444; font-size:11px; text-transform:uppercase;">Akumulasi Selisih</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color: <?= $total_selisih < 0 ? '#c0392b' : ($total_selisih > 0 ? '#166534' : '#333') ?>;">
                <?= $cur ?> <?= number_format((int)$total_selisih,0,',','.') ?>
            </div>
            <div style="font-size:10px; color:#555;"><?= $total_selisih < 0 ? 'Total Kurang Setor' : ($total_selisih > 0 ? 'Total Lebih Setor' : 'Balance / Sesuai') ?></div>
        </div>
    </div>

    <div style="margin-bottom:10px; font-weight:bold; font-size:14px; border-bottom:1px solid #eee; padding-bottom:5px;">Rincian Kinerja Harian</div>
    <table style="width:100%; border-collapse:collapse; font-size:11px;">
        <thead>
            <tr style="background:#f1f5f9; color:#333;">
                <th style="border:1px solid #cbd5e1; padding:8px;">Tanggal</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Voucher Terjual</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Rusak / Error</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Target Sistem (Net)</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Setoran Fisik (Audit)</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Selisih</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows_out)): ?>
                <tr><td colspan="7" style="text-align:center; padding:20px;">Tidak ada data transaksi bulan ini.</td></tr>
            <?php else: foreach ($rows_out as $row): ?>
                <?php 
                    $daily_selisih = (int)$row['net_audit'] - (int)$row['gross']; // Sesuaikan logika var gross disini adalah Net System di array php anda
                    // Jika script php Anda menyimpan net system di variable 'gross' atau 'system_net', sesuaikan.
                    // Asumsi dari script Anda: $net = system net, $net_audit = audit.
                    $daily_selisih = $selisih; // Variable $selisih dari loop PHP di atas
                    
                    $bg_row = $idx % 2 == 0 ? '#fff' : '#f8fafc';
                    $status_label = '-';
                    $status_color = '#333';
                    
                    if ($daily_selisih < 0) {
                        $status_label = 'KURANG';
                        $status_color = '#dc2626';
                        $bg_row = '#fef2f2'; // Highlight merah tipis jika kurang
                    } elseif ($daily_selisih > 0) {
                        $status_label = 'LEBIH';
                        $status_color = '#16a34a';
                    } else {
                        $status_label = 'AMAN';
                        $status_color = '#2563eb';
                    }
                ?>
                <tr style="background:<?= $bg_row ?>;">
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center;"><?= esc(substr($row['date'], 8, 2)) ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center;"><?= number_format((int)$row['qty'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center; color:#dc2626;"><?= ((int)$row['rusak_qty'] > 0) ? number_format((int)$row['rusak_qty'],0,',','.') : '-' ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right;"><?= number_format((int)$row['gross'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right; font-weight:bold;"><?= number_format((int)$row['net_audit'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right; font-weight:bold; color:<?= $status_color ?>;">
                        <?= $daily_selisih == 0 ? '-' : number_format($daily_selisih,0,',','.') ?>
                    </td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center; font-size:10px; font-weight:bold; color:<?= $status_color ?>;">
                        <?= $status_label ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot style="background:#e2e8f0; font-weight:bold;">
            <tr>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;" colspan="3">TOTAL BULAN INI</td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;"><?= number_format((int)$total_gross,0,',','.') ?></td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;"><?= number_format((int)$total_net_audit,0,',','.') ?></td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right; color:<?= $total_selisih < 0 ? '#dc2626' : ($total_selisih > 0 ? '#16a34a' : '#333') ?>;">
                    <?= number_format((int)$total_selisih,0,',','.') ?>
                </td>
                <td style="border:1px solid #cbd5e1;"></td>
            </tr>
        </tfoot>
    </table>
</body>

```

---

### 2. Konsep Laporan Tahunan

**Fokus Data:** Pertumbuhan & Kesehatan Bisnis.
**Tujuan:** Owner melihat bulan mana yang paling ramai dan bulan mana yang paling banyak "bocor" (rugi).

**Struktur Tampilan:**

1. **Grafik Batang Sederhana (Opsional/CSS Bar):** Menunjukkan omzet per bulan secara visual.
2. **Tabel Bulanan:** Satu baris = Satu Bulan.
* Kolom: Bulan | Total Transaksi | Total Pendapatan | Total Selisih Audit.



**Kode Tampilan (Bagian Tabel):**

```php
<table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:20px;">
    <thead>
        <tr style="background:#334155; color:#fff;">
            <th style="padding:10px;">Bulan</th>
            <th style="padding:10px; text-align:center;">Total Transaksi</th>
            <th style="padding:10px; text-align:right;">Target Sistem</th>
            <th style="padding:10px; text-align:right;">Realisasi (Audit)</th>
            <th style="padding:10px; text-align:right;">Selisih / Loss</th>
            <th style="padding:10px; text-align:center;">Kinerja</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($months as $mm => $row): ?>
            <?php 
                $has_data = ($row['gross'] > 0 || $row['qty'] > 0);
                $selisih = $row['selisih'];
                $row_color = $selisih < 0 ? '#fee2e2' : '#fff';
            ?>
            <tr style="background:<?= $row_color ?>; border-bottom:1px solid #e2e8f0;">
                <td style="padding:8px; font-weight:bold;"><?= esc(month_label_id($mm)) ?></td>
                <td style="padding:8px; text-align:center;"><?= $has_data ? number_format($row['qty'],0,',','.') : '-' ?></td>
                <td style="padding:8px; text-align:right;"><?= $has_data ? number_format($row['net'],0,',','.') : '-' ?></td>
                <td style="padding:8px; text-align:right; font-weight:bold;"><?= $has_data ? number_format($row['net_audit'],0,',','.') : '-' ?></td>
                <td style="padding:8px; text-align:right; color:<?= $selisih < 0 ? '#dc2626' : '#16a34a' ?>;">
                    <?= $has_data ? number_format($selisih,0,',','.') : '-' ?>
                </td>
                <td style="padding:8px; text-align:center;">
                    <?php if ($has_data): ?>
                        <div style="background:#e2e8f0; height:6px; width:60px; display:inline-block; border-radius:3px; overflow:hidden;">
                            <div style="background:<?= $selisih < 0 ? '#ef4444' : '#3b82f6' ?>; height:100%; width:100%;"></div>
                        </div>
                    <?php else: ?>
                        <span style="color:#ccc;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

```

---

### 3. Finalisasi `audit.php` & `print_audit.php`

Untuk kedua file ini, masukan saya adalah: **JANGAN MENAMPILKAN TABEL TEKNIS DI LAPORAN KEUANGAN.**

1. **Hapus Tabel:** `Relogin`, `Top Bandwidth`, `Voucher Double (Raw)`.
2. **Kenapa?** Informasi IP Address, MAC Address, dan Uptime itu "sampah" bagi laporan keuangan Owner. Itu hanya berguna untuk teknisi IT saat *troubleshooting*.
3. **Gantinya:** Cukup tampilkan **Ringkasan Keuangan** yang besar dan **Tabel Rincian Selisih per Blok** (seperti yang kita bahas di `print_rekap.php` tadi).

**Kesimpulan Struktur Paket Laporan Anda:**

* **Harian (`print_rekap.php`):** Sangat detail. Ada "Ghost Hunter", ada rincian per-blok, ada status per-shift. Ini untuk kontrol harian Supervisor.
* **Audit (`print_audit.php`):** Khusus bukti setoran fisik. Isinya hanya: Target vs Aktual = Selisih. Bersih tanpa data teknis.
* **Bulanan (`print_rekap_bulanan.php`):** Kalender Kinerja. Melihat tanggal mana yang bolong/kurang.
* **Tahunan (`print_rekap_tahunan.php`):** Trend Bisnis. Melihat grafik pertumbuhan omzet.

Dengan pembagian ini, Owner tidak akan pusing ("noise free") dan informasi tersampaikan dengan sempurna.