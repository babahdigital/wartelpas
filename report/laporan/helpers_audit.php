<?php

if (!function_exists('format_bytes_short')) {
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
}

if (!function_exists('norm_date_from_raw_report')) {
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
}

if (!function_exists('month_label_id')) {
    function month_label_id($ym_or_m) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
            '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $ym_or_m = (string)$ym_or_m;
        if (strlen($ym_or_m) >= 7) {
            $y = substr($ym_or_m, 0, 4);
            $m = substr($ym_or_m, 5, 2);
            return ($months[$m] ?? $m) . ' ' . $y;
        }
        return $months[$ym_or_m] ?? $ym_or_m;
    }
}

if (!function_exists('calc_audit_adjusted_setoran')) {
    function calc_audit_adjusted_setoran(array $ar) {
        global $price10, $price30;
        $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
        $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);

        $p10_qty = 0;
        $p30_qty = 0;
        $cnt_rusak_10 = 0;
        $cnt_rusak_30 = 0;
        $cnt_retur_10 = 0;
        $cnt_retur_30 = 0;
        $cnt_invalid_10 = 0;
        $cnt_invalid_30 = 0;
        $profile10_users = 0;
        $profile30_users = 0;
        $has_manual_evidence = false;

        if (!empty($ar['user_evidence'])) {
            $evidence = json_decode((string)$ar['user_evidence'], true);
            if (is_array($evidence)) {
                $has_manual_evidence = true;
                if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                    $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                    $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
                }
                if (!empty($evidence['users']) && is_array($evidence['users'])) {
                    foreach ($evidence['users'] as $ud) {
                        $kind = (string)($ud['profile_kind'] ?? '10');
                        $status = strtolower((string)($ud['last_status'] ?? ''));
                        if ($kind === '30') {
                            $profile30_users++;
                            if ($status === 'invalid') $cnt_invalid_30++;
                            elseif ($status === 'retur') $cnt_retur_30++;
                            elseif ($status === 'rusak') $cnt_rusak_30++;
                        } else {
                            $profile10_users++;
                            if ($status === 'invalid') $cnt_invalid_10++;
                            elseif ($status === 'retur') $cnt_retur_10++;
                            elseif ($status === 'rusak') $cnt_rusak_10++;
                        }
                    }
                }
            }
        }

        if ($p10_qty <= 0) $p10_qty = $profile10_users;
        if ($p30_qty <= 0) $p30_qty = $profile30_users;

        if ($has_manual_evidence) {
            $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10 + $cnt_retur_10);
            $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30 + $cnt_retur_30);
            $manual_display_setoran = ($manual_net_qty_10 * (int)$price10) + ($manual_net_qty_30 * (int)$price30);
            $expected_adj_setoran = $expected_setoran;
        } else {
            $manual_display_setoran = $actual_setoran_raw;
            $expected_adj_setoran = $expected_setoran;
        }

        return [$manual_display_setoran, $expected_adj_setoran];
    }
}
