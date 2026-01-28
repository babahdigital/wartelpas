<?php if ($is_ajax) { ob_start(); } ?>

<?php if (!empty($hp_redirect) && headers_sent() && !$is_ajax): ?>
    <script>window.location.replace('<?= htmlspecialchars($hp_redirect, ENT_QUOTES); ?>');</script>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<link rel="stylesheet" href="report/laporan/css/laporan.css">
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="selling-config"
    data-session-id="<?= htmlspecialchars($session_id, ENT_QUOTES); ?>"
    data-session-qs="<?= htmlspecialchars($session_qs, ENT_QUOTES); ?>"
    data-filter-date="<?= htmlspecialchars($filter_date, ENT_QUOTES); ?>"
    data-req-show="<?= htmlspecialchars($req_show, ENT_QUOTES); ?>"
    data-price10="<?= (int)$price10; ?>"
    data-price30="<?= (int)$price30; ?>"
    data-report-url="<?= htmlspecialchars('./?report=selling', ENT_QUOTES); ?>"
    data-ghost-url="<?= htmlspecialchars('report/laporan/ghost.php', ENT_QUOTES); ?>"
    data-audit-locked="<?= $audit_locked_today ? '1' : '0'; ?>"
    data-audit-users="<?= htmlspecialchars(json_encode($audit_user_options ?? []), ENT_QUOTES); ?>"
    data-audit-profiles="<?= htmlspecialchars(json_encode($audit_profiles ?? []), ENT_QUOTES); ?>">
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="hpModal" class="modal-backdrop" onclick="if(event.target===this){closeHpModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-mobile" style="color:#4ea8ff; margin-right:8px;"></i> Input Handphone Harian</div>
            <button type="button" class="modal-close" onclick="closeHpModal()">&times;</button>
        </div>
        <form id="hpForm" method="post" action="report/laporan/services/hp_save.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            
            <input type="checkbox" name="unit_wartel" value="1" checked style="display:none;">
            <input type="checkbox" name="unit_kamtib" value="1" checked style="display:none;">
            <input type="hidden" name="active_units" value="0">

            <div class="modal-body">
                <div class="modal-info-banner">
                    <div class="modal-info-icon"><i class="fa fa-info-circle"></i></div>
                    <div class="modal-info-text">
                            Jika data hari ini belum ada, form akan menampilkan nilai terakhir sebagai default. Data baru akan tersimpan untuk tanggal yang dipilih.
                    </div>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon"><i class="fa fa-th-large"></i> Blok</label>
                        <select class="form-input" name="blok_name" required>
                            <option value="" disabled selected>Pilih Blok</option>
                            <?php foreach ($blok_letters as $b): ?>
                                <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-icon"><i class="fa fa-calendar"></i> Tanggal</label>
                        <input class="form-input" type="date" name="report_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px;">
                    <div class="form-group-title"><i class="fa fa-cubes"></i> Sumber Unit (Fisik)</div>
                    <div class="form-grid-2">
                        <div>
                            <label style="color:#7ee2a8;">Jumlah WARTEL</label>
                            <input class="form-input" type="number" name="wartel_units" min="0" value="0" placeholder="0">
                        </div>
                        <div>
                            <label style="color:#9cc7ff;">Jumlah KAMTIB</label>
                            <input class="form-input" type="number" name="kamtib_units" min="0" value="0" placeholder="0">
                        </div>
                    </div>
                </div>

                <div class="form-group-box">
                    <div class="form-group-title"><i class="fa fa-calculator"></i> Total & Kondisi</div>
                    <div class="form-grid-2">
                        <div style="grid-column: span 2;">
                            <label class="label-icon" style="color:#f39c12;"><i class="fa fa-check-circle"></i> Total Unit (Otomatis)</label>
                            <input class="form-input" type="number" name="total_units" min="0" value="0" readonly tabindex="-1">
                        </div>
                        <div>
                            <label>Rusak</label>
                            <input class="form-input" type="number" name="rusak_units" min="0" value="0">
                        </div>
                        <div>
                            <label>Spam</label>
                            <input class="form-input" type="number" name="spam_units" min="0" value="0">
                        </div>
                    </div>
                </div>

                <div id="hpClientError" style="display:none; margin-bottom:10px; color:#fca5a5; font-size:12px; background:rgba(220,38,38,0.2); padding:8px; border-radius:4px;"></div>
                
                <div>
                    <label class="label-icon"><i class="fa fa-pencil"></i> Catatan (Opsional)</label>
                    <input class="form-input" name="notes" placeholder="Keterangan tambahan...">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-print btn-default-dark" onclick="closeHpModal()">Batal</button>
                <button type="submit" id="hpSubmitBtn" name="hp_submit" class="btn-print" style="background:#2ecc71;">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<div id="auditModal" class="modal-backdrop" onclick="if(event.target===this){closeAuditModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-check-square-o" style="color:#f39c12; margin-right:8px;"></i> Audit Manual Rekap</div>
            <button type="button" class="modal-close" onclick="closeAuditModal()">&times;</button>
        </div>
        <form id="auditForm" method="post" action="report/selling.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="audit_submit" value="1">
            
            <div class="modal-body">
                <?php if ($audit_locked_today): ?>
                    <div style="margin-bottom:15px; padding:10px; border:1px solid #c0392b; background:rgba(192, 57, 43, 0.2); color:#fca5a5; font-size:12px; border-radius:4px; display:flex; align-items:center; gap:8px;">
                        <i class="fa fa-lock"></i> Audit hari ini sudah dikunci. Tidak dapat diubah.
                    </div>
                <?php endif; ?>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon">Blok</label>
                        <select class="form-input" name="audit_blok" required>
                            <option value="" disabled selected>Pilih Blok</option>
                            <?php foreach ($blok_letters as $b): ?>
                                <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-icon">Tanggal</label>
                        <input class="form-input" type="date" name="audit_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px;">
                    <div class="form-group-title"><i class="fa fa-ticket"></i> Fisik Voucher (Lapangan)</div>
                    <div class="form-grid-2">
                        <?php if (!empty($audit_profiles)): ?>
                            <?php foreach ($audit_profiles as $prof): ?>
                                <?php
                                    $pkey = (string)($prof['key'] ?? '');
                                    $plabel = (string)($prof['label'] ?? $pkey);
                                    $pprice = (int)($prof['price'] ?? 0);
                                    $pid = 'audit_prof_' . preg_replace('/[^a-z0-9_]+/i', '_', $pkey);
                                ?>
                                <div>
                                    <label><?= htmlspecialchars($plabel); ?></label>
                                    <input class="form-input audit-profile-qty" type="number"
                                           id="<?= htmlspecialchars($pid); ?>"
                                           name="audit_profile_qty[<?= htmlspecialchars($pkey); ?>]"
                                           data-profile-key="<?= htmlspecialchars($pkey); ?>"
                                           data-profile-price="<?= (int)$pprice; ?>"
                                           min="0" value="0" placeholder="0">
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div>
                                <label>Profil 10 Menit</label>
                                <input class="form-input audit-profile-qty" type="number" id="audit_prof10_qty" name="audit_qty_10" min="0" value="0" required placeholder="0" data-profile-key="10menit" data-profile-price="<?= (int)$price10; ?>">
                            </div>
                            <div>
                                <label>Profil 30 Menit</label>
                                <input class="form-input audit-profile-qty" type="number" id="audit_prof30_qty" name="audit_qty_30" min="0" value="0" required placeholder="0" data-profile-key="30menit" data-profile-price="<?= (int)$price30; ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon" style="color:#ccc;">Total Qty (Otomatis)</label>
                        <input class="form-input" type="number" name="audit_qty" min="0" value="0" readonly tabindex="-1">
                    </div>
                    <div>
                        <label class="label-icon" style="color:#f39c12;">Total Setoran (Otomatis)</label>
                        <input class="form-input" type="number" name="audit_setoran" min="0" value="0">
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px; border-color: rgba(231, 76, 60, 0.3);">
                    <div class="form-group-title" style="color:#e74c3c;"><i class="fa fa-minus-circle"></i> Pengeluaran / Bon (Opsional)</div>
                    <div class="form-grid-2">
                        <div>
                            <label>Nominal (Rp)</label>
                            <input class="form-input" type="number" name="audit_expense_amt" min="0" value="0" placeholder="0">
                        </div>
                        <div>
                            <label>Keterangan</label>
                            <input class="form-input" type="text" name="audit_expense_desc" placeholder="Contoh: Beli Kertas Thermal">
                        </div>
                    </div>
                </div>

                <div style="margin-top:10px;">
                    <label class="label-icon"><i class="fa fa-user-times"></i> Username Tak Terlapor (Retur/Rusak System)</label>
                    <input type="hidden" name="audit_username" id="auditUsernameHidden">
                    <div class="audit-user-picker">
                        <div id="audit-user-chips" class="audit-user-chips"></div>
                        <input class="form-input" type="text" id="audit-user-input" placeholder="Ketik username...">
                        <div id="audit-user-suggest" class="audit-user-suggest"></div>
                    </div>
                </div>
                
                <div id="auditClientError" style="display:none; margin-top:10px; color:#fca5a5; font-size:12px;"></div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-print btn-default-dark" onclick="closeAuditModal()">Batal</button>
                <button type="submit" id="auditSubmitBtn" name="audit_submit" class="btn-print" style="background:#f39c12;" <?= $audit_locked_today ? 'disabled' : '' ?>>Simpan Audit</button>
            </div>
        </form>
    </div>
