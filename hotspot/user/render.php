?>

  <link rel="stylesheet" href="./hotspot/user/css/users.css">

<div class="row">
  <div id="page-dim" class="page-dim" aria-hidden="true">
    <div class="spinner"><i class="fa fa-circle-o-notch fa-spin"></i> Memproses...</div>
  </div>
  <div id="action-banner" class="action-banner" aria-live="polite"></div>
  <div id="confirm-modal" class="confirm-modal">
    <div class="confirm-card">
      <div class="confirm-header">
        <div class="confirm-title">Konfirmasi Tindakan</div>
        <button type="button" class="confirm-close" id="confirm-close">&times;</button>
      </div>
      <div class="confirm-body">
        <div class="confirm-icon"><i class="fa fa-question-circle"></i></div>
        <div id="confirm-message" class="confirm-message"></div>
      </div>
      <div class="confirm-footer">
        <button type="button" class="confirm-btn confirm-btn-secondary" id="confirm-print"><i class="fa fa-print dul"></i> Print</button>
        <button type="button" class="confirm-btn confirm-btn-secondary" id="confirm-cancel">Batal</button>
        <button type="button" class="confirm-btn confirm-btn-warning" id="confirm-ok">Ya, Lanjutkan</button>
      </div>
    </div>
  </div>
  <div id="relogin-modal" class="relogin-modal" aria-hidden="true">
    <div class="relogin-card">
      <div class="relogin-header">
        <div>
          <div class="relogin-title" id="relogin-title">Detail Relogin</div>
          <div class="relogin-sub" id="relogin-sub"></div>
        </div>
        <div class="relogin-actions">
          <button type="button" class="relogin-print" id="relogin-print"><i class="fa fa-print"></i> Print</button>
          <button type="button" class="relogin-close" id="relogin-close">&times;</button>
        </div>
      </div>
      <div class="relogin-body" id="relogin-body">
        <div style="text-align:center;color:#9aa0a6;">Memuat...</div>
      </div>
    </div>
  </div>
  <?php if (!$is_ajax): ?>
    <div id="summary-modal" class="summary-modal" aria-hidden="true">
      <div class="summary-card">
        <div class="summary-header">
          <div class="summary-title"><i class="fa fa-list-alt"></i> Sisa Voucher</div>
          <button type="button" class="summary-close" id="summary-close">&times;</button>
        </div>
        <div class="summary-body">
          <div class="users-summary-grid">
            <div>
              <?php if (!empty($summary_ready_by_blok)): ?>
                <div class="users-summary-scroll">
                  <table class="users-summary-table">
                    <thead>
                      <tr>
                        <th>Kode Blok</th>
                        <th class="text-right">Jumlah</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($summary_ready_by_blok as $blok => $count): ?>
                        <?php $total = (int)($count['total'] ?? 0); ?>
                        <?php $p10 = (int)($count['p10'] ?? 0); ?>
                        <?php $p30 = (int)($count['p30'] ?? 0); ?>
                        <?php $is_low = ($total <= 100); ?>
                        <tr class="<?= $is_low ? 'low-stock' : '' ?>">
                          <td><?= htmlspecialchars($blok) ?></td>
                          <td class="text-right <?= $is_low ? 'low-stock' : '' ?>" style="font-weight:600;color:#34d399;">
                            <?= $total ?>
                            <div style="font-size:10px;color:#9aa0a6;margin-top:4px;">
                              10m: <?= $p10 ?> · 30m: <?= $p30 ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div style="text-align:center;color:#9aa0a6;padding:24px 0;">
                  <i class="fa fa-inbox" style="opacity:0.3;font-size:24px;"></i><br>
                  Belum ada stok Ready.
                </div>
              <?php endif; ?>
            </div>
            <div>
              <div class="summary-stat">
                <div class="stat-value"><?= (int)$summary_ready_total ?></div>
                <div class="stat-label">Sisa Ready</div>
              </div>
              <div class="summary-stat">
                <div class="stat-value text-warning"><?= (int)$summary_rusak_total ?></div>
                <div class="stat-label">Rusak</div>
              </div>
              <div class="summary-stat">
                <div class="stat-value text-purple"><?= (int)$summary_retur_total ?></div>
                <div class="stat-label">Retur</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <div class="col-12">
    <div class="card card-solid">
      <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-users mr-2"></i> Manajemen Voucher</h3>
        <span id="users-total" class="badge badge-secondary p-2" style="font-size:14px">Total: <?= $total_items ?> Items</span>
        <span id="users-active" class="badge badge-info p-2" style="font-size:14px;margin-left:6px;">Online: -</span>
      </div>
      <div class="toolbar-container">
        <form action="?" method="GET" class="toolbar-row m-0" id="users-toolbar-form">
          <input type="hidden" name="hotspot" value="users">
          <input type="hidden" name="session" value="<?= $session ?>">

          <div class="toolbar-left">
            <div class="input-group-solid">
              <div class="search-wrap">
                <input type="text" name="q" value="<?= htmlspecialchars($req_search) ?>" class="form-control first-el" placeholder="Cari User... (pisah dengan koma / spasi)" autocomplete="off">
                <button type="button" class="search-clear-btn" id="search-clear" title="Clear">×</button>
              </div>

              <select name="status" class="custom-select-solid mid-el" onchange="this.form.submit()" style="flex: 0 0 220px;">
                <option value="all" <?=($req_status=='all'?'selected':'')?>>Status: Semua</option>
                <option value="ready" <?=($req_status=='ready'?'selected':'')?>>🟢 Hanya Ready</option>
                <option value="online" <?=($req_status=='online'?'selected':'')?>>🔵 Sedang Online</option>
                <option value="used" <?=($req_status=='used'?'selected':'')?>>⚪ Sudah Terpakai</option>
                <option value="rusak" <?=($req_status=='rusak'?'selected':'')?>>🟠 Rusak / Error</option>
                <option value="retur" <?=($req_status=='retur'?'selected':'')?>>🟣 Hasil Retur</option>
              </select>

              <select name="profile" class="custom-select-solid mid-el" onchange="this.form.submit()" style="flex: 0 0 160px;">
                <option value="all" <?=($req_prof=='all'?'selected':'')?>>Profil: Semua</option>
                <option value="10" <?=($req_prof=='10'?'selected':'')?>>10 Menit</option>
                <option value="30" <?=($req_prof=='30'?'selected':'')?>>30 Menit</option>
              </select>

              <select name="comment" class="custom-select-solid last-el" onchange="this.form.submit()" style="flex: 0 0 220px;">
                <option value="">Semua Blok</option>
                  <?php foreach($list_blok as $b) {
                    $label = preg_replace('/^BLOK-/i', 'BLOK ', $b);
                    $sel = (strcasecmp($req_comm, $b) == 0) ? 'selected' : '';
                    echo "<option value='$b' $sel>$label</option>";
                } ?>
              </select>
            </div>
            <div class="input-group-solid period-group">
              <select name="show" class="custom-select-solid first-el no-sep-right" onchange="this.form.submit()" style="flex: 0 0 140px;">
                <option value="semua" <?= $req_show==='semua'?'selected':''; ?> style="display:none;">Semua</option>
                <option value="harian" <?= $req_show==='harian'?'selected':''; ?>>Harian</option>
                <option value="bulanan" <?= $req_show==='bulanan'?'selected':''; ?>>Bulanan</option>
                <option value="tahunan" <?= $req_show==='tahunan'?'selected':''; ?>>Tahunan</option>
              </select>
              <?php if ($req_show === 'harian'): ?>
                <input type="date" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 170px;">
              <?php elseif ($req_show === 'bulanan'): ?>
                <input type="month" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 170px;">
              <?php else: ?>
                <?php if ($req_show === 'tahunan'): ?>
                  <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 120px;">
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <span id="search-loading" style="display:none;font-size:12px;color:var(--txt-muted);margin-left:6px;">
              <i class="fa fa-circle-o-notch fa-spin"></i> Mencari...
            </span>
          </div>
          <?php
            $status_labels = ['used' => 'Terpakai', 'retur' => 'Retur', 'rusak' => 'Rusak'];
            $can_delete_status = in_array($req_status, array_keys($status_labels)) && $req_show === 'semua' && empty($filter_date);
            $status_label = $status_labels[$req_status] ?? '';
            $can_print_block = ($req_comm != '' && $req_status === 'ready');
            $can_print_status = ($req_comm != '' && $req_status === 'retur');
            $can_print_used = ($req_status === 'used');
            $can_print_online = ($req_status === 'online');
            $can_print_rusak = ($req_status === 'rusak');
            $reset_params = $_GET;
            $reset_params['status'] = 'all';
            unset($reset_params['page']);
            $reset_url = './?' . http_build_query($reset_params);
          ?>
          <div class="toolbar-right">
            <?php if ($req_status !== 'all'): ?>
              <button type="button" class="btn btn-outline-light" style="height:40px;" onclick="location.href='<?= $reset_url ?>'">
                <i class="fa fa-undo"></i> Reset Status
              </button>
            <?php endif; ?>
            <?php if ($req_status === 'all' && $req_comm == ''): ?>
              <?php
                $today_params = $_GET;
                $today_params['show'] = 'harian';
                $today_params['date'] = date('Y-m-d');
                unset($today_params['page']);
                $today_url = './?' . http_build_query($today_params);
              ?>
              <button type="button" class="btn btn-outline-light" style="height:40px;" onclick="location.href='<?= $today_url ?>'">
                <i class="fa fa-calendar"></i> Reset Tanggal
              </button>
            <?php endif; ?>
            <?php if (!$is_ajax): ?>
              <button type="button" id="summary-open" class="btn btn-outline-light" style="height:40px;">
                <i class="fa fa-list-alt"></i> Sisa Voucher
              </button>
            <?php endif; ?>
            <?php if ($can_print_used): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'used',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Terpakai
              </button>
            <?php endif; ?>
            <?php if ($can_print_online): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'online',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Online
              </button>
            <?php endif; ?>
            <?php if ($can_print_rusak): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'rusak',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Rusak
              </button>
            <?php endif; ?>
            <?php if ($req_comm == '' && $can_delete_status): ?>
              <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> (tidak online)?')">
                <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
              </button>
            <?php endif; ?>
            <?php if ($req_comm != ''): ?>
              <?php if ($can_print_status): ?>
                <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('./voucher/print.php?status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&small=yes&session=<?= $session ?>','_blank').print()">
                  <i class="fa fa-print"></i> Print Status
                </button>
              <?php elseif ($can_print_block): ?>
                <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('./voucher/print.php?id=<?= urlencode($req_comm) ?>&small=yes&session=<?= $session ?>','_blank').print()">
                  <i class="fa fa-print"></i> Print Blok
                </button>
              <?php endif; ?>
              <?php
                $print_all_params = [
                  'mode' => 'usage',
                  'status' => 'all',
                  'session' => $session,
                  'blok' => $req_comm
                ];
                $print_all_url = './report/print_rincian.php?' . http_build_query($print_all_params);
              ?>
              <?php if ($req_status === 'all'): ?>
                <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $print_all_url ?>','_blank').print()">
                  <i class="fa fa-print"></i> Print Bukti
                </button>
              <?php endif; ?>
              <?php if ($can_delete_status): ?>
                <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> di <?= htmlspecialchars($req_comm) ?> (tidak online)?')">
                  <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
                </button>
              <?php endif; ?>
              <?php if ($req_status == 'all'): ?>
                <button type="button" class="btn btn-danger" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=batch_delete&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>','Hapus semua voucher di <?= htmlspecialchars($req_comm) ?>?')">
                  <i class="fa fa-trash"></i> Hapus Blok
                </button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="card-body p-0">
        <?php if ($debug_mode): ?>
          <div style="background:#111827;color:#e5e7eb;padding:10px 14px;border-bottom:1px solid #374151;font-family:monospace;font-size:12px;">
            <div style="margin-bottom:6px;">DEBUG DB/ROUTER (showing first 10 rows)</div>
            <?php if (!empty($profile_totals)): ?>
              <div style="margin-bottom:8px;">
                <strong>Audit Total Profil:</strong>
                <div>10 Menit: <?= (int)$profile_totals['10']['count'] ?> | ready <?= (int)$profile_totals['10']['ready'] ?> | online <?= (int)$profile_totals['10']['online'] ?> | used <?= (int)$profile_totals['10']['used'] ?> | rusak <?= (int)$profile_totals['10']['rusak'] ?> | retur <?= (int)$profile_totals['10']['retur'] ?></div>
                <div>30 Menit: <?= (int)$profile_totals['30']['count'] ?> | ready <?= (int)$profile_totals['30']['ready'] ?> | online <?= (int)$profile_totals['30']['online'] ?> | used <?= (int)$profile_totals['30']['used'] ?> | rusak <?= (int)$profile_totals['30']['rusak'] ?> | retur <?= (int)$profile_totals['30']['retur'] ?></div>
                <div>Other: <?= (int)$profile_totals['other']['count'] ?> | ready <?= (int)$profile_totals['other']['ready'] ?> | online <?= (int)$profile_totals['other']['online'] ?> | used <?= (int)$profile_totals['other']['used'] ?> | rusak <?= (int)$profile_totals['other']['rusak'] ?> | retur <?= (int)$profile_totals['other']['retur'] ?></div>
              </div>
            <?php endif; ?>
            <table style="width:100%;border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">User</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Status</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Bytes T/A/H</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Uptime U/A/H</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Login</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Logout</th>
                </tr>
              </thead>
              <tbody>
                <?php $dbg = array_slice($debug_rows, 0, 10); foreach ($dbg as $d): ?>
                  <tr>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['name']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['status']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= (int)$d['bytes_total'] ?>/<?= (int)$d['bytes_active'] ?>/<?= (int)$d['bytes_hist'] ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['uptime_user']) ?>/<?= htmlspecialchars($d['uptime_active']) ?>/<?= htmlspecialchars($d['uptime_hist']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['login'] ?: '-') ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['logout'] ?: '-') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-dark-solid table-hover text-nowrap">
            <thead>
              <tr>
                <th>Username <span class="text-muted">/ Ket</span></th>
                <th>Profile</th>
                <th>Identitas</th>
                <th>Koneksi (MAC/IP)</th>
                <th>Waktu (Login/Logout)</th>
                <th class="text-right">Usage</th>
                <th class="text-center">Status</th>
                <th class="text-center" width="120">Aksi</th>
              </tr>
            </thead>
            <tbody id="users-table-body">
              <?php if(count($display_data) > 0): ?>
                <?php foreach($display_data as $u): ?>
                  <tr>
                    <td>
                      <div style="font-size:15px; font-weight:bold; color:var(--txt-main)">
                        <?= htmlspecialchars($u['name']) ?>
                        <?php if(!empty($u['relogin'])): ?><span class="status-badge st-relogin clickable" data-user="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>" data-blok="<?= htmlspecialchars($u['blok'], ENT_QUOTES) ?>" data-profile="<?= htmlspecialchars($u['profile'], ENT_QUOTES) ?>" style="margin-left:6px;">RELOGIN</span><?php endif; ?>
                      </div>
                      <div style="font-size:11px; color:var(--txt-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:5px;" title="<?= htmlspecialchars($u['comment']) ?>">
                        First login: <?= formatDateIndo($u['first_login'] ?? '-') ?>
                      </div>
                      <?php if (!empty($u['retur_ref'])): ?>
                        <div style="font-size:10px;color:#b2bec3;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($u['retur_ref']) ?>">
                          Retur dari: <?= htmlspecialchars($u['retur_ref']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $display_profile = $u['profile'] ?? '';
                        if ($display_profile === '' && !empty($u['profile_kind']) && $u['profile_kind'] !== 'other') {
                          $display_profile = $u['profile_kind'] . ' Menit';
                        }
                      ?>
                      <span class="badge badge-dark border border-secondary p-1"><?= htmlspecialchars($display_profile) ?></span>
                    </td>
                    <td><span class="id-badge"><?= htmlspecialchars($u['blok'] ?: '-') ?></span></td>
                    <td>
                      <div style="font-family:monospace; font-size:12px; color:#aeb6bf"><?= htmlspecialchars($u['mac']) ?></div>
                      <div style="font-family:monospace; font-size:11px; color:#85929e"><?= htmlspecialchars($u['ip']) ?></div>
                    </td>
                    <td>
                      <div style="font-family:monospace; font-size:11px; color:#52c41a" title="Login Time"><?= formatDateIndo($u['login_time']) ?></div>
                      <div style="font-family:monospace; font-size:11px; color:#ff4d4f" title="Logout Time"><?= formatDateIndo($u['logout_time']) ?></div>
                    </td>
                    <td class="text-right">
                      <span style="font-size:13px; font-weight:600"><?= htmlspecialchars($u['uptime']) ?></span><br>
                      <span style="font-size:11px; color:var(--txt-muted)"><?= formatBytes($u['bytes'],2) ?></span>
                    </td>
                    <td class="text-center">
                      <?php if($u['status'] === 'ONLINE'): ?><span class="status-badge st-online">ONLINE</span>
                      <?php elseif($u['status'] === 'RUSAK'): ?><span class="status-badge st-rusak">RUSAK</span>
                      <?php elseif($u['status'] === 'INVALID'): ?><span class="status-badge st-invalid">INVALID</span>
                      <?php elseif($u['status'] === 'RETUR'): ?><span class="status-badge st-retur">RETUR</span>
                      <?php elseif($u['status'] === 'TERPAKAI'): ?><span class="status-badge st-used">TERPAKAI</span>
                      <?php else: ?><span class="status-badge st-ready">READY</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <?php
                        $status_upper = strtoupper($u['status'] ?? '');
                        $is_ready = ($status_upper === 'READY');
                        $is_online = ($status_upper === 'ONLINE');
                        $is_used = ($status_upper === 'TERPAKAI');
                        $is_rusak = ($status_upper === 'RUSAK');
                        $is_retur = ($status_upper === 'RETUR');
                        $is_invalid = ($status_upper === 'INVALID');
                        $has_rusak_comment = !empty($u['comment_rusak']);
                        $is_disabled = !empty($u['is_disabled']);
                        $can_enable = $is_rusak && $is_disabled && !$has_rusak_comment;
                        $can_mark_rusak = $is_used && !$is_online;
                      ?>
                      <?php if (in_array($req_status, ['all','ready','used','rusak','online','retur'], true)): ?>
                        <?php if ($is_used && in_array($req_status, ['all','used'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_online && in_array($req_status, ['all','online'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_rusak && in_array($req_status, ['all','rusak'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print.detail.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Rincian Rusak"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_retur && in_array($req_status, ['all','retur'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher Retur"><i class="fa fa-print"></i></button>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&download=1&img=1&session=<?= $session ?>','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
                        <?php elseif ($is_ready && in_array($req_status, ['all','ready'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if($u['uid'] || $can_mark_rusak): ?>
                        <?php
                          $keep_params = '&profile=' . urlencode($req_prof) .
                            '&comment=' . urlencode($req_comm) .
                            '&status=' . urlencode($req_status) .
                            '&q=' . urlencode($req_search) .
                            '&show=' . urlencode($req_show) .
                            '&date=' . urlencode($filter_date);
                        ?>
                        <?php if ($is_rusak && !$can_enable): ?>
                          <button type="button" class="btn-act btn-act-retur" onclick="actionRequest('./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Retur"><i class="fa fa-exchange"></i></button>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rollback"><i class="fa fa-undo"></i></button>
                        <?php elseif ($can_enable): ?>
                          <button type="button" class="btn-act btn-act-enable" onclick="actionRequest('./?hotspot=users&action=enable&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Enable Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Enable"><i class="fa fa-check"></i></button>
                        <?php elseif ($is_ready): ?>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=disable&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Disable Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Disable"><i class="fa fa-ban"></i></button>
                        <?php elseif ($can_mark_rusak): ?>
                          <button type="button" class="btn-act btn-act-invalid" data-user="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>" data-blok="<?= htmlspecialchars($u['blok'], ENT_QUOTES) ?>" data-profile="<?= htmlspecialchars($u['profile'], ENT_QUOTES) ?>" data-first-login="<?= htmlspecialchars($u['first_login'], ENT_QUOTES) ?>" data-login="<?= htmlspecialchars($u['login_time'], ENT_QUOTES) ?>" data-logout="<?= htmlspecialchars($u['logout_time'], ENT_QUOTES) ?>" data-bytes="<?= (int)$u['bytes'] ?>" data-uptime="<?= htmlspecialchars($u['uptime'], ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES) ?>" data-relogin="<?= (int)($u['relogin_count'] ?? 0) ?>" onclick="actionRequestRusak(this,'./?hotspot=users&action=invalid&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','SET RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rusak"><i class="fa fa-ban"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div id="users-pagination">
          <?php if ($total_pages > 1): ?>
            <div class="p-3" style="border-top:1px solid var(--border-col);">
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;justify-content:center;">
                <?php
                  $base = $pagination_base;
                  $link = function($p) use ($base) {
                    return $base . (strpos($base, '?') !== false ? '&' : '?') . 'page=' . $p;
                  };
                  $window = 2;
                  $start = max(1, $page - $window);
                  $end = min($total_pages, $page + $window);
                ?>
                <?php if ($page > 1): ?>
                  <a class="btn btn-sm btn-secondary" href="<?= $link(1) ?>">« First</a>
                  <a class="btn btn-sm btn-secondary" href="<?= $link($page - 1) ?>">‹ Prev</a>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                  <?php if ($p == $page): ?>
                    <span class="btn btn-sm btn-primary" style="pointer-events:none;opacity:.9;">Page <?= $p ?></span>
                  <?php else: ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= $link($p) ?>"><?= $p ?></a>
                  <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                  <a class="btn btn-sm btn-secondary" href="<?= $link($page + 1) ?>">Next ›</a>
                  <a class="btn btn-sm btn-secondary" href="<?= $link($total_pages) ?>">Last »</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('users-toolbar-form');
  const searchInput = document.querySelector('input[name="q"]');
  const statusSelect = document.querySelector('select[name="status"]');
  const commentSelect = document.querySelector('select[name="comment"]');
  const showSelect = document.querySelector('select[name="show"]');
  const dateInput = document.querySelector('input[name="date"]');
  const tbody = document.getElementById('users-table-body');
  const totalBadge = document.getElementById('users-total');
  const activeBadge = document.getElementById('users-active');
  const paginationWrap = document.getElementById('users-pagination');
  const searchLoading = document.getElementById('search-loading');
  const clearBtn = document.getElementById('search-clear');
  const pageDim = document.getElementById('page-dim');
  const actionBanner = document.getElementById('action-banner');
  const confirmModal = document.getElementById('confirm-modal');
  const confirmMessage = document.getElementById('confirm-message');
  const confirmOk = document.getElementById('confirm-ok');
  const confirmCancel = document.getElementById('confirm-cancel');
  const confirmClose = document.getElementById('confirm-close');
  const confirmPrint = document.getElementById('confirm-print');
  const reloginModal = document.getElementById('relogin-modal');
  const reloginBody = document.getElementById('relogin-body');
  const reloginTitle = document.getElementById('relogin-title');
  const reloginSub = document.getElementById('relogin-sub');
  const reloginClose = document.getElementById('relogin-close');
  const reloginPrint = document.getElementById('relogin-print');
  const summaryModal = document.getElementById('summary-modal');
  const summaryOpen = document.getElementById('summary-open');
  const summaryClose = document.getElementById('summary-close');
  if (!searchInput || !tbody || !totalBadge || !paginationWrap) return;

  if (summaryOpen && summaryModal) {
    summaryOpen.addEventListener('click', () => {
      summaryModal.style.display = 'flex';
    });
  }
  if (summaryClose && summaryModal) {
    summaryClose.addEventListener('click', () => {
      summaryModal.style.display = 'none';
    });
  }
  if (summaryModal) {
    summaryModal.addEventListener('click', (e) => {
      if (e.target === summaryModal) summaryModal.style.display = 'none';
    });
  }

  if (clearBtn) {
    clearBtn.style.display = searchInput.value.trim() !== '' ? 'inline-block' : 'none';
  }

  const ajaxBase = './hotspot/users.php';
  const baseParams = new URLSearchParams(window.location.search);

  let lastFetchId = 0;
  let appliedQuery = (baseParams.get('q') || '').trim();

  window.showActionPopup = function(type, message) {
    if (!actionBanner) return;
    actionBanner.classList.remove('success', 'error');
    actionBanner.classList.add(type === 'error' ? 'error' : 'success');
    actionBanner.innerHTML = `<i class="fa ${type === 'error' ? 'fa-times-circle' : 'fa-check-circle'}"></i><span>${message}</span>`;
    actionBanner.style.display = 'flex';
    setTimeout(() => { actionBanner.style.display = 'none'; }, 2800);
  };

  function showConfirm(message) {
    return new Promise((resolve) => {
      if (!confirmModal || !confirmMessage || !confirmOk || !confirmCancel) {
        resolve(true);
        return;
      }
      confirmMessage.textContent = message || 'Lanjutkan aksi ini?';
      confirmModal.style.display = 'flex';
      const cleanup = (result) => {
        confirmModal.style.display = 'none';
        confirmOk.onclick = null;
        confirmCancel.onclick = null;
        try { document.activeElement && document.activeElement.blur(); } catch (e) {}
        try { document.body.focus(); } catch (e) {}
        resolve(result);
      };
      confirmOk.onclick = () => cleanup(true);
      confirmCancel.onclick = () => cleanup(false);
      if (confirmClose) confirmClose.onclick = () => cleanup(false);
    });
  }

  let rusakPrintPayload = null;

  function showRusakChecklist(data) {
    return new Promise((resolve) => {
      if (!confirmModal || !confirmMessage || !confirmOk || !confirmCancel) {
        resolve(false);
        return;
      }
      const criteria = data.criteria || {};
      const values = data.values || {};
      const limits = data.limits || {};
      const headerMsg = data.message || '';
      const meta = data.meta || {};
      const items = [
        { label: `Offline (tidak sedang online)`, ok: !!criteria.offline, value: values.online || '-' },
        { label: `Bytes maksimal ${limits.bytes || '-'}`, ok: !!criteria.bytes_ok, value: values.bytes || '-' },
        { label: `Uptime (informasi)`, ok: true, value: values.total_uptime || '-' },
        { label: `Pernah login (first login ada)`, ok: !!criteria.first_login_ok, value: String(values.first_login ?? '-') }
      ];
      const rows = items.map(it => {
        const icon = it.ok ? 'fa-check-circle' : 'fa-times-circle';
        const color = it.ok ? '#16a34a' : '#dc2626';
        return `<tr>
          <td style="padding:6px 8px;border-bottom:1px solid #3d3d3d;"><i class="fa ${icon}" style="color:${color};margin-right:6px;"></i>${it.label}</td>
          <td style="padding:6px 8px;border-bottom:1px solid #3d3d3d;color:#cbd5e1;text-align:right;">${it.value}</td>
        </tr>`;
      }).join('');
      const msgHtml = headerMsg ? `<div style="margin-bottom:8px;color:#f3c969;">${headerMsg}</div>` : '';
      confirmMessage.innerHTML = `
        <div style="text-align:left;">
          <div style="margin-bottom:8px;font-weight:600;">Cek Kelayakan Rusak</div>
          ${msgHtml}
          <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
              <tr>
                <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #3d3d3d;">Kriteria</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid #3d3d3d;">Nilai</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>`;
      const isValid = !!data.ok;
      confirmOk.textContent = 'Ya, Lanjutkan';
      confirmOk.disabled = !isValid;
      confirmOk.style.opacity = isValid ? '1' : '0.5';
      confirmOk.style.cursor = isValid ? 'pointer' : 'not-allowed';
      if (confirmPrint) confirmPrint.style.display = 'inline-flex';
      confirmModal.style.display = 'flex';
      rusakPrintPayload = { headerMsg, items, meta };
      const cleanup = (result) => {
        confirmModal.style.display = 'none';
        confirmOk.onclick = null;
        confirmCancel.onclick = null;
        if (confirmPrint) confirmPrint.onclick = null;
        confirmOk.disabled = false;
        confirmOk.style.opacity = '1';
        confirmOk.style.cursor = 'pointer';
        if (confirmPrint) confirmPrint.style.display = 'none';
        try { document.activeElement && document.activeElement.blur(); } catch (e) {}
        try { document.body.focus(); } catch (e) {}
        resolve(result);
      };
      confirmOk.onclick = () => cleanup(true);
      confirmCancel.onclick = () => cleanup(false);
      if (confirmClose) confirmClose.onclick = () => cleanup(false);
      if (confirmPrint) confirmPrint.onclick = () => {
        if (!rusakPrintPayload) return;
        const mt = rusakPrintPayload.meta || {};
        if (!mt.username) return;
        const url = './hotspot/print.detail.php?session=<?= $session ?>&user=' + encodeURIComponent(mt.username);
        window.open(url, '_blank');
      };
    });
  }

  function uptimeToSeconds(uptime) {
    if (!uptime) return 0;
    const re = /(\d+)(w|d|h|m|s)/gi;
    let total = 0;
    let m;
    while ((m = re.exec(uptime)) !== null) {
      const val = parseInt(m[1], 10) || 0;
      const unit = m[2].toLowerCase();
      if (unit === 'w') total += val * 7 * 24 * 3600;
      if (unit === 'd') total += val * 24 * 3600;
      if (unit === 'h') total += val * 3600;
      if (unit === 'm') total += val * 60;
      if (unit === 's') total += val;
    }
    return total;
  }

  function formatBytesSimple(bytes) {
    const b = Number(bytes) || 0;
    if (b <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let idx = 0;
    let val = b;
    while (val >= 1024 && idx < units.length - 1) {
      val /= 1024;
      idx++;
    }
    const dec = idx >= 2 ? 2 : 0;
    return val.toFixed(dec).replace('.', ',') + ' ' + units[idx];
  }

  function resolveRusakLimits(profile) {
    const p = (profile || '').toLowerCase();
    if (/(\b10\s*(menit|m)\b|10menit)/i.test(p)) {
      return { uptime: 180, bytes: 5 * 1024 * 1024, uptimeLabel: '3 menit', bytesLabel: '5MB' };
    }
    if (/(\b30\s*(menit|m)\b|30menit)/i.test(p)) {
      return { uptime: 300, bytes: 5 * 1024 * 1024, uptimeLabel: '5 menit', bytesLabel: '5MB' };
    }
    return { uptime: 300, bytes: 5 * 1024 * 1024, uptimeLabel: '5 menit', bytesLabel: '5MB' };
  }

  function buildRusakDataFromElement(el) {
    if (!el) return null;
    const bytes = Number(el.getAttribute('data-bytes') || 0);
    const uptime = el.getAttribute('data-uptime') || '0s';
    const status = el.getAttribute('data-status') || '';
    const profile = el.getAttribute('data-profile') || '';
    const username = el.getAttribute('data-user') || '';
    const blok = el.getAttribute('data-blok') || '';
    const firstLogin = el.getAttribute('data-first-login') || '';
    const loginTime = el.getAttribute('data-login') || '';
    const logoutTime = el.getAttribute('data-logout') || '';
    const limits = resolveRusakLimits(profile);
    const uptimeSec = uptimeToSeconds(uptime);
    const offline = status !== 'ONLINE';
    const dateBase = loginTime && loginTime !== '-' ? loginTime : (logoutTime && logoutTime !== '-' ? logoutTime : '');
    const headerDate = dateBase ? formatDateHeader(dateBase) : formatDateNow();
    const totalUptimeSec = uptimeSec;
    const criteria = {
      offline,
      bytes_ok: bytes <= limits.bytes,
      total_uptime_ok: true,
      first_login_ok: !!firstLogin
    };
    const ok = criteria.offline && criteria.bytes_ok && criteria.first_login_ok;
    return {
      ok,
      message: ok ? 'Syarat rusak terpenuhi.' : 'Syarat rusak belum terpenuhi.',
      meta: {
        username,
        blok: formatBlokLabel(blok),
        profile: formatProfileLabel(profile),
        date: headerDate,
        first_login: firstLogin || '-',
        login: loginTime || '-',
        logout: logoutTime || '-'
      },
      criteria,
      values: {
        online: offline ? 'Tidak' : 'Ya',
        bytes: formatBytesSimple(bytes),
        uptime,
        total_uptime: uptime,
        first_login: firstLogin || '-'
      },
      limits: {
        bytes: limits.bytesLabel,
        uptime: limits.uptimeLabel
      }
    };
  }

  window.actionRequest = async function(url, confirmMsg) {
    if (confirmMsg) {
      const ok = await showConfirm(confirmMsg);
      if (!ok) return;
    }
    try {
      if (pageDim) pageDim.style.display = 'flex';
      const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxUrl, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (data && data.ok) {
        window.showActionPopup('success', data.message || 'Berhasil diproses.');
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=<?= $session ?>';
          return;
        }
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', '<?= $session ?>');
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        if (data.redirect) {
          try { history.replaceState(null, '', data.redirect); } catch (e) {}
        }
        fetchUsers(true, false);
      } else if (!data) {
        window.showActionPopup('success', 'Berhasil diproses.');
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=<?= $session ?>';
          return;
        }
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', '<?= $session ?>');
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        fetchUsers(true, false);
      } else {
        window.showActionPopup('error', (data && data.message) ? data.message : 'Gagal memproses.');
      }
    } catch (e) {
      window.showActionPopup('error', 'Gagal memproses.');
    } finally {
      if (pageDim) pageDim.style.display = 'none';
    }
  };

  window.actionRequestRusak = async function(elOrUrl, urlMaybe, confirmMsg) {
    let el = null;
    let url = '';
    let reloginEvents = [];
    if (typeof elOrUrl === 'string') {
      url = elOrUrl;
      confirmMsg = urlMaybe;
    } else {
      el = elOrUrl;
      url = urlMaybe;
    }
    try {
      if (el) {
        const uname = el.getAttribute('data-user') || '';
        if (uname) {
          try {
            const params = new URLSearchParams();
            params.set('action', 'login_events');
            params.set('name', uname);
            params.set('session', '<?= $session ?>');
            const firstLogin = el.getAttribute('data-first-login') || '';
            const dateKey = extractDateKey(firstLogin);
            if (dateKey) {
              params.set('show', 'harian');
              params.set('date', dateKey);
            }
            params.set('ajax', '1');
            params.set('_', Date.now().toString());
            const resp = await fetch(ajaxBase + '?' + params.toString(), { cache: 'no-store' });
            const evData = await resp.json();
            if (evData && Array.isArray(evData.events)) {
              reloginEvents = evData.events;
            }
          } catch (e) {}
        }
      }
      const checkUrl = url.replace('action=invalid', 'action=check_rusak');
      const ajaxCheck = checkUrl + (checkUrl.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxCheck, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (!data) {
        data = buildRusakDataFromElement(el) || {
          ok: false,
          message: 'Gagal memproses. Data validasi tidak terbaca.',
          criteria: {},
          values: {},
          limits: {},
          meta: {}
        };
      }
      if (data && data.meta) {
        if (reloginEvents.length > 0) data.meta.relogin_events = reloginEvents;
        if (reloginEvents.length > 0) data.meta.relogin_count = reloginEvents.length;
        const firstLoginMeta = el ? (el.getAttribute('data-first-login') || '') : '';
        const dateKeyMeta = extractDateKey(firstLoginMeta);
        if (dateKeyMeta) data.meta.relogin_date = dateKeyMeta;
      }
      const ok = await showRusakChecklist(data);
      if (!ok) return;
      window.actionRequest(url, null);
    } catch (e) {
      const data = buildRusakDataFromElement(el) || {
        ok: false,
        message: 'Gagal memproses. Tidak bisa mengambil data validasi.',
        criteria: {},
        values: {},
        limits: {}
      };
      await showRusakChecklist(data);
    }
  };

  function closeReloginModal() {
    if (reloginModal) reloginModal.style.display = 'none';
  }

  let reloginPrintPayload = null;

  function formatDateHeader(dt) {
    if (!dt) return '';
    const parts = dt.split(' ');
    if (!parts[0]) return '';
    const d = parts[0].split('-');
    if (d.length !== 3) return '';
    return `${d[2]}-${d[1]}-${d[0]}`;
  }
  function extractDateKey(dt) {
    if (!dt) return '';
    const part = dt.split(' ')[0] || '';
    return /^\d{4}-\d{2}-\d{2}$/.test(part) ? part : '';
  }
  function formatDateNow() {
    const d = new Date();
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}-${mm}-${yy}`;
  }

  function formatBlokLabel(blok) {
    if (!blok) return '';
    const raw = blok.replace(/^BLOK-?/i, '').trim();
    const m = raw.match(/^([A-Z]+)/i);
    return m ? m[1].toUpperCase() : raw.toUpperCase();
  }

  function formatProfileLabel(profile) {
    if (!profile) return '';
    return profile.replace(/(\d+)\s*(menit)/i, '$1 Menit');
  }

  function formatTimeOnly(dt) {
    if (!dt) return '-';
    const parts = dt.split(' ');
    return parts[1] || '-';
  }

  async function openReloginModal(username, blok, profile) {
    if (!reloginModal || !reloginBody) return;
    if (reloginTitle) reloginTitle.textContent = `Detail Relogin - ${username}`;
    if (reloginSub) reloginSub.textContent = '';
    reloginBody.innerHTML = '<div style="text-align:center;color:#9aa0a6;">Memuat...</div>';
    reloginModal.style.display = 'flex';
    reloginPrintPayload = null;
    try {
      const params = new URLSearchParams();
      params.set('action', 'login_events');
      params.set('name', username);
      params.set('session', '<?= $session ?>');
      if (showSelect) params.set('show', showSelect.value);
      if (dateInput) params.set('date', dateInput.value);
      params.set('ajax', '1');
      params.set('_', Date.now().toString());
      const res = await fetch(ajaxBase + '?' + params.toString(), { cache: 'no-store' });
      const data = await res.json();
      if (!data || !data.ok) {
        reloginBody.innerHTML = '<div style="text-align:center;color:#ff6b6b;">Gagal memuat data.</div>';
        return;
      }
      const events = Array.isArray(data.events) ? data.events : [];
      if (events.length === 0) {
        reloginBody.innerHTML = '<div style="text-align:center;color:#9aa0a6;">Tidak ada data relogin.</div>';
        return;
      }
      const firstDateTime = events.find(ev => ev.login_time || ev.logout_time);
      const headerDate = firstDateTime ? formatDateHeader(firstDateTime.login_time || firstDateTime.logout_time) : '';
      if (reloginSub) {
        const parts = [];
        if (headerDate) parts.push(headerDate);
        if (blok) parts.push(`Blok ${formatBlokLabel(blok)}`);
        if (profile) parts.push(formatProfileLabel(profile));
        reloginSub.textContent = parts.join(' · ');
      }
      let html = '<table class="relogin-table"><thead><tr><th>#</th><th>Login</th><th>Logout</th></tr></thead><tbody>';
      events.forEach((ev, idx) => {
        const seq = ev.seq || (idx + 1);
        const loginLabel = formatTimeOnly(ev.login_time);
        const logoutLabel = formatTimeOnly(ev.logout_time);
        const note = (!ev.login_time && ev.logout_time) ? '<span class="relogin-note">logout tanpa login</span>' : '';
        html += `<tr><td>#${seq}</td><td>${loginLabel}${note}</td><td>${logoutLabel}</td></tr>`;
      });
      html += '</tbody></table>';
      if ((data.total || 0) > events.length) {
        html += '<div class="relogin-more">lebih...</div>';
      }
      reloginBody.innerHTML = html;
      reloginPrintPayload = { username, events, headerDate, blok, profile };
    } catch (e) {
      reloginBody.innerHTML = '<div style="text-align:center;color:#ff6b6b;">Gagal memuat data.</div>';
    }
  }

  function buildUrl(isSearch) {
    const params = new URLSearchParams();
    params.set('session', '<?= $session ?>');
    params.set('ajax', '1');
    const qValue = isSearch ? searchInput.value.trim() : appliedQuery;
    params.set('q', qValue);
    if (statusSelect) params.set('status', statusSelect.value);
    if (commentSelect) params.set('comment', commentSelect.value);
    if (showSelect) params.set('show', showSelect.value);
    if (dateInput) params.set('date', dateInput.value);
    const profile = baseParams.get('profile');
    if (profile) params.set('profile', profile);
    const perPage = baseParams.get('per_page');
    if (perPage) params.set('per_page', perPage);
    const debug = baseParams.get('debug');
    if (debug) params.set('debug', debug);
    const ro = baseParams.get('readonly');
    if (ro) params.set('readonly', ro);
    if (isSearch) params.set('page', '1');
    params.set('_', Date.now().toString());
    return ajaxBase + '?' + params.toString();
  }

  function updateSearchUrl(qValue) {
    const url = new URL(window.location.href);
    if (qValue) {
      url.searchParams.set('q', qValue);
      url.searchParams.set('page', '1');
    } else {
      url.searchParams.delete('q');
      url.searchParams.delete('page');
    }
    try { history.replaceState(null, '', url.toString()); } catch (e) {}
  }

  async function refreshActiveStatus() {
    if (!activeBadge) return;
    try {
      const url = './hotspot/aload_users.php?session=<?= $session ?>&load=users_status&_=' + Date.now();
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data && Array.isArray(data.active)) {
        activeBadge.textContent = `Online: ${data.active.length} User`;
      }
    } catch (e) {}
  }

  async function fetchUsers(isSearch, showLoading) {
    const fetchId = ++lastFetchId;
    try {
      if (isSearch) appliedQuery = searchInput.value.trim();
      if (showLoading && searchLoading) searchLoading.style.display = 'inline-block';
      if (showLoading && pageDim) pageDim.style.display = 'flex';
      const res = await fetch(buildUrl(isSearch), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (fetchId !== lastFetchId) return;
      if (typeof data.rows_html === 'string') tbody.innerHTML = data.rows_html;
      if (typeof data.pagination_html === 'string') paginationWrap.innerHTML = data.pagination_html;
      if (typeof data.total_label === 'string') totalBadge.textContent = data.total_label;
    } catch (e) {}
    finally {
      if (showLoading && searchLoading) searchLoading.style.display = 'none';
      if (showLoading && pageDim) pageDim.style.display = 'none';
    }
  }

  // Search hanya saat Enter
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const hasQuery = searchInput.value.trim() !== '';
      updateSearchUrl(searchInput.value.trim());
      fetchUsers(true, hasQuery);
    }
  });
  searchInput.addEventListener('input', () => {
    if (clearBtn) {
      clearBtn.style.display = searchInput.value.trim() !== '' ? 'inline-block' : 'none';
    }
  });
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput.value !== '') {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        updateSearchUrl('');
        fetchUsers(true, true);
      }
    });
  }
  if (form) {
    form.addEventListener('submit', (e) => {
      if (document.activeElement === searchInput) {
        e.preventDefault();
        const hasQuery = searchInput.value.trim() !== '';
        fetchUsers(true, hasQuery);
      }
    });
  }

  if (tbody) {
    tbody.addEventListener('click', (e) => {
      const badge = e.target.closest('.st-relogin');
      if (!badge) return;
      const username = badge.getAttribute('data-user') || '';
      const blok = badge.getAttribute('data-blok') || '';
      const profile = badge.getAttribute('data-profile') || '';
      if (username) openReloginModal(username, blok, profile);
    });
  }
  if (reloginClose) {
    reloginClose.addEventListener('click', closeReloginModal);
  }
  if (reloginPrint) {
    reloginPrint.addEventListener('click', () => {
      if (!reloginPrintPayload || !reloginPrintPayload.events) return;
      const { username, events, headerDate, blok, profile } = reloginPrintPayload;
      const rows = events.map((ev, idx) => {
        const seq = ev.seq || (idx + 1);
        const loginLabel = formatTimeOnly(ev.login_time);
        const logoutLabel = formatTimeOnly(ev.logout_time);
        const note = (!ev.login_time && ev.logout_time) ? ' (logout tanpa login)' : '';
        return `<tr><td>#${seq}</td><td>${loginLabel}${note}</td><td>${logoutLabel}</td></tr>`;
      }).join('');
      const metaParts = [];
      if (headerDate) metaParts.push(headerDate);
      if (blok) metaParts.push(`Blok ${formatBlokLabel(blok)}`);
      if (profile) metaParts.push(formatProfileLabel(profile));
      const metaLine = metaParts.length ? `<div style="margin:6px 0 10px 0;font-size:12px;color:#444;">${metaParts.join(' · ')}</div>` : '';
      const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Detail Relogin</title>
        <style>
          body{font-family:Arial,sans-serif;color:#111;margin:20px;}
          h3{margin:0 0 10px 0;}
          table{width:100%;border-collapse:collapse;font-size:12px;}
          th,td{border:1px solid #444;padding:6px 8px;}
          th{background:#f0f0f0;text-align:left;}
        </style>
      </head><body>
        <h3>Detail Relogin - ${username}</h3>
        ${metaLine}
        <table><thead><tr><th>#</th><th>Login</th><th>Logout</th></tr></thead><tbody>${rows}</tbody></table>
      </body></html>`;
      const w = window.open('', '_blank');
      if (!w) return;
      w.document.open();
      w.document.write(html);
      w.document.close();
      w.focus();
      w.print();
    });
  }
  if (reloginModal) {
    reloginModal.addEventListener('click', (e) => {
      if (e.target === reloginModal) closeReloginModal();
    });
  }

  function canAutoRefresh() {
    const statusOk = !statusSelect || statusSelect.value === 'all';
    const blokOk = !commentSelect || commentSelect.value === '';
    return statusOk && blokOk;
  }

  setInterval(() => {
    if (document.hidden) return;
    if (!canAutoRefresh()) return;
    const currentInput = searchInput.value.trim();
    if (currentInput !== appliedQuery) return;
    fetchUsers(false, false);
    refreshActiveStatus();
  }, 15000);

  if (canAutoRefresh()) {
    refreshActiveStatus();
  }
})();
</script>
