<?php
$_auto_rusak = dirname(__DIR__, 2) . '/include/auto_rusak.php';
if (file_exists($_auto_rusak)) {
  require_once $_auto_rusak;
}
// Helper: Format comment untuk display
if (!function_exists('format_comment_display')) {
  function format_comment_display($comment) {
    if (empty($comment)) return '-';
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}:\d{2})\s*\|\s*([^|]+)/i', $comment, $m)) {
      $date_formatted = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}";
      $blok = trim($m[5]);
      return "Valid {$date_formatted} | {$blok}";
    }
    $short = preg_replace('/\|\s*IP:[^\|]+/', '', $comment);
    $short = preg_replace('/\|\s*MAC:[^\|]+/', '', $short);
    return trim($short);
  }
}

if (!function_exists('env_get_value')) {
  function env_get_value($path, $default = null) {
    $cfg = $GLOBALS['env_config'] ?? [];
    if (!is_array($cfg) || $path === '') return $default;
    $parts = explode('.', (string)$path);
    foreach ($parts as $p) {
      if (!is_array($cfg) || !array_key_exists($p, $cfg)) return $default;
      $cfg = $cfg[$p];
    }
    return $cfg;
  }
}

if (!function_exists('normalize_block_key')) {
  function normalize_block_key($raw) {
    $raw = strtoupper((string)$raw);
    $raw = preg_replace('/^BLOK/i', '', $raw);
    $raw = preg_replace('/[^A-Z0-9]/', '', $raw);
    return $raw;
  }
}

if (!function_exists('resolve_block_alias')) {
  function resolve_block_alias($block_name) {
    $aliases = env_get_value('blok.aliases', []);
    if (!is_array($aliases) || empty($aliases)) return $block_name;
    $key = normalize_block_key($block_name);
    foreach ($aliases as $from => $to) {
      if (normalize_block_key($from) === $key) {
        return (string)$to;
      }
    }
    return $block_name;
  }
}

if (!function_exists('normalize_profile_key')) {
  function normalize_profile_key($profile) {
    $raw = strtolower(trim((string)$profile));
    if ($raw === '') return '';
    $raw = preg_replace('/\s+/', '', $raw);
    return $raw;
  }
}

if (!function_exists('resolve_profile_alias')) {
  function resolve_profile_alias($profile) {
    $aliases = env_get_value('pricing.profile_aliases', []);
    if (!is_array($aliases) || empty($aliases)) return $profile;
    $key = normalize_profile_key($profile);
    foreach ($aliases as $from => $to) {
      if (normalize_profile_key($from) === $key) {
        return (string)$to;
      }
    }
    return $profile;
  }
}

if (!function_exists('get_status_priority_list')) {
  function get_status_priority_list() {
    $priority = env_get_value('report.status_priority', []);
    if (!is_array($priority) || empty($priority)) {
      $priority = ['retur', 'rusak', 'invalid', 'normal'];
    }
    $out = [];
    foreach ($priority as $p) {
      $p = strtolower(trim((string)$p));
      if ($p === '') continue;
      $out[] = $p;
    }
    if (!in_array('normal', $out, true)) $out[] = 'normal';
    return $out;
  }
}

if (!function_exists('resolve_status_priority')) {
  function resolve_status_priority($flags, $fallback = 'READY') {
    $flags = is_array($flags) ? $flags : [];
    foreach (get_status_priority_list() as $p) {
      if (!empty($flags[$p])) return strtoupper($p);
    }
    return $fallback;
  }
}

if (!function_exists('format_customer_name')) {
  function format_customer_name($name) {
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    if ($name === '') return '';
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    if (function_exists('mb_convert_case')) {
      return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords($lower);
  }
}

// Helper: Ekstrak nama blok dari comment
if (!function_exists('extract_blok_name')) {
  function extract_blok_name($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]*\s*([A-Za-z0-9]+)(?:\s*[-_]*\s*([0-9]+))?/i', $comment, $m)) {
      $raw = strtoupper($m[1] . ($m[2] ?? ''));
      $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
      $raw = preg_replace('/^BLOK/', '', $raw);
      if (preg_match('/^([A-Z]+)/', $raw, $mx)) {
        $raw = $mx[1];
      }
      if ($raw !== '') {
        $final = 'BLOK-' . $raw;
        $alias = resolve_block_alias($final);
        $alias_key = normalize_block_key($alias);
        if ($alias_key !== '') return 'BLOK-' . $alias_key;
        return $final;
      }
    }
    if (preg_match('/\b([A-Z](?:[-\s]?\d{1,2})?)\b/', $comment, $m)) {
      $candidate = strtoupper(trim($m[1]));
      if (strlen($candidate) <= 5) {
        $candidate = preg_replace('/\s+/', '', $candidate);
        $candidate = preg_replace('/^-+/', '', $candidate);
        $final = 'BLOK-' . $candidate;
        $alias = resolve_block_alias($final);
        $alias_key = normalize_block_key($alias);
        if ($alias_key !== '') return 'BLOK-' . $alias_key;
        return $final;
      }
    }
    return '';
  }
}

