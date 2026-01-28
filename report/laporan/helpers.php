<?php

function norm_date_from_raw_report($raw_date) {
    $raw = trim((string)$raw_date);
    if ($raw === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        return substr($raw, 0, 10);
    }
    if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
        $mon = strtolower(substr($raw, 0, 3));
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $parts = explode('/', $raw);
        return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }
    return '';
}

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

function normalize_block_key($raw) {
    $raw = strtoupper((string)$raw);
    $raw = preg_replace('/^BLOK/i', '', $raw);
    $raw = preg_replace('/[^A-Z0-9]/', '', $raw);
    return $raw;
}

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

function resolve_status_from_sources($status, $is_invalid, $is_retur, $is_rusak, $comment, $lh_status = '') {
    $base = strtolower(trim((string)$status));
    if ($base !== '' && $base !== 'normal') return $base;

    $flags = [];
    if ((int)$is_invalid === 1) $flags['invalid'] = true;
    if ((int)$is_retur === 1) $flags['retur'] = true;
    if ((int)$is_rusak === 1 || strtolower((string)$lh_status) === 'rusak') $flags['rusak'] = true;

    $cmt_low = strtolower((string)$comment);
    if (strpos($cmt_low, 'invalid') !== false) $flags['invalid'] = true;
    if (strpos($cmt_low, 'retur') !== false) $flags['retur'] = true;
    if (strpos($cmt_low, 'rusak') !== false) $flags['rusak'] = true;

    foreach (get_status_priority_list() as $p) {
        if (isset($flags[$p])) return $p;
    }
    return 'normal';
}

function normalize_block_name($blok_name, $comment = '') {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '' && $comment !== '') {
        if (preg_match('/\bblok\s*[-_]*\s*([A-Z0-9]+)(?:\s*[-_]*\s*([0-9]+))?/i', $comment, $m)) {
            $raw = strtoupper($m[1] . ($m[2] ?? ''));
        }
    }
    if ($raw === '') return 'BLOK-LAIN';
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
    $raw = preg_replace('/^BLOK/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        $raw = $m[1];
    }
    if ($raw === '') return 'BLOK-LAIN';
    $final = 'BLOK-' . $raw;
    $alias = resolve_block_alias($final);
    if ($alias !== '' && $alias !== $final) {
        $alias_key = normalize_block_key($alias);
        if ($alias_key !== '') return 'BLOK-' . $alias_key;
    }
    return $final;
}

function sanitize_comment_short($comment) {
    $comment = (string)$comment;
    $comment = preg_replace('/\s*\|\s*IP\s*:[^|]+/i', '', $comment);
    $comment = preg_replace('/\s*\|\s*MAC\s*:[^|]+/i', '', $comment);
    $comment = preg_replace('/\s+\|\s+/', ' | ', $comment);
    return trim($comment);
}

function extract_profile_from_comment($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bProfile\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\bProfil\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    return '';
}

function normalize_profile_key($profile) {
    $raw = strtolower(trim((string)$profile));
    if ($raw === '') return '';
    $raw = preg_replace('/\s+/', '', $raw);
    return $raw;
}

function resolve_price_from_profile($profile) {
    $profile = resolve_profile_alias($profile);
    $profile_key = normalize_profile_key($profile);
    $map = $GLOBALS['profile_price_map'] ?? [];
    if (!empty($map)) {
        foreach ($map as $k => $v) {
            if (normalize_profile_key($k) === $profile_key && (int)$v > 0) {
                return (int)$v;
            }
        }
    }
    $p = strtolower((string)$profile);
    if (preg_match('/\b10\s*(menit|m)\b/i', $p)) return (int)($GLOBALS['price10'] ?? 0);
    if (preg_match('/\b30\s*(menit|m)\b/i', $p)) return (int)($GLOBALS['price30'] ?? 0);
    return 0;
}

