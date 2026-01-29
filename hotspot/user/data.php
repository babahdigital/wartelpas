<?php
require_once __DIR__ . '/helpers.php';
$history_cache = null;
function save_user_history($name, $data) {
    global $db;
    if(!$db || empty($name)) return false;
    try {
        $stmt = $db->prepare("INSERT INTO login_history (
          username, login_date, login_time, price, ip_address, mac_address, last_uptime, last_bytes,
          first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real,
          validity, blok_name, raw_comment, login_time_real, logout_time_real, last_status, updated_at
        ) VALUES (
          :u, :ld, :lt, :p, :ip, :mac, :up, :lb, :fip, :fmac, :lip, :lmac, :flr, :llr, :val, :bl, :raw, :ltr, :lor, :st, :upd
        ) ON CONFLICT(username) DO UPDATE SET 
            login_date = COALESCE(NULLIF(excluded.login_date, ''), login_history.login_date),
            login_time = COALESCE(NULLIF(excluded.login_time, ''), login_history.login_time),
            price = COALESCE(NULLIF(excluded.price, ''), login_history.price),
            validity = COALESCE(NULLIF(excluded.validity, ''), login_history.validity),
            blok_name = CASE WHEN excluded.blok_name != '' THEN excluded.blok_name ELSE COALESCE(login_history.blok_name, '') END,
            ip_address = CASE WHEN excluded.ip_address != '-' AND excluded.ip_address != '' THEN excluded.ip_address ELSE COALESCE(login_history.ip_address, '-') END,
            mac_address = CASE WHEN excluded.mac_address != '-' AND excluded.mac_address != '' THEN excluded.mac_address ELSE COALESCE(login_history.mac_address, '-') END,
          last_uptime = COALESCE(NULLIF(excluded.last_uptime, ''), login_history.last_uptime),
            last_bytes = CASE WHEN excluded.last_bytes IS NOT NULL AND excluded.last_bytes > 0 THEN excluded.last_bytes ELSE COALESCE(login_history.last_bytes, 0) END,
            first_ip = CASE WHEN COALESCE(login_history.first_ip, '') = '' AND excluded.first_ip != '' THEN excluded.first_ip ELSE login_history.first_ip END,
            first_mac = CASE WHEN COALESCE(login_history.first_mac, '') = '' AND excluded.first_mac != '' THEN excluded.first_mac ELSE login_history.first_mac END,
            last_ip = CASE WHEN excluded.last_ip != '' THEN excluded.last_ip ELSE COALESCE(login_history.last_ip, '') END,
            last_mac = CASE WHEN excluded.last_mac != '' THEN excluded.last_mac ELSE COALESCE(login_history.last_mac, '') END,
            first_login_real = COALESCE(login_history.first_login_real, excluded.first_login_real),
            last_login_real = COALESCE(excluded.last_login_real, login_history.last_login_real),
            login_time_real = CASE WHEN excluded.last_status = 'online' THEN excluded.login_time_real ELSE COALESCE(login_history.login_time_real, excluded.login_time_real) END,
            logout_time_real = CASE WHEN excluded.last_status = 'online' THEN NULL ELSE COALESCE(login_history.logout_time_real, excluded.logout_time_real) END,
            last_status = COALESCE(excluded.last_status, login_history.last_status),
            raw_comment = excluded.raw_comment,
            updated_at = CASE WHEN excluded.last_status = 'online' THEN excluded.updated_at ELSE login_history.updated_at END");
        $stmt->execute([
            ':u' => $name,
            ':ld' => $data['date'] ?? '',
            ':lt' => $data['time'] ?? '',
            ':p'  => $data['price'] ?? '',
            ':ip' => $data['ip'] ?? '-',
            ':mac'=> $data['mac'] ?? '-',
          ':up' => $data['uptime'] ?? '',
            ':lb' => $data['bytes'] ?? null,
            ':fip' => $data['first_ip'] ?? '',
            ':fmac' => $data['first_mac'] ?? '',
            ':lip' => $data['last_ip'] ?? '',
            ':lmac' => $data['last_mac'] ?? '',
            ':flr' => $data['first_login_real'] ?? null,
            ':llr' => $data['last_login_real'] ?? null,
            ':val'=> $data['validity'] ?? '',
            ':bl' => $data['blok'] ?? '',
            ':raw'=> $data['raw'] ?? '',
            ':ltr'=> $data['login_time_real'] ?? null,
            ':lor'=> $data['logout_time_real'] ?? null,
            ':st' => $data['status'] ?? 'ready',
            ':upd'=> date("Y-m-d H:i:s")
        ]);
        return true;
    } catch(Exception $e) {
        return false;
    }
}

function get_user_history($name) {
    global $db;
  global $history_cache;
    if(!$db) return null;
  if (is_array($history_cache)) {
    $key = strtolower((string)$name);
    if ($key !== '' && array_key_exists($key, $history_cache)) {
      return $history_cache[$key];
    }
  }
    try {
  $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_uptime, last_bytes, last_status, first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real, updated_at, login_count, raw_comment, validity FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($history_cache) && $name !== '') {
      $history_cache[strtolower((string)$name)] = $row ?: null;
    }
    return $row;
    } catch(Exception $e){
        return null;
    }
}

function get_cumulative_uptime_from_events($username, $first_login_real = '', $fallback_logout = '') {
  global $db;
  if (!$db || empty($username)) return 0;
  $params = [':u' => $username];
  $where = "username = :u";
  if (!empty($first_login_real)) {
    $date_key = date('Y-m-d', strtotime($first_login_real));
    if (!empty($date_key)) {
      $where .= " AND date_key = :d";
      $params[':d'] = $date_key;
    }
  }
  try {
    $stmt = $db->prepare("SELECT login_time, logout_time FROM login_events WHERE $where ORDER BY seq ASC, id ASC");
    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $total = 0;
    $fallback_ts = !empty($fallback_logout) ? strtotime($fallback_logout) : 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $login_time = $row['login_time'] ?? '';
      $logout_time = $row['logout_time'] ?? '';
      if (empty($login_time)) continue;
      $login_ts = strtotime($login_time);
      if (!$login_ts) continue;
      $logout_ts = !empty($logout_time) ? strtotime($logout_time) : 0;
      if (!$logout_ts && $fallback_ts && $fallback_ts >= $login_ts) {
        $logout_ts = $fallback_ts;
      }
      if ($logout_ts && $logout_ts >= $login_ts) {
        $total += ($logout_ts - $login_ts);
      }
    }
    return (int)$total;
  } catch (Exception $e) {
    return 0;
  }
}