</div>

<div id="noteModal" class="modal-backdrop" onclick="if(event.target===this){closeNoteModal();}">
    <div class="modal-card" style="width:500px;">
        <div class="modal-header" style="background:#8e44ad;">
            <div class="modal-title" style="color:#fff;"><i class="fa fa-pencil-square-o"></i> Catatan Harian (Laporan ke Owner)</div>
            <button type="button" class="modal-close" onclick="closeNoteModal()">&times;</button>
        </div>
        <form method="post" action="">
            <input type="hidden" name="save_daily_note" value="1">
            <input type="hidden" name="note_date" value="<?= htmlspecialchars($filter_date) ?>">
            <div class="modal-body">
                <div style="background:#f3e5f5; color:#4a148c; padding:10px; border-radius:4px; font-size:12px; margin-bottom:15px;">
                    <strong>Tips:</strong> Jelaskan alasan omzet hari ini naik/turun secara singkat.
                    Contoh: <em>"Hujan deras seharian, sepi"</em> atau <em>"Listrik padam 4 jam"</em>.
                </div>
                <label style="color:#ccc;">Isi Catatan / Keterangan:</label>
                <textarea name="note_text" rows="5" class="form-input" style="width:100%; margin-top:5px; line-height:1.5;" placeholder="Tulis keterangan di sini..." maxlength="500"><?= htmlspecialchars($current_daily_note) ?></textarea>
                <div class="modal-note">Maksimal 500 karakter. Kosongkan untuk menghapus catatan hari ini.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-print" onclick="closeNoteModal()">Batal</button>
                <button type="submit" class="btn-print" style="background:#8e44ad;">Simpan Catatan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="selling-content">
