<?php
// Action handler sederhana (invalid/retur/delete)
if (isset($_GET['action']) || isset($_POST['action'])) {
  if (ob_get_level() === 0) {
    ob_start();
  }
  // Non-aktifkan timeout agar proses massal tidak terputus
  set_time_limit(0);
  ignore_user_abort(true);
  $root_dir = dirname(__DIR__, 2);
  require_once($root_dir . '/include/acl.php');
  require_once(__DIR__ . '/helpers.php');
  $env = [];
  $envFile = $root_dir . '/include/env.php';
  if (file_exists($envFile)) {
    require $envFile;
  }
  $pricing = $env['pricing'] ?? [];
  $profiles_cfg = $env['profiles'] ?? [];
  $price10 = isset($pricing['price_10']) ? (int)$pricing['price_10'] : (int)($price10 ?? 0);
  $price30 = isset($pricing['price_30']) ? (int)$pricing['price_30'] : (int)($price30 ?? 0);
  $profile_prices = $pricing['profile_prices'] ?? [];
  $profile_labels = $profiles_cfg['labels'] ?? [];
  $resolve_profile_label = function($profile_key) use ($profile_labels) {
    $profile_key = normalize_profile_key(resolve_profile_alias($profile_key));
    if ($profile_key === '') return '';
    if (is_array($profile_labels)) {
      foreach ($profile_labels as $k => $v) {
        if (normalize_profile_key($k) === $profile_key && trim((string)$v) !== '') {
          return (string)$v;
        }
      }
    }
    if (preg_match('/(\d+)/', $profile_key, $m)) {
      return $m[1] . ' Menit';
    }
    return $profile_key;
  };
  $resolve_price_from_profile = function($profile_key) use ($profile_prices, $price10, $price30) {
    $profile_key = normalize_profile_key(resolve_profile_alias($profile_key));
    if (is_array($profile_prices)) {
      foreach ($profile_prices as $k => $v) {
        if (normalize_profile_key($k) === $profile_key && (int)$v > 0) {
          return (int)$v;
        }
      }
    }
    $p = strtolower((string)$profile_key);
    if (preg_match('/\b10\s*(menit|m)\b/i', $p)) return (int)$price10;
    if (preg_match('/\b30\s*(menit|m)\b/i', $p)) return (int)$price30;
    return 0;
  };
  $is_action_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['action_ajax']);
  $act = $_POST['action'] ?? $_GET['action'];
  $api_print_cache = [];
  $api_print = function($path, $params = []) use ($API, &$api_print_cache) {
    $key = $path . '|' . md5(json_encode($params));
    if (isset($api_print_cache[$key])) {
      return $api_print_cache[$key];
    }
    $res = $API->comm($path, $params);
    $api_print_cache[$key] = $res;
    return $res;
  };
  if ($act === 'login_events') {
    header('Content-Type: application/json');
    if (!$db) {
      if (ob_get_length()) { @ob_clean(); }
      echo json_encode(['ok' => false, 'message' => 'DB tidak tersedia.']);
      exit();
    }
    $name = trim($_GET['name'] ?? '');
    $show = trim($_GET['show'] ?? '');
    $date = trim($_GET['date'] ?? '');
    $recent = (int)($_GET['recent'] ?? 0);
    if ($name === '') {
      if (ob_get_length()) { @ob_clean(); }
      echo json_encode(['ok' => false, 'message' => 'User tidak ditemukan.']);
      exit();
    }
    $where = "username = :u";
    $params = [':u' => $name];
    if ($show === 'harian' && $date !== '') {
      $where .= " AND date_key = :d";
      $params[':d'] = $date;
    } elseif ($show === 'bulanan' && $date !== '') {
      $where .= " AND substr(date_key, 1, 7) = :d";
      $params[':d'] = $date;
    } elseif ($show === 'tahunan' && $date !== '') {
      $where .= " AND substr(date_key, 1, 4) = :d";
      $params[':d'] = $date;
    }
    if ($recent > 0) {
      $where .= " AND ((login_time IS NOT NULL AND login_time >= :since) OR (logout_time IS NOT NULL AND logout_time >= :since))";
      $params[':since'] = date('Y-m-d H:i:s', time() - ($recent * 60));
    }
    try {
      $stmtCount = $db->prepare("SELECT COUNT(*) FROM login_events WHERE $where");
      $stmtCount->execute($params);
      $total = (int)$stmtCount->fetchColumn();

      $limit = 50;
      $stmt = $db->prepare("SELECT login_time, logout_time, seq FROM login_events WHERE $where ORDER BY seq ASC, id ASC LIMIT :lim");
      foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
      }
      $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
      $stmt->execute();
      $events = [];
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
          'seq' => (int)($row['seq'] ?? 0),
          'login_time' => $row['login_time'],
          'logout_time' => $row['logout_time'],
          'login_label' => formatDateIndo($row['login_time'] ?? ''),
          'logout_label' => formatDateIndo($row['logout_time'] ?? '')
        ];
      }
      if (ob_get_length()) { @ob_clean(); }
      echo json_encode(['ok' => true, 'total' => $total, 'limit' => $limit, 'events' => $events]);
      exit();
    } catch (Exception $e) {
      if (ob_get_length()) { @ob_clean(); }
      echo json_encode(['ok' => false, 'message' => 'Gagal mengambil data relogin.']);
      exit();
    }
  }
  if ($act == 'invalid' || $act == 'retur' || $act == 'rollback' || $act == 'delete' || $act == 'delete_user_full' || $act == 'delete_block_full' || $act == 'batch_delete' || $act == 'delete_status' || $act == 'check_rusak' || $act == 'disable' || $act == 'vip' || $act == 'unvip') {
    $uid = $_GET['uid'] ?? '';
    $name = $_GET['name'] ?? '';
    $comm = $_GET['c'] ?? '';
    $prof = $_GET['p'] ?? '';
    $blok = normalize_blok_param($_GET['blok'] ?? '');
    $status = $_GET['status'] ?? '';
    $action_blocked = false;
    $action_error = '';
    $action_message = '';
    $hist_action = null;
    $is_rusak_target = false;
    $new_user = '';
    if ($name != '') {
      $hist_action = get_user_history($name);
      if ($hist_action && strtolower($hist_action['last_status'] ?? '') === 'rusak') {
        $is_rusak_target = true;
      }
    }
    if (!$is_rusak_target && $comm != '' && stripos($comm, 'RUSAK') !== false) {
      $is_rusak_target = true;
    }

    if ($uid == '' && $name != '' && in_array($act, ['invalid','retur','rollback','delete','delete_user_full','disable','enable','vip','unvip'])) {
      $uget = $api_print('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id'
      ]);
      if (isset($uget[0]['.id'])) {
        $uid = $uget[0]['.id'];
      }
    }
    if ($uid == '' && $name != '' && in_array($act, ['invalid','retur','rollback','delete','delete_user_full','disable','enable','vip','unvip'], true)) {
      $uget = $api_print('/ip/hotspot/user/print', [
        '?name' => $name,
        '.proplist' => '.id'
      ]);
      if (isset($uget[0]['.id'])) {
        $uid = $uget[0]['.id'];
      }
    }

    $urow = [];
    $arow = [];
    $bytes = 0;
    $uptime = '0s';
    $uptime_sec = 0;
    $profile_name = $prof ?: '';
    $limits = resolve_rusak_limits($profile_name);
    $bytes_limit = $limits['bytes'];
    $uptime_limit = $limits['uptime'];
    $is_active = false;
    $recent_relogin = 0;

    if ($act == 'invalid' || $act == 'retur' || $act == 'check_rusak') {
      $uinfo = $api_print('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id,name,comment,profile,disabled,bytes-in,bytes-out,uptime'
      ]);
      $ainfo = $api_print('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $name,
        '.proplist' => 'user,uptime,bytes-in,bytes-out'
      ]);
      $urow = $uinfo[0] ?? [];
      $arow = $ainfo[0] ?? [];
      if (!$is_rusak_target) {
        $uc = $urow['comment'] ?? '';
        $ud = $urow['disabled'] ?? 'false';
        if (stripos($uc, 'RUSAK') !== false || $ud === 'true') {
          $is_rusak_target = true;
        }
      }
      $bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
      $bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
      $bytes = max((int)$bytes_total, (int)$bytes_active);
      $uptime = $urow['uptime'] ?? ($arow['uptime'] ?? '0s');
      $uptime_sec = uptime_to_seconds($uptime);
      $profile_name = $prof ?: ($urow['profile'] ?? $profile_name);
      $limits = resolve_rusak_limits($profile_name);
      $bytes_limit = $limits['bytes'];
      $uptime_limit = $limits['uptime'];
      $is_active = isset($arow['user']);
      $hist_check = $hist_action ?: get_user_history($name);
      $first_login_real = $hist_check['first_login_real'] ?? ($hist_check['login_time_real'] ?? '');
      $fallback_logout = $hist_check['logout_time_real'] ?? ($hist_check['last_login_real'] ?? '');
      $total_uptime_sec = get_cumulative_uptime_from_events($name, $first_login_real, $fallback_logout);
      $relogin_count = get_relogin_count_from_events($name, $first_login_real);
      $first_login_ok = !empty($first_login_real);
    }

    if ($enforce_rusak_rules && ($act == 'invalid' || $act == 'retur' || $act == 'check_rusak')) {
      $total_uptime_ok = (!$is_active) && ($bytes <= $bytes_limit);
      $relogin_count_ok = false;
      if (!($act == 'retur' && $is_rusak_target)) {
        $fail_reasons = [];
        if ($is_active) $fail_reasons[] = 'user masih online';
        if ($bytes > $bytes_limit) $fail_reasons[] = 'bytes melebihi ' . $limits['bytes_label'];
        if ($uptime_sec > $uptime_limit) $fail_reasons[] = 'uptime melebihi ' . $limits['uptime_label'];
        if (!empty($fail_reasons)) {
          $action_blocked = true;
          $action_error = 'Voucher masih valid, tidak bisa dianggap rusak (' . implode(' / ', $fail_reasons) . ').';
        }
      }
      if ($action_blocked && $total_uptime_ok && $first_login_ok) {
        $action_blocked = false;
        $action_error = '';
      }
    }

    if ($act == 'check_rusak') {
      $bytes_label = function_exists('formatBytes') ? formatBytes($bytes, 2) : (string)(int)$bytes;
      $criteria = [
        'offline' => !$is_active,
        'bytes_ok' => $bytes <= $bytes_limit,
        'total_uptime_ok' => true,
        'first_login_ok' => !empty($first_login_real)
      ];
      if (ob_get_length()) {
        @ob_clean();
      }
      header('Content-Type: application/json');
      http_response_code(200);
      echo json_encode([
        'ok' => !$action_blocked,
        'message' => $action_blocked ? $action_error : 'Syarat rusak terpenuhi.',
        'criteria' => $criteria,
        'values' => [
          'online' => $is_active ? 'Ya' : 'Tidak',
          'bytes' => $bytes_label,
          'uptime' => $uptime ?: '0s',
          'total_uptime' => seconds_to_uptime($total_uptime_sec),
          'first_login' => $first_login_real ?: '-'
        ],
        'limits' => [
          'bytes' => $limits['bytes_label'] ?? '',
          'uptime' => $limits['uptime_label'] ?? ''
        ]
      ]);
      exit();
    }
    if (!$action_blocked && $act == 'retur') {
      $hist = $hist_action ?: get_user_history($name);
      $last_status = strtolower($hist['last_status'] ?? '');
      $comment_rusak = false;
      if (!empty($comm) && stripos($comm, 'RUSAK') !== false) {
        $comment_rusak = true;
      }
      if (!$comment_rusak && isset($urow) && !empty($urow['comment']) && stripos($urow['comment'], 'RUSAK') !== false) {
        $comment_rusak = true;
      }
      if ($last_status !== 'rusak' && !$comment_rusak) {
        $ucheck = $api_print('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $name,
          '.proplist' => 'comment,disabled'
        ]);
        $uc = $ucheck[0]['comment'] ?? '';
        $ud = $ucheck[0]['disabled'] ?? 'false';
        if (stripos($uc, 'RUSAK') !== false || $ud === 'true') {
          $comment_rusak = true;
        }
      }
      if ($last_status !== 'rusak' && !$comment_rusak) {
        if ($db && $name != '') {
          try {
            $stmt = $db->prepare("SELECT status, is_rusak FROM sales_history WHERE username = :u ORDER BY sale_datetime DESC LIMIT 1");
            $stmt->execute([':u' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
              $st = strtolower((string)($row['status'] ?? ''));
              if ($st === 'rusak' || (int)($row['is_rusak'] ?? 0) === 1) {
                $comment_rusak = true;
              }
            }
          } catch (Exception $e) {}
          if (!$comment_rusak) {
            try {
              $stmt = $db->prepare("SELECT status, is_rusak FROM live_sales WHERE username = :u ORDER BY sale_datetime DESC LIMIT 1");
              $stmt->execute([':u' => $name]);
              $row = $stmt->fetch(PDO::FETCH_ASSOC);
              if ($row) {
                $st = strtolower((string)($row['status'] ?? ''));
                if ($st === 'rusak' || (int)($row['is_rusak'] ?? 0) === 1) {
                  $comment_rusak = true;
                }
              }
            } catch (Exception $e) {}
          }
        }
      }
      if ($last_status !== 'rusak' && !$comment_rusak) {
        $action_blocked = true;
        $action_error = 'Gagal: voucher harus status RUSAK dulu sebelum RETUR.';
      }
    }

    if (!$action_blocked && $act == 'batch_delete') {
      if (trim((string)$blok) === '') {
        $action_blocked = true;
        $action_error = 'Gagal: nama blok tidak boleh kosong.';
      }
    }
    if (!$action_blocked && $act == 'batch_delete' && $blok != '') {
      if (!$db) {
        $action_blocked = true;
        $action_error = 'Gagal: database belum siap. Sync dulu sebelum hapus blok.';
      } else {
        $blok_norm = extract_blok_name($blok);
        $blok_raw = $blok;
        $blok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $blok_norm ?: $blok_raw));
        $list = $api_print("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          ".proplist" => ".id,name,comment"
        ));
        $block_users = [];
        foreach ($list as $usr) {
          $c = $usr['comment'] ?? '';
          $uname = $usr['name'] ?? '';
          if ($uname === '') continue;
          $cblok = extract_blok_name($c);
          $cblok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cblok));
          if (($cblok != '' && strcasecmp($cblok, $blok_norm) == 0) || ($cblok_cmp != '' && $cblok_cmp == $blok_cmp) || ($blok_raw != '' && stripos($c, $blok_raw) !== false)) {
            $block_users[] = $uname;
          }
        }
        if (!empty($block_users)) {
          $stmt = $db->prepare("SELECT username, last_status FROM login_history WHERE blok_name = :b");
          $stmt->execute([':b' => $blok_norm]);
          $db_users = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $db_users[$row['username']] = strtolower(trim($row['last_status'] ?? ''));
          }
          $missing = [];
          foreach ($block_users as $uname) {
            if (!isset($db_users[$uname]) || $db_users[$uname] === '') {
              $missing[] = $uname;
            }
          }
          // if (!empty($missing)) {
          //   $action_blocked = true;
          //   $action_error = 'Gagal: DB belum sync status (retur/rusak/terpakai) untuk ' . count($missing) . ' user. Refresh/sync dulu sebelum hapus blok.';
          // }
        }
      }
    }

    if (!$action_blocked && $act == 'delete_status' && !$db) {
      $action_blocked = true;
      $action_error = 'Gagal: database belum siap. Sync dulu sebelum hapus status.';
    }

    if (!$action_blocked && $act == 'delete_user_full') {
      if (!isSuperAdmin()) {
        $action_blocked = true;
        $action_error = 'Akses ditolak. Hanya Superadmin.';
      } elseif ($name == '') {
        $action_blocked = true;
        $action_error = 'User tidak ditemukan.';
      } elseif (!$db) {
        $action_blocked = true;
        $action_error = 'Gagal: database belum siap.';
      }
    }

    if (!$action_blocked && $act == 'delete_block_full') {
      if (!isSuperAdmin()) {
        $action_blocked = true;
        $action_error = 'Akses ditolak. Hanya Superadmin.';
      } elseif (trim((string)$blok) === '') {
        $action_blocked = true;
        $action_error = 'Blok tidak ditemukan.';
      } elseif (!$db) {
        $action_blocked = true;
        $action_error = 'Gagal: database belum siap.';
      }
    }

    if (!$action_blocked && $act == 'delete' && $name != '') {
      $active_check = $api_print('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $name,
        '.proplist' => 'user'
      ]);
      if (!empty($active_check) && isset($active_check[0]['user'])) {
        $action_blocked = true;
        $action_error = 'Gagal: user sedang online.';
      }
    }

    if (!$action_blocked && in_array($act, ['vip','unvip'], true) && $name != '' && $uid == '') {
      $action_blocked = true;
      $action_error = 'Gagal: user tidak ditemukan di router.';
    }

    if ($action_blocked) {
      // skip action
    } elseif ($act == 'delete_user_full') {
      $delete_map = [];
      if ($name != '') {
        $delete_map[strtolower($name)] = $name;
      }

      $base_comment = '';
      $uinfo_full = $api_print('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id,name,comment'
      ]);
      if (!empty($uinfo_full[0]['comment'])) {
        $base_comment = $uinfo_full[0]['comment'];
      }
      if ($base_comment === '' && $db && $name != '') {
        $hist = get_user_history($name);
        if ($hist && !empty($hist['raw_comment'])) {
          $base_comment = $hist['raw_comment'];
        }
      }

      $ref_user = extract_retur_user_from_ref($base_comment);
      if ($ref_user != '') {
        $delete_map[strtolower($ref_user)] = $ref_user;
      }

      if ($ref_user != '') {
        $list = $api_print('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '.proplist' => '.id,name,comment'
        ]);
        foreach ($list as $usr) {
          $cmt = $usr['comment'] ?? '';
          $uname = $usr['name'] ?? '';
          if ($uname !== '' && $cmt !== '') {
            if (stripos($cmt, $ref_user) !== false || stripos($cmt, 'vc-' . $ref_user) !== false) {
              $delete_map[strtolower($uname)] = $uname;
            }
          }
        }
      }

      $target_ref = 'Retur Ref:vc-' . $name;
      $target_ref_alt = 'Retur Ref:' . $name;
      $list = $api_print('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '.proplist' => '.id,name,comment'
      ]);
      foreach ($list as $usr) {
        $cmt = $usr['comment'] ?? '';
        $uname = $usr['name'] ?? '';
        if ($uname != '' && $cmt != '') {
          if (stripos($cmt, $target_ref) !== false || stripos($cmt, $target_ref_alt) !== false) {
            $delete_map[strtolower($uname)] = $uname;
          }
        }
      }

      if ($db && $name != '') {
        try {
          $stmt = $db->prepare("SELECT DISTINCT username FROM login_history WHERE raw_comment LIKE :a OR raw_comment LIKE :b");
          $stmt->execute([
            ':a' => '%Retur Ref:vc-' . $name . '%',
            ':b' => '%Retur Ref:' . $name . '%'
          ]);
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uname = $row['username'] ?? '';
            if ($uname != '') {
              $delete_map[strtolower($uname)] = $uname;
            }
          }
        } catch (Exception $e) {}
        if ($ref_user != '') {
          try {
            $stmt = $db->prepare("SELECT DISTINCT username FROM login_history WHERE raw_comment LIKE :a OR raw_comment LIKE :b");
            $stmt->execute([
              ':a' => '%vc-' . $ref_user . '%',
              ':b' => '%' . $ref_user . '%'
            ]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $uname = $row['username'] ?? '';
              if ($uname != '') {
                $delete_map[strtolower($uname)] = $uname;
              }
            }
          } catch (Exception $e) {}
        }
      }

      $delete_names = array_values($delete_map);
      $deleted_list = [];

      foreach ($delete_names as $del_name) {
        if ($del_name == '') continue;
        $active_rows = $api_print('/ip/hotspot/active/print', [
          '?server' => $hotspot_server,
          '?user' => $del_name,
          '.proplist' => '.id,user'
        ]);
        if (!empty($active_rows)) {
          foreach ($active_rows as $a) {
            if (!empty($a['.id'])) {
              $API->write('/ip/hotspot/active/remove', false);
              $API->write('=.id=' . $a['.id']);
              $API->read();
            }
          }
        }

        $uid_target = '';
        $uget = $api_print('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $del_name,
          '.proplist' => '.id'
        ]);
        if (isset($uget[0]['.id'])) {
          $uid_target = $uget[0]['.id'];
        }
        if ($uid_target != '') {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $uid_target);
          $API->read();
        }

        try {
          $db->beginTransaction();
          $tables = [
            'login_history' => 'username',
            'login_events' => 'username',
            'sales_history' => 'username',
            'live_sales' => 'username'
          ];
          foreach ($tables as $table => $col) {
            try {
              $stmt = $db->prepare("DELETE FROM {$table} WHERE {$col} = :u");
              $stmt->execute([':u' => $del_name]);
            } catch (Exception $e) {}
          }
          $db->commit();
        } catch (Exception $e) {
          if ($db->inTransaction()) {
            $db->rollBack();
          }
        }
        $deleted_list[] = $del_name;
      }

      $log_dir = $root_dir . '/logs';
      if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
      }
      $admin_name = $_SESSION['mikhmon'] ?? 'superadmin';
      $log_line = '[' . date('Y-m-d H:i:s') . '] ' . $admin_name . ' delete_user_full ' . implode(',', $deleted_list) . "\n";
      @file_put_contents($log_dir . '/admin_actions.log', $log_line, FILE_APPEND);
      if (count($deleted_list) > 1) {
        $action_message = 'Berhasil hapus total user: ' . implode(', ', $deleted_list) . ' (Router + DB).';
      } else {
        $action_message = 'Berhasil hapus total user ' . ($deleted_list[0] ?? $name) . ' (Router + DB).';
      }
    } elseif ($act == 'delete_block_full') {
      $blok_norm = extract_blok_name($blok);
      $target_norm = $blok_norm ?: $blok;
      $target_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $target_norm));
      $blok_clean = preg_replace('/[^A-Za-z0-9]/i', '', (string)$target_norm);
      $blok_keyword = preg_replace('/^BLOK/i', '', strtoupper($blok_clean));
      $blok_upper = 'BLOK' . $blok_keyword;
      $blok_letter = '';
      if (preg_match('/^[A-Z]/', $blok_keyword, $m)) {
        $blok_letter = $m[0];
      }
      $blok_letter_prefix = $blok_letter !== '' ? ($blok_letter . '%') : '';
      $blok_names_cfg = env_get_value('blok.names', []);
      $blok_name_label = '';
      if ($blok_letter !== '' && is_array($blok_names_cfg) && !empty($blok_names_cfg[$blok_letter])) {
        $blok_name_label = strtoupper(trim((string)$blok_names_cfg[$blok_letter]));
      }
      $blok_name_clean = $blok_name_label !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $blok_name_label) : '';
      $blok_name_with_prefix = $blok_name_clean !== '' ? ('BLOK' . $blok_name_clean) : '';
      $blok_prefix = $blok_upper . '%';
      $blok_name_prefix = $blok_name_with_prefix !== '' ? ($blok_name_with_prefix . '%') : '';
      $use_glob = !preg_match('/\d$/', $blok_upper);
      $glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';
      $sql_pattern_1 = 'BLOK-' . $blok_keyword;
      $sql_pattern_2 = 'BLOK ' . $blok_keyword;
      $sql_pattern_3 = 'BLOK' . $blok_keyword;
      $raw_like1 = '%' . $sql_pattern_1 . '%';
      $raw_like2 = '%' . $sql_pattern_2 . '%';
      $raw_like3 = '%' . $sql_pattern_3 . '%';
      $raw_like4 = '%' . $blok_keyword . '%';
      $raw_like5 = $blok_name_label !== '' ? ('%' . $blok_name_label . '%') : '';
      $raw_like6 = $blok_name_with_prefix !== '' ? ('%' . $blok_name_with_prefix . '%') : '';
      $delete_settlement = isset($_GET['delete_settlement']) && $_GET['delete_settlement'] === '1';
      $delete_date = trim((string)($_GET['date'] ?? ''));

      $system_cfg = $env['system'] ?? [];
      $fw_cfg = $system_cfg['firewall_cleanup'] ?? [];
      $fw_enable = !isset($fw_cfg['enable']) || $fw_cfg['enable'] !== false;
      $fw_include_offline = !isset($fw_cfg['include_offline']) || $fw_cfg['include_offline'] !== false;
      $fw_max_ips = isset($fw_cfg['max_ips']) ? (int)$fw_cfg['max_ips'] : 200;
      $fw_max_seconds = isset($fw_cfg['max_seconds']) ? (int)$fw_cfg['max_seconds'] : 20;
      $wartel_subnet = trim((string)($system_cfg['wartel_subnet'] ?? ''));
      $ipInCidr = function($ip, $cidr) {
        $ip = trim((string)$ip);
        $cidr = trim((string)$cidr);
        if ($ip === '' || $cidr === '' || strpos($cidr, '/') === false) return false;
        $parts = explode('/', $cidr, 2);
        $net = $parts[0] ?? '';
        $mask = isset($parts[1]) ? (int)$parts[1] : 0;
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false || $mask < 0 || $mask > 32) return false;
        $maskLong = $mask == 0 ? 0 : (-1 << (32 - $mask));
        return (($ipLong & $maskLong) === ($netLong & $maskLong));
      };

      $active_list = $api_print('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '.proplist' => '.id,user,address'
      ]);
      $active_map = [];
      foreach ($active_list as $a) {
        if (!empty($a['user'])) {
          $active_map[$a['user']] = [
            'id' => $a['.id'] ?? '',
            'address' => $a['address'] ?? ''
          ];
        }
      }

      $base_usernames = [];
      $retur_usernames = [];
      $delete_name_map = [];
      $whereBlok = "UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) = :b_clean" . ($use_glob ? " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) GLOB :bg" : "");
      $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) LIKE :b_prefix";
      if ($blok_letter !== '') {
        $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) = :b_letter";
        $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) LIKE :b_letter_prefix";
      }
      if ($blok_name_clean !== '') {
        $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) = :b_name_clean";
        $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) = :b_name_only";
        $whereBlok .= " OR UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) LIKE :b_name_prefix";
      }
      $whereRaw = " OR UPPER(raw_comment) LIKE :rc1 OR UPPER(raw_comment) LIKE :rc2 OR UPPER(raw_comment) LIKE :rc3 OR UPPER(raw_comment) LIKE :rc4";
      if ($blok_name_label !== '') {
        $whereRaw .= " OR UPPER(raw_comment) LIKE :rc5 OR UPPER(raw_comment) LIKE :rc6";
      }
      $whereMatch = "(" . $whereBlok . $whereRaw . ")";
      $whereSales = "(" . $whereBlok . " OR UPPER(comment) LIKE :rc1 OR UPPER(comment) LIKE :rc2 OR UPPER(comment) LIKE :rc3 OR UPPER(comment) LIKE :rc4";
      if ($blok_name_label !== '') {
        $whereSales .= " OR UPPER(comment) LIKE :rc5 OR UPPER(comment) LIKE :rc6";
      }
      $whereSales .= ")";
      $base_params = $use_glob ? [':b_clean' => $blok_upper, ':bg' => $glob_pattern, ':b_prefix' => $blok_prefix, ':rc1' => $raw_like1, ':rc2' => $raw_like2, ':rc3' => $raw_like3, ':rc4' => $raw_like4]
        : [':b_clean' => $blok_upper, ':b_prefix' => $blok_prefix, ':rc1' => $raw_like1, ':rc2' => $raw_like2, ':rc3' => $raw_like3, ':rc4' => $raw_like4];
      if ($blok_letter !== '') {
        $base_params[':b_letter'] = $blok_letter;
        $base_params[':b_letter_prefix'] = $blok_letter_prefix;
      }
      if ($blok_name_label !== '') {
        $base_params[':rc5'] = $raw_like5;
        $base_params[':rc6'] = $raw_like6;
      }
      if ($blok_name_clean !== '') {
        $base_params[':b_name_clean'] = $blok_name_with_prefix;
        $base_params[':b_name_only'] = $blok_name_clean;
        $base_params[':b_name_prefix'] = $blok_name_prefix;
      }

      try {
        $stmt = $db->prepare("SELECT username FROM login_history WHERE $whereMatch");
        $stmt->execute($base_params);
        $base_usernames = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach ($base_usernames as $uname) {
          $key = strtolower((string)$uname);
          if ($key !== '') $delete_name_map[$key] = $uname;
        }
      } catch (Exception $e) {}

      $parent_name_map = $delete_name_map;
      if ($db) {
        try {
          $stmt = $db->prepare("SELECT username FROM sales_history WHERE $whereMatch");
          $stmt->execute($base_params);
          foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $uname) {
            $key = strtolower((string)$uname);
            if ($key !== '' && !isset($parent_name_map[$key])) {
              $parent_name_map[$key] = $uname;
            }
          }
        } catch (Exception $e) {}
        try {
          $stmt = $db->prepare("SELECT username FROM live_sales WHERE $whereMatch");
          $stmt->execute($base_params);
          foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $uname) {
            $key = strtolower((string)$uname);
            if ($key !== '' && !isset($parent_name_map[$key])) {
              $parent_name_map[$key] = $uname;
            }
          }
        } catch (Exception $e) {}
      }

      if (!empty($delete_name_map) || !empty($parent_name_map)) {
        try {
          $stmt = $db->query("SELECT username, raw_comment FROM login_history WHERE raw_comment IS NOT NULL AND raw_comment != ''");
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ru = (string)($row['username'] ?? '');
            $rc = (string)($row['raw_comment'] ?? '');
            if ($ru === '' || $rc === '') continue;
            $ref_user = extract_retur_user_from_ref($rc);
            if ($ref_user !== '') {
              $ref_key = strtolower($ref_user);
              if (isset($delete_name_map[$ref_key]) || isset($parent_name_map[$ref_key])) {
                $ru_key = strtolower($ru);
                if (!isset($delete_name_map[$ru_key])) {
                  $delete_name_map[$ru_key] = $ru;
                  $retur_usernames[] = $ru;
                }
              }
            }
          }
        } catch (Exception $e) {}
      }

      $router_names = array_values(array_unique(array_merge($base_usernames, $retur_usernames, array_keys($delete_name_map))));
      $db_delete_names = array_values(array_unique($router_names));
      $offline_ips = [];
      $db_deleted_count = 0;
      try {
        $db->beginTransaction();
        $params = $base_params;

        $userClause = '';
        $userParams = [];
        if (!empty($db_delete_names)) {
          $placeholders = [];
          foreach ($db_delete_names as $i => $uname) {
            $key = ':u' . $i;
            $placeholders[] = $key;
            $userParams[$key] = $uname;
          }
          $userClause = " OR username IN (" . implode(',', $placeholders) . ")";
        }

        if (!empty($db_delete_names) && $fw_enable && $fw_include_offline) {
          try {
            $stmtIp = $db->prepare("SELECT username, last_ip, ip_address FROM login_history WHERE $whereMatch$userClause");
            $stmtIp->execute(array_merge($params, $userParams));
            while ($row = $stmtIp->fetch(PDO::FETCH_ASSOC)) {
              $ip1 = trim((string)($row['last_ip'] ?? ''));
              $ip2 = trim((string)($row['ip_address'] ?? ''));
              if ($ip1 !== '' && $ip1 !== '-') $offline_ips[$ip1] = true;
              if ($ip2 !== '' && $ip2 !== '-') $offline_ips[$ip2] = true;
            }
          } catch (Exception $e) {}
        }

        $stmt = $db->prepare("DELETE FROM login_history WHERE $whereMatch$userClause");
        $stmt->execute(array_merge($params, $userParams));

        if (!empty($db_delete_names)) {
          $placeholders = [];
          $userParams = [];
          foreach ($db_delete_names as $i => $uname) {
            $key = ':e' . $i;
            $placeholders[] = $key;
            $userParams[$key] = $uname;
          }
          $stmt = $db->prepare("DELETE FROM login_events WHERE username IN (" . implode(',', $placeholders) . ")");
          $stmt->execute($userParams);
        }

        $stmt = $db->prepare("DELETE FROM sales_history WHERE $whereSales$userClause");
        $stmt->execute(array_merge($params, $userParams));

        $stmt = $db->prepare("DELETE FROM live_sales WHERE $whereSales$userClause");
        $stmt->execute(array_merge($params, $userParams));

        $blok_params = $use_glob ? [':b_clean' => $blok_upper, ':bg' => $glob_pattern, ':b_prefix' => $blok_prefix] : [':b_clean' => $blok_upper, ':b_prefix' => $blok_prefix];
        if ($blok_letter !== '') {
          $blok_params[':b_letter'] = $blok_letter;
          $blok_params[':b_letter_prefix'] = $blok_letter_prefix;
        }
        if ($blok_name_clean !== '') {
          $blok_params[':b_name_clean'] = $blok_name_with_prefix;
          $blok_params[':b_name_only'] = $blok_name_clean;
          $blok_params[':b_name_prefix'] = $blok_name_prefix;
        }
        try {
          $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_rekap_manual' LIMIT 1");
          if ($stmtChk && $stmtChk->fetchColumn()) {
            $stmt = $db->prepare("DELETE FROM audit_rekap_manual WHERE $whereBlok");
            $stmt->execute($blok_params);
          }
        } catch (Exception $e) {}
        try {
          $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='phone_block_daily' LIMIT 1");
          if ($stmtChk && $stmtChk->fetchColumn()) {
            $stmt = $db->prepare("DELETE FROM phone_block_daily WHERE $whereBlok");
            $stmt->execute($blok_params);
          }
        } catch (Exception $e) {}

        if ($delete_settlement && $delete_date !== '') {
          try {
            $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settlement_log' LIMIT 1");
            if ($stmtChk && $stmtChk->fetchColumn()) {
              $stmt = $db->prepare("DELETE FROM settlement_log WHERE report_date = :d");
              $stmt->execute([':d' => $delete_date]);
            }
          } catch (Exception $e) {}
        }

        $summary_helper = $root_dir . '/report/laporan/sales_summary_helper.php';
        if (file_exists($summary_helper)) {
          require_once $summary_helper;
          if (function_exists('rebuild_sales_summary')) {
            rebuild_sales_summary($db);
          }
        }

        $db->commit();
        $db_deleted_count = count($db_delete_names);
      } catch (Exception $e) {
        if ($db->inTransaction()) {
          $db->rollBack();
        }
      }

      // Fallback cleanup untuk audit_rekap_manual & phone_block_daily
      if ($db) {
        $blok_patterns = [];
        $blok_patterns[] = $blok_upper . '%';
        if ($blok_letter !== '') {
          $blok_patterns[] = $blok_letter . '%';
        }
        if ($blok_name_with_prefix !== '') {
          $blok_patterns[] = $blok_name_with_prefix . '%';
        }
        if ($blok_name_clean !== '') {
          $blok_patterns[] = $blok_name_clean . '%';
        }
        $blok_patterns = array_values(array_unique(array_filter($blok_patterns)));
        try {
          $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_rekap_manual' LIMIT 1");
          if ($stmtChk && $stmtChk->fetchColumn()) {
            foreach ($blok_patterns as $pat) {
              $sql = "DELETE FROM audit_rekap_manual WHERE UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) LIKE :p";
              $params = [':p' => $pat];
              if ($delete_date !== '') {
                $sql .= " AND report_date = :d";
                $params[':d'] = $delete_date;
              }
              $stmt = $db->prepare($sql);
              $stmt->execute($params);
            }
          }
        } catch (Exception $e) {}
        try {
          $stmtChk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='phone_block_daily' LIMIT 1");
          if ($stmtChk && $stmtChk->fetchColumn()) {
            foreach ($blok_patterns as $pat) {
              $sql = "DELETE FROM phone_block_daily WHERE UPPER(REPLACE(REPLACE(blok_name, '-', ''), ' ', '')) LIKE :p";
              $params = [':p' => $pat];
              if ($delete_date !== '') {
                $sql .= " AND report_date = :d";
                $params[':d'] = $delete_date;
              }
              $stmt = $db->prepare($sql);
              $stmt->execute($params);
            }
          }
        } catch (Exception $e) {}
      }

      // Final cleanup: parse blok dari raw_comment/blok_name (tangkap user rusak hasil retur)
      if ($db) {
        $target_key = normalize_block_key($blok_upper);
        if ($target_key !== '') {
          $stale_users = [];
          try {
            $stmt = $db->query("SELECT username, raw_comment, blok_name FROM login_history WHERE username IS NOT NULL AND username != ''");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $uname = (string)($row['username'] ?? '');
              if ($uname === '') continue;
              $raw_comment = (string)($row['raw_comment'] ?? '');
              $blok_name = (string)($row['blok_name'] ?? '');
              $blok_guess = extract_blok_name($raw_comment);
              if ($blok_guess === '') {
                $blok_guess = extract_blok_name($blok_name) ?: $blok_name;
              }
              $guess_key = normalize_block_key($blok_guess);
              if ($guess_key !== '' && strpos($guess_key, $target_key) === 0) {
                $stale_users[strtolower($uname)] = $uname;
              }
            }
          } catch (Exception $e) {}

          if (!empty($stale_users)) {
            $names = array_values($stale_users);
            $placeholders = [];
            $params = [];
            foreach ($names as $i => $uname) {
              $key = ':s' . $i;
              $placeholders[] = $key;
              $params[$key] = $uname;
            }
            try {
              $db->beginTransaction();
              $stmt = $db->prepare("DELETE FROM login_history WHERE username IN (" . implode(',', $placeholders) . ")");
              $stmt->execute($params);
              $stmt = $db->prepare("DELETE FROM login_events WHERE username IN (" . implode(',', $placeholders) . ")");
              $stmt->execute($params);
              $stmt = $db->prepare("DELETE FROM sales_history WHERE username IN (" . implode(',', $placeholders) . ")");
              $stmt->execute($params);
              $stmt = $db->prepare("DELETE FROM live_sales WHERE username IN (" . implode(',', $placeholders) . ")");
              $stmt->execute($params);
              $db->commit();
            } catch (Exception $e) {
              if ($db->inTransaction()) {
                $db->rollBack();
              }
            }
          }
        }
      }

      $list = $api_print('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '.proplist' => '.id,name,comment'
      ]);
      $to_delete = [];
      $base_router_map = [];
      foreach ($list as $usr) {
        $uname = $usr['name'] ?? '';
        if ($uname === '') continue;
        $c = $usr['comment'] ?? '';
        $cblok = extract_blok_name($c);
        $cblok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cblok));
        $uname_key = strtolower($uname);
        $is_target = false;
        if ($cblok_cmp != '' && $cblok_cmp === $target_cmp) {
          $is_target = true;
        }
        if (!$is_target && $blok_keyword !== '' && $c !== '') {
          if (preg_match('/\b' . preg_quote($blok_keyword, '/') . '\b/i', $c)) {
            $is_target = true;
          }
        }
        if (!$is_target && isset($delete_name_map[$uname_key])) {
          $is_target = true;
        }
        if ($is_target) {
          $to_delete[] = ['id' => $usr['.id'] ?? '', 'name' => $uname];
          $base_router_map[$uname_key] = true;
        }
      }

      if (!empty($base_router_map) || !empty($delete_name_map) || !empty($parent_name_map)) {
        foreach ($list as $usr) {
          $uname = $usr['name'] ?? '';
          if ($uname === '') continue;
          $c = $usr['comment'] ?? '';
          $ref_user = extract_retur_user_from_ref($c);
          if ($ref_user !== '') {
            $ref_key = strtolower($ref_user);
            if (isset($base_router_map[$ref_key]) || isset($delete_name_map[$ref_key]) || isset($parent_name_map[$ref_key])) {
              $uname_key = strtolower($uname);
              if (!isset($delete_name_map[$uname_key])) {
                $delete_name_map[$uname_key] = $uname;
                $retur_usernames[] = $uname;
              }
              $to_delete[] = ['id' => $usr['.id'] ?? '', 'name' => $uname];
            }
          }
        }
      }

      $router_deleted = 0;
      $fw_start = microtime(true);
      foreach ($to_delete as $d) {
        $uname = $d['name'] ?? '';
        if ($uname !== '') {
          try {
            $cookies = $api_print('/ip/hotspot/cookie/print', [
              '?user' => $uname,
              '.proplist' => '.id'
            ]);
            foreach ($cookies as $ck) {
              if (!empty($ck['.id'])) {
                $API->comm('/ip/hotspot/cookie/remove', [
                  '.id' => $ck['.id']
                ]);
              }
            }
          } catch (Exception $e) {}

          $active_info = $active_map[$uname] ?? null;
          $active_id = is_array($active_info) ? ($active_info['id'] ?? '') : '';
          $active_addr = is_array($active_info) ? ($active_info['address'] ?? '') : '';
          if ($fw_enable && $active_addr !== '' && ($wartel_subnet === '' || $ipInCidr($active_addr, $wartel_subnet))) {
            try {
              $conn_list = $api_print('/ip/firewall/connection/print', [
                '?src-address' => $active_addr,
                '.proplist' => '.id'
              ]);
              foreach ($conn_list as $conn) {
                if (!empty($conn['.id'])) {
                  $API->comm('/ip/firewall/connection/remove', [
                    '.id' => $conn['.id']
                  ]);
                }
              }
            } catch (Exception $e) {}
          }

          if ($active_id !== '') {
            $API->write('/ip/hotspot/active/remove', false);
            $API->write('=.id=' . $active_id);
            $API->read();
          }
        }
        if (!empty($d['id'])) {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $d['id']);
          $API->read();
          $router_deleted++;
        }
      }

      if ($fw_enable && $fw_include_offline && !empty($offline_ips)) {
        $ips = array_keys($offline_ips);
        if ($fw_max_ips > 0) {
          $ips = array_slice($ips, 0, $fw_max_ips);
        }
        foreach ($ips as $ip) {
          if ($ip === '' || $ip === '-') continue;
          if ($wartel_subnet !== '' && !$ipInCidr($ip, $wartel_subnet)) continue;
          if ($fw_max_seconds > 0 && (microtime(true) - $fw_start) >= $fw_max_seconds) break;
          try {
            $conn_list = $api_print('/ip/firewall/connection/print', [
              '?src-address' => $ip,
              '.proplist' => '.id'
            ]);
            foreach ($conn_list as $conn) {
              if (!empty($conn['.id'])) {
                $API->comm('/ip/firewall/connection/remove', [
                  '.id' => $conn['.id']
                ]);
              }
            }
          } catch (Exception $e) {}
        }
      }

      $extra_db_names = [];
      foreach ($to_delete as $d) {
        $uname = $d['name'] ?? '';
        if ($uname !== '') $extra_db_names[] = $uname;
      }
      $extra_db_names = array_values(array_unique($extra_db_names));
      if (!empty($extra_db_names)) {
        $extra_user_params = [];
        $extra_placeholders = [];
        foreach ($extra_db_names as $i => $uname) {
          $key = ':x' . $i;
          $extra_placeholders[] = $key;
          $extra_user_params[$key] = $uname;
        }
        try {
          $db->beginTransaction();
          $stmt = $db->prepare("DELETE FROM login_history WHERE username IN (" . implode(',', $extra_placeholders) . ")");
          $stmt->execute($extra_user_params);
          $stmt = $db->prepare("DELETE FROM login_events WHERE username IN (" . implode(',', $extra_placeholders) . ")");
          $stmt->execute($extra_user_params);
          $stmt = $db->prepare("DELETE FROM sales_history WHERE username IN (" . implode(',', $extra_placeholders) . ")");
          $stmt->execute($extra_user_params);
          $stmt = $db->prepare("DELETE FROM live_sales WHERE username IN (" . implode(',', $extra_placeholders) . ")");
          $stmt->execute($extra_user_params);
          $db->commit();
          $db_deleted_count += count($extra_db_names);
        } catch (Exception $e) {
          if ($db->inTransaction()) {
            $db->rollBack();
          }
        }

        $summary_helper = $root_dir . '/report/laporan/sales_summary_helper.php';
        if (file_exists($summary_helper)) {
          require_once $summary_helper;
          if (function_exists('rebuild_sales_summary')) {
            rebuild_sales_summary($db);
          }
        }
      }

      $script_deleted = 0;
      try {
        $scripts = $api_print('/system/script/print', [
          '?comment' => 'mikhmon',
          '.proplist' => '.id,name,comment'
        ]);
        $patterns = [
          strtolower($sql_pattern_1),
          strtolower($sql_pattern_2),
          strtolower($sql_pattern_3),
          strtolower($blok_keyword)
        ];
        foreach ($scripts as $sc) {
          $nm = strtolower((string)($sc['name'] ?? ''));
          if ($nm === '') continue;
          $matched = false;
          foreach ($patterns as $pat) {
            if ($pat !== '' && strpos($nm, strtolower($pat)) !== false) {
              $matched = true;
              break;
            }
          }
          if ($matched && !empty($sc['.id'])) {
            $API->write('/system/script/remove', false);
            $API->write('=.id=' . $sc['.id']);
            $API->read();
            $script_deleted++;
          }
        }
      } catch (Exception $e) {}

      $log_dir = $root_dir . '/logs';
      if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
      }
      $admin_name = $_SESSION['mikhmon'] ?? 'superadmin';
      $log_line = '[' . date('Y-m-d H:i:s') . '] ' . $admin_name . ' delete_block_full ' . $blok_upper . "\n";
      @file_put_contents($log_dir . '/admin_actions.log', $log_line, FILE_APPEND);
      $retur_note = !empty($retur_usernames) ? ' (termasuk retur: ' . count($retur_usernames) . ' user)' : '';
      $script_note = $script_deleted > 0 ? ' + script mikhmon: ' . $script_deleted : '';
      $action_message = 'Berhasil hapus total blok ' . $blok_upper . ' (Router: ' . $router_deleted . ' user, DB: ' . $db_deleted_count . ' user)' . $retur_note . $script_note . '.';
    } elseif ($act == 'delete_status') {
      $status_map = [
        'used' => 'terpakai',
        'retur' => 'retur',
        'rusak' => 'rusak'
      ];
      $target_status = $status_map[$status] ?? '';
      if ($target_status != '' && $db) {
        $blok_norm = $blok != '' ? extract_blok_name($blok) : '';
        $active_list = $api_print("/ip/hotspot/active/print", array(
          "?server" => $hotspot_server,
          ".proplist" => "user"
        ));
        $active_names = [];
        foreach ($active_list as $a) {
          if (isset($a['user'])) $active_names[$a['user']] = true;
        }
        try {
          if ($blok_norm != '') {
            $stmt = $db->prepare("SELECT username, blok_name FROM login_history WHERE lower(last_status) = :st AND blok_name IS NOT NULL AND blok_name != ''");
            $stmt->execute([':st' => $target_status]);
          } else {
            $stmt = $db->prepare("SELECT username, blok_name FROM login_history WHERE lower(last_status) = :st");
            $stmt->execute([':st' => $target_status]);
          }
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          $deleted_any = false;
          foreach ($rows as $row) {
            $uname = $row['username'] ?? '';
            if ($uname === '' || isset($active_names[$uname])) continue;
            if ($blok_norm != '') {
              $row_blok = extract_blok_name($row['blok_name'] ?? '');
              if (strcasecmp($row_blok, $blok_norm) !== 0) continue;
            }
            $u = $api_print("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              "?name" => $uname,
              ".proplist" => ".id"
            ));
            if (isset($u[0]['.id'])) {
              $API->write('/ip/hotspot/user/remove', false);
              $API->write('=.id=' . $u[0]['.id']);
              $API->read();
            }
            $tables = ['login_history', 'login_events', 'sales_history', 'live_sales'];
            foreach ($tables as $table) {
              try {
                $del = $db->prepare("DELETE FROM {$table} WHERE username = :u");
                $del->execute([':u' => $uname]);
              } catch (Exception $e) {}
            }
            $deleted_any = true;
          }
          if (!$deleted_any && $target_status !== '') {
            $list = $api_print("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              ".proplist" => ".id,name,comment,disabled,bytes-in,bytes-out,uptime"
            ));
            foreach ($list as $usr) {
              $uname = $usr['name'] ?? '';
              if ($uname === '' || isset($active_names[$uname])) continue;
              $cmt = $usr['comment'] ?? '';
              if ($blok_norm != '') {
                $cblok = extract_blok_name($cmt);
                if (strcasecmp($cblok, $blok_norm) !== 0) continue;
              }
              $is_rusak = (stripos($cmt, 'RUSAK') !== false) || (($usr['disabled'] ?? 'false') === 'true');
              $is_retur = stripos($cmt, '(Retur)') !== false || stripos($cmt, 'Retur Ref:') !== false;
              if ($is_retur && !$is_rusak && $target_status === 'retur') {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
                $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                $del->execute([':u' => $uname]);
                $deleted_any = true;
                continue;
              }
              if ($is_rusak && !$is_retur && $target_status === 'rusak') {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
                $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                $del->execute([':u' => $uname]);
                $deleted_any = true;
                continue;
              }
              if ($target_status === 'terpakai' && !$is_rusak && !$is_retur) {
                $bytes = (int)(($usr['bytes-in'] ?? 0) + ($usr['bytes-out'] ?? 0));
                $uptime = $usr['uptime'] ?? '';
                $cm = extract_ip_mac_from_comment($cmt);
                $is_used = ($bytes > 50 || ($uptime != '' && $uptime != '0s') || ($cm['ip'] ?? '') != '');
                if ($is_used) {
                  $API->write('/ip/hotspot/user/remove', false);
                  $API->write('=.id=' . $usr['.id']);
                  $API->read();
                  $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                  $del->execute([':u' => $uname]);
                  $deleted_any = true;
                }
              }
            }
          }
          if ($target_status === 'rusak') {
            $list = $api_print("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              ".proplist" => ".id,name,comment,disabled"
            ));
            foreach ($list as $usr) {
              $uname = $usr['name'] ?? '';
              if ($uname === '' || isset($active_names[$uname])) continue;
              $cmt = $usr['comment'] ?? '';
              $disabled = $usr['disabled'] ?? 'false';
              $is_rusak_router = (stripos($cmt, 'RUSAK') !== false) || ($disabled === 'true');
              $is_retur_router = stripos($cmt, '(Retur)') !== false || stripos($cmt, 'Retur Ref:') !== false;
              if (!$is_rusak_router) continue;
              if ($is_retur_router) continue;
              if ($blok_norm != '') {
                $cblok = extract_blok_name($cmt);
                if (strcasecmp($cblok, $blok_norm) !== 0) continue;
              }
              if (isset($usr['.id'])) {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
              }
              $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
              $del->execute([':u' => $uname]);
            }
          }
        } catch(Exception $e) {}
      }
      if ($target_status !== '') {
        $action_message = 'Berhasil hapus voucher status ' . $target_status . ($blok_norm ? (' pada ' . $blok_norm) : '') . '.';
      }
    } elseif ($act == 'batch_delete' && $blok != '') {
      $active_list = $api_print("/ip/hotspot/active/print", array(
        "?server" => $hotspot_server,
        ".proplist" => "user"
      ));
      $active_names = [];
      foreach ($active_list as $a) {
        if (isset($a['user'])) $active_names[$a['user']] = true;
      }
      $list = $api_print("/ip/hotspot/user/print", array(
        "?server" => $hotspot_server,
        ".proplist" => ".id,name,comment"
      ));
      $blok_norm = extract_blok_name($blok);
      $blok_raw = $blok;
      $blok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $blok_norm ?: $blok_raw));
      $to_delete = [];
      foreach ($list as $usr) {
        $c = $usr['comment'] ?? '';
        $uname = $usr['name'] ?? '';
        if ($uname !== '' && isset($active_names[$uname])) {
          continue; // jangan hapus user online
        }
        $cblok = extract_blok_name($c);
        $cblok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cblok));
        if ($cblok_cmp != '' && $cblok_cmp == $blok_cmp) {
          $to_delete[] = ['id' => $usr['.id'], 'name' => $uname];
        }
      }
      foreach ($to_delete as $d) {
        if (!empty($d['id'])) {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $d['id']);
          $API->read();
        }
        // Jangan hapus histori pemakaian (login_history) saat hapus blok
      }
      // Jangan hapus histori pemakaian per blok di login_history
      $action_message = 'Berhasil hapus user Router untuk blok ' . ($blok_norm ?: $blok_raw) . '.';
    } elseif ($act == 'rollback' && $uid == '' && $name != '' && $db) {
      $hist = get_user_history($name);
      $base_comment = $comm != '' ? $comm : (string)($hist['raw_comment'] ?? '');
      $clean_comment = preg_replace('/\bAudit:\s*RUSAK\s*\d{2}\/\d{2}\/\d{2}\s*/i', '', $base_comment);
      $clean_comment = preg_replace('/\bRUSAK\b\s*/i', '', $clean_comment);
      $clean_comment = preg_replace('/\(Retur\)\s*/i', '', $clean_comment);
      $clean_comment = preg_replace('/Retur\s*Ref\s*:[^|]+/i', '', $clean_comment);
      $clean_comment = preg_replace('/\s+\|\s+/', ' | ', $clean_comment);
      $clean_comment = trim($clean_comment);

      $save_data = [
        'raw' => $clean_comment,
        'status' => 'ready'
      ];
      save_user_history($name, $save_data);

      try {
        $stmt = $db->prepare("UPDATE login_history SET last_status='ready', updated_at=CURRENT_TIMESTAMP,
          login_time_real=COALESCE(NULLIF(login_time_real,''), CURRENT_TIMESTAMP),
          last_login_real=COALESCE(NULLIF(last_login_real,''), CURRENT_TIMESTAMP)
          WHERE username = :u");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}

      try {
        $stmt = $db->prepare("UPDATE sales_history SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}
      try {
        $stmt = $db->prepare("UPDATE live_sales SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}

      $action_message = 'Berhasil rollback RUSAK untuk ' . $name . ' (DB).';
    } elseif ($uid != '') {
      if ($act == 'delete') {
        $API->write('/ip/hotspot/user/remove', false);
        $API->write('=.id=' . $uid);
        $API->read();
        if ($db && $name != '') {
          try {
            $tables = ['login_history', 'login_events', 'sales_history', 'live_sales'];
            foreach ($tables as $table) {
              $stmt = $db->prepare("DELETE FROM {$table} WHERE username = :u");
              $stmt->execute([':u' => $name]);
            }
          } catch(Exception $e) {}
        }
        $action_message = 'Berhasil hapus user ' . $name . ' dari Router.';
      } elseif ($act == 'vip' || $act == 'unvip') {
        $vip_cfg = $env['vip'] ?? [];
        $vip_daily_limit = (int)($vip_cfg['daily_limit'] ?? 0);
        $vip_date_key = date('Y-m-d');
        if ($act === 'vip' && $vip_daily_limit > 0) {
          $vip_used = get_vip_daily_usage($db, $vip_date_key);
          if ($vip_used >= $vip_daily_limit) {
            $action_blocked = true;
            $action_error = 'Gagal: limit Pengelola harian tercapai (' . $vip_used . '/' . $vip_daily_limit . ').';
          }
        }
        $uinfo = $api_print('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $name,
          '.proplist' => '.id,name,comment,disabled,bytes-in,bytes-out,uptime'
        ]);
        $ainfo = $api_print('/ip/hotspot/active/print', [
          '?server' => $hotspot_server,
          '?user' => $name,
          '.proplist' => 'user'
        ]);
        $urow = $uinfo[0] ?? [];
        $arow = $ainfo[0] ?? [];
        $comment_raw = (string)($urow['comment'] ?? $comm);
        $disabled = $urow['disabled'] ?? 'false';
        $bytes = (int)($urow['bytes-in'] ?? 0) + (int)($urow['bytes-out'] ?? 0);
        $uptime = (string)($urow['uptime'] ?? '');
        $is_active = isset($arow['user']);
        $hist_vip = $hist_action ?: get_user_history($name);
        $hist_status = strtolower((string)($hist_vip['last_status'] ?? ''));
        $hist_used = $hist_vip && (
          in_array($hist_status, ['online','terpakai','rusak','retur'], true) ||
          !empty($hist_vip['login_time_real']) ||
          !empty($hist_vip['logout_time_real']) ||
          (!empty($hist_vip['last_uptime']) && $hist_vip['last_uptime'] != '0s') ||
          (int)($hist_vip['last_bytes'] ?? 0) > 0
        );
        $is_ready_now = (!$is_active && $disabled !== 'true' && $bytes <= 50 && ($uptime === '' || $uptime === '0s'));
        $has_vip = is_vip_comment($comment_raw) || ($hist_vip && is_vip_comment($hist_vip['raw_comment'] ?? ''));
        $did_unvip = false;

        $did_set_vip = false;
        if ($act === 'vip' && !$is_ready_now) {
          $reasons = [];
          if ($is_active) $reasons[] = 'masih online';
          if ($disabled === 'true') $reasons[] = 'disabled';
          if ($bytes > 50) $reasons[] = 'bytes > 50';
          if ($uptime !== '' && $uptime !== '0s') $reasons[] = 'uptime terisi';
          if ($hist_used) $reasons[] = 'riwayat terpakai';
          $reason_text = !empty($reasons) ? (' (' . implode(', ', $reasons) . ')') : '';
          $action_blocked = true;
          $action_error = 'Gagal: VIP hanya untuk user READY (tidak online/terpakai).' . $reason_text;
        } else {
          $new_comment = $comment_raw;
          if ($act === 'vip') {
            if ($has_vip) {
              $action_message = 'User sudah ditandai sebagai Pengelola.';
            } else {
              $base = trim($comment_raw);
              if ($base === '') {
                $new_comment = 'VIP';
              } elseif (!preg_match('/\bVIP\b/i', $base)) {
                $new_comment = trim($base . ' | VIP');
              }
              $action_message = 'Berhasil set Pengelola untuk ' . $name . '.';
              $did_set_vip = true;
            }
          } else {
            if (!$has_vip) {
              $action_message = 'User tidak berstatus Pengelola.';
            } else {
              $clean = preg_replace('/\s*\|\s*(VIP|Pengelola)\b/i', '', $comment_raw);
              $clean = preg_replace('/\b(VIP|Pengelola)\b/i', '', $clean);
              $clean = preg_replace('/\s+\|\s+/', ' | ', $clean);
              $clean = trim($clean, " \t\n\r\0\x0B|");
              $new_comment = $clean;
              $action_message = 'Berhasil hapus Pengelola untuk ' . $name . '.';
              $did_unvip = true;
            }
          }

          if ($uid != '' && !$action_blocked) {
            $API->write('/ip/hotspot/user/set', false);
            $API->write('=.id='.$uid, false);
            $API->write('=comment='.$new_comment);
            $API->read();
            if ($db && $name != '') {
              $save_data = [
                'raw' => $new_comment,
                'status' => 'ready'
              ];
              save_user_history($name, $save_data);
              try {
                $stmt = $db->prepare("UPDATE login_history SET last_status='ready', raw_comment=:c, updated_at=CURRENT_TIMESTAMP WHERE username = :u");
                $stmt->execute([':u' => $name, ':c' => $new_comment]);
              } catch(Exception $e) {}
              if ($did_set_vip && $vip_daily_limit > 0) {
                increment_vip_daily_usage($db, $vip_date_key);
              }
              if ($did_unvip) {
                decrement_vip_daily_usage($db, $vip_date_key);
                $vip_left = 0;
                $vip_list = $api_print('/ip/hotspot/user/print', [
                  '?server' => $hotspot_server,
                  '.proplist' => 'comment'
                ]);
                if (is_array($vip_list)) {
                  foreach ($vip_list as $vu) {
                    if (is_vip_comment($vu['comment'] ?? '')) {
                      $vip_left++;
                    }
                  }
                }
                set_vip_daily_usage($db, $vip_date_key, $vip_left);
              }
            }
          }
        }
      } elseif ($act == 'disable') {
        $active_check = $api_print('/ip/hotspot/active/print', [
          '?server' => $hotspot_server,
          '?user' => $name,
          '.proplist' => 'user'
        ]);
        if (!empty($active_check)) {
          $action_blocked = true;
          $action_error = 'Gagal: user sedang online.';
        } else {
          $API->write('/ip/hotspot/user/set', false);
          $API->write('=.id='.$uid, false);
          $API->write('=disabled=yes');
          $API->read();
          if ($db && $name != '') {
            try {
              $stmt = $db->prepare("UPDATE login_history SET last_status='rusak', updated_at=CURRENT_TIMESTAMP,
                login_time_real=COALESCE(NULLIF(login_time_real,''), CURRENT_TIMESTAMP),
                logout_time_real=COALESCE(NULLIF(logout_time_real,''), CURRENT_TIMESTAMP),
                last_login_real=COALESCE(NULLIF(last_login_real,''), CURRENT_TIMESTAMP)
                WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }
        }
        if (!$action_blocked) {
          $action_message = 'Berhasil disable voucher ' . $name . '.';
        }
      } elseif ($act == 'invalid') {
        $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $comm;
        $profile_key = (string)($urow['profile'] ?? '');
        $profile_label = $resolve_profile_label($profile_key);
        $price_value = $resolve_price_from_profile($profile_key !== '' ? $profile_key : $profile_label);
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=yes', false);
        $API->write('=comment='.$new_c);
        $API->read();
        if ($db && $name != '') {
          $hist = get_user_history($name);
          $uinfo = $api_print('/ip/hotspot/user/print', [
            '?server' => $hotspot_server,
            '?name' => $name,
            '.proplist' => 'comment,bytes-in,bytes-out,uptime,mac-address'
          ]);
          $ainfo = $api_print('/ip/hotspot/active/print', [
            '?server' => $hotspot_server,
            '?user' => $name,
            '.proplist' => 'user,uptime,address,mac-address,bytes-in,bytes-out'
          ]);
          $urow = $uinfo[0] ?? [];
          $arow = $ainfo[0] ?? [];
          $bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
          $bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
          $bytes_hist = (int)($hist['last_bytes'] ?? 0);
          $bytes_final = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);
          $uptime_user = $urow['uptime'] ?? '';
          $uptime_active = $arow['uptime'] ?? '';
          $uptime_hist = $hist['last_uptime'] ?? '';
          $base_total = max(uptime_to_seconds($uptime_user), uptime_to_seconds($uptime_hist));
          if (!empty($uptime_active)) {
            $uptime_final = seconds_to_uptime($base_total + uptime_to_seconds($uptime_active));
          } else {
            $uptime_final = $uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s');
          }
          $comment_src = $urow['comment'] ?? $comm;
          $profile_kind_manual = detect_profile_kind_unified($profile_label, $comment_src, extract_blok_name($comm), $uptime_final);
          $bytes_final = (int)$bytes_final;

          if ($profile_kind_manual === '30') {
            $min_bytes = 7 * 1024 * 1024;
            if ($bytes_final >= $min_bytes) {
              $action_blocked = true;
              $action_error = 'Syarat manual RUSAK tidak terpenuhi: profil 30 menit harus < 7MB.';
            }
          } elseif ($profile_kind_manual === '10') {
            $max_bytes = 5 * 1024 * 1024;
            if ($bytes_final >= $max_bytes) {
              $action_blocked = true;
              $action_error = 'Syarat manual RUSAK tidak terpenuhi: profil 10 menit harus < 5MB.';
            }
          }

          if ($action_blocked) {
            // stop manual rusak if validation failed
          } else {
          $cm = extract_ip_mac_from_comment($comment_src);
          $ip_final = $arow['address'] ?? ($cm['ip'] ?? ($hist['last_ip'] ?? ($hist['ip_address'] ?? '-')));
          $mac_final = $arow['mac-address'] ?? ($urow['mac-address'] ?? ($cm['mac'] ?? ($hist['last_mac'] ?? ($hist['mac_address'] ?? '-'))));
          $login_time_real = $hist['login_time_real'] ?? null;
          $logout_time_real = $hist['logout_time_real'] ?? null;
          if (empty($logout_time_real)) {
            $comment_dt = extract_datetime_from_comment($comm);
            $logout_time_real = $comment_dt != '' ? $comment_dt : date('Y-m-d H:i:s');
          }
          if (empty($login_time_real) && !empty($logout_time_real)) {
            $login_time_real = $logout_time_real;
          }
          $save_data = [
            'ip' => $ip_final ?: '-',
            'mac' => $mac_final ?: '-',
            'uptime' => $uptime_final,
            'bytes' => $bytes_final,
            'first_ip' => (!empty($hist['first_ip']) ? $hist['first_ip'] : ($ip_final ?: '')),
            'first_mac' => (!empty($hist['first_mac']) ? $hist['first_mac'] : ($mac_final ?: '')),
            'last_ip' => ($ip_final && $ip_final != '-') ? $ip_final : ($hist['last_ip'] ?? ''),
            'last_mac' => ($mac_final && $mac_final != '-') ? $mac_final : ($hist['last_mac'] ?? ''),
            'blok' => extract_blok_name($comm),
            'raw' => $new_c,
            'validity' => $profile_label,
            'price' => $price_value,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => 'rusak'
          ];
          save_user_history($name, $save_data);

          if ($db && $name != '') {
            try {
              $stmt = $db->prepare("UPDATE login_history SET auto_rusak=0, updated_at=CURRENT_TIMESTAMP,
                login_time_real=COALESCE(NULLIF(login_time_real,''), CURRENT_TIMESTAMP),
                last_login_real=COALESCE(NULLIF(last_login_real,''), CURRENT_TIMESTAMP)
                WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }

          // Update status transaksi agar laporan live berkurang
          if ($db && $name != '') {
            try {
              $stmt = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
            try {
              $stmt = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }
          }
        }
        $action_message = 'Berhasil set RUSAK untuk ' . $name . '.';
      } elseif ($act == 'rollback') {
        // Kembalikan status RUSAK
        $uinfo = $api_print('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $name,
          '.proplist' => '.id,comment,disabled'
        ]);
        $urow = $uinfo[0] ?? [];
        $old_comment = $urow['comment'] ?? $comm;
        $clean_comment = preg_replace('/\bAudit:\s*RUSAK\s*\d{2}\/\d{2}\/\d{2}\s*/i', '', $old_comment);
        $clean_comment = preg_replace('/\bRUSAK\b\s*/i', '', $clean_comment);
        $clean_comment = preg_replace('/\(Retur\)\s*/i', '', $clean_comment);
        $clean_comment = preg_replace('/Retur\s*Ref\s*:[^|]+/i', '', $clean_comment);
        $clean_comment = preg_replace('/\s+\|\s+/', ' | ', $clean_comment);
        $clean_comment = trim($clean_comment);
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=no', false);
        $API->write('=comment='.$clean_comment);
        $API->read();
        if ($db && $name != '') {
          $save_data = [
            'raw' => $clean_comment,
            'status' => 'ready'
          ];
          save_user_history($name, $save_data);

          if ($db && $name != '') {
            try {
              $stmt = $db->prepare("UPDATE login_history SET updated_at=CURRENT_TIMESTAMP,
                login_time_real=COALESCE(NULLIF(login_time_real,''), CURRENT_TIMESTAMP),
                last_login_real=COALESCE(NULLIF(last_login_real,''), CURRENT_TIMESTAMP)
                WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }

          // Kembalikan status transaksi laporan agar tidak tetap rusak
          try {
            $stmt = $db->prepare("UPDATE sales_history SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
          try {
            $stmt = $db->prepare("UPDATE live_sales SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
        }
        $action_message = 'Berhasil rollback RUSAK untuk ' . $name . '.';
      } elseif ($act == 'retur') {
        // Simpan data voucher lama ke DB sebelum dihapus
        $user_info = $api_print("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          "?name" => $name,
          ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
        ));
        $uinfo = $user_info[0] ?? [];
        if (empty($uinfo) && $uid != '') {
          $user_info = $api_print("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            "?.id" => $uid,
            ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
          ));
          $uinfo = $user_info[0] ?? [];
        }
        $active_info = $api_print("/ip/hotspot/active/print", array(
          "?server" => $hotspot_server,
          "?user" => $name,
          ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
        ));
        $ainfo = $active_info[0] ?? [];

        $cmt = $uinfo['comment'] ?? $comm;
        $blok = extract_blok_name($cmt);
        $prof = $uinfo['profile'] ?? $prof;
        $cm = extract_ip_mac_from_comment($cmt);
        $f_ip = $ainfo['address'] ?? ($cm['ip'] ?? '-');
        $f_mac = $ainfo['mac-address'] ?? ($uinfo['mac-address'] ?? ($cm['mac'] ?? '-'));

        $bytes_total = ($uinfo['bytes-in'] ?? 0) + ($uinfo['bytes-out'] ?? 0);
        $bytes_active = ($ainfo['bytes-in'] ?? 0) + ($ainfo['bytes-out'] ?? 0);
        $bytes = max($bytes_total, $bytes_active);

        $uptime_user = $uinfo['uptime'] ?? '';
        $uptime_active = $ainfo['uptime'] ?? '';
        $uptime = $uptime_user != '' ? $uptime_user : $uptime_active;

        $hist = get_user_history($name);
        $login_time_real = $hist['login_time_real'] ?? null;
        $logout_time_real = $hist['logout_time_real'] ?? null;
        if (empty($logout_time_real)) {
          $comment_dt = extract_datetime_from_comment($cmt);
          if ($comment_dt != '') $logout_time_real = $comment_dt;
        }

        if ($db) {
          $save_data = [
            'ip' => $f_ip,
            'mac' => $f_mac,
            'uptime' => $uptime,
            'bytes' => $bytes,
            'blok' => $blok,
            'raw' => $cmt,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => 'rusak'
          ];
          save_user_history($name, $save_data);

          if ($name != '') {
            try {
              $stmt = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
            try {
              $stmt = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }
        }

        // Hapus voucher lama
        $del_id = $uid ?: ($uinfo['.id'] ?? '');
        if ($del_id != '') {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $del_id);
          $API->read();
        }

        // Generate voucher baru
        $gen = gen_user($prof ?: 'default', $comm ?: $name, $name);
        $new_user = $gen['u'] ?? '';
        $profile_kind = detect_profile_kind_unified($prof ?: ($uinfo['profile'] ?? ''), $cmt ?? '', $blok ?? '', $uptime ?? '');
        $limit_uptime = '';
        if ($profile_kind === '30') {
          $limit_uptime = '30m';
        } elseif ($profile_kind === '10') {
          $limit_uptime = '10m';
        } else {
          $profile_label = (string)($prof ?: ($uinfo['profile'] ?? ''));
          if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $profile_label)) {
            $limit_uptime = '30m';
          } elseif (preg_match('/\b10\s*(menit|m)\b|10menit/i', $profile_label)) {
            $limit_uptime = '10m';
          }
        }
        $API->write('/ip/hotspot/user/add', false);
        $API->write('=server='.$hotspot_server, false);
        $API->write('=name='.$gen['u'], false);
        $API->write('=password='.$gen['p'], false);
        $API->write('=profile='.($prof ?: 'default'), false);
        if ($limit_uptime !== '') {
          $API->write('=limit-uptime='.$limit_uptime, false);
        }
        $API->write('=comment='.$gen['c']);
        $API->read();

        // Simpan status READY untuk user baru (hasil retur)
        if ($db) {
          $new_blok = extract_blok_name($gen['c']);
          $save_new = [
            'ip' => '-',
            'mac' => '-',
            'uptime' => '0s',
            'bytes' => 0,
            'blok' => $new_blok,
            'raw' => $gen['c'],
            'login_time_real' => null,
            'logout_time_real' => null,
            'status' => 'ready'
          ];
          save_user_history($gen['u'], $save_new);
        }
      }
    } elseif ($act == 'invalid' && $name != '' && $db) {
      // fallback: jika user sudah hilang di RouterOS, tetap tandai RUSAK di DB
      $hist = get_user_history($name);
      $base_comment = $comm != '' ? $comm : ($hist['raw_comment'] ?? '');
      $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $base_comment;
      $profile_label = (string)($hist['validity'] ?? '');
      $profile_key = $profile_label;
      if ($profile_key === '') {
        if (preg_match('/\bProfil\s*:\s*([^|]+)/i', $base_comment, $m)) {
          $profile_key = trim($m[1]);
        } elseif (preg_match('/\bProfile\s*:\s*([^|]+)/i', $base_comment, $m)) {
          $profile_key = trim($m[1]);
        } elseif (preg_match('/(\d+)\s*(menit|m)\b/i', $base_comment, $m)) {
          $profile_key = $m[1] . 'menit';
        }
      }
      $profile_label = $resolve_profile_label($profile_key !== '' ? $profile_key : $profile_label);
      $price_value = $resolve_price_from_profile($profile_key !== '' ? $profile_key : $profile_label);
      $logout_time_real = $hist['logout_time_real'] ?? null;
      if (empty($logout_time_real)) {
        $comment_dt = extract_datetime_from_comment($base_comment);
        $logout_time_real = $comment_dt != '' ? $comment_dt : date('Y-m-d H:i:s');
      }
      $login_time_real = $hist['login_time_real'] ?? null;
      if (empty($login_time_real) && !empty($logout_time_real)) {
        $login_time_real = $logout_time_real;
      }
      $save_data = [
        'ip' => $hist['ip_address'] ?? '-',
        'mac' => $hist['mac_address'] ?? '-',
        'uptime' => $hist['last_uptime'] ?? '0s',
        'bytes' => (int)($hist['last_bytes'] ?? 0),
        'first_ip' => $hist['first_ip'] ?? '',
        'first_mac' => $hist['first_mac'] ?? '',
        'last_ip' => $hist['last_ip'] ?? '',
        'last_mac' => $hist['last_mac'] ?? '',
        'blok' => $hist['blok_name'] ?? extract_blok_name($base_comment),
        'raw' => $new_c,
        'validity' => $profile_label,
        'price' => $price_value,
        'login_time_real' => $login_time_real,
        'logout_time_real' => $logout_time_real,
        'status' => 'rusak'
      ];
      save_user_history($name, $save_data);

      try {
        $stmt = $db->prepare("UPDATE login_history SET updated_at=CURRENT_TIMESTAMP,
          login_time_real=COALESCE(NULLIF(login_time_real,''), CURRENT_TIMESTAMP),
          last_login_real=COALESCE(NULLIF(last_login_real,''), CURRENT_TIMESTAMP)
          WHERE username = :u");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}

      try {
        $stmt = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}
      try {
        $stmt = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
        $stmt->execute([':u' => $name]);
      } catch(Exception $e) {}
    }
    $action_message = $action_message ?: ('Berhasil retur voucher ' . $name . '.');
  }
  $redir_params = [
    'hotspot' => 'users',
    'session' => $session,
  ];
  if (isset($_GET['profile'])) $redir_params['profile'] = $_GET['profile'];
  if (isset($_GET['comment'])) $redir_params['comment'] = $_GET['comment'];
  if (isset($_GET['status'])) $redir_params['status'] = $_GET['status'];
  if (isset($_GET['q'])) $redir_params['q'] = $_GET['q'];
  if (isset($_GET['show'])) $redir_params['show'] = $_GET['show'];
  if (isset($_GET['date'])) $redir_params['date'] = $_GET['date'];
  if (isset($_GET['debug'])) $redir_params['debug'] = $_GET['debug'];
  if (isset($_GET['only_wartel'])) $redir_params['only_wartel'] = $_GET['only_wartel'];
  if (in_array($act, ['delete_block_full','batch_delete','delete_status'], true)) {
    $redir_params['status'] = 'all';
    $redir_params['comment'] = '';
    $redir_params['q'] = '';
    $redir_params['page'] = 1;
  }
  $redir = './?' . http_build_query($redir_params);
  if ($is_action_ajax) {
    if (ob_get_length()) { @ob_clean(); }
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => !$action_blocked,
      'message' => $action_blocked ? $action_error : ($action_message ?: 'Berhasil diproses.'),
      'redirect' => $action_blocked ? '' : $redir,
      'new_user' => (!$action_blocked && $act === 'retur' && $new_user !== '') ? $new_user : ''
    ]);
    exit();
  }
  if ($action_blocked) {
    echo "<script>if(window.showActionPopup){window.showActionPopup('error','" . addslashes($action_error) . "');}</script>";
  } else {
    echo "<script>if(window.showActionPopup){window.showActionPopup('success','Berhasil diproses.','{$redir}');}else{window.location.href='{$redir}';}</script>";
  }
  exit();
}
