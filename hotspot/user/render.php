
  <link rel="stylesheet" href="./hotspot/user/css/users.css?v=<?= time() ?>">

<div class="row">
  <div id="page-dim" class="page-dim" aria-hidden="true">
    <div class="spinner"><i class="fa fa-circle-o-notch fa-spin"></i> Memproses...</div>
  </div>
  <div id="action-banner" class="action-banner" aria-live="polite"></div>
  <div id="users-overlay" class="overlay-backdrop">
    <div class="overlay-modal" id="users-overlay-container">
      <div class="overlay-icon-box">
        <i id="users-overlay-icon" class="fa fa-question-circle"></i>
      </div>
      <div class="overlay-title" id="users-overlay-title">Konfirmasi</div>
      <div class="overlay-message" id="users-overlay-text"></div>
      <div class="overlay-actions" id="users-overlay-actions"></div>
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
                <option value="ready" <?=($req_status=='ready'?'selected':'')?>>🟢 Voucher</option>
                <option value="vip" <?=($req_status=='vip'?'selected':'')?>>🟡 Pengelola</option>
                <option value="online" <?=($req_status=='online'?'selected':'')?>>🔵 Online</option>
                <option value="used" <?=($req_status=='used'?'selected':'')?>>⚪ Terpakai</option>
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
                    $blok_key = normalize_block_key($b);
                    $blok_letter = $blok_key !== '' ? substr($blok_key, 0, 1) : '';
                    $blok_names = env_get_value('blok.names', []);
                    if ($blok_letter !== '' && is_array($blok_names) && !empty($blok_names[$blok_letter])) {
                      $label = $blok_names[$blok_letter];
                    }
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
            $status_labels = ['used' => 'Terpakai', 'retur' => 'Retur', 'rusak' => 'Rusak', 'ready' => 'Ready', 'vip' => 'Pengelola'];
            $can_delete_status = in_array($req_status, array_keys($status_labels)) && $req_show === 'semua' && empty($filter_date);
            $status_label = $status_labels[$req_status] ?? '';
            $can_print_block = ($req_comm != '' && $req_status === 'ready');
            $can_print_status = ($req_comm != '' && $req_status === 'retur');
            $can_print_used = ($req_status === 'used');
            $can_print_online = ($req_status === 'online');
            $can_print_rusak = ($req_status === 'rusak');
            $can_print_ready = ($req_status === 'ready');
            $can_print_retur = ($req_status === 'retur');
            $profile_param = ($req_prof != '' && $req_prof != 'all') ? '&profile=' . urlencode($req_prof) : '';
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
            <?php
              $can_print_list = ($can_print_used || $can_print_online || $can_print_rusak || $can_print_ready || $can_print_retur || ($req_status === 'all' && $req_comm != ''));
            ?>
            <?php if ($req_comm == '' && $can_delete_status): ?>
              <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> (tidak online)?')">
                <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
              </button>
            <?php endif; ?>
            <?php if ($req_comm != ''): ?>
              <?php
                $print_code_url = '';
                if ($can_print_status) {
                  $print_code_url = './voucher/print.php?status=' . urlencode($req_status) . '&blok=' . urlencode($req_comm) . $profile_param . '&small=yes&session=' . urlencode($session);
                } elseif ($can_print_block) {
                  $print_code_url = './voucher/print.php?id=' . urlencode($req_comm) . $profile_param . '&small=yes&session=' . urlencode($session);
                }
              ?>
              <?php
                $print_all_params = [
                  'mode' => 'usage',
                  'status' => 'all',
                  'session' => $session,
                  'blok' => $req_comm
                ];
                if ($req_prof != '' && $req_prof != 'all') $print_all_params['profile'] = $req_prof;
                if ($req_show !== 'semua' && !empty($filter_date)) {
                  $print_all_params['show'] = $req_show;
                  $print_all_params['date'] = $filter_date;
                }
                $print_all_url = './hotspot/print/print_list.php?' . http_build_query($print_all_params);
              ?>
              <?php if ($can_print_list || $print_code_url !== ''): ?>
                <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.openUnifiedPrintPopupWithCode && window.openUnifiedPrintPopupWithCode('<?= $print_code_url ?>','<?= htmlspecialchars($req_comm, ENT_QUOTES) ?>')">
                  <i class="fa fa-print"></i> Print
                </button>
              <?php endif; ?>
              <?php if ($can_delete_status): ?>
                <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> di <?= htmlspecialchars($req_comm) ?> (tidak online)?')">
                  <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
                </button>
              <?php endif; ?>
              <?php if ($req_status == 'all'): ?>
                <button type="button" class="btn btn-danger" style="height:40px;" onclick="openDeleteBlockPopup('<?= htmlspecialchars($req_comm, ENT_QUOTES) ?>')">
                  <i class="fa fa-trash"></i> Hapus Blok
                </button>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($req_comm == '' && $can_print_list): ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.openPrintPopup && window.openPrintPopup()">
                <i class="fa fa-print"></i> Print
              </button>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <script>
        window.isSuperAdmin = <?= !empty($is_superadmin) ? 'true' : 'false' ?>;
      </script>
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
                        <?php if (($u['status'] ?? '') === 'RETUR'): ?>
                          Retur dari: <?= htmlspecialchars($u['retur_ref_user'] ?? '-') ?>
                        <?php else: ?>
                          First login: <?= formatDateIndo($u['first_login'] ?? '-') ?>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($u['retur_ref']) && empty($u['retur_ref_user'])): ?>
                        <div style="font-size:10px;color:#b2bec3;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($u['retur_ref']) ?>">
                          Retur dari: <?= htmlspecialchars($u['retur_ref']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $display_profile = $u['profile'] ?? '';
                        $display_profile_lower = strtolower($display_profile);
                        if (($display_profile === '' || $display_profile_lower === 'default') && !empty($u['profile_kind']) && $u['profile_kind'] !== 'other') {
                          $status_upper = strtoupper($u['status'] ?? '');
                          $suffix = in_array($status_upper, ['RUSAK', 'RETUR'], true) ? ' Menit (Hist)' : ' Menit';
                          $display_profile = $u['profile_kind'] . $suffix;
                        }
                        if ($display_profile === '' || strtolower($display_profile) === 'default') {
                          $display_profile = 'Lainnya';
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
                        <?php elseif($u['status'] === 'VIP'): ?><span class="status-badge st-vip">PENGELOLA</span>
                        <?php else: ?><span class="status-badge st-ready">READY</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <?php
                        $status_upper = strtoupper($u['status'] ?? '');
                        $is_ready = ($status_upper === 'READY');
                        $is_vip = ($status_upper === 'VIP');
                        $is_online = ($status_upper === 'ONLINE');
                        $is_used = ($status_upper === 'TERPAKAI');
                        $is_rusak = ($status_upper === 'RUSAK');
                        $is_retur = ($status_upper === 'RETUR');
                        $is_invalid = ($status_upper === 'INVALID');
                        $has_rusak_comment = !empty($u['comment_rusak']);
                        $is_disabled = !empty($u['is_disabled']);
                        $can_enable = false;
                        $can_mark_rusak = $is_used && !$is_online;
                      ?>
                      <?php if (in_array($req_status, ['all','ready','vip','used','rusak','online','retur'], true)): ?>
                        <?php if ($is_used && in_array($req_status, ['all','used'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_online && in_array($req_status, ['all','online'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_rusak && in_array($req_status, ['all','rusak'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.detail.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Rincian Rusak"><i class="fa fa-print"></i></button>
                        <?php elseif ($is_retur && in_array($req_status, ['all','retur'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.retur.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Voucher Retur"><i class="fa fa-print"></i></button>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.retur.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>&download=1&img=1','_blank')" title="Download Voucher Retur (PNG)"><i class="fa fa-download"></i></button>
                        <?php elseif ($is_ready && in_array($req_status, ['all','ready'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if($u['uid'] || $can_mark_rusak || $is_rusak || $is_vip): ?>
                        <?php
                          $keep_params = '&profile=' . urlencode($req_prof) .
                            '&comment=' . urlencode($req_comm) .
                            '&status=' . urlencode($req_status) .
                            '&q=' . urlencode($req_search) .
                            '&show=' . urlencode($req_show) .
                            '&date=' . urlencode($filter_date);
                        ?>
                        <?php if ($is_rusak): ?>
                          <button type="button" class="btn-act btn-act-retur" onclick="actionRequest('./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Retur"><i class="fa fa-exchange"></i></button>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rollback"><i class="fa fa-undo"></i></button>
                          <?php if ($can_enable): ?>
                          <?php endif; ?>
                        <?php elseif ($is_ready): ?>
                          <button type="button" class="btn-act btn-act-info" onclick="actionRequest('./?hotspot=users&action=vip&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Tetapkan <?= htmlspecialchars($u['name']) ?> sebagai Pengelola?')" title="Jadikan Pengelola"><i class="fa fa-star"></i></button>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=disable&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Disable Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Disable"><i class="fa fa-ban"></i></button>
                        <?php elseif ($is_vip): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>&download=1&img=1','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
                          <button type="button" class="btn-act btn-act-warning" onclick="actionRequest('./?hotspot=users&action=unvip&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Keluarkan <?= htmlspecialchars($u['name']) ?> dari Pengelola (VIP)?')" title="Batalkan Pengelola"><i class="fa fa-star-o"></i></button>
                          <button type="button" class="btn-act btn-act-warning" onclick="actionRequest('./?hotspot=users&action=unvip&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Keluarkan <?= htmlspecialchars($u['name']) ?> dari Pengelola?')" title="Batalkan Pengelola"><i class="fa fa-star-o"></i></button>
                        <?php elseif ($can_mark_rusak): ?>
                          <button type="button" class="btn-act btn-act-invalid" data-user="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>" data-blok="<?= htmlspecialchars($u['blok'], ENT_QUOTES) ?>" data-profile="<?= htmlspecialchars($u['profile'], ENT_QUOTES) ?>" data-first-login="<?= htmlspecialchars($u['first_login'], ENT_QUOTES) ?>" data-login="<?= htmlspecialchars($u['login_time'], ENT_QUOTES) ?>" data-logout="<?= htmlspecialchars($u['logout_time'], ENT_QUOTES) ?>" data-bytes="<?= (int)$u['bytes'] ?>" data-uptime="<?= htmlspecialchars($u['uptime'], ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES) ?>" data-relogin="<?= (int)($u['relogin_count'] ?? 0) ?>" onclick="actionRequestRusak(this,'./?hotspot=users&action=invalid&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','SET RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rusak"><i class="fa fa-ban"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if (!empty($is_superadmin)): ?>
                        <?php $retur_pair_flag = ($is_retur || !empty($u['retur_ref_user']) || !empty($u['retur_ref'])); ?>
                        <button type="button" class="btn-act btn-act-delete" onclick="actionRequest('./?hotspot=users&action=delete_user_full&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Hapus total user <?= htmlspecialchars($u['name']) ?> (Router + DB)?<?= $retur_pair_flag ? ' [RETUR_PAIR]' : '' ?>')" title="Hapus Total"><i class="fa fa-trash"></i></button>
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

<?php
  $low_stock_total = (int)$summary_ready_total;
  $low_stock_label = 'total';
  if (!empty($req_comm) && isset($summary_ready_by_blok[$req_comm])) {
    $low_stock_total = (int)($summary_ready_by_blok[$req_comm]['total'] ?? 0);
    $low_stock_label = $req_comm;
  }
?>
<script>
  window.usersSession = "<?= htmlspecialchars($session, ENT_QUOTES) ?>";
  window.lowStockInfo = {
    total: <?= (int)$low_stock_total ?>,
    label: "<?= htmlspecialchars($low_stock_label, ENT_QUOTES) ?>",
    show: <?= ($low_stock_total < 100) ? 'true' : 'false' ?>
  };
  window.hasUserData = <?= ((int)$total_items > 0) ? 'true' : 'false' ?>;

  function buildPrintListUrl(statusOverride) {
    const form = document.getElementById('users-toolbar-form');
    if (!form) return;

    const sess = form.querySelector('input[name="session"]')?.value || '';
    const st = statusOverride || form.querySelector('select[name="status"]')?.value || 'all';
    const prof = form.querySelector('select[name="profile"]')?.value || '';
    const blok = form.querySelector('select[name="comment"]')?.value || '';
    const show = form.querySelector('select[name="show"]')?.value || 'harian';

    let dateVal = '';
    if (show === 'harian') {
      const el = form.querySelector('input[type="date"]');
      if (el) dateVal = el.value;
    } else if (show === 'bulanan') {
      const el = form.querySelector('input[type="month"]');
      if (el) dateVal = el.value;
    } else if (show === 'tahunan') {
      const el = form.querySelector('input[type="number"]');
      if (el) dateVal = el.value;
    }

    let url = './hotspot/print/print_list.php?mode=usage&auto_print=1';
    if (sess) url += '&session=' + encodeURIComponent(sess);
    if (st) url += '&status=' + encodeURIComponent(st);
    if (prof && prof !== 'all') url += '&profile=' + encodeURIComponent(prof);
    if (blok) url += '&blok=' + encodeURIComponent(blok);
    url += '&show=' + encodeURIComponent(show);
    if (dateVal) url += '&date=' + encodeURIComponent(dateVal);
    return url;
  }

  function openPrintPopup() {
    const listUrl = buildPrintListUrl();
    if (window.openUnifiedPrintPopup) {
      window.openUnifiedPrintPopup({
        listUrl,
        blok: document.querySelector('select[name="comment"]')?.value || ''
      });
      return;
    }
    if (listUrl) window.open(listUrl, '_blank');
  }

  function openUnifiedPrintPopupWithCode(codeUrl, blokLabel) {
    const listUrl = buildPrintListUrl();
    if (window.openUnifiedPrintPopup) {
      window.openUnifiedPrintPopup({
        listUrl,
        codeUrl: codeUrl || '',
        blok: blokLabel || ''
      });
      return;
    }
    if (listUrl) window.open(listUrl, '_blank');
  }

  window.openPrintPopup = openPrintPopup;
  window.openUnifiedPrintPopupWithCode = openUnifiedPrintPopupWithCode;
</script>
<script src="./hotspot/user/js/users.js"></script>