function resolve_profile_label($profile_key) {
    $profile_key = normalize_profile_key(resolve_profile_alias($profile_key));
    if ($profile_key === '') return '';
    $labels = env_get_value('profiles.labels', []);
    if (is_array($labels)) {
        foreach ($labels as $k => $v) {
            if (normalize_profile_key($k) === $profile_key && trim((string)$v) !== '') {
                return (string)$v;
            }
        }
    }
    if (preg_match('/(\d+)/', $profile_key, $m)) {
        return (int)$m[1] . ' Menit';
    }
    return $profile_key;
}

function format_first_login($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i:s', $ts);
}

function format_date_dmy($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i', $ts);
}

function month_label_id($val) {
    $val = trim((string)$val);
    if ($val === '') return '';
    $mm = '';
    if (preg_match('/^\d{4}-(\d{2})/', $val, $m)) {
        $mm = $m[1];
    } elseif (preg_match('/^\d{2}$/', $val)) {
        $mm = $val;
    } elseif (preg_match('/^\d{1}$/', $val)) {
        $mm = str_pad($val, 2, '0', STR_PAD_LEFT);
    } elseif (preg_match('/-\s*(\d{2})\s*-/', $val, $m)) {
        $mm = $m[1];
    } elseif (preg_match('/^\d{4}$/', $val)) {
        return $val;
    }

    $map = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember'
    ];

    return $map[$mm] ?? $val;
}

function format_blok_label($blok) {
    $blok = (string)$blok;
    if ($blok === '') return '';
    return preg_replace('/^BLOK-?/i', '', $blok);
}

function render_audit_lines($lines) {
    if (empty($lines)) return '-';
    return implode('', array_map(function($line) {
        return '<div class="audit-line">' . htmlspecialchars((string)$line) . '</div>';
    }, $lines));
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
}