// Helper: Normalisasi label blok
if (!function_exists('normalize_blok_label')) {
  function normalize_blok_label($blok) {
    $raw = strtoupper(trim((string)$blok));
    if ($raw === '') return '';
    $raw = preg_replace('/[^A-Z0-9]/', '', $raw);
    $raw = preg_replace('/^BLOK/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
      return $m[1];
    }
    return $raw;
  }
}

// Helper: Normalisasi label profile
if (!function_exists('normalize_profile_label')) {
  function normalize_profile_label($profile) {
    $profile = resolve_profile_alias($profile);
    $p = trim((string)$profile);
    if ($p === '') return '';
    $pl = strtolower($p);
    if ($pl === 'lainnya' || $pl === 'other' || $pl === 'default' || $pl === '-') return '';
    if (preg_match('/\b(10|30)\s*(menit|m)\b/i', $p, $m)) {
      return $m[1] . ' Menit';
    }
    $p = preg_replace('/\s*menit\b/i', ' Menit', $p);
    return $p;
  }
}

if (!function_exists('is_vip_comment')) {
  function is_vip_comment($comment) {
    $c = trim((string)$comment);
    if ($c === '') return false;
    return (bool)preg_match('/\bvip\b|\bpengelola\b/i', $c);
  }
}

// Helper: Ekstrak IP/MAC dari comment (format: IP:... | MAC:...)
if (!function_exists('extract_ip_mac_from_comment')) {
  function extract_ip_mac_from_comment($comment) {
    $ip = '';
    $mac = '';
    if (!empty($comment)) {
      if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) {
        $ip = trim($m[1]);
      }
      if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) {
        $mac = trim($m[1]);
      }
    }
    return ['ip' => $ip, 'mac' => $mac];
  }
}

// Helper: Konversi uptime (1w2d3h4m5s) ke detik
if (!function_exists('uptime_to_seconds')) {
  function uptime_to_seconds($uptime) {
    if (empty($uptime)) return 0;
    if ($uptime === '0s') return 0;
    $total = 0;
    if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $uptime, $m, PREG_SET_ORDER)) {
      foreach ($m as $part) {
        $val = (int)$part[1];
        switch (strtolower($part[2])) {
          case 'w': $total += $val * 7 * 24 * 3600; break;
          case 'd': $total += $val * 24 * 3600; break;
          case 'h': $total += $val * 3600; break;
          case 'm': $total += $val * 60; break;
          case 's': $total += $val; break;
        }
      }
    }
    return $total;
  }
}

// Helper: Konversi detik ke uptime RouterOS
if (!function_exists('seconds_to_uptime')) {
  function seconds_to_uptime($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0s';
    $parts = [];
    $weeks = intdiv($seconds, 7 * 24 * 3600); $seconds %= 7 * 24 * 3600;
    $days = intdiv($seconds, 24 * 3600); $seconds %= 24 * 3600;
    $hours = intdiv($seconds, 3600); $seconds %= 3600;
    $mins = intdiv($seconds, 60); $seconds %= 60;
    if ($weeks) $parts[] = $weeks . 'w';
    if ($days) $parts[] = $days . 'd';
    if ($hours) $parts[] = $hours . 'h';
    if ($mins) $parts[] = $mins . 'm';
    if ($seconds || empty($parts)) $parts[] = $seconds . 's';
    return implode('', $parts);
  }
}

