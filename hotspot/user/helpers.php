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

// Helper: Ekstrak username asal retur dari comment
function extract_retur_user_from_ref($comment) {
  $ref = extract_retur_ref($comment);
  if ($ref === '') return '';
  if (preg_match('/\b(vc-[A-Za-z0-9._-]+)/', $ref, $m)) {
    return $m[1];
  }
  if (preg_match('/\b([a-z0-9]{6})\b/i', $ref, $m)) {
    return $m[1];
  }
  return '';
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