function get_relogin_count_from_events($username, $first_login_real = '') {
  global $db;
  if (!$db || empty($username)) return 0;
  $params = [':u' => $username];
  $where = "username = :u AND login_time IS NOT NULL";
  if (!empty($first_login_real)) {
    $date_key = date('Y-m-d', strtotime($first_login_real));
    if (!empty($date_key)) {
      $where .= " AND date_key = :d";
      $params[':d'] = $date_key;
    }
  }
  try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_events WHERE $where");
    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    return (int)$stmt->fetchColumn();
  } catch (Exception $e) {
    return 0;
  }
}

if (isset($_GET['action']) || isset($_POST['action'])) {
  return;
}

$all_users = $API->comm("/ip/hotspot/user/print", array(
    "?server" => $hotspot_server,
  ".proplist" => ".id,name,comment,profile,disabled,bytes-in,bytes-out,uptime"
));
$router_users = $all_users;
$active = $API->comm("/ip/hotspot/active/print", array(
  "?server" => $hotspot_server,
  ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
));

$activeMap = [];
foreach($active as $a) {
    if(isset($a['user'])) $activeMap[$a['user']] = $a;
}

if ($db && !empty($all_users)) {
  $history_cache = [];
  $names = [];
  foreach ($all_users as $u) {
    $uname = $u['name'] ?? '';
    if ($uname !== '') {
      $names[strtolower($uname)] = $uname;
    }
  }
  $name_list = array_values($names);
  $chunk_size = 400;
  for ($i = 0; $i < count($name_list); $i += $chunk_size) {
    $chunk = array_slice($name_list, $i, $chunk_size);
    if (empty($chunk)) continue;
    $placeholders = [];
    $params = [];
    foreach ($chunk as $j => $uname) {
      $ph = ':u' . $i . '_' . $j;
      $placeholders[] = $ph;
      $params[$ph] = $uname;
    }
    try {
      $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_uptime, last_bytes, last_status, first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real, updated_at, login_count, raw_comment, validity FROM login_history WHERE username IN (" . implode(',', $placeholders) . ")");
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uname = strtolower((string)($row['username'] ?? ''));
        if ($uname !== '') {
          $history_cache[$uname] = $row;
        }
      }
    } catch (Exception $e) {}
  }
}

$summary_ready_by_blok = [];
$summary_ready_total = 0;
$summary_rusak_total = 0;
$summary_retur_total = 0;
$summary_seen_users = [];
if (!empty($router_users)) {
  foreach ($router_users as $u) {
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);
    $bytes = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
    $uptime = $u['uptime'] ?? '';
    $cm = extract_ip_mac_from_comment($comment);
    $blok = extract_blok_name($comment);

    if ($name !== '') {
      $summary_seen_users[strtolower($name)] = true;
    }

    if ($only_wartel && !is_wartel_client($comment, $blok)) {
      continue;
    }

    $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
    $is_retur = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $comment);
    $is_rusak = $comment_rusak || (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
    if ($db && $name !== '') {
      $hist_sum = get_user_history($name);
      $hist_status = strtolower($hist_sum['last_status'] ?? '');
      if ($hist_status === 'retur') {
        $is_retur = true;
      } elseif ($hist_status === 'rusak') {
        $is_rusak = true;
      } elseif ($hist_status === 'invalid') {
        $is_invalid = true;
      }
    }

    $flags_priority = [
      'retur' => $is_retur,
      'rusak' => $is_rusak,
      'invalid' => $is_invalid ?? false
    ];
    $priority_status = resolve_status_priority($flags_priority, 'READY');
    if ($priority_status === 'RETUR') {
      $is_retur = true;
      $is_rusak = false;
    } elseif ($priority_status === 'RUSAK') {
      $is_rusak = true;
      $is_retur = false;
    } elseif ($priority_status === 'INVALID') {
      $is_invalid = true;
      $is_rusak = false;
      $is_retur = false;
    }

    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || ($uptime !== '' && $uptime !== '0s') || (($cm['ip'] ?? '') !== ''));

    $status = 'READY';
    if ($is_active) $status = 'ONLINE';
    elseif ($priority_status !== 'READY') $status = $priority_status;
    elseif ($is_used) $status = 'TERPAKAI';

    if ($status === 'READY') {
      $summary_ready_total++;
      if ($blok !== '') {
        if (!isset($summary_ready_by_blok[$blok])) {
          $summary_ready_by_blok[$blok] = ['total' => 0, 'p10' => 0, 'p30' => 0, 'other' => 0];
        }
        $summary_ready_by_blok[$blok]['total']++;
        $kind = detect_profile_kind_summary($u['profile'] ?? '');
        if ($kind === '10') $summary_ready_by_blok[$blok]['p10']++;
        elseif ($kind === '30') $summary_ready_by_blok[$blok]['p30']++;
        else $summary_ready_by_blok[$blok]['other']++;
      }
    } elseif ($status === 'RUSAK') {
      $summary_rusak_total++;
    } elseif ($status === 'RETUR') {
      $summary_retur_total++;
    }
  }
  if (!empty($summary_ready_by_blok)) {
    ksort($summary_ready_by_blok, SORT_NATURAL | SORT_FLAG_CASE);
  }
}