// Helper: Ambil batas rusak/retur per profil
if (!function_exists('resolve_rusak_limits')) {
  function resolve_rusak_limits($profile) {
    $p = strtolower((string)$profile);
    $limits = ['uptime' => 300, 'bytes' => 5 * 1024 * 1024, 'uptime_label' => '5 menit', 'bytes_label' => '5MB'];
    if (preg_match('/\b10\s*(menit|m)\b|10menit/i', $p)) {
      $limits['uptime'] = 180;
      $limits['uptime_label'] = '3 menit';
    }
    return $limits;
  }
}

// Helper: Format tanggal ke d-m-Y H:i:s
if (!function_exists('format_dmy')) {
  function format_dmy($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i:s', $ts);
  }
}

// Helper: Format tanggal ke d-m-Y
if (!function_exists('format_dmy_date')) {
  function format_dmy_date($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
  }
}

// Helper: Normalisasi datetime ke Y-m-d H:i:s
if (!function_exists('normalize_dt')) {
  function normalize_dt($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return '';
    return date('Y-m-d H:i:s', $ts);
  }
}

// Helper: Ekstrak datetime dari comment (format umum MikroTik)
if (!function_exists('extract_datetime_from_comment')) {
  function extract_datetime_from_comment($comment) {
    if (empty($comment)) return '';
    // Support format: "Audit: RUSAK dd/mm/yy YYYY-mm-dd HH:MM:SS"
    if (preg_match('/RUSAK\s*\d{2}\/\d{2}\/\d{2}\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/i', $comment, $m)) {
      return $m[1];
    }
    // Support format: mm.dd.yy (contoh: 01.20.26)
    if (preg_match('/\b(\d{2})[\.\/-](\d{2})[\.\/-](\d{2})\b/', $comment, $m)) {
      $yy = (int)$m[3];
      $year = $yy < 70 ? (2000 + $yy) : (1900 + $yy);
      return sprintf('%04d-%02d-%02d 00:00:00', $year, (int)$m[1], (int)$m[2]);
    }
    // Support format: "Valid dd-mm-YYYY HH:MM:SS" (or with colon)
    if (preg_match('/\bValid\s*:?(\d{2})-(\d{2})-(\d{4})\s+(\d{2}:\d{2}:\d{2})/i', $comment, $m)) {
      return $m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4];
    }
    // Support format: "Valid dd-mm-YYYY" (tanpa jam)
    if (preg_match('/\bValid\s*:?(\d{2})-(\d{2})-(\d{4})\b/i', $comment, $m)) {
      return $m[3] . '-' . $m[2] . '-' . $m[1] . ' 00:00:00';
    }
    // Fallback: parse first segment before '|'
    $first = trim(explode('|', $comment)[0] ?? '');
    if ($first === '') return '';
    $ts = strtotime($first);
    if ($ts === false) return '';
    return date('Y-m-d H:i:s', $ts);
  }
}

// Helper: gabungkan tanggal dari $dateStr dengan jam dari $timeStr
if (!function_exists('merge_date_time')) {
  function merge_date_time($dateStr, $timeStr) {
    if (empty($dateStr) || empty($timeStr)) return $dateStr;
    $date = date('Y-m-d', strtotime($dateStr));
    $time = date('H:i:s', strtotime($timeStr));
    return $date . ' ' . $time;
  }
}

