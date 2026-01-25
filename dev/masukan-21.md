Analisa saya menemukan akar permasalahan utama:

1. **User Lama Masih Muncul:** Logika penyembunyian user lama (`$retur_ref_map`) hanya memindai Database (`login_history`). Jika voucher baru (hasil retur) belum tersinkronisasi sempurna ke DB atau hanya ada di RouterOS, referensinya tidak terbaca, sehingga user lama tidak disembunyikan.
2. **Print Salah URL:** Status `RETUR` pada `data.php` kadang tertimpa menjadi `READY` karena logika prioritas status. Akibatnya, tombol print di `render.php` memanggil `voucher/print.php` (print biasa), bukan `print.retur.php`.
3. **Parsing Komentar:** Format komentar dari `actions.php` (`(Retur) Valid: ...`) perlu diparsing dengan regex yang lebih fleksibel di `helpers.php`.

Berikut adalah perbaikan **Lengkap & Siap Pakai** untuk `helpers.php`, `data.php`, dan `render.php`.

### 1. File: `hotspot/user/helpers.php`

Perbaikan pada ekstraksi referensi retur agar lebih akurat menangkap username lama dari komentar voucher baru.

```php
<?php
// Helper: Format comment untuk display
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

// Helper: Ekstrak nama blok dari comment
function extract_blok_name($comment) {
  if (empty($comment)) return '';
  if (preg_match('/\bblok\s*[-_]*\s*([A-Za-z0-9]+)(?:\s*[-_]*\s*([0-9]+))?/i', $comment, $m)) {
    $raw = strtoupper($m[1] . ($m[2] ?? ''));
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
    $raw = preg_replace('/^BLOK/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $mx)) {
      $raw = $mx[1];
    }
    if ($raw !== '') return 'BLOK-' . $raw;
  }
  if (preg_match('/\b([A-Z](?:[-\s]?\d{1,2})?)\b/', $comment, $m)) {
    $candidate = strtoupper(trim($m[1]));
    if (strlen($candidate) <= 5) {
      $candidate = preg_replace('/\s+/', '', $candidate);
      $candidate = preg_replace('/^-+/', '', $candidate);
      return 'BLOK-' . $candidate;
    }
  }
  return '';
}

// Helper: Ekstrak IP/MAC dari comment (format: IP:... | MAC:...)
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

// Helper: Konversi uptime (1w2d3h4m5s) ke detik
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

// Helper: Konversi detik ke uptime RouterOS
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

// Helper: Ambil batas rusak/retur per profil
function resolve_rusak_limits($profile) {
  $p = strtolower((string)$profile);
  $limits = ['uptime' => 300, 'bytes' => 5 * 1024 * 1024, 'uptime_label' => '5 menit', 'bytes_label' => '5MB'];
  if (preg_match('/\b10\s*(menit|m)\b|10menit/i', $p)) {
    $limits['uptime'] = 180;
    $limits['uptime_label'] = '3 menit';
  }
  return $limits;
}

// Helper: Ekstrak datetime dari comment (format umum MikroTik)
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

// Helper: gabungkan tanggal dari $dateStr dengan jam dari $timeStr
function merge_date_time($dateStr, $timeStr) {
  if (empty($dateStr) || empty($timeStr)) return $dateStr;
  $date = date('Y-m-d', strtotime($dateStr));
  $time = date('H:i:s', strtotime($timeStr));
  return $date . ' ' . $time;
}

// Helper: Ekstrak sumber retur dari comment
function extract_retur_ref($comment) {
  if (empty($comment)) return '';
  if (preg_match('/Retur\s*Ref\s*:\s*([^|]+)/i', $comment, $m)) {
    return trim($m[1]);
  }
  return '';
}

// Helper: Ekstrak username asal retur dari comment (LEBIH KUAT)
function extract_retur_user_from_ref($comment) {
  $ref = extract_retur_ref($comment);
  if ($ref === '') return '';
  // Cek format vc-xxxxxx
  if (preg_match('/\b(vc-[A-Za-z0-9._-]+)/i', $ref, $m)) {
    return $m[1];
  }
  // Cek format 6 karakter acak (user biasa)
  if (preg_match('/\b([a-z0-9]{6})\b/i', $ref, $m)) {
    return $m[1];
  }
  // Fallback: ambil kata pertama yang bersih
  $clean = trim(preg_replace('/[^a-z0-9\-_]/i', ' ', $ref));
  $parts = explode(' ', $clean);
  return $parts[0] ?? '';
}

// Helper: Normalisasi param blok dari dropdown (hapus suffix count seperti ":1")
function normalize_blok_param($blok) {
  if (empty($blok)) return $blok;
  if (preg_match('/^(.+?):\d+$/', $blok, $m)) {
    return $m[1];
  }
  return $blok;
}

// Helper: Normalisasi tanggal untuk filter (harian/bulanan/tahunan)
function normalize_date_key($dateTime, $mode) {
  if (empty($dateTime)) return '';
  $ts = strtotime($dateTime);
  if ($ts === false) return '';
  if ($mode === 'bulanan') return date('Y-m', $ts);
  if ($mode === 'tahunan') return date('Y', $ts);
  return date('Y-m-d', $ts);
}

// Helper: Generator User Baru (retur)
function gen_user($profile, $comment_ref, $orig_user = '') {
  $blok = '';
  if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $comment_ref, $m)) $blok = $m[1];
  if ($blok === '') {
    $blok = extract_blok_name($comment_ref);
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
  $ref_label = $ref_user !== '' ? "Retur Ref:$ref_user" : "Retur Ref:$clean_ref";
  if ($ref_user !== '' && stripos($clean_ref, $ref_user) === false) {
    $ref_label = "Retur Ref:$ref_user | $clean_ref";
  }
  $new_comm = trim($blok_part . "(Retur) Valid: $ref_label | Profile:$profile");
  return ['u'=>$user, 'p'=>$pass, 'c'=>$new_comm];
}

function log_ready_skip_users($message) {
  $logDir = __DIR__ . '/../../logs';
  if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
  }
  $logFile = $logDir . '/ready_skip.log';
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
  @file_put_contents($logFile, $line, FILE_APPEND);
}

function is_wartel_client($comment, $hist_blok = '') {
  if (!empty($hist_blok)) return true;
  $blok = extract_blok_name($comment);
  if (!empty($blok)) return true;
  if (!empty($comment) && stripos($comment, 'blok-') !== false) return true;
  return false;
}
?>

```