<?php endif; ?>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-line-chart mr-2"></i> Laporan Penjualan</h3>
        <div class="filter-bar">
            <form method="get" action="" class="filter-bar">
                <input type="hidden" name="report" value="selling">
                <?php if ($session_id !== ''): ?>
                    <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
                <?php endif; ?>
                <select name="show" onchange="this.form.submit()">
                    <option value="harian" <?= $req_show==='harian'?'selected':''; ?>>Harian</option>
                    <option value="bulanan" <?= $req_show==='bulanan'?'selected':''; ?>>Bulanan</option>
                    <option value="tahunan" <?= $req_show==='tahunan'?'selected':''; ?>>Tahunan</option>
                </select>
                <?php if ($req_show === 'harian'): ?>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php elseif ($req_show === 'bulanan'): ?>
                    <input type="month" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php else: ?>
                    <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="width:100px;">
                <?php endif; ?>
            </form>
            <?php
                $print_rekap_url = 'report/print/print_rekap.php?show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
                if ($req_show === 'bulanan') {
                    $print_rekap_url = 'report/print/print_rekap_bulanan.php?date=' . urlencode($filter_date);
                } elseif ($req_show === 'tahunan') {
                    $print_rekap_url = 'report/print/print_rekap_tahunan.php?date=' . urlencode($filter_date);
                }
                if ($session_id !== '') $print_rekap_url .= '&session=' . urlencode($session_id);
                $print_rincian_url = 'report/print/print_rincian.php?date=' . urlencode($filter_date);
                if ($session_id !== '') $print_rincian_url .= '&session=' . urlencode($session_id);
            ?>
            <button class="btn-print" onclick="window.open('<?= $print_rekap_url ?>','_blank')">Print Rekap</button>
            <?php if ($req_show === 'harian'): ?>
                <button class="btn-print" onclick="window.open('<?= $print_rincian_url ?>','_blank')">Print Rincian</button>
            <?php endif; ?>
            <button class="btn-print" type="button" onclick="openHpModal()">Input HP Blok</button>
            <?php if ($req_show === 'harian'): ?>
                <button class="btn-print" type="button" onclick="openAuditModal()" <?= $audit_locked_today ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '' ?>>Audit Manual</button>
                <button class="btn-print" type="button" onclick="openNoteModal()" style="background:#8e44ad; color:#fff;">
                    <i class="fa fa-sticky-note-o"></i> Catatan / Insiden
                </button>
                <?php if ($audit_locked_today): ?>
                    <span style="font-size:12px;color:#f39c12;align-self:center;">Audit terkunci</span>
                <?php endif; ?>
            <?php endif; ?>
            <button class="btn-print" type="button" id="btn-settlement" onclick="manualSettlement()" <?= (!empty($settled_today) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '') ?>>Settlement</button>
            <?php if (!empty($settled_today)): ?>
                <button class="btn-print" type="button" id="btn-settlement-reset" onclick="openSettlementResetModal()" style="background:#ff9800;color:#fff; display:none;">Reset</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding:16px;">
        <?php if (!empty($no_sales_message)): ?>
            <div style="background:#2b2b2b;border:1px solid #555;border-radius:6px;padding:10px 12px;margin-bottom:12px;color:#f3c969;">
                <i class="fa fa-info-circle"></i> <?= htmlspecialchars($no_sales_message); ?>
            </div>
        <?php elseif (!empty($auto_date_applied)): ?>
            <div style="background:#2b2b2b;border:1px solid #555;border-radius:6px;padding:10px 12px;margin-bottom:12px;color:#8bd0ff;">
                <i class="fa fa-info-circle"></i> Menampilkan data terakhir: <?= htmlspecialchars($filter_date); ?>
            </div>
        <?php endif; ?>
        <?php
            $net_system_display = (int)$total_net;
            $voucher_loss_display = (int)$total_rusak + (int)$total_invalid;
            $setoran_loss_display = $audit_selisih_setoran_adj_total < 0 ? abs((int)$audit_selisih_setoran_adj_total) : 0;
            $kerugian_display = $voucher_loss_display + $setoran_loss_display;
            $waterfall_tech_loss = $voucher_loss_display;
            $waterfall_target = $net_system_display;
            $waterfall_actual = ($req_show === 'harian') ? (int)$audit_total_actual_setoran : 0;
            $waterfall_variance = $waterfall_actual - $waterfall_target;
            $total_expenses_today = 0;
            if (!empty($audit_rows)) {
                foreach ($audit_rows as $ar) {
                    $total_expenses_today += (int)($ar['expenses_amt'] ?? 0);
                }
            }
            $real_cash = $audit_total_actual_setoran - $total_expenses_today;
        ?>
        <div class="summary-grid">
            <?php
                $audit_qty_cls = $audit_total_selisih_qty > 0 ? 'audit-pos' : ($audit_total_selisih_qty < 0 ? 'audit-neg' : 'audit-zero');
                $audit_setoran_cls = $audit_total_selisih_setoran > 0 ? 'audit-pos' : ($audit_total_selisih_setoran < 0 ? 'audit-neg' : 'audit-zero');
            ?>
            <div class="summary-card">
                <div class="summary-title">Gross Income</div>
                <div class="summary-value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Kerugian</div>
                <div class="summary-value" style="color:#c0392b;">
                    <?= $cur ?> <?= number_format($kerugian_display,0,',','.') ?>
                </div>
                <div style="font-size:12px;color:var(--txt-muted)">Voucher: <?= $cur ?> <?= number_format($voucher_loss_display,0,',','.') ?> | Setoran: <?= $cur ?> <?= number_format($setoran_loss_display,0,',','.') ?></div>
            </div>
            <div class="summary-card" style="border:1px solid <?= $total_expenses_today > 0 ? '#f39c12' : 'var(--border-col)' ?>;">
                <div class="summary-title">Setoran Fisik (Cash)</div>
                <div class="summary-value" style="color:#fff;">
                    <?= $cur ?> <?= number_format((int)$real_cash,0,',','.') ?>
                </div>
                <?php if ($total_expenses_today > 0): ?>
                    <div style="font-size:11px;color:#f39c12; margin-top:2px;">
                        <i class="fa fa-minus-circle"></i> Ops: <?= $cur ?> <?= number_format((int)$total_expenses_today,0,',','.') ?> (Bon)
                    </div>
                <?php else: ?>
                    <div style="font-size:11px;color:#777; position:relative; top:3px;">Murni Tunai</div>
                <?php endif; ?>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Device</div>
                <div style="margin-top:7px; margin-bottom:10px;">
                    <span class="summary-badge badge-wartel">WARTEL: <?= number_format($hp_wartel_units,0,',','.') ?></span>
                    <span class="summary-badge badge-kamtib">KAMTIB: <?= number_format($hp_kamtib_units,0,',','.') ?></span>
                </div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top:6px;">
                    Total: <span style="color:#f39c12;"><?= number_format($hp_total_units,0,',','.') ?></span> | Aktif: <span class="text-green"><?= number_format($hp_active_units,0,',','.') ?></span> | Rusak: <?= number_format($hp_rusak_units,0,',','.') ?> | Spam: <?= number_format($hp_spam_units,0,',','.') ?>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Voucher Terjual</div>
                <div class="summary-value"><?= number_format($total_qty_laku,0,',','.') ?></div>
                <div style="font-size:12px;color:var(--txt-muted);margin-top: 1px;">Rusak: <?= number_format($total_qty_rusak,0,',','.') ?> | Retur: <?= number_format($total_qty_retur,0,',','.') ?> | Bandwidth: <?= htmlspecialchars(format_bytes_short($total_bandwidth)) ?></div>
            </div>
        </div>
        <?php if (!empty($audit_ghost_hint)): ?>
            <div style="margin-top:8px;color:#fca5a5;">
                Ghost Hunter: <b><?= htmlspecialchars($audit_ghost_hint) ?></b>
            </div>
        <?php endif; ?>
        <?php if ($req_show === 'harian'): ?>
            <div style="margin-top:12px;border:1px solid #3a4046;border-radius:8px;padding:10px;background:#1f2327;">
                <div style="font-size:11px;color:var(--txt-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Waterfall Pendapatan</div>
                <div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;">
                    <div>
                        <div style="font-size:11px;color:var(--txt-muted);">Gross Total</div>
                        <div style="font-weight:700;"><?= $cur ?> <?= number_format((int)$total_gross,0,',','.') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--txt-muted);">Technical Loss</div>
                        <div style="font-weight:700;color:#c0392b;"><?= $cur ?> <?= number_format((int)$waterfall_tech_loss,0,',','.') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--txt-muted);">Target Setoran</div>
                        <div style="font-weight:700;"><?= $cur ?> <?= number_format((int)$waterfall_target,0,',','.') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--txt-muted);">Actual Setoran</div>
                        <div style="font-weight:700;"><?= $cur ?> <?= number_format((int)$waterfall_actual,0,',','.') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--txt-muted);">Variance</div>
                        <div style="font-weight:700;color:<?= $waterfall_variance < 0 ? '#c0392b' : '#2ecc71'; ?>;"><?= $cur ?> <?= number_format((int)$waterfall_variance,0,',','.') ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div id="settlement-time" style="margin-top:12px;font-size:12px;color:var(--txt-muted);">
            Settlement terakhir: <?= $settlement_time ? date('d-m-Y H:i:s', strtotime($settlement_time)) : '-' ?>
        </div>
    </div>
</div>

