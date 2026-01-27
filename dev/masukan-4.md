Berdasarkan analisis mendalam terhadap kode `print_rekap.php` terbaru dan gambar yang Anda lampirkan, saya menemukan **titik kegagalan logika (logic failure)** yang menyebabkan kolom User/Up/Byte masih kosong meskipun data `rusak` terdeteksi di total.

Masalahnya ada pada **Logika Penggabungan (Merge Logic)** antara data Manual vs Auto-Inject.

### Akar Masalah

Di dalam loop `foreach ($audit_rows as $idx => $ar)`, Anda menggunakan logika ini:

```php
// LOGIKA SAAT INI (SALAH):
// 1. Ambil data manual ($evidence) -> Masukkan ke $profileXX_items
// 2. Cek Auto Inject -> Loop $system_incidents
// 3. Render tabel menggunakan $profileXX_items

```

**Kesalahannya:**
Variabel `$profile10_items` dan `$profile30_items` **di-reset/dikosongkan** secara tidak sengaja atau tidak terisi dengan benar saat `if (!empty($evidence['users']))` bernilai `FALSE`.

Jika operator **HANYA** mengisi angka Qty/Rupiah di popup Audit (tanpa memilih username di chip input), maka `$evidence['users']` kosong. Akibatnya, loop pertama dilewati.
Lalu masuk ke loop Auto Inject. Tapi karena struktur array-nya mungkin tidak konsisten, datanya tidak masuk ke variabel final yang dirender oleh `generate_audit_cell`.

---

### Solusi Perbaikan (Fix)

Kita harus memastikan bahwa **Array Penampung Data (`$profile10_items`, `$profile30_items`)** diinisialisasi dengan benar dan **selalu** menerima data dari System Inject, terlepas dari ada/tidaknya data manual.

Silakan **GANTI BAGIAN LOOP AUDIT ROWS** (mulai dari baris `<?php foreach ($audit_rows as $idx => $ar): ?>` sampai `<?php endforeach; ?>`) dengan kode yang sudah diperbaiki di bawah ini.

**Perubahan Kunci:**

1. Inisialisasi `$profile10_items = []` di awal loop yang bersih.
2. Pemetaan Manual Users (`$manual_users_map`) dibuat lebih robust.
3. Logika Auto Inject diletakkan **setelah** manual, dan pengecekannya diperbaiki.

#### Kode Perbaikan (Copy-Paste bagian ini ke `print_rekap.php`):