if ($db) {
  try {
    $stmtSum = $db->query("SELECT username, last_status, raw_comment, blok_name FROM login_history WHERE username IS NOT NULL AND username != ''");
    $rows = $stmtSum->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      $uname = strtolower($row['username'] ?? '');
      if ($uname === '' || isset($summary_seen_users[$uname])) continue;
      $raw_comment = (string)($row['raw_comment'] ?? '');
      $blok_name = (string)($row['blok_name'] ?? '');
      if ($only_wartel && !is_wartel_client($raw_comment, $blok_name)) continue;
      $hist_status = strtolower((string)($row['last_status'] ?? ''));
      $is_hist_rusak = $hist_status === 'rusak' || preg_match('/\bAudit:\s*RUSAK\b/i', $raw_comment) || preg_match('/^\s*RUSAK\b/i', $raw_comment) || (stripos($raw_comment, 'RUSAK') !== false);
      $is_hist_retur = $hist_status === 'retur' || (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $raw_comment);
      if ($is_hist_rusak) {
        $summary_rusak_total++;
      } elseif ($is_hist_retur) {
        $summary_retur_total++;
      }
    }
  } catch (Exception $e) {}
}

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';
$is_print = isset($_GET['print']) && $_GET['print'] == '1';

$retur_ref_map = [];
if ($db) {
  try {
    $stmtRefs = $db->query("SELECT raw_comment FROM login_history WHERE raw_comment IS NOT NULL AND raw_comment != ''");
    foreach ($stmtRefs->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $ref_user = extract_retur_user_from_ref($row['raw_comment'] ?? '');
      if ($ref_user !== '') {
        $retur_ref_map[strtolower($ref_user)] = true;
      }
    }
  } catch (Exception $e) {}
}

// Tambahkan data history-only agar TERPAKAI/RUSAK/RETUR tetap tampil
if ($db) {
  try {
    $need_history = in_array(strtolower($req_status), ['used','rusak','retur','all']) || trim($req_search) !== '';
    if ($need_history) {
      $res = $db->query("SELECT username, raw_comment, last_status, last_bytes, last_uptime, ip_address, mac_address, blok_name, validity FROM login_history WHERE username IS NOT NULL AND username != ''");
      $existing = [];
      foreach ($all_users as $u) {
        if (!empty($u['name'])) $existing[$u['name']] = true;
      }
      foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uname = $row['username'] ?? '';
        if ($uname === '' || isset($existing[$uname])) continue;
        $comment = (string)($row['raw_comment'] ?? '');
        $uptime_hist = (string)($row['last_uptime'] ?? '');
        $hist_profile = resolve_profile_from_history($comment, $row['validity'] ?? '', $uptime_hist);
        $hist_blok = (string)($row['blok_name'] ?? '');
        if ($only_wartel && !is_wartel_client($comment, $hist_blok)) continue;
        $st = strtolower((string)($row['last_status'] ?? ''));
        $bytes_hist = (int)($row['last_bytes'] ?? 0);
        $uptime_hist = (string)($row['last_uptime'] ?? '');
        $ip_hist = (string)($row['ip_address'] ?? '');
        $mac_hist = (string)($row['mac_address'] ?? '');
        $cm = extract_ip_mac_from_comment($comment);
        $ip_use = $ip_hist !== '' && $ip_hist !== '-' ? $ip_hist : ($cm['ip'] ?? '');
        $mac_use = $mac_hist !== '' && $mac_hist !== '-' ? $mac_hist : ($cm['mac'] ?? '');

        $h_comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
        $h_has_retur_tag = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false);
        $h_is_retur = ($st === 'retur') || $h_has_retur_tag;
        $h_is_rusak = ($st === 'rusak') || $h_comment_rusak;
        if (isset($retur_ref_map[strtolower($uname)])) {
          $h_is_retur = true;
          $h_is_rusak = false;
        }
        if ($h_is_retur) {
          $h_is_rusak = false;
        }
        $h_is_used = (!$h_is_rusak && !$h_is_retur) && (
          $bytes_hist > 50 ||
          ($uptime_hist !== '' && $uptime_hist !== '0s') ||
          ($ip_use !== '' && $ip_use !== '-')
        );
        $h_status = 'READY';
        if ($h_is_retur) $h_status = 'RETUR';
        elseif ($h_is_rusak) $h_status = 'RUSAK';
        elseif ($h_is_used) $h_status = 'TERPAKAI';

        if ($h_status === 'READY') continue;
        if (in_array($req_status, ['used','retur','all'], true) && isset($retur_ref_map[strtolower($uname)])) continue;
        if ($req_status === 'used' && !in_array($h_status, ['TERPAKAI','RETUR','RUSAK'])) continue;
        if ($req_status === 'rusak' && $h_status !== 'RUSAK') continue;
        if ($req_status === 'retur' && $h_status !== 'RETUR') continue;
        $all_users[] = [
          'name' => $uname,
          'comment' => $comment,
          'profile' => $hist_profile,
          'disabled' => $h_status === 'RUSAK' ? 'true' : 'false',
          'bytes-in' => $bytes_hist,
          'bytes-out' => 0,
          'uptime' => $uptime_hist
        ];
        $existing[$uname] = true;
      }
    }
  } catch (Exception $e) {}
}

// List blok untuk dropdown (gabungkan router + history agar filter konsisten)
$list_blok = [];
if (!$is_ajax) {
  if (!empty($all_users)) {
    foreach ($all_users as $u) {
      $bn = extract_blok_name($u['comment'] ?? '') ?: extract_blok_name($u['raw_comment'] ?? '');
      if ($bn && !in_array($bn, $list_blok)) $list_blok[] = $bn;
    }
  }
  if (!empty($list_blok)) {
    sort($list_blok, SORT_NATURAL | SORT_FLAG_CASE);
  }
}