// Helper: Ambil riwayat user dari DB (standalone)
if (!function_exists('get_user_history_from_db')) {
  function get_user_history_from_db($db, $name) {
    if (!$db) return null;
    try {
      $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_status, first_login_real, last_login_real, last_uptime, last_bytes, raw_comment FROM login_history WHERE username = :u LIMIT 1");
      $stmt->execute([':u' => $name]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      return null;
    }
  }
}

// Helper: Ambil meta voucher (nama/kamar/blok/profile/usage)
if (!function_exists('get_voucher_meta_info')) {
  function get_voucher_meta_info($db, $voucher_code) {
    $info = [
      'customer_name' => '',
      'room_name' => '',
      'blok_name' => '',
      'profile' => '',
      'bytes' => 0,
      'uptime' => '',
      'last_status' => ''
    ];
    if (!$db) return $info;
    $voucher_code = trim((string)$voucher_code);
    if ($voucher_code === '') return $info;

    try {
      $stmt = $db->prepare("SELECT customer_name, room_name, blok_name, validity, last_bytes, last_uptime, last_status, raw_comment FROM login_history WHERE username = :u LIMIT 1");
      $stmt->execute([':u' => $voucher_code]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($row) {
        $cn = trim((string)($row['customer_name'] ?? ''));
        $rn = trim((string)($row['room_name'] ?? ''));
        $bn = trim((string)($row['blok_name'] ?? ''));
        $pv = trim((string)($row['validity'] ?? ''));
        if ($info['customer_name'] === '' && $cn !== '') $info['customer_name'] = $cn;
        if ($info['room_name'] === '' && $rn !== '') $info['room_name'] = $rn;
        if ($info['blok_name'] === '' && $bn !== '') $info['blok_name'] = $bn;
        if ($info['profile'] === '' && $pv !== '') {
          $info['profile'] = function_exists('normalize_profile_label') ? normalize_profile_label($pv) : $pv;
        }
        if ($info['blok_name'] === '' && !empty($row['raw_comment']) && function_exists('extract_blok_name')) {
          $info['blok_name'] = extract_blok_name($row['raw_comment']);
        }
        $info['bytes'] = (int)($row['last_bytes'] ?? 0);
        $info['uptime'] = (string)($row['last_uptime'] ?? '');
        $info['last_status'] = (string)($row['last_status'] ?? '');
      }
    } catch (Exception $e) {
      // silent
    }

    try {
      if ($info['customer_name'] === '' || $info['room_name'] === '' || $info['blok_name'] === '' || $info['profile'] === '') {
        $stmt = $db->prepare("SELECT customer_name, room_name, blok_name, profile_name FROM login_meta_queue WHERE voucher_code = :u ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':u' => $voucher_code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
          $cn = trim((string)($row['customer_name'] ?? ''));
          $rn = trim((string)($row['room_name'] ?? ''));
          $bn = trim((string)($row['blok_name'] ?? ''));
          $pv = trim((string)($row['profile_name'] ?? ''));
          if ($info['customer_name'] === '' && $cn !== '') $info['customer_name'] = $cn;
          if ($info['room_name'] === '' && $rn !== '') $info['room_name'] = $rn;
          if ($info['blok_name'] === '' && $bn !== '') $info['blok_name'] = $bn;
          if ($info['profile'] === '' && $pv !== '') {
            $info['profile'] = function_exists('normalize_profile_label') ? normalize_profile_label($pv) : $pv;
          }
        }
      }
    } catch (Exception $e) {
      // silent
    }

    try {
      if ($info['customer_name'] === '' || $info['room_name'] === '' || $info['blok_name'] === '' || $info['profile'] === '') {
        $stmt = $db->prepare("SELECT customer_name, room_name, blok_name, validity FROM sales_history WHERE username = :u ORDER BY sale_date DESC, raw_date DESC LIMIT 1");
        $stmt->execute([':u' => $voucher_code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
          $cn = trim((string)($row['customer_name'] ?? ''));
          $rn = trim((string)($row['room_name'] ?? ''));
          $bn = trim((string)($row['blok_name'] ?? ''));
          $pv = trim((string)($row['validity'] ?? ''));
          if ($info['customer_name'] === '' && $cn !== '') $info['customer_name'] = $cn;
          if ($info['room_name'] === '' && $rn !== '') $info['room_name'] = $rn;
          if ($info['blok_name'] === '' && $bn !== '') $info['blok_name'] = $bn;
          if ($info['profile'] === '' && $pv !== '') {
            $info['profile'] = function_exists('normalize_profile_label') ? normalize_profile_label($pv) : $pv;
          }
        }
      }
    } catch (Exception $e) {
      // silent
    }

    try {
      if ($info['customer_name'] === '' || $info['room_name'] === '' || $info['blok_name'] === '' || $info['profile'] === '') {
        $stmt = $db->prepare("SELECT customer_name, room_name, blok_name, validity FROM live_sales WHERE username = :u ORDER BY sale_date DESC, raw_date DESC LIMIT 1");
        $stmt->execute([':u' => $voucher_code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
          $cn = trim((string)($row['customer_name'] ?? ''));
          $rn = trim((string)($row['room_name'] ?? ''));
          $bn = trim((string)($row['blok_name'] ?? ''));
          $pv = trim((string)($row['validity'] ?? ''));
          if ($info['customer_name'] === '' && $cn !== '') $info['customer_name'] = $cn;
          if ($info['room_name'] === '' && $rn !== '') $info['room_name'] = $rn;
          if ($info['blok_name'] === '' && $bn !== '') $info['blok_name'] = $bn;
          if ($info['profile'] === '' && $pv !== '') {
            $info['profile'] = function_exists('normalize_profile_label') ? normalize_profile_label($pv) : $pv;
          }
        }
      }
    } catch (Exception $e) {
      // silent
    }

    return $info;
  }
}

// Helper: Limit VIP harian
if (!function_exists('ensure_vip_daily_table')) {
  function ensure_vip_daily_table($db) {
    if (!$db) return false;
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS vip_daily_quota (
        date_key TEXT PRIMARY KEY,
        count INTEGER DEFAULT 0,
        updated_at DATETIME
      )");
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
}

if (!function_exists('get_vip_daily_usage')) {
  function get_vip_daily_usage($db, $date_key) {
    if (!$db || $date_key === '') return 0;
    if (!ensure_vip_daily_table($db)) return 0;
    try {
      $stmt = $db->prepare("SELECT count FROM vip_daily_quota WHERE date_key = :d LIMIT 1");
      $stmt->execute([':d' => $date_key]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return (int)($row['count'] ?? 0);
    } catch (Exception $e) {
      return 0;
    }
  }
}

if (!function_exists('increment_vip_daily_usage')) {
  function increment_vip_daily_usage($db, $date_key) {
    if (!$db || $date_key === '') return false;
    if (!ensure_vip_daily_table($db)) return false;
    try {
      $stmt = $db->prepare("INSERT INTO vip_daily_quota(date_key, count, updated_at)
        VALUES(:d, 1, CURRENT_TIMESTAMP)
        ON CONFLICT(date_key) DO UPDATE SET count = count + 1, updated_at = CURRENT_TIMESTAMP");
      $stmt->execute([':d' => $date_key]);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
}

if (!function_exists('decrement_vip_daily_usage')) {
  function decrement_vip_daily_usage($db, $date_key) {
    if (!$db || $date_key === '') return false;
    if (!ensure_vip_daily_table($db)) return false;
    try {
      $stmt = $db->prepare("INSERT INTO vip_daily_quota(date_key, count, updated_at)
        VALUES(:d, 0, CURRENT_TIMESTAMP)
        ON CONFLICT(date_key) DO UPDATE SET count = CASE WHEN count > 0 THEN count - 1 ELSE 0 END, updated_at = CURRENT_TIMESTAMP");
      $stmt->execute([':d' => $date_key]);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
}

if (!function_exists('set_vip_daily_usage')) {
  function set_vip_daily_usage($db, $date_key, $count) {
    if (!$db || $date_key === '') return false;
    if (!ensure_vip_daily_table($db)) return false;
    $count = max(0, (int)$count);
    try {
      $stmt = $db->prepare("INSERT INTO vip_daily_quota(date_key, count, updated_at)
        VALUES(:d, :c, CURRENT_TIMESTAMP)
        ON CONFLICT(date_key) DO UPDATE SET count = :c, updated_at = CURRENT_TIMESTAMP");
      $stmt->execute([':d' => $date_key, ':c' => $count]);
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
}

// Helper: Total uptime dari event login (standalone)
if (!function_exists('get_cumulative_uptime_from_events_db')) {
  function get_cumulative_uptime_from_events_db($db, $username, $date_key = '', $fallback_logout = '', $max_session_seconds = 0) {
    if (!$db || empty($username)) return 0;
    $params = [':u' => $username];
    $where = "username = :u";
    if (!empty($date_key)) {
      $where .= " AND date_key = :d";
      $params[':d'] = $date_key;
    }
    try {
      $stmt = $db->prepare("SELECT login_time, logout_time FROM login_events WHERE $where ORDER BY seq ASC, id ASC");
      foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
      }
      $stmt->execute();
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $total = 0;
      $fallback_ts = !empty($fallback_logout) ? strtotime($fallback_logout) : 0;
      $row_count = count($rows);
      for ($i = 0; $i < $row_count; $i++) {
        $login_time = $rows[$i]['login_time'] ?? '';
        $logout_time = $rows[$i]['logout_time'] ?? '';
        if (empty($login_time)) continue;
        $login_ts = strtotime($login_time);
        if (!$login_ts) continue;

        $logout_missing = empty($logout_time);
        $logout_ts = !$logout_missing ? strtotime($logout_time) : 0;
        if (!$logout_ts) {
          $next_login_ts = 0;
          for ($j = $i + 1; $j < $row_count; $j++) {
            $next_login = $rows[$j]['login_time'] ?? '';
            if ($next_login !== '') {
              $next_login_ts = strtotime($next_login);
              if ($next_login_ts) break;
            }
          }
          if ($next_login_ts && $next_login_ts >= $login_ts) {
            $logout_ts = $next_login_ts;
          } elseif ($fallback_ts && $fallback_ts >= $login_ts) {
            $logout_ts = $fallback_ts;
          }
        }

        if ($logout_missing && $max_session_seconds > 0) {
          $cap_ts = $login_ts + (int)$max_session_seconds;
          if (!$logout_ts || $logout_ts > $cap_ts) {
            $logout_ts = $cap_ts;
          }
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
}

// Helper: Ambil event relogin (standalone)
if (!function_exists('get_relogin_events_db')) {
  function get_relogin_events_db($db, $username, $date_key = '') {
    if (!$db || empty($username) || empty($date_key)) return [];
    try {
      $stmt = $db->prepare("SELECT login_time, logout_time, seq FROM login_events WHERE username = :u AND date_key = :d ORDER BY seq ASC, id ASC");
      $stmt->execute([':u' => $username, ':d' => $date_key]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      return [];
    }
  }
}

// Helper: Ekstrak sumber retur dari comment
if (!function_exists('extract_retur_ref')) {
  function extract_retur_ref($comment) {
    if (empty($comment)) return '';
    if (preg_match('/Retur\s*Ref\s*:\s*([^|]+)/i', $comment, $m)) {
      return trim($m[1]);
    }
    return '';
  }
}

// Helper: Ekstrak username asal retur dari comment
if (!function_exists('extract_retur_user_from_ref')) {
  function extract_retur_user_from_ref($comment) {
    $ref = extract_retur_ref($comment);
    if ($ref === '') return '';
    $ref = trim($ref);
    if (preg_match('/\bvc-([A-Za-z0-9._-]+)/', $ref, $m)) {
      return $m[1];
    }
    if (preg_match('/^([A-Za-z0-9._-]+)/', $ref, $m)) {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[1])) {
        return $m[1];
      }
    }
    return '';
  }
}

// Helper: Normalisasi param blok dari dropdown (hapus suffix count seperti ":1")
if (!function_exists('normalize_blok_param')) {
  function normalize_blok_param($blok) {
    if (empty($blok)) return $blok;
    if (preg_match('/^(.+?):\d+$/', $blok, $m)) {
      return $m[1];
    }
    return $blok;
  }
}

// Helper: Normalisasi tanggal untuk filter (harian/bulanan/tahunan)
if (!function_exists('normalize_date_key')) {
  function normalize_date_key($dateTime, $mode) {
    if (empty($dateTime)) return '';
    $ts = strtotime($dateTime);
    if ($ts === false) return '';
    if ($mode === 'bulanan') return date('Y-m', $ts);
    if ($mode === 'tahunan') return date('Y', $ts);
    return date('Y-m-d', $ts);
  }
}

// Helper: Generator User Baru (retur)
if (!function_exists('gen_user')) {
  function gen_user($profile, $comment_ref, $orig_user = '') {
    $blok = '';
    if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $comment_ref, $m)) $blok = $m[1];
    if ($blok === '') {
      $blok = extract_blok_name($comment_ref);
    }
    if ($blok === '' && $orig_user !== '' && function_exists('get_user_history')) {
      $orig_hist = get_user_history($orig_user);
      if ($orig_hist) {
        $hist_blok = trim((string)($orig_hist['blok_name'] ?? ''));
        if ($hist_blok === '' && !empty($orig_hist['raw_comment'])) {
          $hist_blok = extract_blok_name($orig_hist['raw_comment']);
        }
        if ($hist_blok !== '') {
          $blok = $hist_blok;
        }
      }
    }
    // Hindari nested Retur Ref
    $clean_ref = $comment_ref;
    $clean_ref = preg_replace('/\(Retur\)/i', '', $clean_ref);
    $clean_ref = preg_replace('/Retur\s*Ref\s*:[^|]+/i', '', $clean_ref);
    $clean_ref = preg_replace('/\s+\|\s+/', ' | ', $clean_ref);
    $clean_ref = trim($clean_ref);
    $char = "abcdefghijklmnopqrstuvwxyz0123456789"; 
    $user = substr(str_shuffle($char), 0, 6);
    $pass = $user;
    $blok_part = $blok != '' ? $blok . ' ' : '';
    $ref_user = trim((string)$orig_user);
    if ($ref_user !== '') {
      $ref_label = "Retur Ref:vc-{$ref_user}";
    } else {
      $ref_user_simple = extract_retur_user_from_ref($comment_ref);
      if ($ref_user_simple !== '') {
        $ref_label = "Retur Ref:vc-{$ref_user_simple}";
      } else {
        $ref_label = 'Retur Ref:' . substr(preg_replace('/[^A-Za-z0-9]/', '', $clean_ref), 0, 20);
      }
    }
    $new_comm = trim("{$blok_part}(Retur) Valid: {$ref_label} | Profile:{$profile}");
    return ['u'=>$user, 'p'=>$pass, 'c'=>$new_comm];
  }
}

if (!function_exists('log_ready_skip_users')) {
  function log_ready_skip_users($message) {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
      @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/ready_skip.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
  }
}

if (!function_exists('is_wartel_client')) {
  function is_wartel_client($comment, $hist_blok = '') {
    if (!empty($hist_blok)) return true;
    $blok = extract_blok_name($comment);
    if (!empty($blok)) return true;
    if (!empty($comment) && (stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false)) return true;
    if (!empty($comment) && stripos($comment, 'blok-') !== false) return true;
    return false;
  }
}

if (!function_exists('detect_profile_kind_summary')) {
  function detect_profile_kind_summary($profile) {
    $profile = resolve_profile_alias($profile);
    $p = strtolower((string)$profile);
    if (preg_match('/(\d+)\s*(menit|m|min)\b/i', $p, $m)) {
      return (string)((int)$m[1]);
    }
    if (preg_match('/(\d+)(menit|m)\b/i', $p, $m)) {
      return (string)((int)$m[1]);
    }
    if (preg_match('/(^|[^0-9])(10|30)([^0-9]|$)/', $p, $m)) {
      return (string)$m[2];
    }
    return 'other';
  }
}

if (!function_exists('detect_profile_kind_from_comment')) {
  function detect_profile_kind_from_comment($comment) {
    $c = strtolower((string)$comment);
    if (preg_match('/profile\s*:\s*(\d+)\s*(menit|m|min)?/i', $c, $m)) {
      return (string)((int)$m[1]);
    }
    if (preg_match('/(\d+)\s*(menit|m|min)\b/i', $c, $m)) {
      return (string)((int)$m[1]);
    }
    if (preg_match('/(^|[^0-9])(10|30)([^0-9]|$)/', $c, $m)) {
      return (string)$m[2];
    }
    return 'other';
  }
}

if (!function_exists('detect_profile_kind_unified')) {
  function detect_profile_kind_unified($profile, $comment, $blok, $uptime = '') {
    $kind = detect_profile_kind_summary($profile);
    if ($kind !== 'other') return $kind;

    $kind = detect_profile_kind_from_comment($comment);
    if ($kind !== 'other') return $kind;

    $combined = strtolower(trim((string)$comment . ' ' . (string)$blok));
    if (preg_match('/(^|[^0-9])(10|30)([^0-9]|$)/', $combined, $m)) return (string)$m[2];
    if (preg_match('/\b10\s*(menit|m|min)\b/', $combined)) return '10';
    if (preg_match('/\b30\s*(menit|m|min)\b/', $combined)) return '30';

    if (!empty($uptime) && $uptime !== '0s') {
      $sec = uptime_to_seconds($uptime);
      if ($sec >= 570 && $sec <= 660) return '10';
      if ($sec >= 1740 && $sec <= 1860) return '30';
    }

    return 'other';
  }
}

if (!function_exists('resolve_profile_from_history')) {
  function resolve_profile_from_history($comment, $validity = '', $uptime = '') {
    $validity = trim((string)$validity);
    if ($validity !== '') return $validity;

    $kind = detect_profile_kind_from_comment($comment);
    if ($kind !== 'other') return $kind . ' Menit';

    if (!empty($uptime) && $uptime !== '0s') {
      $sec = uptime_to_seconds($uptime);
      if ($sec >= 570 && $sec <= 660) return '10 Menit';
      if ($sec >= 1740 && $sec <= 1860) return '30 Menit';
    }

    if (preg_match('/profile\s*[:=]?\s*([a-z0-9]+)/i', (string)$comment, $m)) {
      return $m[1];
    }
    return '';
  }
}