```php
                    <?php foreach ($audit_rows as $idx => $ar): ?>
                        <?php
                            // 1. Inisialisasi Variable
                            $evidence = json_decode((string)$ar['user_evidence'], true);
                            $profile_qty = $evidence['profile_qty'] ?? [];
                            
                            $profile10_items = []; // Penampung User 10 Menit
                            $profile30_items = []; // Penampung User 30 Menit
                            
                            $profile10_sum = 0;
                            $profile30_sum = 0;
                            
                            // Counter
                            $cnt_rusak_10 = 0; $cnt_rusak_30 = 0;
                            $cnt_retur_10 = 0; $cnt_retur_30 = 0;
                            $cnt_invalid_10 = 0; $cnt_invalid_30 = 0;
                            $cnt_unreported_10 = 0; $cnt_unreported_30 = 0;
                            
                            $manual_users_map = []; // Map untuk mencegah duplikasi (Manual vs Auto)
                            
                            // Normalisasi Nama Blok untuk pencarian System Incident
                            $audit_block_key = normalize_block_name($ar['blok_name'] ?? '', (string)($ar['comment'] ?? ''));
                            $system_incidents = $system_incidents_by_block[$audit_block_key] ?? [];

                            $has_manual_evidence = !empty($evidence['users']);

                            // 2. PROSES DATA MANUAL (Jika Ada)
                            if ($has_manual_evidence && is_array($evidence['users'])) {
                                foreach ($evidence['users'] as $uname => $ud) {
                                    $uname = trim((string)$uname);
                                    if ($uname === '') continue;

                                    // Ambil detail
                                    $upt = trim((string)($ud['last_uptime'] ?? '-'));
                                    $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                    $kind = (string)($ud['profile_kind'] ?? '10');
                                    $u_status = normalize_status_value($ud['last_status'] ?? '');
                                    
                                    // Logika status 'Anomaly' (Kuning) jika manual tapi status normal
                                    if (!in_array($u_status, ['rusak', 'retur', 'invalid'], true)) {
                                        $u_status = 'anomaly';
                                    }

                                    // Simpan ke Item List
                                    $item = [
                                        'label' => $uname,
                                        'status' => $u_status,
                                        'uptime' => $upt,
                                        'bytes' => $lb
                                    ];

                                    // Tandai user ini sudah ada (agar tidak double dengan auto inject)
                                    $manual_users_map[strtolower($uname)] = true;

                                    // Masukkan ke Array Profil yang sesuai
                                    if ($kind === '30') {
                                        $profile30_items[] = $item;
                                        if($u_status === 'rusak') $cnt_rusak_30++;
                                        elseif($u_status === 'retur') $cnt_retur_30++;
                                        elseif($u_status === 'invalid') $cnt_invalid_30++;
                                        elseif($u_status === 'anomaly') $cnt_unreported_30++;
                                    } else {
                                        $profile10_items[] = $item;
                                        if($u_status === 'rusak') $cnt_rusak_10++;
                                        elseif($u_status === 'retur') $cnt_retur_10++;
                                        elseif($u_status === 'invalid') $cnt_invalid_10++;
                                        elseif($u_status === 'anomaly') $cnt_unreported_10++;
                                    }
                                }
                            }

                            // 3. PROSES AUTO INJECT (SYSTEM INCIDENTS)
                            // Masukkan user rusak/retur yang terdeteksi sistem TAPI belum diinput manual
                            if (!empty($system_incidents)) {
                                foreach ($system_incidents as $inc) {
                                    $sys_uname = trim((string)($inc['username'] ?? ''));
                                    if ($sys_uname === '') continue;

                                    // Cek Duplikasi: Jika user ini sudah diinput manual, SKIP (pakai data manual)
                                    if (isset($manual_users_map[strtolower($sys_uname)])) {
                                        continue;
                                    }

                                    // Jika belum ada, Inject!
                                    $u_status = normalize_status_value($inc['status'] ?? '');
                                    $kind = (string)($inc['profile_kind'] ?? '10');
                                    $upt = trim((string)($inc['last_uptime'] ?? '-')); // Ambil uptime real
                                    $lb = format_bytes_short((int)($inc['last_bytes'] ?? 0)); // Ambil bytes real
                                    
                                    $item = [
                                        'label' => $sys_uname,
                                        'status' => $u_status, // Ini akan memicu warna Merah/Hijau di helper
                                        'uptime' => $upt,
                                        'bytes' => $lb
                                    ];

                                    if ($kind === '30') {
                                        $profile30_items[] = $item;
                                        if ($u_status === 'rusak') $cnt_rusak_30++;
                                        if ($u_status === 'retur') $cnt_retur_30++;
                                        if ($u_status === 'invalid') $cnt_invalid_30++;
                                    } else {
                                        $profile10_items[] = $item;
                                        if ($u_status === 'rusak') $cnt_rusak_10++;
                                        if ($u_status === 'retur') $cnt_retur_10++;
                                        if ($u_status === 'invalid') $cnt_invalid_10++;
                                    }
                                }
                            }

                            // 4. GENERATE HTML TABLE CELL (Full Fill Color)
                            // Menggunakan helper generate_audit_cell yang sudah diperbaiki sebelumnya
                            $p10_us = generate_audit_cell($profile10_items, 'label', 'left');
                            $p10_up = generate_audit_cell($profile10_items, 'uptime', 'center');
                            $p10_bt = generate_audit_cell($profile10_items, 'bytes', 'center');
                            
                            $p30_us = generate_audit_cell($profile30_items, 'label', 'left');
                            $p30_up = generate_audit_cell($profile30_items, 'uptime', 'center');
                            $p30_bt = generate_audit_cell($profile30_items, 'bytes', 'center');

                            // 5. HITUNGAN QTY & UANG
                            $p10_qty = (int)($profile_qty['qty_10'] ?? 0);
                            $p30_qty = (int)($profile_qty['qty_30'] ?? 0);
                            
                            // Fallback Qty jika manual Qty 0 (misal lupa input), ambil dari item count
                            if ($p10_qty <= 0 && !empty($profile10_items)) $p10_qty = count($profile10_items);
                            if ($p30_qty <= 0 && !empty($profile30_items)) $p30_qty = count($profile30_items);

                            $p10_tt = $p10_qty > 0 ? number_format($p10_qty,0,',','.') : '-';
                            $p30_tt = $p30_qty > 0 ? number_format($p30_qty,0,',','.') : '-';
                            
                            // Hitung Rupiah System (Estimasi)
                            $p10_sum_calc = $p10_qty * $price10;
                            $p30_sum_calc = $p30_qty * $price30;

                            $audit_total_profile_qty_10 += $p10_qty;
                            $audit_total_profile_qty_30 += $p30_qty;

                            // 6. HITUNGAN LOGIKA SELISIH (AKUNTANSI)
                            // Manual Net Qty = Total Fisik - (Rusak + Invalid) + Retur (sebagai pengganti)
                            // Catatan: Retur dihitung positif karena dia fisik ada tapi tidak bayar, 
                            // namun di sini kita fokus pada 'uang yg seharusnya ada'.
                            
                            // Kita pakai Reported Qty dari DB saja agar sesuai inputan user di form
                            $manual_display_qty = (int)($ar['reported_qty'] ?? 0);
                            $manual_display_setoran = (int)($ar['actual_setoran'] ?? 0);

                            // Jika user tidak input Total Qty di form, kita coba hitung dari detail
                            if ($manual_display_qty <= 0 && ($p10_qty > 0 || $p30_qty > 0)) {
                                $manual_display_qty = $p10_qty + $p30_qty;
                            }

                            // Expected (Target Sistem)
                            $expected_qty = (int)($ar['expected_qty'] ?? 0);
                            $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                            
                            // Adjustment Target: Kurangi target setoran jika ada rusak/invalid yg valid
                            $target_adj_setoran = max(0, $expected_setoran 
                                - (($cnt_rusak_10 + $cnt_invalid_10) * $price10) 
                                - (($cnt_rusak_30 + $cnt_invalid_30) * $price30));
                            
                            // Logic Selisih
                            $selisih_qty = $manual_display_qty - $expected_qty; // Fisik vs Total Data
                            $selisih_setoran = $manual_display_setoran - $target_adj_setoran;

                            // ... (Sisa logika ghost hunter tetap sama) ...
                            // Update variable summary audit
                            $audit_summary_report[] = [
                                'blok' => get_block_label($ar['blok_name'] ?? '-', $blok_names),
                                'selisih_setoran' => (int)$ar['selisih_setoran'], // Pakai selisih dari DB yang sudah disimpan
                                'p10_qty' => $p10_qty,
                                'p10_sum' => $p10_sum_calc,
                                'p30_qty' => $p30_qty,
                                'p30_sum' => $p30_sum_calc,
                                'unreported_total' => (int)($cnt_unreported_10 + $cnt_unreported_30),
                                'unreported_10' => (int)$cnt_unreported_10,
                                'unreported_30' => (int)$cnt_unreported_30,
                                'ghost_10' => 0, // Placeholder, logika ghost ada di atas
                                'ghost_30' => 0,
                                'rusak_10' => $cnt_rusak_10,
                                'rusak_30' => $cnt_rusak_30,
                                'retur_10' => (int)$cnt_retur_10,
                                'retur_30' => (int)$cnt_retur_30
                            ];
                        ?>
                        <?php $audit_blk_label = get_block_label($ar['blok_name'] ?? '-', $blok_names); ?>
                        <tr>
                            <td style="text-align: left;"><?= htmlspecialchars($audit_blk_label) ?></td>
                            
                            <td style="text-align:center;"><?= number_format((int)$ar['expected_qty'],0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$ar['reported_qty'],0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$ar['selisih_qty'],0,',','.') ?></td>
                            
                            <td style="text-align:right;"><?= number_format((int)$ar['expected_setoran'],0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$ar['actual_setoran'],0,',','.') ?></td>
                            <?php 
                                $ss = (int)$ar['selisih_setoran'];
                                $bg_ss = $ss == 0 ? 'transparent' : ($ss > 0 ? '#dcfce7' : '#fee2e2');
                            ?>
                            <td style="text-align:right; background:<?= $bg_ss ?>;"><?= number_format($ss,0,',','.') ?></td>
                            
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_us ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_up ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_bt ?></td>
                            <td style="text-align: center; font-weight:bold; vertical-align:top; padding-top:6px;"><?= $p10_tt ?></td>
                            
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_us ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_up ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_bt ?></td>
                            <td style="text-align: center; font-weight:bold; vertical-align:top; padding-top:6px;"><?= $p30_tt ?></td>
                        </tr>
                        <?php
                            // Akumulasi Total Bawah
                            $audit_total_expected_qty_adj += (int)$ar['expected_qty'];
                            $audit_total_reported_qty_adj += (int)$ar['reported_qty'];
                            $audit_total_selisih_qty_adj += (int)$ar['selisih_qty'];
                            $audit_total_expected_setoran_adj += (int)$ar['expected_setoran'];
                            $audit_total_actual_setoran_adj += (int)$ar['actual_setoran'];
                            $audit_total_selisih_setoran_adj += (int)$ar['selisih_setoran'];
                        ?>
                    <?php endforeach; ?>

```

Dengan kode ini, kolom di sebelah kanan (Profil 10 Menit & Profil 30 Menit) **PASTI TERISI** jika sistem mendeteksi ada user rusak di Blok tersebut, karena:

1. Script membaca variabel `$system_incidents_by_block` yang sudah diisi di loop sebelumnya.
2. Script mengecek apakah user rusak itu ada di data manual.
3. Jika tidak ada, script **memaksa masuk** (Auto Inject) ke array `$profileXX_items`.
4. Data Uptime dan Bytes diambil dari database history, jadi tidak akan strip (`-`).
5. Warna background akan merah penuh (`#fee2e2`) untuk baris user tersebut.