function table_exists(PDO $db, $table) {
    try {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function build_ghost_hint($selisih_qty, $selisih_rp) {
    global $price10, $price30;
    $ghost_qty = abs((int)$selisih_qty);
    $ghost_rp = abs((int)$selisih_rp);
    if ($ghost_qty <= 0 || $ghost_rp <= 0) return '';

    $price10 = (int)$price10;
    $price30 = (int)$price30;
    $divisor = $price30 - $price10;
    $ghost_10 = 0;
    $ghost_30 = 0;

    if ($ghost_rp >= ($ghost_qty * $price10) && $divisor > 0) {
        $numerator = $ghost_rp - ($ghost_qty * $price10);
        if ($numerator % $divisor === 0) {
            $ghost_30 = (int)($numerator / $divisor);
            $ghost_10 = $ghost_qty - $ghost_30;
        }
    }

    if ($ghost_10 < 0 || $ghost_30 < 0) {
        $ghost_10 = 0;
        $ghost_30 = 0;
    }

    if ($ghost_10 === 0 && $ghost_30 === 0) {
        if ($ghost_rp === ($price30 * $ghost_qty)) {
            $ghost_30 = $ghost_qty;
        } elseif ($ghost_rp === ($price10 * $ghost_qty)) {
            $ghost_10 = $ghost_qty;
        }
    }

    if ($ghost_10 <= 0 && $ghost_30 <= 0) return '';
    $parts = [];
    if ($ghost_10 > 0) $parts[] = number_format($ghost_10, 0, ',', '.') . ' unit 10 menit';
    if ($ghost_30 > 0) $parts[] = number_format($ghost_30, 0, ',', '.') . ' unit 30 menit';
    return 'Deteksi otomatis: ' . implode(' + ', $parts) . '.';
}

function detect_profile_kind_from_label($label) {
    $low = strtolower((string)$label);
    if (preg_match('/\b30\s*(menit|m)\b|30menit/', $low)) return '30';
    if (preg_match('/\b10\s*(menit|m)\b|10menit/', $low)) return '10';
    return '10';
}

function extract_retur_user_from_ref($comment) {
    if (empty($comment)) return '';
    if (preg_match('/Retur\s*Ref\s*:\s*([^|]+)/i', (string)$comment, $m)) {
        $ref = trim($m[1]);
        if ($ref === '') return '';
        if (preg_match('/\b(vc-[A-Za-z0-9._-]+)/', $ref, $m2)) {
            $ref = $m2[1];
        } else {
            $ref = preg_replace('/\s+.*/', '', $ref);
        }
        if (stripos($ref, 'vc-') === 0) {
            $ref = substr($ref, 3);
        }
        return trim($ref);
    }
    return '';
}

function parse_reported_users_from_audit($row) {
    $users = [];
    $raw = trim((string)($row['audit_username'] ?? ''));
    if ($raw !== '') {
        foreach (preg_split('/[\n,]+/', $raw) as $u) {
            $u = trim((string)$u);
            if ($u !== '') $users[] = $u;
        }
    }
    if (!empty($row['user_evidence'])) {
        $ev = json_decode((string)$row['user_evidence'], true);
        if (is_array($ev) && !empty($ev['users']) && is_array($ev['users'])) {
            foreach (array_keys($ev['users']) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $users[] = $u;
            }
        }
    }
    $uniq = [];
    foreach ($users as $u) {
        $key = strtolower($u);
        $uniq[$key] = $u;
    }
    return array_values($uniq);
}

function get_ghost_suspects(PDO $db, $audit_date, $audit_blok, array $reported_users = [], $min_bytes = 51200) {
    $audit_date = trim((string)$audit_date);
    $audit_blok = normalize_block_name($audit_blok);
    $reported_map = [];
    foreach ($reported_users as $u) {
        $reported_map[strtolower((string)$u)] = true;
    }
    $suspects = [];
    $stmt = $db->prepare("SELECT username, blok_name, raw_comment, validity, last_status, last_bytes, last_uptime, login_time_real, last_login_real, logout_time_real, updated_at, first_ip, first_mac, last_ip, last_mac
        FROM login_history
        WHERE username != '' AND (
            substr(login_time_real,1,10) = :d OR
            substr(last_login_real,1,10) = :d OR
            substr(logout_time_real,1,10) = :d OR
            substr(updated_at,1,10) = :d OR
            login_date = :d
        )");
    $stmt->execute([':d' => $audit_date]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') continue;
        if (isset($reported_map[strtolower($username)])) continue;
        $blok = normalize_block_name($row['blok_name'] ?? '', $row['raw_comment'] ?? '');
        if ($blok !== $audit_blok) continue;

        $status = strtolower((string)($row['last_status'] ?? ''));

        $bytes = (int)($row['last_bytes'] ?? 0);
        $uptime = (string)($row['last_uptime'] ?? '');
        $has_login_time = !empty($row['login_time_real']) || !empty($row['last_login_real']) || !empty($row['logout_time_real']);
        $has_usage = $bytes > 0 || ($uptime !== '' && $uptime !== '0s') || $has_login_time;
        if (!$has_usage) continue;

        if ($bytes < $min_bytes && !$has_login_time && ($uptime === '' || $uptime === '0s')) continue;

        $profile_label = (string)($row['validity'] ?? '');
        $profile_kind = detect_profile_kind_from_label($profile_label);
        $score = 0;
        if ($bytes >= 5 * 1024 * 1024) $score += 30;
        elseif ($bytes >= 1024 * 1024) $score += 20;
        elseif ($bytes >= 100 * 1024) $score += 10;
        if ($status === 'online') $score += 30;
        elseif ($status === 'terpakai') $score += 20;
        else $score += 10;
        $login_time = (string)($row['login_time_real'] ?? $row['last_login_real'] ?? '');
        if ($login_time !== '') $score += 10;
        if ($score > 100) $score = 100;

        $suspects[] = [
            'username' => $username,
            'profile' => $profile_label !== '' ? $profile_label : ($profile_kind === '30' ? '30 Menit' : '10 Menit'),
            'profile_kind' => $profile_kind,
            'bytes' => $bytes,
            'uptime' => $uptime,
            'status' => strtoupper($status !== '' ? $status : 'TERPAKAI'),
            'login_time' => $login_time !== '' ? $login_time : (string)($row['updated_at'] ?? ''),
            'ip' => (string)($row['first_ip'] ?? $row['last_ip'] ?? '-'),
            'mac' => (string)($row['first_mac'] ?? $row['last_mac'] ?? '-'),
            'confidence' => $score,
            'blok' => $blok
        ];
    }
    usort($suspects, function($a, $b){ return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0); });
    return $suspects;
}

function calc_expected_for_block(array $rows, $audit_date, $audit_blok) {
    $rusak_user_map = [];
    $retur_ref_map = [];
    foreach ($rows as $r) {
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        if ($sale_date !== $audit_date) continue;
        $raw_comment = (string)($r['comment'] ?? '');
        $lh_comment = (string)($r['raw_comment'] ?? '');
        if ($lh_comment !== '') {
            $lh_low = strtolower($lh_comment);
            $cmt_low = strtolower($raw_comment);
            if ((strpos($lh_low, 'retur') !== false || strpos($lh_low, 'rusak') !== false) &&
                !(strpos($cmt_low, 'retur') !== false || strpos($cmt_low, 'rusak') !== false)) {
                $raw_comment = $lh_comment;
            }
        }
        $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);
        if ($blok !== $audit_blok) continue;

        $status = resolve_status_from_sources(
            $r['status'] ?? '',
            $r['is_invalid'] ?? 0,
            $r['is_retur'] ?? 0,
            $r['is_rusak'] ?? 0,
            $raw_comment,
            $r['last_status'] ?? ''
        );

        if ($status === 'retur') {
            $ref_user = extract_retur_user_from_ref($raw_comment);
            if ($ref_user !== '') {
                $retur_ref_map[strtolower($ref_user)] = true;
            }
        }
        if ($status === 'rusak') {
            $username = strtolower((string)($r['username'] ?? ''));
            if ($username !== '') {
                $rusak_user_map[$username] = true;
            }
        }
    }

    $seen_sales = [];
    $seen_user_day = [];
    $qty_total = 0;
    $rusak_qty = 0;
    $retur_qty = 0;
    $invalid_qty = 0;
    $net_total = 0;

    foreach ($rows as $r) {
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        if ($sale_date !== $audit_date) continue;

        $raw_date = trim((string)($r['raw_date'] ?? ''));
        $raw_time = trim((string)($r['raw_time'] ?? ''));
        $full_raw = trim((string)($r['full_raw_data'] ?? ''));
        $price_snapshot = (int)($r['price_snapshot'] ?? 0);
        $price_val = (int)($r['price'] ?? 0);
        $sprice_val = (int)($r['sprice_snapshot'] ?? 0);
        $is_login_history_row = ($raw_date === '' && $raw_time === '' && $full_raw === '' && $price_snapshot <= 0 && $price_val <= 0 && $sprice_val <= 0);
        if ($is_login_history_row) {
            continue;
        }

        $username = $r['username'] ?? '';
        if ($username !== '' && $sale_date !== '') {
            $user_day_key = $username . '|' . $sale_date;
            if (isset($seen_user_day[$user_day_key])) continue;
            $seen_user_day[$user_day_key] = true;
        }

        $raw_key = trim((string)($r['full_raw_data'] ?? ''));
        $unique_key = '';
        if ($raw_key !== '') {
            $unique_key = 'raw|' . $raw_key;
        } elseif ($username !== '' && $sale_date !== '') {
            $unique_key = $username . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($r['sale_time'] ?? '')));
            if ($unique_key === $username . '|') {
                $unique_key = $username . '|' . $sale_date . '|' . ($r['sale_time'] ?? '');
            }
        } elseif ($sale_date !== '') {
            $unique_key = 'date|' . $sale_date . '|' . ($r['sale_time'] ?? '');
        }
        if ($unique_key !== '') {
            if (isset($seen_sales[$unique_key])) continue;
            $seen_sales[$unique_key] = true;
        }

        $raw_comment = (string)($r['comment'] ?? '');
        $blok = normalize_block_name($r['blok_name'] ?? '', $raw_comment);
        if ($blok !== $audit_blok) continue;

        $status = resolve_status_from_sources(
            $r['status'] ?? '',
            $r['is_invalid'] ?? 0,
            $r['is_retur'] ?? 0,
            $r['is_rusak'] ?? 0,
            $raw_comment,
            $r['last_status'] ?? ''
        );

        if ($status !== 'invalid') {
            if (strpos($cmt_low, 'retur') !== false) {
                $status = 'retur';
            } elseif (strpos($cmt_low, 'rusak') !== false) {
                $status = 'rusak';
            }
        }

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
        if ($price <= 0) {
            $profile = (string)($r['profile_snapshot'] ?? ($r['profile'] ?? ''));
            if ($profile === '' || $profile === '-') {
                $profile = extract_profile_from_comment($raw_comment);
            }
            $price = resolve_price_from_profile($profile);
        }
        $qty = (int)($r['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;
        $line_price = $price * $qty;

        $gross_add = 0;
        $loss_rusak = 0;
        $loss_invalid = 0;
        $net_add = 0;

        $rusak_recovered = false;
        if ($status === 'rusak') {
            $uname_key = strtolower((string)($username ?? ''));
            if ($uname_key !== '' && isset($retur_ref_map[$uname_key])) {
                $rusak_recovered = true;
            }
        }
        $retur_ref_user = '';
        if ($status === 'retur') {
            $retur_ref_user = extract_retur_user_from_ref($raw_comment);
        }

        if ($status === 'invalid') {
            $gross_add = 0;
            $net_add = 0;
        } elseif ($status === 'retur') {
            $gross_add = 0;
            if ($retur_ref_user !== '' && isset($rusak_user_map[strtolower($retur_ref_user)])) {
                $net_add = 0;
            } else {
                $net_add = $line_price;
            }
        } elseif ($status === 'rusak') {
            $gross_add = $line_price;
            if ($rusak_recovered) {
                $loss_rusak = 0;
                $net_add = $line_price;
            } else {
                $loss_rusak = $line_price;
                $net_add = 0;
            }
        } else {
            $gross_add = $line_price;
            $net_add = $line_price;
        }

        $qty_total += 1;
        if ($status === 'rusak' && !$rusak_recovered) $rusak_qty += 1;
        if ($status === 'retur') $retur_qty += 1;
        if ($status === 'invalid') $invalid_qty += 1;
        $net_total += $net_add;
    }

    $expected_qty = max(0, $qty_total - $rusak_qty - $invalid_qty);
    return [
        'qty' => $expected_qty,
        'raw_qty' => $qty_total,
        'rusak_qty' => $rusak_qty,
        'invalid_qty' => $invalid_qty,
        'net' => $net_total,
        'retur_qty' => $retur_qty
    ];
}

function calc_audit_adjusted_setoran(array $ar) {
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);
    $has_manual_evidence = false;
    $profile_qty_map = [];
    $status_count_map = [];

    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence)) {
            $has_manual_evidence = true;
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
            if (!empty($evidence['users']) && is_array($evidence['users'])) {
                foreach ($evidence['users'] as $ud) {
                    $status = strtolower((string)($ud['last_status'] ?? ''));
                    $kind = strtolower((string)($ud['profile_key'] ?? $ud['profile_kind'] ?? ''));
                    if ($kind !== '' && preg_match('/^(\d+)$/', $kind, $m)) {
                        $kind = $m[1] . 'menit';
                    }
                    if ($kind === '') $kind = '10menit';
                    if (!isset($status_count_map[$kind])) {
                        $status_count_map[$kind] = ['invalid' => 0, 'retur' => 0, 'rusak' => 0];
                    }
                    if ($status === 'invalid') $status_count_map[$kind]['invalid']++;
                    elseif ($status === 'retur') $status_count_map[$kind]['retur']++;
                    elseif ($status === 'rusak') $status_count_map[$kind]['rusak']++;
                }
            }
        }
    }

    if ($has_manual_evidence && !empty($profile_qty_map)) {
        $manual_display_setoran = 0;
        foreach ($profile_qty_map as $k => $qty) {
            $qty = (int)$qty;
            $counts = $status_count_map[$k] ?? ['invalid' => 0, 'retur' => 0, 'rusak' => 0];
            $money_qty = max(0, $qty - (int)$counts['rusak'] - (int)$counts['invalid']);
            $price_val = isset($GLOBALS['profile_price_map'][$k]) ? (int)$GLOBALS['profile_price_map'][$k] : (int)resolve_price_from_profile($k);
            $manual_display_setoran += ($money_qty * $price_val);
        }
        $expected_adj_setoran = $expected_setoran;
    } else {
        $manual_display_setoran = $actual_setoran_raw;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_setoran, $expected_adj_setoran];
}