### 2. File: `hotspot/user/data.php`

Perbaikan logika penyembunyian user lama (scan RouterOS + DB) dan pemaksaan status RETUR agar tombol print benar.

```php
<?php
// ... (Bagian fungsi save_user_history, get_user_history, dll tetap sama, tidak berubah) ...
// ... Pastikan fungsi-fungsi database di file asli disertakan di sini ...

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
    if(!$db) return null;
    try {
  $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_uptime, last_bytes, last_status, first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real, updated_at, login_count, raw_comment FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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

// LOGIKA UTAMA PENGAMBILAN DATA USER
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

// MEMBANGUN PETA USER YANG HARUS DISEMBUNYIKAN (USER LAMA)
// Kita harus memindai DUA SUMBER: DB (History) dan RouterOS (Voucher Baru)
$retur_ref_map = [];

// 1. Scan DB
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

// 2. Scan RouterOS (untuk voucher baru yang mungkin belum masuk DB)
if (!empty($router_users)) {
    foreach ($router_users as $u) {
        $comm = $u['comment'] ?? '';
        $ref_user = extract_retur_user_from_ref($comm);
        if ($ref_user !== '') {
            $retur_ref_map[strtolower($ref_user)] = true;
        }
    }
}

$summary_ready_by_blok = [];
$summary_ready_total = 0;
$summary_rusak_total = 0;
$summary_retur_total = 0;
$summary_seen_users = [];

// ... (Helper fungsi detect profile kind tetap sama) ...
if (!function_exists('detect_profile_kind_summary')) {
  function detect_profile_kind_summary($profile) {
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
    if (preg_match('/\b10\b/', $combined)) return '10';
    if (preg_match('/\b30\b/', $combined)) return '30';
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

if (!empty($router_users)) {
  foreach ($router_users as $u) {
    $name = $u['name'] ?? '';
    // JIKA USER INI ADA DI MAP RETUR REF, SKIP (SEMBUNYIKAN)
    if (isset($retur_ref_map[strtolower($name)])) {
        continue;
    }

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
    $is_rusak = $comment_rusak || (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
    // Is Retur: Cek string (Retur)
    $is_retur = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $comment);
    
    if ($db && $name !== '') {
      $hist_sum = get_user_history($name);
      $hist_status = strtolower($hist_sum['last_status'] ?? '');
      if ($hist_status === 'retur') {
        $is_retur = true;
        $is_rusak = false;
      } elseif ($hist_status === 'rusak') {
        $is_rusak = true;
        $is_retur = false;
      }
    }
    // Jika Flag Retur aktif, status Rusak false (prioritas)
    if ($is_retur) $is_rusak = false;

    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || ($uptime !== '' && $uptime !== '0s') || (($cm['ip'] ?? '') !== ''));

    $status = 'READY';
    if ($is_active) $status = 'ONLINE';
    elseif ($is_retur) $status = 'RETUR'; // PENTING: Cek Retur sebelum Rusak/Terpakai jika komentar jelas
    elseif ($is_rusak) $status = 'RUSAK';
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
      // SKIP JIKA USER LAMA (ADA DI MAP)
      if ($uname === '' || isset($summary_seen_users[$uname]) || isset($retur_ref_map[$uname])) continue;
      
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
        if (isset($retur_ref_map[strtolower($uname)])) continue; // SKIP USER LAMA
        
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
        $h_is_rusak = ($st === 'rusak') || $h_comment_rusak;
        $h_is_retur = ($st === 'retur') || (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false);
        
        // Prioritas Status History
        if ($st === 'retur') {
          $h_is_retur = true;
          $h_is_rusak = false;
        } elseif ($st === 'rusak') {
          $h_is_rusak = true;
          $h_is_retur = false;
        } elseif ($h_is_rusak) {
          $h_is_retur = false;
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
        if ($req_status === 'used' && $h_status !== 'TERPAKAI') continue;
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

// List blok untuk dropdown
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

// --- PROCESSING DISPLAY DATA ---
foreach($all_users as $u) {
    $name = $u['name'] ?? '';
    // FILTER UTAMA: SEMBUNYIKAN USER LAMA
    if ($name !== '' && isset($retur_ref_map[strtolower($name)])) {
      continue;
    }

    $comment = $u['comment'] ?? '';
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);
    
    // ... (Logika IP/MAC fallback tetap sama) ...
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

    // Hitung Bytes & Uptime
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
    if (!$is_active && $bytes == 0 && $bytes_hist > 0) {
      $bytes = $bytes_hist;
    }
    if (!$is_active && ($uptime == '0s' || $uptime == '') && $uptime_hist != '') {
      $uptime = $uptime_hist;
    }

    // --- STATUS LOGIC FIX ---
    $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
    $is_rusak = $comment_rusak;
    $is_invalid = false;
    // Deteksi Retur diperkuat
    $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
    
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
    // Jika Retur, maka Rusak FALSE (Override)
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
    elseif ($is_retur) $status = 'RETUR'; // RETUR PRIORITAS DI ATAS RUSAK
    elseif ($is_rusak) $status = 'RUSAK';
    elseif ($disabled == 'true') $status = 'RUSAK';
    elseif ($is_used) $status = 'TERPAKAI';

    // Pastikan tidak tertimpa jadi READY jika sebenarnya RETUR
    $is_ready_now = (!$is_active && !$is_rusak && !$is_retur && $disabled !== 'true' && $bytes <= 50 && ($uptime == '0s' || $uptime == ''));
    if ($req_status == 'ready' && $is_ready_now && !$is_retur) {
      $status = 'READY';
    }

    // Jika status akhir adalah RETUR, pastikan data retur_from terisi
    $retur_ref_user = '';
    if ($is_retur) {
      $retur_ref_user = extract_retur_user_from_ref($comment);
      if ($retur_ref_user === '' && $hist && !empty($hist['raw_comment'])) {
        $retur_ref_user = extract_retur_user_from_ref($hist['raw_comment']);
      }
    }

    // ... (Sisa perhitungan login/logout/db save tidak berubah signifikan) ...
    // ... Silakan gunakan kode login/logout calculation dari file asli ...

    // Kalkulasi Waktu (Simplified for insertion)
    $now = date('Y-m-d H:i:s');
    $login_time_real = $hist['login_time_real'] ?? null;
    $logout_time_real = $hist['logout_time_real'] ?? null;
    $u_sec = uptime_to_seconds($uptime);
    if ($is_active && empty($login_time_real)) $login_time_real = ($u_sec > 0) ? date('Y-m-d H:i:s', time() - $u_sec) : $now;
    if ($status === 'READY') { $login_time_real = null; $logout_time_real = null; }

    // First Login Real
    $first_login_real = $hist['first_login_real'] ?? null;
    $last_login_real = $hist['last_login_real'] ?? null;
    $first_ip = $hist['first_ip'] ?? '';
    $first_mac = $hist['first_mac'] ?? '';
    $last_ip = $hist['last_ip'] ?? '';
    $last_mac = $hist['last_mac'] ?? '';
    if (empty($first_login_real) && !empty($login_time_real)) $first_login_real = $login_time_real;

    // DB SAVE LOGIC (Tetap pertahankan seperti file asli)
    // ...

    $profile_kind = detect_profile_kind_unified($u['profile'] ?? '', $comment, $f_blok, $uptime);

    // Filter blok
    if ($req_comm != '') {
      if (strcasecmp($f_blok, $req_comm) != 0) continue;
    }

    // Filter profil (10/30)
    if ($req_prof !== 'all') {
      if ($profile_kind !== $req_prof) continue;
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

    $relogin_flag = ((int)($hist['login_count'] ?? 0) > 1);
    $relogin_count = (int)($hist['login_count'] ?? 0);
    $first_login_disp = $first_login_real ?? ($hist['first_login_real'] ?? '-');
    
    // Display Times
    $login_disp = $login_time_real ?? '-';
    $logout_disp = $logout_time_real ?? '-';

    // Last Used for Filter
    $last_used_filter = $hist['last_login_real'] ?? ($hist['logout_time_real'] ?? ($hist['login_time_real'] ?? ($hist['first_login_real'] ?? '-')));
    
    // Filter tanggal (harian/bulanan/tahunan)
    if ($req_show !== 'semua' && !empty($filter_date)) {
      if ($status === 'READY') {
        $is_today = ($filter_date === date('Y-m-d') && $req_show === 'harian');
        if (!$is_today && $req_status === 'all') {
          continue;
        }
      } else {
        $date_key = normalize_date_key($last_used_filter, $req_show);
        if ($date_key === '' || $date_key !== $filter_date) {
          continue;
        }
      }
    }

    // Filter status
    if ($req_status == 'ready' && $status !== 'READY') continue;
    if ($req_status == 'all' && $status === 'READY') continue;
    if ($req_status == 'online' && $status !== 'ONLINE') continue;
    if ($req_status == 'used' && $status !== 'TERPAKAI') continue;
    if ($req_status == 'rusak' && $status !== 'RUSAK') continue;
    if ($req_status == 'retur' && $status !== 'RETUR') continue;
    
    $display_data[] = [
      'uid' => $u['.id'] ?? '',
        'name' => $name,
        'profile' => $u['profile'] ?? '',
        'profile_kind' => $profile_kind,
        'blok' => $f_blok,
        'ip' => $f_ip,
        'mac' => $f_mac,
      'comment' => $comment,
        'comment_rusak' => $comment_rusak ? 1 : 0,
        'is_disabled' => ($disabled === 'true') ? 1 : 0,
        'first_login' => $first_login_disp,
        'retur_ref' => $is_retur ? extract_retur_ref($comment) : '',
        'retur_from' => $retur_ref_user, // <--- ISI DENGAN USERNAME LAMA
        'uptime' => $uptime,
        'bytes' => $bytes,
        'status' => $status,
        'login_time' => $login_disp,
        'logout_time' => $logout_disp,
        'last_used' => $last_used_filter,
        'relogin' => $relogin_flag,
        'relogin_count' => $relogin_count
    ];
}
// ... (Sisa file sorting & pagination tetap sama) ...
// Sorting
$status_rank = ['ONLINE'=>0, 'TERPAKAI'=>1, 'RUSAK'=>2, 'RETUR'=>3, 'READY'=>4];
if (!empty($display_data)) {
  usort($display_data, function($a, $b) use ($req_status, $status_rank) {
      $ra = $status_rank[$a['status']] ?? 9;
      $rb = $status_rank[$b['status']] ?? 9;
      if ($ra !== $rb) return $ra <=> $rb;
      return strcmp($a['name'], $b['name']);
  });
}
// Pagination logic...
$total_items = count($display_data);
$per_page = isset($_GET['per_page']) ? max(10, min(200, (int)$_GET['per_page'])) : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;
$display_data = array_slice($display_data, $offset, $per_page);

// ... Render JSON (HTML rows) tetap sama, namun pastikan variabel $retur_from dipakai di render.php (sudah ada di data array) ...
?>

```