<?php
$hp_rows = [];
$hp_rows_total = [];
$hp_summary = [];
$hp_default_map = [];
$hp_default_date = '';
$hp_today_map = [];
if (isset($db) && $db instanceof PDO && $req_show === 'harian') {
    try {
        // Ambil data HP hari sebelumnya untuk default form (tanpa menyimpan otomatis)
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM phone_block_daily WHERE report_date = :d");
        $stmtCount->execute([':d' => $filter_date]);
        $hasRows = (int)$stmtCount->fetchColumn();
        if ($hasRows === 0) {
            $stmtLast = $db->prepare("SELECT MAX(report_date) FROM phone_block_daily WHERE report_date < :d");
            $stmtLast->execute([':d' => $filter_date]);
            $hp_default_date = (string)($stmtLast->fetchColumn() ?? '');
            if ($hp_default_date !== '') {
                $stmtSrc = $db->prepare("SELECT blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes
                    FROM phone_block_daily WHERE report_date = :d AND unit_type IN ('TOTAL','WARTEL','KAMTIB')");
                $stmtSrc->execute([':d' => $hp_default_date]);
                while ($row = $stmtSrc->fetch(PDO::FETCH_ASSOC)) {
                    $bname = (string)($row['blok_name'] ?? '');
                    if ($bname === '') continue;
                    if (!isset($hp_default_map[$bname])) {
                        $hp_default_map[$bname] = [
                            'wartel_units' => 0,
                            'kamtib_units' => 0,
                            'total_units' => 0,
                            'rusak_units' => 0,
                            'spam_units' => 0,
                            'notes' => ''
                        ];
                    }
                    $ut = strtoupper((string)($row['unit_type'] ?? ''));
                    if ($ut === 'TOTAL') {
                        $hp_default_map[$bname]['total_units'] = (int)($row['total_units'] ?? 0);
                        $hp_default_map[$bname]['rusak_units'] = (int)($row['rusak_units'] ?? 0);
                        $hp_default_map[$bname]['spam_units'] = (int)($row['spam_units'] ?? 0);
                        $hp_default_map[$bname]['notes'] = (string)($row['notes'] ?? '');
                    } elseif ($ut === 'WARTEL') {
                        $hp_default_map[$bname]['wartel_units'] = (int)($row['total_units'] ?? 0);
                    } elseif ($ut === 'KAMTIB') {
                        $hp_default_map[$bname]['kamtib_units'] = (int)($row['total_units'] ?? 0);
                    }
                }
            }
        }

        $stmt = $db->prepare("SELECT * FROM phone_block_daily WHERE report_date = :d ORDER BY blok_name,
            CASE unit_type WHEN 'TOTAL' THEN 0 WHEN 'WARTEL' THEN 1 WHEN 'KAMTIB' THEN 2 ELSE 3 END");
        $stmt->execute([':d' => $filter_date]);
        $hp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hp_breakdown = [];
        foreach ($hp_rows as $row) {
            $bname = $row['blok_name'] ?? '';
            $ut = $row['unit_type'] ?? '';
            if ($bname === '' || ($ut !== 'WARTEL' && $ut !== 'KAMTIB')) continue;
            if (!isset($hp_breakdown[$bname])) $hp_breakdown[$bname] = ['WARTEL' => 0, 'KAMTIB' => 0];
            $hp_breakdown[$bname][$ut] = (int)($row['total_units'] ?? 0);
        }

        $hp_rows_total = array_values(array_filter($hp_rows, function($row){
            return strtoupper((string)($row['unit_type'] ?? '')) === 'TOTAL';
        }));

        foreach ($hp_rows_total as $row) {
            $bname = (string)($row['blok_name'] ?? '');
            if ($bname === '') continue;
            $bw = $hp_breakdown[$bname]['WARTEL'] ?? 0;
            $bk = $hp_breakdown[$bname]['KAMTIB'] ?? 0;
            $hp_today_map[$bname] = [
                'wartel_units' => (int)$bw,
                'kamtib_units' => (int)$bk,
                'total_units' => (int)($row['total_units'] ?? 0),
                'rusak_units' => (int)($row['rusak_units'] ?? 0),
                'spam_units' => (int)($row['spam_units'] ?? 0),
                'notes' => (string)($row['notes'] ?? '')
            ];
        }

        $stmt2 = $db->prepare("SELECT unit_type,
            SUM(total_units) AS total_units,
            SUM(active_units) AS active_units,
            SUM(rusak_units) AS rusak_units,
            SUM(spam_units) AS spam_units
          FROM phone_block_daily
            WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
          GROUP BY unit_type
          ORDER BY unit_type");
        $stmt2->execute([':d' => $filter_date]);
        $hp_summary = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hp_rows = [];
        $hp_rows_total = [];
        $hp_summary = [];
    }
}
?>

<?php if ($req_show === 'harian'): ?>
<script>
window.hpDefaults = <?= json_encode($hp_default_map ?? [], JSON_UNESCAPED_UNICODE); ?>;
window.hpTodayMap = <?= json_encode($hp_today_map ?? [], JSON_UNESCAPED_UNICODE); ?>;
window.hpDefaultDate = <?= json_encode($filter_date ?? ''); ?>;
window.hpDefaultSourceDate = <?= json_encode($hp_default_date ?? ''); ?>;
</script>
<?php if (!empty($hp_error)): ?>
    <div class="card-solid mb-3">
        <div class="card-body" style="padding:12px;color:#fca5a5;">
            <?= htmlspecialchars($hp_error); ?>
        </div>
    </div>
<?php endif; ?>
<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-mobile mr-2"></i> Data Handphone per Blok (Harian)</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap audit-table">
                <thead>
                    <tr>
                        <th>Blok</th>
                        <th class="text-center">WARTEL</th>
                        <th class="text-center">KAMTIB</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Aktif</th>
                        <th class="text-center">Rusak</th>
                        <th class="text-center">Spam</th>
                        <th class="text-right">Catatan</th>
                        <th class="text-right">Hapus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hp_rows_total)): ?>
                        <tr><td colspan="9" style="text-align:center;color:var(--txt-muted);padding:30px;">Belum ada input.</td></tr>
                    <?php else: foreach ($hp_rows_total as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['blok_name'] ?? '-') ?></td>
                            <td>
                                <?php
                                    $bname = $r['blok_name'] ?? '';
                                    $bw = $hp_breakdown[$bname]['WARTEL'] ?? 0;
                                    $bk = $hp_breakdown[$bname]['KAMTIB'] ?? 0;
                                ?>
                                <span class="unit-stack unit-wartel" style="display:inline-block;"><?= (int)$bw; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="unit-stack unit-kamtib" style="display:inline-block;"><?= (int)$bk; ?></span>
                            </td>
                            <td class="text-center"><?= (int)($r['total_units'] ?? 0) ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['active_units'] ?? 0) : '-' ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['rusak_units'] ?? 0) : '-' ?></td>
                              <td class="text-center"><?= ($r['unit_type'] ?? '') === 'TOTAL' ? (int)($r['spam_units'] ?? 0) : '-' ?></td>
                            <td class="hp-notes"><small title="<?= htmlspecialchars($r['notes'] ?? '') ?>"><?= htmlspecialchars($r['notes'] ?? '') ?></small></td>
                            <td class="text-right">
                                <?php if (($r['unit_type'] ?? '') === 'TOTAL'): ?>
                                    <button type="button" class="btn-act" onclick="openHpEdit(this)"
                                        data-blok="<?= htmlspecialchars($bname); ?>"
                                        data-date="<?= htmlspecialchars($filter_date); ?>"
                                        data-total="<?= (int)($r['total_units'] ?? 0); ?>"
                                        data-rusak="<?= (int)($r['rusak_units'] ?? 0); ?>"
                                        data-spam="<?= (int)($r['spam_units'] ?? 0); ?>"
                                        data-notes="<?= htmlspecialchars($r['notes'] ?? ''); ?>"
                                        data-wartel="<?= (int)$bw; ?>"
                                        data-kamtib="<?= (int)$bk; ?>">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-act btn-act-danger" onclick="openDeleteHpModal('<?= './?report=selling' . $session_qs . '&show=' . $req_show . '&date=' . urlencode($filter_date) . '&hp_delete=1&blok=' . urlencode($bname) . '&hp_date=' . urlencode($filter_date); ?>','<?= htmlspecialchars($bname); ?>','<?= htmlspecialchars($filter_date); ?>')">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <span style="color:var(--txt-muted);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="hp-total-bar">
            <div>Total: <b><?= number_format($hp_total_units,0,',','.') ?></b></div>
            <div>Aktif: <b><?= number_format($hp_active_units,0,',','.') ?></b></div>
            <div>Rusak: <b><?= number_format($hp_rusak_units,0,',','.') ?></b></div>
            <div>Spam: <b><?= number_format($hp_spam_units,0,',','.') ?></b></div>
        </div>
    </div>