$display_data = [];
$has_transactions_in_filter = false;
$filtering_by_date = ($req_status === 'all' && $req_show !== 'semua' && !empty($filter_date));
$debug_rows = [];
$search_terms = array_filter(array_map('trim', preg_split('/[,\s]+/', $req_search)));
foreach($all_users as $u) {
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);

    if (in_array($req_status, ['used','terpakai','retur','all'], true) && isset($retur_ref_map[strtolower($name)])) {
      continue;
    }

    $f_ip = $is_active ? ($activeMap[$name]['address'] ?? '-') : '-';
    $f_mac = $is_active ? ($activeMap[$name]['mac-address'] ?? '-') : '-';
    if ($f_ip == '-' || $f_mac == '-') {
      $cm = extract_ip_mac_from_comment($comment);
      if ($f_ip == '-' && !empty($cm['ip'])) $f_ip = $cm['ip'];
      if ($f_mac == '-' && !empty($cm['mac'])) $f_mac = $cm['mac'];
    }

    $f_blok = extract_blok_name($comment);

    $hist = get_user_history($name);
    if (empty($f_blok) && $hist && !empty($hist['blok_name'])) {
      $f_blok = $hist['blok_name'];
    }
    if ($only_wartel && !is_wartel_client($comment, $f_blok)) {
      if ($req_status !== 'ready') {
        continue;
      }
    }
    if (!$is_active && $hist) {
      if ($f_ip == '-') $f_ip = $hist['ip_address'] ?? '-';
      if ($f_mac == '-') $f_mac = $hist['mac_address'] ?? '-';
    }

    $bytes_total = ($u['bytes-in'] ?? 0) + ($u['bytes-out'] ?? 0);
    $bytes_active = 0;
    if ($is_active) {
      $bytes_active = ($activeMap[$name]['bytes-in'] ?? 0) + ($activeMap[$name]['bytes-out'] ?? 0);
    }
    $bytes_hist = (int)($hist['last_bytes'] ?? 0);
    if ($is_active) {
      $bytes = max($bytes_total, $bytes_active, $bytes_hist);
    } else {
      $bytes = max($bytes_total, $bytes_hist);
    }
    $uptime_user = $u['uptime'] ?? '';
    $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
    $uptime_hist = $hist['last_uptime'] ?? '';
    if ($is_active) {
      $uptime = $uptime_active != '' ? $uptime_active : ($uptime_user != '' ? $uptime_user : '0s');
    } else {
      $uptime = $uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s');
    }

    // Jika RUSAK dan data kosong, pakai history
    if (!$is_active && $bytes == 0 && $bytes_hist > 0) {
      $bytes = $bytes_hist;
    }
    if (!$is_active && ($uptime == '0s' || $uptime == '') && $uptime_hist != '') {
      $uptime = $uptime_hist;
    }

    $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
    $is_invalid = false;
    $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
    $is_rusak = $comment_rusak;
    $hist_status = strtolower($hist['last_status'] ?? '');
    if (!$is_retur && $hist && $hist_status === 'retur') {
      $is_retur = true;
    }
    if ($hist && $hist_status === 'rusak') {
      $is_rusak = true;
    }
    if ($disabled === 'true') {
      $is_rusak = true;
    }
    if ($hist_status === 'retur') {
      $is_retur = true;
    } elseif ($hist_status === 'rusak') {
      $is_rusak = true;
    }
    if (isset($retur_ref_map[strtolower($name)])) {
      $is_retur = true;
      $is_rusak = false;
    }
    // Retur harus tetap retur meski ada Audit: RUSAK atau disabled
    if ($is_retur) {
      $is_rusak = false;
    }
    $hist_used = $hist && (
      in_array($hist_status, ['online','terpakai','rusak','retur']) ||
      !empty($hist['login_time_real']) ||
      !empty($hist['logout_time_real']) ||
      (!empty($hist['last_uptime']) && $hist['last_uptime'] != '0s') ||
      (int)($hist['last_bytes'] ?? 0) > 0
    );
    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || $uptime != '0s' || $hist_used);

    $status = 'READY';
    if ($is_active) $status = 'ONLINE';
    elseif ($is_retur) $status = 'RETUR';
    elseif ($is_rusak) $status = 'RUSAK';
    elseif ($disabled == 'true') $status = 'RUSAK';
    elseif ($is_used) $status = 'TERPAKAI';

    $is_ready_now = (!$is_active && !$is_rusak && !$is_retur && $disabled !== 'true' && $bytes <= 50 && ($uptime == '0s' || $uptime == ''));
    if ($req_status == 'ready' && $is_ready_now) {
      $status = 'READY';
    }

    // Pastikan data usage tampil saat RUSAK
    if ($status === 'RUSAK' && $hist) {
      if ($bytes == 0 && (int)($hist['last_bytes'] ?? 0) > 0) {
        $bytes = (int)$hist['last_bytes'];
      }
      if (($uptime == '0s' || $uptime == '') && !empty($hist['last_uptime'])) {
        $uptime = $hist['last_uptime'];
      }
      if (($f_ip == '-' || $f_ip == '') && !empty($hist['ip_address'])) {
        $f_ip = $hist['ip_address'];
      }
      if (($f_mac == '-' || $f_mac == '') && !empty($hist['mac_address'])) {
        $f_mac = $hist['mac_address'];
      }
    }

    // Simpan waktu login/logout dan status ke DB (back-calculation)
    $now = date('Y-m-d H:i:s');
    $login_time_real = $hist['login_time_real'] ?? null;
    $logout_time_real = $hist['logout_time_real'] ?? null;
    $last_status_db = strtolower($hist['last_status'] ?? 'ready');
    $db_updated_at = $hist['updated_at'] ?? null;
    $u_sec = uptime_to_seconds($uptime);

    if ($is_active) {
      $logout_time_real = null;
      if (empty($login_time_real)) {
        $login_time_real = ($u_sec > 0) ? date('Y-m-d H:i:s', time() - $u_sec) : $now;
      }
    } else {
      if (!empty($hist['login_time_real']) && !empty($hist['logout_time_real'])) {
        $login_time_real = $hist['login_time_real'];
        $logout_time_real = $hist['logout_time_real'];
      } else {
        if (empty($logout_time_real)) {
          if ($last_status_db === 'online') {
            $logout_time_real = $now;
          } elseif (!empty($db_updated_at) && ($status === 'TERPAKAI' || $status === 'RUSAK')) {
            $logout_time_real = $db_updated_at;
          } else {
            $comment_dt = extract_datetime_from_comment($comment);
            if ($comment_dt != '') {
              if (!($status === 'TERPAKAI' && substr($comment_dt, -8) === '00:00:00')) {
                $logout_time_real = $comment_dt;
              }
            }
          }
        }
        if (!empty($logout_time_real) && substr($logout_time_real, -8) === '00:00:00' && !empty($db_updated_at)) {
          $logout_time_real = merge_date_time($logout_time_real, $db_updated_at);
        }
        if (empty($login_time_real) && !empty($logout_time_real) && $u_sec > 0) {
          $login_time_real = date('Y-m-d H:i:s', strtotime($logout_time_real) - $u_sec);
        }
        if (!empty($login_time_real) && empty($logout_time_real) && $u_sec > 0) {
          $logout_time_real = date('Y-m-d H:i:s', strtotime($login_time_real) + $u_sec);
        }
        if ($status === 'TERPAKAI' && empty($login_time_real) && empty($logout_time_real)) {
          if (!empty($db_updated_at) && $u_sec > 0) {
            $logout_time_real = $db_updated_at;
            $login_time_real = date('Y-m-d H:i:s', strtotime($db_updated_at) - $u_sec);
          }
        }
      }
    }
    if ($status === 'READY') {
      $login_time_real = null;
      $logout_time_real = null;
    }

      // (tanggal filter dipindah setelah last_used dihitung)

    // Jika voucher pernah login (ada IP/MAC) namun waktu/uptime masih kosong, coba isi dari selisih login/logout
    if (!$is_active && $uptime == '0s' && !empty($logout_time_real) && !empty($login_time_real)) {
      $diff = strtotime($logout_time_real) - strtotime($login_time_real);
      if ($diff > 0) {
        $uptime = seconds_to_uptime($diff);
      }
    }

    $next_status = strtolower($status);
    $first_login_real = $hist['first_login_real'] ?? null;
    $last_login_real = $hist['last_login_real'] ?? null;
    $first_ip = $hist['first_ip'] ?? '';
    $first_mac = $hist['first_mac'] ?? '';
    $last_ip = $hist['last_ip'] ?? '';
    $last_mac = $hist['last_mac'] ?? '';
    if ($is_active) {
      if (empty($first_login_real)) $first_login_real = $now;
      $last_login_real = $now;
      if ($first_ip == '' && $f_ip != '-' && $f_ip != '') $first_ip = $f_ip;
      if ($first_mac == '' && $f_mac != '-' && $f_mac != '') $first_mac = $f_mac;
      if ($f_ip != '-' && $f_ip != '') $last_ip = $f_ip;
      if ($f_mac != '-' && $f_mac != '') $last_mac = $f_mac;
    }
    if (empty($first_login_real)) {
      if (!empty($login_time_real)) $first_login_real = $login_time_real;
      elseif (!empty($hist['login_time_real'])) $first_login_real = $hist['login_time_real'];
      elseif (!empty($hist['first_login_real'])) $first_login_real = $hist['first_login_real'];
    }
    // Untuk RETUR: gunakan data dari voucher asal jika tersedia dan data saat ini masih kosong
    $retur_ref_user = '';
    $retur_hist = null;
    if ($is_retur) {
      $retur_ref_user = extract_retur_user_from_ref($comment);
      if ($retur_ref_user === '' && $hist && !empty($hist['raw_comment'])) {
        $retur_ref_user = extract_retur_user_from_ref($hist['raw_comment']);
      }
      if ($retur_ref_user != '') {
        $retur_hist = get_user_history($retur_ref_user);
      }
      if ($retur_hist) {
        if ($bytes == 0 && (int)($retur_hist['last_bytes'] ?? 0) > 0) {
          $bytes = (int)$retur_hist['last_bytes'];
        }
        if (($uptime == '0s' || $uptime == '') && !empty($retur_hist['last_uptime'])) {
          $uptime = $retur_hist['last_uptime'];
        }
        if (empty($login_time_real) && !empty($retur_hist['login_time_real'])) {
          $login_time_real = $retur_hist['login_time_real'];
        }
        if (empty($logout_time_real) && !empty($retur_hist['logout_time_real'])) {
          $logout_time_real = $retur_hist['logout_time_real'];
        }
        if (($f_ip == '-' || $f_ip == '') && !empty($retur_hist['ip_address'])) {
          $f_ip = $retur_hist['ip_address'];
        }
        if (($f_mac == '-' || $f_mac == '') && !empty($retur_hist['mac_address'])) {
          $f_mac = $retur_hist['mac_address'];
        }
      }
    }

    if ($db && !$read_only && $name != '') {
        $should_save = false;
        $skip_ready_save = ($next_status === 'ready' && !$is_active && (int)$bytes <= 0 && ($uptime === '' || $uptime === '0s'));
        if ($skip_ready_save) {
          $should_save = false;
          log_ready_skip_users("users.php skip READY user={$name}");
        }
        if (!$skip_ready_save) {
          if (!$hist) {
            $should_save = true;
          } else {
            $db_status = strtolower((string)($hist['last_status'] ?? ''));
            $db_uptime = (string)($hist['last_uptime'] ?? '');
            $db_bytes = (int)($hist['last_bytes'] ?? 0);

            $u_sec_new = uptime_to_seconds($uptime);
            $u_sec_db = uptime_to_seconds($db_uptime);

            $status_changed = ($db_status !== $next_status);
            $bytes_changed = (abs($bytes - $db_bytes) > 1024);
            $uptime_changed = (abs($u_sec_new - $u_sec_db) > 60);
            $time_changed = ((string)($hist['login_time_real'] ?? '') !== (string)($login_time_real ?? '') ||
                             (string)($hist['logout_time_real'] ?? '') !== (string)($logout_time_real ?? ''));

            if ($status_changed || $bytes_changed || $uptime_changed || $time_changed) {
              $should_save = true;
            }
          }
        }
        if ($should_save) {
          $profile_guess_raw = (string)($u['profile'] ?? '');
          $profile_guess_kind = detect_profile_kind_unified($profile_guess_raw, $comment, $f_blok, $uptime);
          $validity_label = normalize_profile_label($profile_guess_raw);
          if ($validity_label === '') {
            $validity_fallback = resolve_profile_from_history($comment, $hist['validity'] ?? '', $uptime);
            $validity_label = normalize_profile_label($validity_fallback);
          }
          if ($validity_label === '' && in_array($profile_guess_kind, ['10', '30'], true)) {
            $validity_label = $profile_guess_kind . ' Menit';
          }
          $save_data = [
              'ip' => $f_ip,
              'mac' => $f_mac,
            'uptime' => $uptime,
              'bytes' => $bytes,
              'first_ip' => $first_ip,
              'first_mac' => $first_mac,
              'last_ip' => $last_ip,
              'last_mac' => $last_mac,
              'first_login_real' => $first_login_real,
              'last_login_real' => $last_login_real,
              'validity' => $validity_label,
              'blok' => $f_blok,
              'raw' => $comment,
              'login_time_real' => $login_time_real,
              'logout_time_real' => $logout_time_real,
              'status' => $next_status,
              'updated_at' => ($next_status === 'online') ? date('Y-m-d H:i:s') : null
          ];
          save_user_history($name, $save_data);
          $hist = get_user_history($name);
        }
    }

    $profile_source_raw = $u['profile'] ?? '';
    $profile_kind_final = detect_profile_kind_unified($profile_source_raw, $comment, $f_blok, $uptime);

    if (in_array($status, ['RUSAK','RETUR'], true)) {
      $found_in_history = false;

      if (!empty($hist['validity'])) {
        $db_kind = detect_profile_kind_summary($hist['validity']);
        if ($db_kind !== 'other') {
          $profile_kind_final = $db_kind;
          $found_in_history = true;
        }
      }

      if (!$found_in_history) {
        $check_comment = !empty($hist['raw_comment']) ? $hist['raw_comment'] : $comment;
        $comm_kind = detect_profile_kind_from_comment($check_comment);
        if ($comm_kind !== 'other') {
          $profile_kind_final = $comm_kind;
        } elseif (preg_match('/(10|30)/', (string)$f_blok, $m_blok)) {
          $profile_kind_final = $m_blok[1];
        }
      }

      $profile_raw = (string)($u['profile'] ?? '');
      $profile_lower = strtolower(trim($profile_raw));
      if ($profile_raw === '' || $profile_lower === 'default' || $profile_lower === 'lainnya' || $profile_lower === 'other' || $profile_lower === '-') {
        if ($profile_kind_final !== 'other') {
          $u['profile'] = $profile_kind_final . ' Menit (Hist)';
        }
      }
    }

    if ($status === 'READY' && $profile_kind_final === 'other') {
      $profile_kind_final = detect_profile_kind_from_comment($comment);
    }

    $profile_raw = (string)($u['profile'] ?? '');
    $profile_lower = strtolower(trim($profile_raw));
    if ($profile_raw === '' || $profile_lower === 'default' || $profile_lower === 'lainnya' || $profile_lower === 'other' || $profile_lower === '-') {
      if ($profile_kind_final !== 'other') {
        $u['profile'] = $profile_kind_final . ' Menit';
      } else {
        $u['profile'] = 'Lainnya';
      }
    }

    $profile_kind = $profile_kind_final;
    $profile_kind_filter = $profile_kind_final;

    // Filter blok
    if ($req_comm != '') {
      $req_key = normalize_block_key($req_comm);
      $f_key = normalize_block_key($f_blok);
      if ($req_key !== '') {
        if ($f_key === '' || strpos($f_key, $req_key) !== 0) continue;
      } else {
        if (strcasecmp($f_blok, $req_comm) != 0) continue;
      }
    }

    // Filter profil (10/30)
    if ($req_prof !== 'all') {
      if ($profile_kind_filter !== $req_prof) continue;
    }

    // Search
    if (!empty($search_terms)) {
      $found = false;
      foreach($search_terms as $term) {
        if (stripos($name, $term) !== false ||
          stripos($comment, $term) !== false ||
          stripos($f_ip, $term) !== false ||
          stripos($f_blok, $term) !== false) {
          $found = true; break;
        }
      }
      if (!$found) continue;
    }

    $login_disp = $login_time_real ?? ($hist['login_time_real'] ?? ($hist['first_login_real'] ?? '-'));
    $logout_disp = $logout_time_real ?? ($hist['logout_time_real'] ?? ($hist['last_login_real'] ?? '-'));
    if ($status === 'READY') {
      $login_disp = '-';
      $logout_disp = '-';
    }
    if ($status === 'ONLINE') {
      $logout_disp = '-';
    }
    if ($status === 'TERPAKAI' && $logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00') {
      // fallback: jika logout masih jam 00:00:00 dan ada login+uptime, hitung ulang logout
      $base_uptime = $uptime_hist != '' ? $uptime_hist : $uptime_user;
      $u_sec = uptime_to_seconds($base_uptime);
      if ($login_disp !== '-' && $u_sec > 0) {
        $logout_disp = date('Y-m-d H:i:s', strtotime($login_disp) + $u_sec);
      }
    }
    if ($status === 'RUSAK') {
      if ($logout_disp === '-' && !empty($hist['updated_at'])) {
        $logout_disp = $hist['updated_at'];
      }
      if ($login_disp === '-' && $logout_disp !== '-' && !empty($uptime) && $uptime !== '0s') {
        $u_sec = uptime_to_seconds($uptime);
        if ($u_sec > 0) {
          $login_disp = date('Y-m-d H:i:s', strtotime($logout_disp) - $u_sec);
        }
      }
    }
    if ($login_disp !== '-' && strtotime($login_disp) !== false && date('Y', strtotime($login_disp)) < 2000) {
      $login_disp = '-';
    }
    if ($logout_disp !== '-' && strtotime($logout_disp) !== false && date('Y', strtotime($logout_disp)) < 2000) {
      $logout_disp = '-';
    }
    if ($logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00' && !empty($hist['updated_at'])) {
      $logout_disp = merge_date_time($logout_disp, $hist['updated_at']);
    }
    if ($status === 'TERPAKAI' && $logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00') {
      if (!empty($hist['updated_at'])) {
        $logout_disp = $hist['updated_at'];
      } elseif (!empty($uptime) && !empty($login_disp) && $login_disp !== '-') {
        $ts_login = strtotime($login_disp);
        $sec_up = uptime_to_seconds($uptime);
        if ($ts_login && $sec_up > 0) {
          $logout_disp = date('Y-m-d H:i:s', $ts_login + $sec_up);
        }
      }
    }

    $relogin_flag = ((int)($hist['login_count'] ?? 0) > 1);
    $relogin_count = (int)($hist['login_count'] ?? 0);
    $first_login_disp = $first_login_real ?? ($hist['first_login_real'] ?? '-');
    $last_used_disp = $hist['last_login_real'] ?? ($logout_disp !== '-' ? $logout_disp : ($login_disp !== '-' ? $login_disp : ($hist['first_login_real'] ?? '-')));
    if ($status === 'ONLINE' && $login_disp !== '-') {
      $last_used_disp = $login_disp;
    }
    $last_used_filter = $hist['last_login_real'] ?? ($hist['logout_time_real'] ?? ($hist['login_time_real'] ?? ($hist['first_login_real'] ?? '-')));
    if ($status === 'ONLINE' && !empty($hist['login_time_real'])) {
      $last_used_filter = $hist['login_time_real'];
    }

    // Filter tanggal (harian/bulanan/tahunan) memakai last_used (selalu aktif)
    if ($req_show !== 'semua' && !empty($filter_date)) {
      if ($status === 'READY') {
        if (!$is_print) {
          $is_today = ($filter_date === date('Y-m-d') && $req_show === 'harian');
          if (!$is_today && $req_status === 'all') {
            continue;
          }
        }
      } else {
        $date_key = normalize_date_key($last_used_filter, $req_show);
        if ($date_key === '' || $date_key !== $filter_date) {
          continue;
        }
        if ($filtering_by_date) {
          $has_transactions_in_filter = true;
        }
      }
    }

    // Filter status
    if ($req_status == 'ready' && $status !== 'READY') continue;
    if ($req_status == 'all' && $status === 'READY') continue;
    if ($req_status == 'online' && $status !== 'ONLINE') continue;
    if ($req_status == 'used' && !in_array($status, ['TERPAKAI','RETUR','RUSAK'])) continue;
    if ($req_status == 'rusak' && $status !== 'RUSAK') continue;
    if ($req_status == 'retur' && $status !== 'RETUR') continue;
    if ($req_status == 'invalid') continue;

    if ($debug_mode && count($debug_rows) < 10) {
      $debug_rows[] = [
        'name' => $name,
        'status' => $status,
        'bytes_total' => $bytes_total,
        'bytes_active' => $bytes_active,
        'bytes_hist' => $hist['last_bytes'] ?? 0,
        'uptime_user' => $uptime_user,
        'uptime_active' => $uptime_active,
        'uptime_hist' => $hist['last_uptime'] ?? '',
        'login' => $login_time_real,
        'logout' => $logout_time_real
      ];
    }
    $retur_ref_user = '';
    if ($is_retur) {
      $retur_ref_user = extract_retur_user_from_ref($comment);
      if ($retur_ref_user === '' && $hist && !empty($hist['raw_comment'])) {
        $retur_ref_user = extract_retur_user_from_ref($hist['raw_comment']);
      }
      if ($retur_ref_user === '') {
        $retur_ref_raw = extract_retur_ref($comment);
        if ($retur_ref_raw === '' && $hist && !empty($hist['raw_comment'])) {
          $retur_ref_raw = extract_retur_ref($hist['raw_comment']);
        }
        if ($retur_ref_raw !== '') {
          $retur_ref_user = trim(preg_replace('/^Retur\s*Ref\s*:/i', '', $retur_ref_raw));
        }
      }
      if (stripos($retur_ref_user, 'vc-') === 0) {
        $retur_ref_user = substr($retur_ref_user, 3);
      }
    }

    $profile_label_display = normalize_profile_label($u['profile'] ?? '');
    if ($profile_label_display === '') {
      $profile_hist_fallback = resolve_profile_from_history($comment, $hist['validity'] ?? '', $uptime);
      $profile_label_display = normalize_profile_label($profile_hist_fallback);
    }
    if ($profile_label_display === '' && in_array($profile_kind_final, ['10', '30'], true)) {
      $profile_label_display = $profile_kind_final . ' Menit';
    }
    if ($profile_label_display === '') {
      $profile_label_display = 'Lainnya';
    }

    $display_data[] = [
      'uid' => $u['.id'] ?? '',
        'name' => $name,
        'profile' => $profile_label_display,
        'profile_kind' => $profile_kind,
        'blok' => $f_blok,
        'ip' => $f_ip,
        'mac' => $f_mac,
      'comment' => $comment,
        'comment_rusak' => $comment_rusak ? 1 : 0,
        'is_disabled' => ($disabled === 'true') ? 1 : 0,
        'first_login' => $first_login_disp,
        'retur_ref' => $is_retur ? extract_retur_ref($comment) : '',
        'retur_ref_user' => $is_retur ? ($retur_ref_user !== '' ? $retur_ref_user : '-') : '',
        'uptime' => $uptime,
        'bytes' => $bytes,
        'status' => $status,
        'login_time' => $login_disp,
        'logout_time' => $logout_disp,
        'last_used' => $last_used_disp,
        'relogin' => $relogin_flag,
        'relogin_count' => $relogin_count
    ];
}
$profile_totals = [];
if ($debug_mode) {
  $profile_totals = [
    '10' => ['count' => 0, 'ready' => 0, 'online' => 0, 'used' => 0, 'rusak' => 0, 'retur' => 0, 'invalid' => 0],
    '30' => ['count' => 0, 'ready' => 0, 'online' => 0, 'used' => 0, 'rusak' => 0, 'retur' => 0, 'invalid' => 0],
    'other' => ['count' => 0, 'ready' => 0, 'online' => 0, 'used' => 0, 'rusak' => 0, 'retur' => 0, 'invalid' => 0]
  ];
  foreach ($display_data as $row) {
    $kind = $row['profile_kind'] ?? detect_profile_kind_unified($row['profile'] ?? '', $row['comment'] ?? '', $row['blok'] ?? '', $row['uptime'] ?? '');
    if (!isset($profile_totals[$kind])) {
      $kind = 'other';
    }
    $status_key = strtolower($row['status'] ?? 'ready');
    if ($status_key === 'terpakai') $status_key = 'used';
    $profile_totals[$kind]['count']++;
    if (isset($profile_totals[$kind][$status_key])) {
      $profile_totals[$kind][$status_key]++;
    }
  }
}
$API->disconnect();

