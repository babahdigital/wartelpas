<?php

$helpersFile = __DIR__ . '/../report/laporan/helpers.php';
if (file_exists($helpersFile)) {
    require_once $helpersFile;
}

function app_collect_todo_items(array $env, $session = '', $backupKey = '')
{
    $todo_list = [];
    $db_sync = null;
    try {
        $system_cfg = $env['system'] ?? [];
        $db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
        if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
            $stats_db = $db_rel;
        } else {
            $stats_db = dirname(__DIR__) . '/' . ltrim($db_rel, '/');
        }
        if (is_file($stats_db)) {
            $db_sync = new PDO('sqlite:' . $stats_db);
            $db_sync->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $today = date('Y-m-d');
            $now_ts = time();
            $late_minutes = 60;
            $todo_cfg = $env['todo'] ?? [];
            $audit_after = (string)($todo_cfg['audit_after'] ?? '18:00');
            $settlement_open = (string)($todo_cfg['settlement_open'] ?? $audit_after);
            $settlement_close = (string)($todo_cfg['settlement_close'] ?? '23:59');
            $phone_after = (string)($todo_cfg['phone_after'] ?? $audit_after);
            $settle_running_minutes = (int)($todo_cfg['settlement_running_minutes'] ?? 20);
            $is_super = function_exists('isSuperAdmin') ? isSuperAdmin() : false;
            $is_operator = function_exists('isOperator') ? isOperator() : false;
            $can_force_sync = $is_super || ($is_operator && function_exists('operator_can') && operator_can('sync_sales_force'));
            $can_todo_ack = $is_super || ($is_operator && function_exists('operator_can') && operator_can('todo_ack'));

            $parse_time = function ($timeStr) use ($today) {
                $timeStr = trim((string)$timeStr);
                if ($timeStr === '' || !preg_match('/^\d{2}:\d{2}$/', $timeStr)) return null;
                return strtotime($today . ' ' . $timeStr);
            };
            $audit_ts = $parse_time($audit_after);
            $settle_open_ts = $parse_time($settlement_open);
            $settle_close_ts = $parse_time($settlement_close);
            $phone_ts = $parse_time($phone_after);
            $is_after_audit = $audit_ts ? ($now_ts >= $audit_ts) : false;
            $is_after_phone = $phone_ts ? ($now_ts >= $phone_ts) : false;
            $is_settlement_window = $settle_open_ts && $settle_close_ts
                ? ($now_ts >= $settle_open_ts && $now_ts <= $settle_close_ts)
                : false;
            $is_settlement_after_close = $settle_close_ts ? ($now_ts > $settle_close_ts) : false;

            $todo_next = $_SERVER['REQUEST_URI'] ?? '';
            if ($todo_next === '') $todo_next = './?session=' . urlencode($session);

            $db_sync->exec("CREATE TABLE IF NOT EXISTS todo_ack (key TEXT, report_date TEXT, ack_at TEXT, PRIMARY KEY (key, report_date))");
            $format_ddmmyyyy = function ($dateStr) {
                $dateStr = trim((string)$dateStr);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) return $dateStr;
                $parts = explode('-', $dateStr);
                return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            };
            $todo_is_ack = function ($key, $date) use ($db_sync) {
                $stmt = $db_sync->prepare("SELECT ack_at FROM todo_ack WHERE key = :k AND report_date = :d LIMIT 1");
                $stmt->execute([':k' => $key, ':d' => $date]);
                $val = (string)$stmt->fetchColumn();
                return $val !== '';
            };
            $todo_ack_url = function ($key, $dateOverride = null) use ($session, $todo_next, $can_todo_ack) {
                if (!$can_todo_ack) return '';
                $ack_date = $dateOverride ?: date('Y-m-d');
                $qs = http_build_query([
                    'session' => $session,
                    'key' => $key,
                    'date' => $ack_date,
                    'next' => $todo_next
                ]);
                return './tools/todo_ack.php?' . $qs;
            };

            $profile_price_map = $env['pricing']['profile_prices'] ?? [];
            $rows_src_cache = [];
            $audit_rows_cache = [];

            $get_rows_src = function ($date) use ($db_sync, &$rows_src_cache) {
                if (isset($rows_src_cache[$date])) return $rows_src_cache[$date];
                if (function_exists('fetch_rows_for_audit')) {
                    $rows_src_cache[$date] = fetch_rows_for_audit($db_sync, $date);
                } else {
                    $rows_src_cache[$date] = [];
                }
                return $rows_src_cache[$date];
            };

            $get_audit_rows = function ($date) use ($db_sync, &$audit_rows_cache) {
                if (isset($audit_rows_cache[$date])) return $audit_rows_cache[$date];
                try {
                    $stmt = $db_sync->prepare("SELECT report_date, blok_name, expected_setoran, actual_setoran, reported_qty, refund_amt, refund_desc, kurang_bayar_amt, kurang_bayar_desc, user_evidence FROM audit_rekap_manual WHERE report_date = :d");
                    $stmt->execute([':d' => $date]);
                    $audit_rows_cache[$date] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $audit_rows_cache[$date] = [];
                }
                return $audit_rows_cache[$date];
            };

            $is_paid_desc = function ($desc) {
                $desc = strtolower(trim((string)$desc));
                if ($desc === '') return false;
                return (strpos($desc, 'sudah dibayar') !== false
                    || strpos($desc, 'lunas') !== false
                    || strpos($desc, 'terbayar') !== false);
            };

            $calc_effective_selisih = function ($ar, $audit_date) use ($get_rows_src, $profile_price_map) {
                $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                $rows_src = $get_rows_src($audit_date);
                if (!empty($rows_src) && function_exists('calc_expected_for_block') && function_exists('normalize_block_name')) {
                    $expected = calc_expected_for_block($rows_src, $audit_date, normalize_block_name($ar['blok_name'] ?? ''));
                    $expected_setoran = (int)($expected['net'] ?? $expected_setoran);
                }

                $actual_setoran = function_exists('normalize_actual_setoran')
                    ? normalize_actual_setoran($ar)
                    : (int)($ar['actual_setoran'] ?? 0);

                $manual_display_qty = 0;
                $manual_display_setoran = 0;
                $has_manual_evidence = false;
                $manual_setoran_override = false;
                $profile_qty_map = [];
                $status_count_map = [];

                if (!empty($ar['user_evidence'])) {
                    $evidence = json_decode((string)$ar['user_evidence'], true);
                    if (is_array($evidence)) {
                        $has_manual_evidence = true;
                        $manual_setoran_override = !empty($evidence['manual_setoran']);
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
                                elseif ($status === 'rusak') $status_count_map[$kind]['rusak']++;
                            }
                        }
                    }
                }

                if ($has_manual_evidence) {
                    foreach ($profile_qty_map as $k => $qty) {
                        $qty = (int)$qty;
                        $manual_display_qty += $qty;
                        $counts = $status_count_map[$k] ?? ['invalid' => 0, 'retur' => 0, 'rusak' => 0];
                        $money_qty = max(0, $qty - (int)$counts['rusak'] - (int)$counts['invalid']);
                        $price_val = isset($profile_price_map[$k]) ? (int)$profile_price_map[$k] : (int)resolve_price_from_profile($k);
                        $manual_display_setoran += ($money_qty * $price_val);
                    }
                    if ($manual_setoran_override || ($actual_setoran > 0 && $actual_setoran !== $manual_display_setoran)) {
                        $manual_display_setoran = $actual_setoran;
                    }
                    if ($manual_display_qty === 0) {
                        $manual_display_setoran = $actual_setoran;
                    }
                } else {
                    $manual_display_setoran = $actual_setoran;
                }

                $selisih_raw = $manual_display_setoran - $expected_setoran;
                $refund_amt = (int)($ar['refund_amt'] ?? 0);
                $kurang_bayar_amt = (int)($ar['kurang_bayar_amt'] ?? 0);
                $selisih_adj = $selisih_raw - $refund_amt + $kurang_bayar_amt;
                return [$selisih_raw, $selisih_adj];
            };

            $settle_status_pre = '';
            $settle_message_pre = '';
            $settle_sales_sync_at_pre = '';
            $sync_failed = false;
            try {
                $stmtSetPre = $db_sync->prepare("SELECT status, message, sales_sync_at FROM settlement_log WHERE report_date = :d LIMIT 1");
                $stmtSetPre->execute([':d' => $today]);
                $srowPre = $stmtSetPre->fetch(PDO::FETCH_ASSOC) ?: [];
                $settle_status_pre = strtolower((string)($srowPre['status'] ?? ''));
                $settle_message_pre = (string)($srowPre['message'] ?? '');
                $settle_sales_sync_at_pre = (string)($srowPre['sales_sync_at'] ?? '');
                if ($settle_status_pre === 'done' && stripos($settle_message_pre, 'SYNC SALES: GAGAL') !== false) {
                    $sync_failed = true;
                }
            } catch (Exception $e) {
                $sync_failed = false;
            }

            if ($sync_failed) {
                try {
                    $sales_cols = $db_sync->query("PRAGMA table_info(sales_history)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $sales_names = array_map(function ($c) { return $c['name'] ?? ''; }, $sales_cols);
                    $sales_col = in_array('sync_date', $sales_names, true)
                        ? 'sync_date'
                        : (in_array('created_at', $sales_names, true) ? 'created_at' : '');
                    if ($sales_col !== '') {
                        $last_sales_sync = (string)$db_sync->query("SELECT MAX($sales_col) FROM sales_history")->fetchColumn();
                        if ($last_sales_sync !== '') {
                            $last_ts = strtotime($last_sales_sync);
                            $settle_ts = $settle_sales_sync_at_pre !== '' ? strtotime($settle_sales_sync_at_pre) : 0;
                            if ($last_ts && (!$settle_ts || $last_ts > $settle_ts)) {
                                $sync_failed = false;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $sync_failed = $sync_failed;
                }
            }

            // Live Sales stale detection
            $last_live_full = '-';
            $live_cols = $db_sync->query("PRAGMA table_info(live_sales)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $live_names = array_map(function ($c) { return $c['name'] ?? ''; }, $live_cols);
            $live_col = in_array('sync_date', $live_names, true)
                ? 'sync_date'
                : (in_array('sale_datetime', $live_names, true)
                    ? 'sale_datetime'
                    : (in_array('created_at', $live_names, true) ? 'created_at' : ''));
            if ($live_col !== '') {
                $last_live_full = (string)$db_sync->query("SELECT MAX($live_col) FROM live_sales")->fetchColumn();
            }
            if ($last_live_full === '') $last_live_full = '-';

            $live_diff = null;
            $live_title = '-';
            $live_pending_today = 0;
            if ($last_live_full !== '-') {
                $ts = strtotime($last_live_full);
                if ($ts) {
                    $live_title = date('d-m-Y H:i', $ts);
                    $live_diff = (int)floor(($now_ts - $ts) / 60);
                }
            }
            try {
                $stmtPend = $db_sync->prepare("SELECT COUNT(*) FROM live_sales WHERE sync_status = 'pending' AND (sale_date = :d OR substr(sale_datetime,1,10) = :d)");
                $stmtPend->execute([':d' => $today]);
                $live_pending_today = (int)$stmtPend->fetchColumn();
            } catch (Exception $e) {
                $live_pending_today = 0;
            }

            $is_live_ack = $todo_is_ack('live_sales_stale', $today);
            if ($sync_failed && !$todo_is_ack('sync_sales_failed', $today)) {
                $can_sync_fix = $is_super || ($is_operator && function_exists('operator_can') && operator_can('sync_sales_force'));
                $desc = 'Sync sales gagal. Jalankan sync ulang agar laporan final akurat.';
                $desc .= ' Terakhir: ' . ($live_title !== '-' ? $live_title : 'Tidak ada data');
                if ($live_diff !== null) $desc .= ' (selisih ' . $live_diff . ' menit)';
                if ($settle_message_pre !== '') {
                    $desc .= ' (' . $settle_message_pre . ')';
                }
                $action_url = $can_sync_fix ? ('./tools/settlement_sync_fix.php?session=' . urlencode($session) . '&force=1') : '';
                $action_label = $can_sync_fix ? 'Bersihkan Status' : '';
                $todo_list[] = [
                    'id' => 'sync_sales_failed',
                    'title' => 'Sync Sales Gagal',
                    'desc' => $desc,
                    'level' => 'danger',
                    'action_label' => $action_label,
                    'action_url' => $action_url,
                    'action_ajax' => $can_sync_fix,
                    'action_ack' => '',
                    'action_target' => '_blank'
                ];
            } elseif (($last_live_full === '-' || ($live_diff !== null && $live_diff >= $late_minutes))
                && !$is_live_ack) {
                $desc = 'Terakhir: ' . ($live_title !== '-' ? $live_title : 'Tidak ada data');
                if ($live_diff !== null) $desc .= ' (selisih ' . $live_diff . ' menit)';
                $desc .= '. Hubungi administrator jika masih belum normal.';
                $action_url = '';
                $action_label = '';
                if ($can_force_sync && $backupKey !== '') {
                    $action_url = './report/laporan/services/sync_sales.php?key=' . urlencode($backupKey) . '&session=' . urlencode($session) . '&force=1';
                    $action_label = 'Sync Sales (Force)';
                }
                $todo_list[] = [
                    'id' => 'live_sales_stale',
                    'title' => 'Live Sales tidak update',
                    'desc' => $desc,
                    'level' => 'danger',
                    'action_label' => $action_label,
                    'action_url' => $action_url,
                    'action_ajax' => $action_url !== '',
                    'action_ack' => $todo_ack_url('live_sales_stale'),
                    'action_target' => '_blank'
                ];
            }

            // Audit & Settlement status
            $audit_t_count = 0;
            try {
                $stmtTodayAudit = $db_sync->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d");
                $stmtTodayAudit->execute([':d' => $today]);
                $audit_t_count = (int)$stmtTodayAudit->fetchColumn();
            } catch (Exception $e) {
                $audit_t_count = 0;
            }

            $audit_rebuild_needed = false;
            if (isset($_GET['debug_audit_todo']) && $_GET['debug_audit_todo'] === '1') {
                $audit_rebuild_needed = true;
            }
            if ($audit_t_count > 0) {
                try {
                    $stmtAuditChk = $db_sync->prepare("SELECT expected_qty, reported_qty, expected_setoran, actual_setoran, selisih_qty, selisih_setoran FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtAuditChk->execute([':d' => $today]);
                    foreach ($stmtAuditChk->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                        $calc_qty = (int)($ar['reported_qty'] ?? 0) - (int)($ar['expected_qty'] ?? 0);
                        $calc_set = (int)($ar['actual_setoran'] ?? 0) - (int)($ar['expected_setoran'] ?? 0);
                        if ($calc_qty !== (int)($ar['selisih_qty'] ?? 0) || $calc_set !== (int)($ar['selisih_setoran'] ?? 0)) {
                            $audit_rebuild_needed = true;
                            break;
                        }
                    }
                } catch (Exception $e) {
                    $audit_rebuild_needed = false;
                }
            }

            $audit_locked_today = false;
            try {
                $stmtLock = $db_sync->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(is_locked,0) = 1");
                $stmtLock->execute([':d' => $today]);
                $audit_locked_today = (int)$stmtLock->fetchColumn() > 0;
            } catch (Exception $e) {
                $audit_locked_today = false;
            }

            $settled_today = false;
            $settle_status = '';
            $settle_triggered = '';
            $settle_completed = '';
            $settle_sales_sync_at = '';
            $settle_message = '';
            try {
                $stmtSet = $db_sync->prepare("SELECT status, triggered_at, completed_at, sales_sync_at, message FROM settlement_log WHERE report_date = :d LIMIT 1");
                $stmtSet->execute([':d' => $today]);
                $srow = $stmtSet->fetch(PDO::FETCH_ASSOC);
                if ($srow) {
                    $settle_status = strtolower((string)($srow['status'] ?? ''));
                    $settle_triggered = (string)($srow['triggered_at'] ?? '');
                    $settle_completed = (string)($srow['completed_at'] ?? '');
                    $settle_sales_sync_at = (string)($srow['sales_sync_at'] ?? '');
                    $settle_message = (string)($srow['message'] ?? '');
                }
                $settled_today = $settle_status === 'done';
            } catch (Exception $e) {
                $settled_today = false;
            }

            $report_url = './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($today);
            if ($is_after_audit && $audit_t_count === 0) {
                $todo_list[] = [
                    'id' => 'audit_missing_today',
                    'title' => 'Audit hari ini belum diisi',
                    'desc' => 'Lengkapi audit harian terlebih dahulu sebelum settlement.',
                    'level' => 'warn',
                    'action_label' => 'Buka Laporan Harian',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            } elseif ($audit_t_count > 0 && !$settled_today && $is_settlement_window) {
                $todo_list[] = [
                    'id' => 'settlement_pending',
                    'title' => 'Audit hari ini belum di settlement',
                    'desc' => 'Audit sudah terisi, lanjutkan settlement harian.',
                    'level' => 'warn',
                    'action_label' => 'Buka Settlement',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            } elseif ($audit_t_count > 0 && !$settled_today && $is_settlement_after_close) {
                $todo_list[] = [
                    'id' => 'settlement_overdue',
                    'title' => 'Audit hari ini belum di settlement',
                    'desc' => 'Jam settlement sudah lewat. Lakukan settlement agar laporan akurat.',
                    'level' => 'danger',
                    'action_label' => 'Buka Settlement',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            }

            if ($settle_status === 'failed') {
                $todo_list[] = [
                    'id' => 'settlement_failed',
                    'title' => 'Settlement gagal',
                    'desc' => 'Proses settlement gagal. Ulangi settlement atau hubungi administrator.',
                    'level' => 'danger',
                    'action_label' => 'Buka Settlement',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            }

            if ($audit_rebuild_needed) {
                $can_rebuild = $is_super || ($is_operator && function_exists('operator_can') && operator_can('audit_manual'));
                if ($can_rebuild) {
                    $todo_list[] = [
                        'id' => 'audit_rebuild_needed',
                        'title' => 'Audit perlu diperbaiki',
                        'desc' => 'Ditemukan selisih audit yang tidak sesuai. Jalankan perbaikan audit agar data sinkron.',
                        'level' => 'warn',
                        'action_label' => 'Perbaiki Audit',
                        'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($today) . '&audit_rebuild=1',
                        'action_target' => '_self'
                    ];
                }
            }

            if ($settle_status === 'running' && $settle_triggered !== '') {
                $trigger_ts = strtotime($settle_triggered);
                if ($trigger_ts && ($now_ts - $trigger_ts) > ($settle_running_minutes * 60)) {
                    $todo_list[] = [
                        'id' => 'settlement_stuck',
                        'title' => 'Settlement masih berjalan',
                        'desc' => 'Settlement berjalan terlalu lama. Cek status settlement.',
                        'level' => 'warn',
                        'action_label' => 'Buka Settlement',
                        'action_url' => $report_url,
                        'action_target' => '_self'
                    ];
                }
            }

            if ($settled_today && $live_pending_today > 0 && $is_after_audit) {
                $pending_after_settle = $live_pending_today;
                if ($settle_completed !== '') {
                    try {
                        $stmtPendAfter = $db_sync->prepare("SELECT COUNT(*) FROM live_sales WHERE sync_status = 'pending' AND (sale_datetime <= :cutoff OR sale_date <= :d)");
                        $stmtPendAfter->execute([
                            ':cutoff' => $settle_completed,
                            ':d' => $today
                        ]);
                        $pending_after_settle = (int)$stmtPendAfter->fetchColumn();
                    } catch (Exception $e) {
                        $pending_after_settle = $live_pending_today;
                    }
                }
                if ($pending_after_settle <= 0) {
                    $pending_after_settle = 0;
                }
                if ($pending_after_settle === 0) {
                    // no stale pending after settlement
                } else {
                $sync_label = ($can_force_sync && $backupKey !== '') ? 'Sync Sales (Force)' : 'Hubungi Admin (Sync Sales)';
                $sync_url = ($can_force_sync && $backupKey !== '')
                    ? './report/laporan/services/sync_sales.php?key=' . urlencode($backupKey) . '&session=' . urlencode($session) . '&force=1'
                    : '';
                $sync_ajax = ($can_force_sync && $backupKey !== '');
                $sync_ack = ($can_force_sync && $backupKey !== '') ? $todo_ack_url('live_pending_after_settle') : '';
                $sync_target = ($can_force_sync && $backupKey !== '') ? '_blank' : '_self';
                $todo_list[] = [
                    'id' => 'live_pending_after_settle',
                    'title' => 'Live Sales masih pending',
                    'desc' => 'Masih ada ' . $pending_after_settle . ' transaksi live pending setelah settlement. Lakukan sync ulang.',
                    'level' => 'warn',
                    'action_label' => $sync_label,
                    'action_url' => $sync_url,
                    'action_ajax' => $sync_ajax,
                    'action_ack' => $sync_ack,
                    'action_target' => $sync_target
                ];
                }
            }

            // Audit missing / piutang
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $today_report_url = './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($today);
            $today_label = $format_ddmmyyyy($today);
            $yesterday_label = $format_ddmmyyyy($yesterday);
            try {
                $stmtAuditCount = $db_sync->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d");
                $stmtAuditCount->execute([':d' => $yesterday]);
                $audit_y_count = (int)$stmtAuditCount->fetchColumn();
            } catch (Exception $e) {
                $audit_y_count = 0;
            }
            if ($audit_y_count === 0) {
                $todo_list[] = [
                    'id' => 'audit_missing_' . $yesterday,
                    'title' => 'Audit belum diisi (Kemarin)',
                    'desc' => 'Audit harian tanggal ' . $yesterday_label . ' belum diinput. Lengkapi agar laporan akurat.',
                    'level' => 'danger',
                    'action_label' => 'Buka Tanggal ' . $yesterday_label,
                    'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($yesterday),
                    'action_target' => '_self'
                ];
            } else {
                try {
                    $rows_y = $get_audit_rows($yesterday);
                    $remain = 0;
                    $block_rem = [];
                    foreach ($rows_y as $ar) {
                        [$ss_raw] = $calc_effective_selisih($ar, $yesterday);
                        if ($ss_raw >= 0) continue;
                        $neg = -$ss_raw;
                        $kb_amt = (int)($ar['kurang_bayar_amt'] ?? 0);
                        $kb_desc = (string)($ar['kurang_bayar_desc'] ?? '');
                        $paid = 0;
                        if ($kb_amt > 0) $paid = $kb_amt;
                        elseif ($is_paid_desc($kb_desc)) $paid = $neg;
                        $rem = $neg - $paid;
                        if ($rem <= 0) continue;
                        $remain += $rem;
                        $bname = $format_block_label($ar['blok_name'] ?? '');
                        $block_rem[$bname] = ($block_rem[$bname] ?? 0) + $rem;
                    }
                    if ($remain > 0) {
                        $abs = number_format($remain, 0, ",", ".");
                        $parts = [];
                        foreach ($block_rem as $bname => $brem) {
                            if ($brem <= 0) continue;
                            $parts[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                        }
                        $blok_list = !empty($parts) ? (' ' . implode(', ', $parts) . '.') : '';
                        $todo_list[] = [
                            'id' => 'audit_kurang_' . $yesterday,
                            'title' => 'Audit piutang (Kemarin)',
                            'desc' => 'Terdapat kekurangan Rp ' . $abs . ' pada audit tanggal ' . $yesterday_label . '.' . $blok_list,
                            'level' => 'danger',
                            'action_label' => 'Buka Tanggal ' . $yesterday_label,
                            'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($yesterday),
                            'action_target' => '_self'
                        ];
                    }
                } catch (Exception $e) {
                }
            }

            $format_block_label = function($name) {
                $name = trim((string)$name);
                if ($name === '') return '-';
                $name = str_replace('-', ' ', $name);
                $name = preg_replace('/^BLOK\s+/i', '', $name);
                $name = trim($name);
                if ($name === '') return '-';
                return 'BLOK ' . strtoupper($name);
            };

            // Piutang hari ini
            if ($audit_t_count > 0) {
                try {
                    $stmtExp = $db_sync->prepare("SELECT COUNT(*) AS cnt, SUM(COALESCE(expenses_amt,0)) AS total, SUM(CASE WHEN expenses_amt IS NULL OR expenses_amt = '' THEN 1 ELSE 0 END) AS missing FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtExp->execute([':d' => $today]);
                    $expRow = $stmtExp->fetch(PDO::FETCH_ASSOC) ?: [];
                    $expTotal = (int)($expRow['total'] ?? 0);
                    $expMissing = (int)($expRow['missing'] ?? 0);
                    if ($expMissing > 0) {
                        $desc = 'Masih ada ' . $expMissing . ' data audit tanpa pengeluaran. Jika tidak ada pengeluaran, isi 0 di audit manual.';
                        $todo_list[] = [
                            'id' => 'audit_expense_missing_' . $today,
                            'title' => 'Pengeluaran audit belum diisi',
                            'desc' => $desc,
                            'level' => 'warn',
                            'action_label' => 'Buka Audit Hari Ini',
                            'action_url' => $today_report_url,
                            'action_target' => '_self'
                        ];
                    } elseif ($expTotal <= 0 && $is_after_audit && !$todo_is_ack('audit_expense_zero', $today)) {
                        $todo_list[] = [
                            'id' => 'audit_expense_zero_' . $today,
                            'title' => 'Pengeluaran harian (konfirmasi)',
                            'desc' => 'Total pengeluaran hari ini 0. Jika memang tidak ada pengeluaran, klik "Sesuai".',
                            'level' => 'info',
                            'action_label' => 'Sesuai',
                            'action_url' => $todo_ack_url('audit_expense_zero', $today),
                            'action_target' => '_self'
                        ];
                    }
                } catch (Exception $e) {
                }
            }
            if ($audit_t_count > 0) {
                try {
                    $rows_t = $get_audit_rows($today);
                    $remainT = 0;
                    $block_rem = [];
                    foreach ($rows_t as $ar) {
                        [$ss_raw] = $calc_effective_selisih($ar, $today);
                        if ($ss_raw >= 0) continue;
                        $neg = -$ss_raw;
                        $kb_amt = (int)($ar['kurang_bayar_amt'] ?? 0);
                        $kb_desc = (string)($ar['kurang_bayar_desc'] ?? '');
                        $paid = 0;
                        if ($kb_amt > 0) $paid = $kb_amt;
                        elseif ($is_paid_desc($kb_desc)) $paid = $neg;
                        $rem = $neg - $paid;
                        if ($rem <= 0) continue;
                        $remainT += $rem;
                        $bname = $format_block_label($ar['blok_name'] ?? '');
                        $block_rem[$bname] = ($block_rem[$bname] ?? 0) + $rem;
                    }
                    if ($remainT > 0) {
                        $absT = number_format($remainT, 0, ",", ".");
                        $parts = [];
                        foreach ($block_rem as $bname => $brem) {
                            if ($brem <= 0) continue;
                            $parts[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                        }
                        $blok_list = !empty($parts) ? (' ' . implode(', ', $parts) . '.') : '';
                        $todo_list[] = [
                            'id' => 'audit_kurang_' . $today,
                            'title' => 'Audit piutang (Hari Ini)',
                            'desc' => 'Terdapat kekurangan Rp ' . $absT . ' pada audit tanggal ' . $today_label . '.' . $blok_list,
                            'level' => 'danger',
                            'action_label' => 'Buka Tanggal ' . $today_label,
                            'action_url' => $today_report_url,
                            'action_target' => '_self'
                        ];
                    }
                } catch (Exception $e) {
                }
            }

            // Refund hari ini (selisih plus belum dicatat)
            if ($audit_t_count > 0) {
                try {
                    $rows_t = $get_audit_rows($today);
                    $remainT = 0;
                    $block_rem = [];
                    foreach ($rows_t as $ar) {
                        [$ss_raw] = $calc_effective_selisih($ar, $today);
                        if ($ss_raw <= 0) continue;
                        $refund_amt = (int)($ar['refund_amt'] ?? 0);
                        $rem = $ss_raw - $refund_amt;
                        if ($rem <= 0) continue;
                        $remainT += $rem;
                        $bname = $format_block_label($ar['blok_name'] ?? '');
                        $block_rem[$bname] = ($block_rem[$bname] ?? 0) + $rem;
                    }
                    if ($remainT > 0) {
                        $parts = [];
                        foreach ($block_rem as $bname => $brem) {
                            if ($brem <= 0) continue;
                            $parts[] = $bname . ' (lebih ' . number_format($brem, 0, ",", ".") . ')';
                        }
                        $blok_list = !empty($parts) ? (' ' . implode(', ', $parts) . '.') : '';
                        $todo_list[] = [
                            'id' => 'refund_pending_' . $today,
                            'title' => 'Refund belum dicatat (Hari Ini)',
                            'desc' => 'Terdapat selisih lebih Rp ' . number_format($remainT, 0, ",", ".") . ' pada audit tanggal ' . $today_label . '.' . $blok_list,
                            'level' => 'warn',
                            'action_label' => 'Buka Tanggal ' . $today_label,
                            'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($today),
                            'action_target' => '_self'
                        ];
                    }
                } catch (Exception $e) {
                }
            }

            // Target sistem kosong (expected_setoran=0) tapi ada setoran
            try {
                $stmtZero = $db_sync->prepare("SELECT report_date, blok_name FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(expected_setoran,0)=0 AND COALESCE(actual_setoran,0)>0");
                $stmtZero->execute([':d' => $today]);
                $rowsZero = $stmtZero->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rowsZero)) {
                    $parts = [];
                    foreach ($rowsZero as $zr) {
                        $parts[] = $format_block_label($zr['blok_name'] ?? '');
                    }
                    $parts = array_values(array_unique(array_filter($parts)));
                    $blok_list = !empty($parts) ? (' Blok: ' . implode(', ', $parts) . '.') : '';
                    $todo_list[] = [
                        'id' => 'audit_target_zero_' . $today,
                        'title' => 'Target sistem audit kosong',
                        'desc' => 'Ada target sistem 0 namun ada setoran pada audit tanggal ' . $today_label . '.' . $blok_list . ' Jalankan Rebuild Target Sistem.',
                        'level' => 'warn',
                        'action_label' => 'Buka Audit ' . $today_label,
                        'action_url' => './?report=audit_session&session=' . urlencode($session) . '&show=harian&date=' . urlencode($today),
                        'action_target' => '_self'
                    ];
                }
            } catch (Exception $e) {
            }

            try {
                $stmtZeroOther = $db_sync->query("SELECT report_date, GROUP_CONCAT(DISTINCT blok_name) AS blocks FROM audit_rekap_manual WHERE COALESCE(expected_setoran,0)=0 AND COALESCE(actual_setoran,0)>0 GROUP BY report_date ORDER BY report_date DESC LIMIT 10");
                $zeroRows = $stmtZeroOther ? $stmtZeroOther->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Exception $e) {
                $zeroRows = [];
            }
            foreach ($zeroRows as $zr) {
                $rdate = (string)($zr['report_date'] ?? '');
                if ($rdate === '' || $rdate === $today) continue;
                $rlabel = $format_ddmmyyyy($rdate);
                $blok_list = '';
                $raw_blocks = (string)($zr['blocks'] ?? '');
                if ($raw_blocks !== '') {
                    $raw_parts = array_map('trim', explode(',', $raw_blocks));
                    $parts = [];
                    foreach ($raw_parts as $bp) {
                        $parts[] = $format_block_label($bp);
                    }
                    $parts = array_values(array_unique(array_filter($parts)));
                    if (!empty($parts)) {
                        $blok_list = ' Blok: ' . implode(', ', $parts) . '.';
                    }
                }
                $todo_list[] = [
                    'id' => 'audit_target_zero_' . $rdate,
                    'title' => 'Target sistem audit kosong',
                    'desc' => 'Ada target sistem 0 namun ada setoran pada audit tanggal ' . $rlabel . '.' . $blok_list . ' Jalankan Rebuild Target Sistem.',
                    'level' => 'warn',
                    'action_label' => 'Buka Audit ' . $rlabel,
                    'action_url' => './?report=audit_session&session=' . urlencode($session) . '&show=harian&date=' . urlencode($rdate),
                    'action_target' => '_self'
                ];
            }

            // Piutang tanggal lain
            try {
                $stmtDates = $db_sync->query("SELECT DISTINCT report_date FROM audit_rekap_manual ORDER BY report_date DESC LIMIT 20");
                $date_rows = $stmtDates ? $stmtDates->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            } catch (Exception $e) {
                $date_rows = [];
            }
            foreach ($date_rows as $rdate) {
                $rdate = (string)$rdate;
                if ($rdate === '' || $rdate === $today || $rdate === $yesterday) continue;
                $rows_r = $get_audit_rows($rdate);
                $remainV = 0;
                $block_rem = [];
                foreach ($rows_r as $ar) {
                    [$ss_raw] = $calc_effective_selisih($ar, $rdate);
                    if ($ss_raw >= 0) continue;
                    $neg = -$ss_raw;
                    $kb_amt = (int)($ar['kurang_bayar_amt'] ?? 0);
                    $kb_desc = (string)($ar['kurang_bayar_desc'] ?? '');
                    $paid = 0;
                    if ($kb_amt > 0) $paid = $kb_amt;
                    elseif ($is_paid_desc($kb_desc)) $paid = $neg;
                    $rem = $neg - $paid;
                    if ($rem <= 0) continue;
                    $remainV += $rem;
                    $bname = $format_block_label($ar['blok_name'] ?? '');
                    $block_rem[$bname] = ($block_rem[$bname] ?? 0) + $rem;
                }
                if ($remainV <= 0) continue;
                $parts = [];
                foreach ($block_rem as $bname => $brem) {
                    if ($brem <= 0) continue;
                    $parts[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                }
                $blok_list = !empty($parts) ? (' ' . implode(', ', $parts) . '.') : '';
                $rlabel = $format_ddmmyyyy($rdate);
                $desc = 'Terdapat piutang Rp ' . number_format($remainV, 0, ",", ".") . ' pada audit tanggal ' . $rlabel . '.' . $blok_list;
                $todo_list[] = [
                    'id' => 'audit_kurang_' . $rdate,
                    'title' => 'Audit piutang',
                    'desc' => $desc,
                    'level' => 'danger',
                    'action_label' => 'Buka Tanggal ' . $rlabel,
                    'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($rdate),
                    'action_target' => '_self'
                ];
            }

            // Refund tanggal lain (selisih plus belum dicatat)
            foreach ($date_rows as $rdate) {
                $rdate = (string)$rdate;
                if ($rdate === '' || $rdate === $today) continue;
                $rows_r = $get_audit_rows($rdate);
                $remainV = 0;
                $block_rem = [];
                foreach ($rows_r as $ar) {
                    [$ss_raw] = $calc_effective_selisih($ar, $rdate);
                    if ($ss_raw <= 0) continue;
                    $refund_amt = (int)($ar['refund_amt'] ?? 0);
                    $rem = $ss_raw - $refund_amt;
                    if ($rem <= 0) continue;
                    $remainV += $rem;
                    $bname = $format_block_label($ar['blok_name'] ?? '');
                    $block_rem[$bname] = ($block_rem[$bname] ?? 0) + $rem;
                }
                if ($remainV <= 0) continue;
                $parts = [];
                foreach ($block_rem as $bname => $brem) {
                    if ($brem <= 0) continue;
                    $parts[] = $bname . ' (lebih ' . number_format($brem, 0, ",", ".") . ')';
                }
                $blok_list = !empty($parts) ? (' ' . implode(', ', $parts) . '.') : '';
                $rlabel = $format_ddmmyyyy($rdate);
                $todo_list[] = [
                    'id' => 'refund_pending_' . $rdate,
                    'title' => 'Refund belum dicatat',
                    'desc' => 'Terdapat selisih lebih Rp ' . number_format($remainV, 0, ",", ".") . ' pada audit tanggal ' . $rlabel . '.' . $blok_list,
                    'level' => 'warn',
                    'action_label' => 'Buka Tanggal ' . $rlabel,
                    'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($rdate),
                    'action_target' => '_self'
                ];
            }

            // Handphone input review (harian)
            $hp_count = 0;
            $hp_last = '';
            try {
                $stmtHp = $db_sync->prepare("SELECT COUNT(*) AS cnt, MAX(updated_at) AS last_upd FROM phone_block_daily WHERE report_date = :d");
                $stmtHp->execute([':d' => $today]);
                $hp_row = $stmtHp->fetch(PDO::FETCH_ASSOC) ?: [];
                $hp_count = (int)($hp_row['cnt'] ?? 0);
                $hp_last = (string)($hp_row['last_upd'] ?? '');
            } catch (Exception $e) {
                $hp_count = 0;
            }
            if ($is_after_phone && !$todo_is_ack('hp_review', $today)) {
                $hp_last_text = $hp_last !== '' ? date('d-m-Y H:i', strtotime($hp_last)) : '-';
                $hp_hint = $hp_count === 0 ? 'Belum ada data Handphone hari ini.' : 'Belum ada konfirmasi Handphone hari ini.';
                $todo_list[] = [
                    'id' => 'hp_review',
                    'title' => 'Input Handphone belum ditinjau',
                    'desc' => $hp_hint . ' Terakhir: ' . $hp_last_text . '. Mohon tinjau data Handphone.',
                    'level' => 'info',
                    'action_label' => 'Sesuai',
                    'action_url' => $todo_ack_url('hp_review'),
                    'action_target' => '_self'
                ];
            }
        }
    } catch (Exception $e) {
        $todo_list = [];
    }

    return $todo_list;
}