</div>

<?php if (!empty($audit_error)): ?>
    <div class="card-solid mb-3">
        <div class="card-body" style="padding:12px;color:#fca5a5;">
            <?= htmlspecialchars($audit_error); ?>
        </div>
    </div>
<?php endif; ?>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-check-square-o mr-2"></i> Audit Manual Rekap (Harian)</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th>Blok</th>
                        <th class="text-center">QTY</th>
                        <th class="text-center">Selisih</th>
                        <th class="text-right">Setoran</th>
                        <th class="text-center">Selisih</th>
                        <th class="text-center">Rusak</th>
                        <th class="text-center">Retur</th>
                        <th class="text-center">Voucher 10</th>
                        <th class="text-center">Voucher 30</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_rows)): ?>
                        <tr><td colspan="10" style="text-align:center;color:var(--txt-muted);padding:30px;">Belum ada audit manual.</td></tr>
                    <?php else: 
                        $price10 = (int)$price10;
                        $price30 = (int)$price30;
                        $audit_manual_qty_display_total = 0;
                        $audit_manual_setoran_display_total = 0;
                        $audit_system_qty_display_total = 0;
                        $audit_system_setoran_display_total = 0;
                        $audit_selisih_qty_display_total = 0;
                        $audit_selisih_setoran_display_total = 0;
                        foreach ($audit_rows as $ar): ?>
                        <?php
                            $sq = (int)($ar['selisih_qty'] ?? 0);
                            $ss = (int)($ar['selisih_setoran'] ?? 0);
                            $audit_date_row = $ar['report_date'] ?? $filter_date;
                            $audit_block_row = normalize_block_name($ar['blok_name'] ?? '');
                            $is_locked_row = (int)($ar['is_locked'] ?? 0) === 1;
                            $sys_rusak = (int)($by_block[$ar['blok_name']]['rusak_qty'] ?? 0);
                            $sys_retur = (int)($by_block[$ar['blok_name']]['retur'] ?? 0);
                            $expected_qty = (int)($ar['expected_qty'] ?? 0);
                            $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                            if ($audit_date_row !== '') {
                                $rows_src = !empty($rows) ? $rows : (isset($db) && $db instanceof PDO ? fetch_rows_for_audit($db, $audit_date_row) : []);
                                if (!empty($rows_src)) {
                                    $expected = calc_expected_for_block($rows_src, $audit_date_row, $audit_block_row);
                                    $expected_qty = (int)($expected['raw_qty'] ?? 0);
                                    $expected_setoran = (int)($expected['net'] ?? 0);
                                    $sys_rusak = (int)($expected['rusak_qty'] ?? $sys_rusak);
                                    $sys_retur = (int)($expected['retur_qty'] ?? $sys_retur);
                                }
                            }
                            $cls_q = $sq > 0 ? 'audit-pos' : ($sq < 0 ? 'audit-neg' : 'audit-zero');
                            $cls_s = $ss > 0 ? 'audit-pos' : ($ss < 0 ? 'audit-neg' : 'audit-zero');
                            $evidence = [];
                            $profile10 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile30 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile10_sum = 0;
                            $profile30_sum = 0;
                            $profile_qty_10 = 0;
                            $profile_qty_30 = 0;
                            $manual_rusak_10 = 0;
                            $manual_rusak_30 = 0;
                            $manual_retur_10 = 0;
                            $manual_retur_30 = 0;
                            $manual_invalid_10 = 0;
                            $manual_invalid_30 = 0;
                            $has_manual_evidence = false;
                            if (!empty($ar['user_evidence'])) {
                                $evidence = json_decode((string)$ar['user_evidence'], true);
                                if (is_array($evidence)) {
                                    $has_manual_evidence = true;
                                    if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                                        $profile_qty_10 = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                                        $profile_qty_30 = (int)($evidence['profile_qty']['qty_30'] ?? 0);
                                    }
                                    if (!empty($evidence['users']) && is_array($evidence['users'])) {
                                        foreach ($evidence['users'] as $uname => $ud) {
                                            $cnt = isset($ud['events']) && is_array($ud['events']) ? count($ud['events']) : 0;
                                            $upt = trim((string)($ud['last_uptime'] ?? ''));
                                            $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                            $price_val = (int)($ud['price'] ?? 0);
                                            $upt = $upt !== '' ? $upt : '-';
                                            $kind = (string)($ud['profile_kind'] ?? '10');
                                            $u_status = strtolower((string)($ud['last_status'] ?? ''));
                                            $bucket = ($kind === '30') ? $profile30 : $profile10;
                                            $bucket['user'][] = (string)$uname;
                                            $bucket['up'][] = $upt;
                                            $bucket['byte'][] = $lb;
                                            $bucket['login'][] = $cnt . 'x';
                                            $bucket['total'][] = number_format($price_val,0,',','.');
                                            if ($kind === '30') {
                                                $profile30_sum += $price_val;
                                                $profile30 = $bucket;
                                                if ($u_status === 'invalid') $manual_invalid_30++;
                                                elseif ($u_status === 'retur') $manual_retur_30++;
                                                elseif ($u_status === 'rusak') $manual_rusak_30++;
                                            } else {
                                                $profile10_sum += $price_val;
                                                $profile10 = $bucket;
                                                if ($u_status === 'invalid') $manual_invalid_10++;
                                                elseif ($u_status === 'retur') $manual_retur_10++;
                                                elseif ($u_status === 'rusak') $manual_rusak_10++;
                                            }
                                        }
                                    } else {
                                        $cnt = isset($evidence['events']) && is_array($evidence['events']) ? count($evidence['events']) : 0;
                                        $upt = trim((string)($evidence['last_uptime'] ?? ''));
                                        $lb = format_bytes_short((int)($evidence['last_bytes'] ?? 0));
                                        $price_val = (int)($evidence['price'] ?? 0);
                                        $upt = $upt !== '' ? $upt : '-';
                                        $profile10['user'][] = '-';
                                        $profile10['up'][] = $upt;
                                        $profile10['byte'][] = $lb;
                                        $profile10['login'][] = $cnt . 'x';
                                        $profile10['total'][] = number_format($price_val,0,',','.');
                                        $profile10_sum += $price_val;
                                    }
                                }
                            }
                            if ($profile_qty_10 <= 0) $profile_qty_10 = count($profile10['user'] ?? []);
                            if ($profile_qty_30 <= 0) $profile_qty_30 = count($profile30['user'] ?? []);

                            $profile_qty_map = [];
                            $audit_profile_label_map = [];
                            if (!empty($audit_profiles)) {
                                foreach ($audit_profiles as $ap) {
                                    $k = strtolower((string)($ap['key'] ?? ''));
                                    if ($k !== '') {
                                        $audit_profile_label_map[$k] = (string)($ap['label'] ?? $k);
                                    }
                                }
                            }
                            if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                                $raw_map = $evidence['profile_qty'];
                                if (isset($raw_map['qty_10']) || isset($raw_map['qty_30'])) {
                                    $profile_qty_map['10menit'] = (int)($raw_map['qty_10'] ?? 0);
                                    $profile_qty_map['30menit'] = (int)($raw_map['qty_30'] ?? 0);
                                } else {
                                    foreach ($raw_map as $k => $v) {
                                        $key = strtolower(trim((string)$k));
                                        if ($key === '') continue;
                                        $profile_qty_map[$key] = (int)$v;
                                    }
                                }
                            }
                            if (empty($profile_qty_map)) {
                                $profile_qty_map['10menit'] = (int)$profile_qty_10;
                                $profile_qty_map['30menit'] = (int)$profile_qty_30;
                            }

                            $status_count_map = [
                                '10menit' => ['rusak' => $manual_rusak_10, 'invalid' => $manual_invalid_10, 'retur' => $manual_retur_10],
                                '30menit' => ['rusak' => $manual_rusak_30, 'invalid' => $manual_invalid_30, 'retur' => $manual_retur_30]
                            ];

                            $manual_display_qty = 0;
                            $manual_display_setoran = 0;
                            $profile_qty_summary = [];
                            foreach ($profile_qty_map as $pkey => $pqty) {
                                $pqty = (int)$pqty;
                                $manual_display_qty += $pqty;
                                $counts = $status_count_map[$pkey] ?? ['rusak' => 0, 'invalid' => 0, 'retur' => 0];
                                $money_qty = max(0, $pqty - (int)$counts['rusak'] - (int)$counts['invalid']);
                                $price_val = isset($profile_price_map[$pkey]) ? (int)$profile_price_map[$pkey] : (int)resolve_price_from_profile($pkey);
                                $manual_display_setoran += ($money_qty * $price_val);
                                if ($pqty > 0) {
                                    $label = $audit_profile_label_map[$pkey] ?? $pkey;
                                    $profile_qty_summary[] = $label . ':' . $pqty;
                                }
                            }
                            if (!$has_manual_evidence || $manual_display_qty === 0) {
                                $manual_display_qty = (int)($ar['reported_qty'] ?? 0);
                                $manual_display_setoran = (int)($ar['actual_setoran'] ?? 0);
                            }
                            $profile_qty_10 = (int)($profile_qty_map['10menit'] ?? $profile_qty_10 ?? 0);
                            $profile_qty_30 = (int)($profile_qty_map['30menit'] ?? $profile_qty_30 ?? 0);
                            $expected_adj_qty = $expected_qty;
                            $expected_adj_setoran = $expected_setoran;
                            $sq = $manual_display_qty - $expected_adj_qty;
                            $ss = $manual_display_setoran - $expected_adj_setoran;
                            $cls_q = $sq > 0 ? 'audit-pos' : ($sq < 0 ? 'audit-neg' : 'audit-zero');
                            $cls_s = $ss > 0 ? 'audit-pos' : ($ss < 0 ? 'audit-neg' : 'audit-zero');
                            $audit_manual_qty_display_total += $manual_display_qty;
                            $audit_manual_setoran_display_total += $manual_display_setoran;
                            $audit_system_qty_display_total += $expected_adj_qty;
                            $audit_system_setoran_display_total += $expected_adj_setoran;
                            $audit_selisih_qty_display_total += $sq;
                            $audit_selisih_setoran_display_total += $ss;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($ar['blok_name'] ?? '-') ?></td>
                            <td class="text-center"><?= number_format($manual_display_qty,0,',','.') ?></td>
                            <td class="text-center"><span class="<?= $cls_q; ?>"><?= number_format($sq,0,',','.') ?></span></td>
                            <td class="text-right"><?= number_format($manual_display_setoran,0,',','.') ?></td>
                            <td class="text-center"><span class="<?= $cls_s; ?>"><?= number_format($ss,0,',','.') ?></span></td>
                            <td class="text-center"><small><?= number_format($sys_rusak,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format($sys_retur,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format((int)$profile_qty_10,0,',','.') ?></small></td>
                            <td class="text-center"><small><?= number_format((int)$profile_qty_30,0,',','.') ?></small></td>
                            <td class="text-right">
                                <?php if ($sq < 0): ?>
                                    <button type="button" class="btn-act" title="Cek Ghost" style="background:#8e44ad;color:#fff;" onclick="openGhostModal('<?= htmlspecialchars($audit_block_row); ?>','<?= htmlspecialchars($audit_date_row); ?>',<?= abs((int)$sq); ?>)">
                                        <i class="fa fa-search"></i>
                                    </button>
                                <?php endif; ?>
                                <?php $audit_btn_disabled = (!$is_superadmin || $is_locked_row) ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''; ?>
                                <?php $profile_qty_json = htmlspecialchars(json_encode($profile_qty_map), ENT_QUOTES); ?>
                                <button type="button" class="btn-act" onclick="openAuditEdit(this)" <?= $audit_btn_disabled ?>
                                    data-blok="<?= htmlspecialchars($ar['blok_name'] ?? ''); ?>"
                                    data-user="<?= htmlspecialchars($ar['audit_username'] ?? ''); ?>"
                                    data-date="<?= htmlspecialchars($ar['report_date'] ?? $filter_date); ?>"
                                    data-qty="<?= (int)($ar['reported_qty'] ?? 0); ?>"
                                    data-setoran="<?= (int)($ar['actual_setoran'] ?? 0); ?>"
                                    data-qty10="<?= (int)$profile_qty_10; ?>"
                                    data-qty30="<?= (int)$profile_qty_30; ?>"
                                    data-profile-qty="<?= $profile_qty_json; ?>">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <button type="button" class="btn-act btn-act-danger" onclick="openDeleteAuditModal('<?= './?report=selling' . $session_qs . '&show=' . $req_show . '&date=' . urlencode($filter_date) . '&audit_delete=1&audit_blok=' . urlencode($ar['blok_name'] ?? '') . '&audit_date=' . urlencode($filter_date); ?>','<?= htmlspecialchars($ar['blok_name'] ?? '-'); ?>','<?= htmlspecialchars($filter_date); ?>')" <?= $audit_btn_disabled ?> >
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
            $has_audit_rows = !empty($audit_rows);
            $audit_system_qty_total = (int)($audit_system_qty_display_total ?? $total_qty_laku);
            $audit_system_setoran_total = (int)($audit_system_setoran_display_total ?? $total_net);
            $audit_manual_qty_total = $has_audit_rows ? (int)($audit_manual_qty_display_total ?? $audit_total_reported_qty) : 0;
            $audit_manual_setoran_total = $has_audit_rows ? (int)($audit_manual_setoran_display_total ?? $audit_total_actual_setoran) : 0;
            $audit_selisih_qty_total = $has_audit_rows
                ? (int)($audit_selisih_qty_display_total ?? ($audit_manual_qty_total - $audit_system_qty_total))
                : 0;
            $audit_selisih_setoran_total = $has_audit_rows
                ? (int)($audit_selisih_setoran_display_total ?? ($audit_manual_setoran_total - $audit_system_setoran_total))
                : 0;
        ?>
        <div class="hp-total-bar">
            <div>Sistem Qty (Total): <b><?= number_format($audit_system_qty_total,0,',','.') ?></b></div>
            <div>Manual Qty: <b><?= number_format($audit_manual_qty_total,0,',','.') ?></b></div>
            <div>Selisih Qty: <b><?= number_format($audit_selisih_qty_total,0,',','.') ?></b></div>
            <div>Sistem Rp (Total): <b><?= number_format($audit_system_setoran_total,0,',','.') ?></b></div>
            <div>Manual Rp: <b><?= number_format($audit_manual_setoran_total,0,',','.') ?></b></div>
            <div>Selisih Rp: <b><?= number_format($audit_selisih_setoran_total,0,',','.') ?></b></div>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-table mr-2"></i> Transaksi</h3>
    </div>
    <div class="card-body p-0">
        <?php
            $retur_ref_map = [];
            if (isset($db) && $db instanceof PDO) {
                try {
                    $stmtRef = $db->query("SELECT comment FROM live_sales WHERE comment LIKE '%Retur Ref%'");
                    while ($rr = $stmtRef->fetch(PDO::FETCH_ASSOC)) {
                        $ref_u = extract_retur_user_from_ref($rr['comment'] ?? '');
                        if ($ref_u !== '') $retur_ref_map[strtolower($ref_u)] = true;
                    }
                    $stmtRefHist = $db->query("SELECT comment FROM sales_history WHERE comment LIKE '%Retur Ref%'");
                    while ($rr = $stmtRefHist->fetch(PDO::FETCH_ASSOC)) {
                        $ref_u = extract_retur_user_from_ref($rr['comment'] ?? '');
                        if ($ref_u !== '') $retur_ref_map[strtolower($ref_u)] = true;
                    }
                } catch (Exception $e) {}
            }
        ?>
        <div class="table-responsive">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th style="text-align:center;">Tanggal</th>
                        <th style="text-align:center;">User</th>
                        <th style="text-align:center;">Profile</th>
                        <th style="text-align:center;">Blok</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:right; padding-right:12px;">Harga</th>
                        <th style="text-align:right; padding-right:12px;">Bandwidth</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_page)): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--txt-muted);padding:30px;">Tidak ada data pada periode ini.</td></tr>
                    <?php else: foreach ($list_page as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['dt']) ?></td>
                            <td style="text-align:center">
                                <div><?= htmlspecialchars($it['user']) ?></div>
                                <?php if (!empty($it['retur_ref_user'])): ?>
                                    <div style="font-size:11px;color:var(--txt-muted);">Ref: <?= htmlspecialchars($it['retur_ref_user']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center"><?= htmlspecialchars($it['profile']) ?></td>
                            <td style="text-align:center"><?= htmlspecialchars($it['blok']) ?></td>
                            <td style="text-align:center">
                                <?php
                                    $st = strtolower((string)($it['status'] ?? ''));
                                    $u_name = strtolower((string)($it['user'] ?? ''));
                                    $is_replaced = $u_name !== '' && isset($retur_ref_map[$u_name]);
                                    $st_label = strtoupper($st);
                                    $cls = 'st-normal';
                                    if ($st === 'rusak') {
                                        $cls = 'st-rusak';
                                        if ($is_replaced) {
                                            $st_label = 'RUSAK (DIGANTI)';
                                            $cls = 'st-retur';
                                        }
                                    } elseif ($st === 'retur') {
                                        $cls = 'st-retur';
                                        $st_label = 'RETUR (PENGGANTI)';
                                    } elseif ($st === 'invalid') {
                                        $cls = 'st-invalid';
                                    }
                                ?>
                                <span class="status-badge <?= $cls; ?>"><?= htmlspecialchars($st_label) ?></span>
                            </td>
                            <td class="text-right"><?= number_format($it['price'],0,',','.') ?></td>
                            <td class="text-right"><?= htmlspecialchars(format_bytes_short((int)($it['bytes'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($tx_pages > 1): ?>
            <?php
                $tx_base = './?report=selling' . $session_qs . '&show=' . urlencode($req_show) . '&date=' . urlencode($filter_date);
                $tx_link = function($p) use ($tx_base) { return $tx_base . '&tx_page=' . $p; };
                $tx_window = 2;
                $tx_start = max(1, $tx_page - $tx_window);
                $tx_end = min($tx_pages, $tx_page + $tx_window);
            ?>
            <div class="card-footer-solid">
                <div class="tx-pager">
                    <?php if ($tx_page > 1): ?>
                        <a href="<?= $tx_link(1); ?>">« First</a>
                        <a href="<?= $tx_link($tx_page - 1); ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php for ($p = $tx_start; $p <= $tx_end; $p++): ?>
                        <?php if ($p == $tx_page): ?>
                            <span class="active"><?= $p; ?></span>
                        <?php else: ?>
                            <a href="<?= $tx_link($p); ?>"><?= $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($tx_page < $tx_pages): ?>
                        <a href="<?= $tx_link($tx_page + 1); ?>">Next ›</a>
                        <a href="<?= $tx_link($tx_pages); ?>">Last »</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$is_ajax): ?>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="settlement-reset-modal" class="modal-backdrop" onclick="if(event.target===this){closeSettlementResetModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-refresh" style="color:#ff9800;margin-right:6px;"></i> Reset Settlement</div>
            <button type="button" class="modal-close" onclick="closeSettlementResetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="line-height:1.6;">Reset status settlement untuk tanggal ini agar tombol Settlement bisa dipakai lagi?</div>
            <div class="modal-note">Tindakan ini hanya menghapus status selesai pada sistem, tidak mengubah data MikroTik.</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-print btn-default-dark" onclick="closeSettlementResetModal()">Batal</button>
            <button type="button" class="btn-print" style="background:#ff9800;color:#fff;" onclick="confirmSettlementReset()">Ya, Reset</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="settlement-modal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);z-index:10050;">
    <div style="background:#1f1f1f;color:#e5e5e5;border:1px solid #444;border-radius:8px;width:720px;max-width:94vw;box-shadow:0 10px 30px rgba(0,0,0,0.6);overflow:hidden;">
        <div style="padding:14px 18px;border-bottom:1px solid #333;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
            <span><i class="fa fa-cog fa-spin" style="margin-right:6px;"></i> Settlement Manual</span>
            <span id="settlement-status" style="font-size:12px;color:#9aa0a6;">Menunggu konfirmasi</span>
        </div>
        <div style="padding:16px 18px;">
            <div id="settlement-confirm" style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:#111;border:1px solid #333;border-radius:6px;padding:12px;margin-bottom:12px;">
                <div style="font-size:12px;color:#cbd5e1;">Jalankan settlement manual sekarang?</div>
                <div style="display:flex;gap:8px;">
                    <button id="settlement-start" type="button" class="btn-print">Mulai</button>
                    <button id="settlement-cancel" type="button" class="btn-print" style="opacity:.8;">Batal</button>
                </div>
            </div>
            <div id="settlement-log-wrap" style="display:none;">
                <div style="font-size:12px;color:#9aa0a6;margin-bottom:8px;">Log settlement (MikroTik)</div>
                <div id="settlement-log" class="terminal-window"></div>
            </div>
        </div>
        <div id="settlement-footer" style="padding:10px 18px;border-top:1px solid #333;display:none;justify-content:space-between;gap:8px;align-items:center;">
            <span id="processStatus" style="font-size:12px;color:#ff9800;"><i class="fa fa-refresh fa-spin"></i> Menunggu proses...</span>
            <button id="settlement-close" type="button" class="btn-print" onclick="closeSettlementModal()" disabled style="opacity:.6;cursor:not-allowed;">Tutup</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="hp-delete-modal" class="modal-backdrop" onclick="if(event.target===this){closeDeleteHpModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-exclamation-triangle" style="color:#ff9800;margin-right:6px;"></i> Konfirmasi Hapus</div>
            <button type="button" id="hp-delete-close" onclick="closeDeleteHpModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="hp-delete-text" style="text-align:left;line-height:1.6;">Hapus data ini?</div>
            <div class="modal-note">Tindakan ini menghapus seluruh data untuk Blok terkait (TOTAL, WARTEL, KAMTIB) pada tanggal tersebut.</div>
        </div>
        <div class="modal-footer">
            <button type="button" id="hp-delete-cancel" onclick="closeDeleteHpModal()" class="btn-print btn-default-dark">Batal</button>
            <button type="button" id="hp-delete-confirm" onclick="confirmDeleteHpModal()" class="btn-print" style="background:#ff9800;color:#fff;">Ya, Hapus</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="audit-delete-modal" class="modal-backdrop" onclick="if(event.target===this){closeDeleteAuditModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-exclamation-triangle" style="color:#ff9800;margin-right:6px;"></i> Konfirmasi Hapus</div>
            <button type="button" onclick="closeDeleteAuditModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="audit-delete-text" style="text-align:left;line-height:1.6;">Hapus audit ini?</div>
            <div class="modal-note">Tindakan ini hanya menghapus catatan audit manual untuk blok dan tanggal tersebut.</div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeDeleteAuditModal()" class="btn-print btn-default-dark">Batal</button>
            <button type="button" onclick="confirmDeleteAuditModal()" class="btn-print" style="background:#ff9800;color:#fff;">Ya, Hapus</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<?php if (!empty($is_superadmin)): ?>
<div id="audit-lock-modal" class="modal-backdrop" onclick="if(event.target===this){closeAuditLockModal();}">
    <div class="modal-card" style="width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-lock" style="color:#ff9800;margin-right:6px;"></i> Konfirmasi Kunci Audit</div>
            <button type="button" onclick="closeAuditLockModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="audit-lock-text" style="text-align:left;line-height:1.6;">Kunci audit ini?</div>
            <div class="modal-note">Setelah dikunci, audit tidak bisa diedit kecuali oleh admin.</div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeAuditLockModal()" class="btn-print btn-default-dark">Batal</button>
            <button type="button" onclick="confirmAuditLockModal()" class="btn-print" style="background:#ff9800;color:#fff;">Ya, Kunci</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$is_ajax): ?>
<div id="ghost-modal" class="modal-backdrop" onclick="if(event.target===this){closeGhostModal();}">
    <div class="modal-card" style="width:760px;max-width:96vw;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-search" style="color:#8e44ad;margin-right:6px;"></i> Ghost Hunter</div>
            <button type="button" onclick="closeGhostModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="ghost-meta" style="font-size:12px;color:var(--txt-muted);margin-bottom:8px;"></div>
            <div id="ghost-status" style="font-size:12px;color:#ccc;margin-bottom:10px;"></div>
            <div class="table-responsive" style="max-height:360px;overflow:auto;">
                <table class="table-dark-solid text-nowrap" id="ghost-table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th class="text-center">Profile</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Uptime</th>
                            <th class="text-center">Usage</th>
                            <th class="text-center">Login</th>
                            <th class="text-center">Skor</th>
                        </tr>
                    </thead>
                    <tbody id="ghost-body">
                        <tr><td colspan="7" style="text-align:center;color:var(--txt-muted);padding:20px;">Belum ada data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeGhostModal()" class="btn-print btn-default-dark">Tutup</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_ajax) { echo ob_get_clean(); exit; } ?>

<?php if (!$is_ajax): ?>
<script src="report/laporan/js/laporan.js" defer></script>
<?php endif; ?>

<!-- Pendapatan per Blok/Profile sementara disembunyikan sesuai permintaan -->