// READY tetap ditampilkan walau ada transaksi pada tanggal filter

// Sorting (before pagination)
$status_rank = [
  'ONLINE' => 0,
  'TERPAKAI' => 1,
  'RUSAK' => 2,
  'RETUR' => 3,
  'READY' => 4,
  'INVALID' => 5
];
$to_ts = function($dt) {
  if (empty($dt) || $dt === '-') return 0;
  $ts = strtotime($dt);
  return $ts ? $ts : 0;
};
if (!empty($display_data)) {
  usort($display_data, function($a, $b) use ($req_status, $status_rank, $to_ts) {
    $sa = $a['status'] ?? 'READY';
    $sb = $b['status'] ?? 'READY';

    if ($req_status === 'used') {
      $ta = $to_ts($a['logout_time'] ?? '-');
      $tb = $to_ts($b['logout_time'] ?? '-');
      if ($ta !== $tb) return $tb <=> $ta;
      $la = $to_ts($a['login_time'] ?? '-');
      $lb = $to_ts($b['login_time'] ?? '-');
      if ($la !== $lb) return $lb <=> $la;
      return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }

    if ($req_status === 'online') {
      $la = $to_ts($a['login_time'] ?? '-');
      $lb = $to_ts($b['login_time'] ?? '-');
      if ($la !== $lb) return $lb <=> $la;
      return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }

    if ($req_status === 'rusak' || $req_status === 'retur') {
      $ta = $to_ts($a['logout_time'] ?? '-');
      $tb = $to_ts($b['logout_time'] ?? '-');
      if ($ta !== $tb) return $tb <=> $ta;
      $la = $to_ts($a['login_time'] ?? '-');
      $lb = $to_ts($b['login_time'] ?? '-');
      if ($la !== $lb) return $lb <=> $la;
      return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }

    if ($req_status === 'ready') {
      return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }

    $ra = $status_rank[$sa] ?? 99;
    $rb = $status_rank[$sb] ?? 99;
    if ($ra !== $rb) return $ra <=> $rb;
    $ta = $to_ts($a['last_used'] ?? '-');
    $tb = $to_ts($b['last_used'] ?? '-');
    if ($ta !== $tb) return $tb <=> $ta;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
}

// Pagination (after filtering)
$total_items = count($display_data);
if (!$is_print) {
  $per_page = isset($_GET['per_page']) ? max(10, min(200, (int)$_GET['per_page'])) : 50;
  $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $total_pages = $per_page > 0 ? (int)ceil($total_items / $per_page) : 1;
  if ($page < 1) $page = 1;
  if ($total_pages > 0 && $page > $total_pages) $page = $total_pages;
  $offset = ($page - 1) * $per_page;
  $display_data = array_slice($display_data, $offset, $per_page);

  $pagination_params = $_GET;
  unset($pagination_params['page']);
  $pagination_base = './?' . http_build_query($pagination_params);
} else {
  $per_page = $total_items;
  $page = 1;
  $total_pages = $total_items > 0 ? 1 : 0;
  $offset = 0;
  $pagination_base = '';
}

if ($is_ajax) {
  ob_start();
  if (count($display_data) > 0) {
    foreach ($display_data as $u) {
      $keep_params = '&profile=' . urlencode($req_prof) .
        '&comment=' . urlencode($req_comm) .
        '&status=' . urlencode($req_status) .
        '&q=' . urlencode($req_search) .
        '&show=' . urlencode($req_show) .
        '&date=' . urlencode($filter_date);
      ?>
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
            <?php if ($is_used): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
            <?php elseif ($is_online): ?>
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
          <?php if($u['uid'] || $can_mark_rusak || $is_rusak): ?>
            <?php if ($is_rusak): ?>
              <button type="button" class="btn-act btn-act-retur" onclick="actionRequest('./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Retur"><i class="fa fa-exchange"></i></button>
              <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rollback"><i class="fa fa-undo"></i></button>
              <?php if ($can_enable): ?>
                <button type="button" class="btn-act btn-act-enable" onclick="actionRequest('./?hotspot=users&action=enable&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Enable Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Enable"><i class="fa fa-check"></i></button>
              <?php endif; ?>
            <?php elseif ($is_ready): ?>
              <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=disable&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&session=<?= $session ?><?= $keep_params ?>','Disable Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Disable"><i class="fa fa-ban"></i></button>
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
      <?php
    }
  } else {
    ?><tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data.</td></tr><?php
  }
  $rows_html = ob_get_clean();

  ob_start();
  if ($total_pages > 1) {
    ?>
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
    <?php
  }
  $pagination_html = ob_get_clean();

  header('Content-Type: application/json');
  echo json_encode([
    'rows_html' => $rows_html,
    'pagination_html' => $pagination_html,
    'total_label' => 'Total: ' . $total_items . ' Items'
  ]);
  exit();
}

if ($debug_mode && !$is_ajax) {
  $logDir = __DIR__ . '/../../logs';
  if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
  $logFile = $logDir . '/users_debug.log';
  foreach ($debug_rows as $row) {
    $line = date('Y-m-d H:i:s') . " | {$row['name']} | {$row['status']} | bytes_total={$row['bytes_total']} bytes_active={$row['bytes_active']} bytes_hist={$row['bytes_hist']} | uptime_user={$row['uptime_user']} uptime_active={$row['uptime_active']} uptime_hist={$row['uptime_hist']} | login={$row['login']} logout={$row['logout']}\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
  }
}