function fetch_rows_for_audit(PDO $db, $audit_date) {
    $rows = [];
    $hasSales = table_exists($db, 'sales_history');
    $hasLive = table_exists($db, 'live_sales');
    $hasLogin = table_exists($db, 'login_history');

    if ($hasSales) {
        $sql = "SELECT
            sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
            sh.username, sh.profile, sh.profile_snapshot,
            sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
            sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
            sh.full_raw_data,
            " . ($hasLogin ? "lh.last_status" : "'' AS last_status") . "
            FROM sales_history sh
            " . ($hasLogin ? "LEFT JOIN login_history lh ON lh.username = sh.username" : "") . "
            WHERE sh.sale_date = :d";
        $stmt = $db->prepare($sql);
        $stmt->execute([':d' => $audit_date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($hasLive) {
        $sql = "SELECT
            ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
            ls.username, ls.profile, ls.profile_snapshot,
            ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
            ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
            ls.full_raw_data,
            " . ($hasLogin ? "lh2.last_status" : "'' AS last_status") . "
            FROM live_sales ls
            " . ($hasLogin ? "LEFT JOIN login_history lh2 ON lh2.username = ls.username" : "") . "
            WHERE ls.sale_date = :d AND ls.sync_status = 'pending'";
        $stmt = $db->prepare($sql);
        $stmt->execute([':d' => $audit_date]);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if (empty($rows) && $hasLogin) {
        $stmtFallback = $db->prepare("SELECT
            '' AS raw_date,
            '' AS raw_time,
            COALESCE(NULLIF(substr(login_time_real,1,10),''), NULLIF(substr(last_login_real,1,10),''), NULLIF(substr(logout_time_real,1,10),''), NULLIF(substr(updated_at,1,10),''), login_date) AS sale_date,
            COALESCE(NULLIF(substr(login_time_real,12,8),''), NULLIF(substr(last_login_real,12,8),''), NULLIF(substr(logout_time_real,12,8),''), NULLIF(substr(updated_at,12,8),''), login_time) AS sale_time,
            COALESCE(NULLIF(login_time_real,''), NULLIF(last_login_real,''), NULLIF(logout_time_real,''), NULLIF(updated_at,'')) AS sale_datetime,
            username,
            COALESCE(NULLIF(validity,''), '-') AS profile,
            COALESCE(NULLIF(validity,''), '-') AS profile_snapshot,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS price_snapshot,
            CAST(COALESCE(NULLIF(price,''), 0) AS INTEGER) AS sprice_snapshot,
            validity,
            raw_comment AS comment,
            blok_name,
            '' AS status,
            0 AS is_rusak,
            0 AS is_retur,
            0 AS is_invalid,
            1 AS qty,
            '' AS full_raw_data,
            last_status
                        FROM login_history
                        WHERE username != ''
                            AND (
                                substr(login_time_real,1,10) = :d OR
                                substr(last_login_real,1,10) = :d OR
                                substr(logout_time_real,1,10) = :d OR
                                substr(updated_at,1,10) = :d OR
                                login_date = :d
                            )
              AND COALESCE(NULLIF(last_status,''), 'ready') != 'ready'" );
        $stmtFallback->execute([':d' => $audit_date]);
        $rows = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
    }

    return $rows;
}
