<?php
// Action handler sederhana (invalid/retur/delete)
if (isset($_GET['action']) || isset($_POST['action'])) {
  $is_action_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['action_ajax']);
  $act = $_POST['action'] ?? $_GET['action'];
  if ($act === 'login_events') {
    header('Content-Type: application/json');
    if (!$db) {
      echo json_encode(['ok' => false, 'message' => 'DB tidak tersedia.']);
      exit();
    }
    $name = trim($_GET['name'] ?? '');
    $show = trim($_GET['show'] ?? '');
    $date = trim($_GET['date'] ?? '');
    $recent = (int)($_GET['recent'] ?? 0);
    if ($name === '') {
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
      echo json_encode(['ok' => true, 'total' => $total, 'limit' => $limit, 'events' => $events]);
      exit();
    } catch (Exception $e) {
      echo json_encode(['ok' => false, 'message' => 'Gagal mengambil data relogin.']);
      exit();
    }
  }
  if ($act == 'invalid' || $act == 'retur' || $act == 'rollback' || $act == 'delete' || $act == 'batch_delete' || $act == 'delete_status' || $act == 'check_rusak' || $act == 'disable' || $act == 'enable') {
    $uid = $_GET['uid'] ?? '';
    $name = $_GET['name'] ?? '';
    $comm = $_GET['c'] ?? '';
    $prof = $_GET['p'] ?? '';
    $blok = normalize_blok_param($_GET['blok'] ?? '');
    $status = $_GET['status'] ?? '';
    $action_blocked = false;
    $action_error = '';
    $hist_action = null;
    $is_rusak_target = false;
    if ($name != '') {
      $hist_action = get_user_history($name);
      if ($hist_action && strtolower($hist_action['last_status'] ?? '') === 'rusak') {
        $is_rusak_target = true;
      }
    }
    if (!$is_rusak_target && $comm != '' && stripos($comm, 'RUSAK') !== false) {
      $is_rusak_target = true;
    }

    if ($uid == '' && $name != '' && in_array($act, ['invalid','retur','rollback','delete','disable','enable'])) {
      $uget = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
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
      $uinfo = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id,name,comment,profile,disabled,bytes-in,bytes-out,uptime'
      ]);
      $ainfo = $API->comm('/ip/hotspot/active/print', [
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
        $ucheck = $API->comm('/ip/hotspot/user/print', [
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
        $list = $API->comm("/ip/hotspot/user/print", array(
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

    if (!$action_blocked && $act == 'delete' && $name != '') {
      $active_check = $API->comm('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $name,
        '.proplist' => 'user'
      ]);
      if (!empty($active_check) && isset($active_check[0]['user'])) {
        $action_blocked = true;
        $action_error = 'Gagal: user sedang online.';
      }
    }

    if ($action_blocked) {
      // skip action
    } elseif ($act == 'delete_status') {
      $status_map = [
        'used' => 'terpakai',
        'retur' => 'retur',
        'rusak' => 'rusak'
      ];
      $target_status = $status_map[$status] ?? '';
      if ($target_status != '' && $db) {
        $blok_norm = $blok != '' ? extract_blok_name($blok) : '';
        $active_list = $API->comm("/ip/hotspot/active/print", array(
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
            $u = $API->comm("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              "?name" => $uname,
              ".proplist" => ".id"
            ));
            if (isset($u[0]['.id'])) {
              $API->write('/ip/hotspot/user/remove', false);
              $API->write('=.id=' . $u[0]['.id']);
              $API->read();
            }
            $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
            $del->execute([':u' => $uname]);
            $deleted_any = true;
          }
          if (!$deleted_any && $target_status !== '') {
            $list = $API->comm("/ip/hotspot/user/print", array(
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
            $list = $API->comm("/ip/hotspot/user/print", array(
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
    } elseif ($act == 'batch_delete' && $blok != '') {
      $active_list = $API->comm("/ip/hotspot/active/print", array(
        "?server" => $hotspot_server,
        ".proplist" => "user"
      ));
      $active_names = [];
      foreach ($active_list as $a) {
        if (isset($a['user'])) $active_names[$a['user']] = true;
      }
      $list = $API->comm("/ip/hotspot/user/print", array(
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
        if (($cblok != '' && strcasecmp($cblok, $blok_norm) == 0) || ($cblok_cmp != '' && $cblok_cmp == $blok_cmp) || ($blok_raw != '' && stripos($c, $blok_raw) !== false)) {
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
    } elseif ($uid != '') {
      if ($act == 'delete') {
        $API->write('/ip/hotspot/user/remove', false);
        $API->write('=.id=' . $uid);
        $API->read();
        if ($db && $name != '') {
          try {
            $stmt = $db->prepare("DELETE FROM login_history WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
        }
      } elseif ($act == 'disable') {
        $active_check = $API->comm('/ip/hotspot/active/print', [
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
      } elseif ($act == 'enable') {
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=no');
        $API->read();
        if ($db && $name != '') {
          try {
            $stmt = $db->prepare("UPDATE login_history SET last_status='ready', updated_at=CURRENT_TIMESTAMP WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
        }
      } elseif ($act == 'invalid') {
        $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $comm;
        $profile_label = (string)($urow['profile'] ?? '');
        $price_value = 0;
        if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $profile_label)) {
          $price_value = 20000;
        } else {
          $price_value = 5000;
        }
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=yes', false);
        $API->write('=comment='.$new_c);
        $API->read();
        if ($db && $name != '') {
          $hist = get_user_history($name);
          $uinfo = $API->comm('/ip/hotspot/user/print', [
            '?server' => $hotspot_server,
            '?name' => $name,
            '.proplist' => 'comment,bytes-in,bytes-out,uptime,mac-address'
          ]);
          $ainfo = $API->comm('/ip/hotspot/active/print', [
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
              $stmt = $db->prepare("UPDATE login_history SET updated_at=CURRENT_TIMESTAMP,
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
      } elseif ($act == 'rollback') {
        // Kembalikan status RUSAK
        $uinfo = $API->comm('/ip/hotspot/user/print', [
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
        }
      } elseif ($act == 'retur') {
        // Simpan data voucher lama ke DB sebelum dihapus
        $user_info = $API->comm("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          "?name" => $name,
          ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
        ));
        $uinfo = $user_info[0] ?? [];
        if (empty($uinfo) && $uid != '') {
          $user_info = $API->comm("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            "?.id" => $uid,
            ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
          ));
          $uinfo = $user_info[0] ?? [];
        }
        $active_info = $API->comm("/ip/hotspot/active/print", array(
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
            'status' => 'retur'
          ];
          save_user_history($name, $save_data);

          // Kembalikan pendapatan yang sempat berkurang karena RUSAK
          try {
            $stmt = $db->prepare("UPDATE sales_history SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
          try {
            $stmt = $db->prepare("UPDATE live_sales SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
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
        $API->write('/ip/hotspot/user/add', false);
        $API->write('=server='.$hotspot_server, false);
        $API->write('=name='.$gen['u'], false);
        $API->write('=password='.$gen['p'], false);
        $API->write('=profile='.($prof ?: 'default'), false);
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
      if ($profile_label === '') {
        if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $base_comment)) $profile_label = '30 Menit';
        elseif (preg_match('/\b10\s*(menit|m)\b|10menit/i', $base_comment)) $profile_label = '10 Menit';
      }
      $price_value = 0;
      if (preg_match('/\b30\s*(menit|m)\b|30menit/i', $profile_label)) {
        $price_value = 20000;
      } else {
        $price_value = 5000;
      }
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
  $redir = './?' . http_build_query($redir_params);
  if ($is_action_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => !$action_blocked,
      'message' => $action_blocked ? $action_error : 'Berhasil diproses.',
      'redirect' => $action_blocked ? '' : $redir
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
