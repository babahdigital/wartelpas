<?php

if (!function_exists('auto_rusak_uptime_to_seconds')) {
    function auto_rusak_uptime_to_seconds($uptime) {
        if (empty($uptime) || $uptime === '0s') return 0;
        $total = 0;
        if (preg_match_all('/(\d+)(w|d|h|m|s)/i', (string)$uptime, $m, PREG_SET_ORDER)) {
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

if (!function_exists('auto_rusak_profile_minutes')) {
    function auto_rusak_profile_minutes($validity, $raw_comment) {
        $src = strtolower(trim((string)$validity));
        $cmt = strtolower(trim((string)$raw_comment));
        $val = 0;
        if (preg_match('/\b(\d{1,2})\s*(menit|m)\b/', $src, $m)) {
            $val = (int)$m[1];
        } elseif (preg_match('/\b(10|30)\b/', $src, $m)) {
            $val = (int)$m[1];
        } elseif (preg_match('/\b(10|30)\s*(menit|m)\b/', $cmt, $m)) {
            $val = (int)$m[1];
        } elseif (preg_match('/\bblok[-\s]?[a-z]+(10|30)\b/i', (string)$raw_comment, $m)) {
            $val = (int)$m[1];
        }
        if (!in_array($val, [10, 30], true)) return 0;
        return $val;
    }
}

if (!function_exists('auto_rusak_login_minutes')) {
    function auto_rusak_login_minutes(array $row, $date) {
        $fields = ['login_time_real', 'first_login_real', 'last_login_real', 'logout_time_real', 'updated_at'];
        foreach ($fields as $f) {
            $v = trim((string)($row[$f] ?? ''));
            if ($v === '') continue;
            $ts = strtotime($v);
            if ($ts === false) continue;
            if (date('Y-m-d', $ts) !== $date) continue;
            return ((int)date('H', $ts)) * 60 + (int)date('i', $ts);
        }
        return null;
    }
}

if (!function_exists('auto_rusak_normalize_bytes')) {
    function auto_rusak_normalize_bytes($bytes_raw) {
        $bytes = (int)$bytes_raw;
        if ($bytes > 0 && $bytes < 1024 * 1024 && $bytes <= 1024) {
            $bytes = $bytes * 1024 * 1024;
        }
        return $bytes;
    }
}

if (!function_exists('auto_rusak_should_rusak')) {
    function auto_rusak_should_rusak($profile_minutes, $uptime, $bytes_raw, $login_minutes) {
        $profile_minutes = (int)$profile_minutes;
        if ($profile_minutes <= 0) return false;
        $uptime_sec = auto_rusak_uptime_to_seconds($uptime);
        $bytes = auto_rusak_normalize_bytes($bytes_raw);
        $short_uptime_limit = 5 * 60;
        $bytes_threshold_full = ($profile_minutes === 10) ? (2 * 1024 * 1024) : (3 * 1024 * 1024);
        $bytes_threshold_short = $bytes_threshold_full;
        $is_full_uptime = $uptime_sec >= ($profile_minutes * 60);
        $is_short_use = ($uptime_sec > 0 && $uptime_sec <= $short_uptime_limit);
        $near_full_limit = max(0, ($profile_minutes * 60) - 60);
        $is_near_full = $uptime_sec >= $near_full_limit;
        $close_window_start = ($profile_minutes === 10) ? (17 * 60 + 55) : (17 * 60 + 45);
        $is_close_window = ($login_minutes !== null && $login_minutes >= $close_window_start);
        if ($is_close_window || $is_near_full) return false;
        return ($is_full_uptime && $bytes < $bytes_threshold_full) || ($is_short_use && $bytes < $bytes_threshold_short);
    }
}

if (!function_exists('auto_rusak_should_warn')) {
    function auto_rusak_should_warn($profile_minutes, $uptime, $bytes_raw) {
        $profile_minutes = (int)$profile_minutes;
        if ($profile_minutes <= 0) return false;
        $uptime_sec = auto_rusak_uptime_to_seconds($uptime);
        if ($uptime_sec <= 0) return false;
        $bytes = auto_rusak_normalize_bytes($bytes_raw);
        $short_uptime_limit = 5 * 60;
        $bytes_threshold = ($profile_minutes === 10) ? (2 * 1024 * 1024) : (3 * 1024 * 1024);
        $is_full_uptime = $uptime_sec >= ($profile_minutes * 60);
        $is_short_use = ($uptime_sec > 0 && $uptime_sec <= $short_uptime_limit);
        return ($bytes < $bytes_threshold) && ($is_full_uptime || $is_short_use);
    }
}