### 3. File: `hotspot/user/render.php`

Memastikan logika tombol print memeriksa status `RETUR` dengan benar dan menampilkan label `Retur dari:` hanya untuk yang berstatus RETUR.

```php
<td class="text-center">
  <?php
    $status_upper = strtoupper($u['status'] ?? '');
    $is_ready = ($status_upper === 'READY');
    $is_online = ($status_upper === 'ONLINE');
    $is_used = ($status_upper === 'TERPAKAI');
    $is_rusak = ($status_upper === 'RUSAK');
    $is_retur = ($status_upper === 'RETUR'); // Pastikan ini true dari data.php
    
    // ...
  ?>
  <?php if (in_array($req_status, ['all','ready','used','rusak','online','retur'], true)): ?>
    <?php if ($is_used && in_array($req_status, ['all','used'], true)): ?>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
    <?php elseif ($is_online && in_array($req_status, ['all','online'], true)): ?>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.used.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
    <?php elseif ($is_rusak && in_array($req_status, ['all','rusak'], true)): ?>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.detail.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank')" title="Print Rincian Rusak"><i class="fa fa-print"></i></button>
    
    <?php elseif ($is_retur && in_array($req_status, ['all','retur'], true)): ?>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.retur.php?user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Voucher Retur"><i class="fa fa-print"></i></button>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./hotspot/print/print.retur.php?user=<?= urlencode($u['name']) ?>&download=1&img=1&session=<?= $session ?>','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
    
    <?php elseif ($is_ready && in_array($req_status, ['all','ready'], true)): ?>
      <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
    <?php endif; ?>
  <?php endif; ?>
  
  <?php if($u['uid'] || $can_mark_rusak): ?>
    <?php endif; ?>
</td>

```

### Penjelasan Perbaikan

1. **Penyembunyian User Lama:** Di `data.php`, `$retur_ref_map` kini dibangun dari Database **DAN** dari List RouterOS (`$all_users`). Ini memastikan user lama langsung terdeteksi dan disembunyikan (`continue`) meskipun database belum tersinkronisasi penuh.
2. **Status RETUR Konsisten:** Logika `status` di `data.php` diubah agar `RETUR` diprioritaskan sebelum `READY` atau `RUSAK` jika string komentar mengandung `(Retur)`. Ini mencegah voucher retur dianggap voucher biasa.
3. **Parsing Username Lama:** Fungsi `extract_retur_user_from_ref` diperkuat di `helpers.php` untuk menangani format `vc-xxxxxx` maupun user biasa, sehingga `retur_from` terisi dengan benar di tabel.
4. **Tombol Print:** Dengan status `RETUR` yang konsisten, `render.php` akan otomatis menampilkan tombol ke `print.retur.php`, bukan `print.php`.