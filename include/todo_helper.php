<?php

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
            $todo_ack_url = function ($key) use ($session, $todo_next, $can_todo_ack) {
                if (!$can_todo_ack) return '';
                $qs = http_build_query([
                    'session' => $session,
                    'key' => $key,
                    'date' => date('Y-m-d'),
                    'next' => $todo_next
                ]);
                return './tools/todo_ack.php?' . $qs;
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
                    $stmtSel = $db_sync->prepare("SELECT SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtSel->execute([':d' => $yesterday]);
                    $rowSel = $stmtSel->fetch(PDO::FETCH_ASSOC) ?: [];
                    $neg = (int)($rowSel['neg'] ?? 0);
                    $kb = (int)($rowSel['kb'] ?? 0);
                    $remain = $neg - $kb;
                    if ($remain > 0) {
                        $abs = number_format($remain, 0, ",", ".");
                        $blok_list = '';
                        try {
                            $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                                FROM audit_rekap_manual
                                WHERE report_date = :d
                                GROUP BY blok_name
                                HAVING (neg - kb) > 0
                                ORDER BY blok_name ASC
                                LIMIT 10");
                            $stmtBlok->execute([':d' => $yesterday]);
                            $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $parts = [];
                            foreach ($rows as $br) {
                                $bname = $format_block_label($br['blok_name'] ?? '');
                                $bneg = (int)($br['neg'] ?? 0);
                                $bkb = (int)($br['kb'] ?? 0);
                                $brem = $bneg - $bkb;
                                if ($brem <= 0) continue;
                                $parts[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                            }
                            if (!empty($parts)) {
                                $blok_list = ' ' . implode(', ', $parts) . '.';
                            }
                        } catch (Exception $e) {
                        }
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
                    if ($expMissing > 0 || $expTotal <= 0) {
                        $desc = $expMissing > 0
                            ? 'Masih ada ' . $expMissing . ' data audit tanpa pengeluaran. Jika tidak ada pengeluaran, isi 0 di audit manual.'
                            : 'Total pengeluaran hari ini masih 0. Jika tidak ada pengeluaran, pastikan isi 0 di audit manual.';
                        $todo_list[] = [
                            'id' => 'audit_expense_missing_' . $today,
                            'title' => 'Pengeluaran audit belum diisi',
                            'desc' => $desc,
                            'level' => 'warn',
                            'action_label' => 'Buka Audit Hari Ini',
                            'action_url' => $today_report_url,
                            'action_target' => '_self'
                        ];
                    }
                } catch (Exception $e) {
                }
            }
            if ($audit_t_count > 0) {
                try {
                    $stmtSelT = $db_sync->prepare("SELECT SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtSelT->execute([':d' => $today]);
                    $rowSelT = $stmtSelT->fetch(PDO::FETCH_ASSOC) ?: [];
                    $negT = (int)($rowSelT['neg'] ?? 0);
                    $kbT = (int)($rowSelT['kb'] ?? 0);
                    $remainT = $negT - $kbT;
                    if ($remainT > 0) {
                        $absT = number_format($remainT, 0, ",", ".");
                        $blok_list = '';
                        try {
                            $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                                FROM audit_rekap_manual
                                WHERE report_date = :d
                                GROUP BY blok_name
                                HAVING (neg - kb) > 0
                                ORDER BY blok_name ASC
                                LIMIT 10");
                            $stmtBlok->execute([':d' => $today]);
                            $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $parts = [];
                            foreach ($rows as $br) {
                                $bname = $format_block_label($br['blok_name'] ?? '');
                                $bneg = (int)($br['neg'] ?? 0);
                                $bkb = (int)($br['kb'] ?? 0);
                                $brem = $bneg - $bkb;
                                if ($brem <= 0) continue;
                                $parts[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                            }
                            if (!empty($parts)) {
                                $blok_list = ' ' . implode(', ', $parts) . '.';
                            }
                        } catch (Exception $e) {
                        }
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

            // Piutang tanggal lain
            try {
                $stmtKb = $db_sync->query("SELECT report_date, SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                    FROM audit_rekap_manual
                    GROUP BY report_date
                    HAVING (neg - kb) > 0
                    ORDER BY report_date DESC
                    LIMIT 20");
                $kb_rows = $stmtKb ? $stmtKb->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Exception $e) {
                $kb_rows = [];
            }
            foreach ($kb_rows as $kr) {
                $rdate = (string)($kr['report_date'] ?? '');
                if ($rdate === '' || $rdate === $today || $rdate === $yesterday) continue;
                $negV = (int)($kr['neg'] ?? 0);
                $kbV = (int)($kr['kb'] ?? 0);
                $remainV = $negV - $kbV;
                if ($remainV <= 0) continue;
                $parts = ['piutang Rp ' . number_format($remainV, 0, ",", ".")];
                $blok_list = '';
                try {
                    $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(CASE WHEN selisih_setoran < 0 THEN -selisih_setoran ELSE 0 END) AS neg, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                        FROM audit_rekap_manual
                        WHERE report_date = :d
                        GROUP BY blok_name
                        HAVING (neg - kb) > 0
                        ORDER BY blok_name ASC
                        LIMIT 10");
                    $stmtBlok->execute([':d' => $rdate]);
                    $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $partsBlok = [];
                    foreach ($rows as $br) {
                        $bname = $format_block_label($br['blok_name'] ?? '');
                        $bneg = (int)($br['neg'] ?? 0);
                        $bkb = (int)($br['kb'] ?? 0);
                        $brem = $bneg - $bkb;
                        if ($brem <= 0) continue;
                        $partsBlok[] = $bname . ' (kurang ' . number_format($brem, 0, ",", ".") . ')';
                    }
                    if (!empty($partsBlok)) {
                        $blok_list = ' ' . implode(', ', $partsBlok) . '.';
                    }
                } catch (Exception $e) {
                }
                $rlabel = $format_ddmmyyyy($rdate);
                $desc = 'Terdapat ' . implode(' + ', $parts) . ' pada audit tanggal ' . $rlabel . '.' . $blok_list;
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
