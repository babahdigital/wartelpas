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
            $todo_ack_url = function ($key) use ($session, $todo_next) {
                $qs = http_build_query([
                    'session' => $session,
                    'key' => $key,
                    'date' => date('Y-m-d'),
                    'next' => $todo_next
                ]);
                return './tools/todo_ack.php?' . $qs;
            };

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

            if (($last_live_full === '-' || ($live_diff !== null && $live_diff >= $late_minutes))
                && !$todo_is_ack('live_sales_stale', $today)) {
                $desc = 'Terakhir: ' . ($live_title !== '-' ? $live_title : 'Tidak ada data');
                if ($live_diff !== null) $desc .= ' (selisih ' . $live_diff . ' menit)';
                $desc .= '. Hubungi administrator jika masih belum normal.';
                $action_url = '';
                $action_label = '';
                if ($is_super && $backupKey !== '') {
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
            if ($is_after_audit && !$audit_locked_today) {
                $todo_list[] = [
                    'id' => 'audit_pending',
                    'title' => 'Audit belum dikunci',
                    'desc' => 'Lengkapi audit harian terlebih dahulu sebelum settlement.',
                    'level' => 'warn',
                    'action_label' => 'Buka Laporan Harian',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            } elseif ($audit_locked_today && !$settled_today && $is_settlement_window) {
                $todo_list[] = [
                    'id' => 'settlement_pending',
                    'title' => 'Settlement belum dilakukan',
                    'desc' => 'Audit sudah dikunci, lanjutkan settlement harian.',
                    'level' => 'warn',
                    'action_label' => 'Buka Settlement',
                    'action_url' => $report_url,
                    'action_target' => '_self'
                ];
            } elseif ($audit_locked_today && !$settled_today && $is_settlement_after_close) {
                $todo_list[] = [
                    'id' => 'settlement_overdue',
                    'title' => 'Settlement terlewat',
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

            if ($settled_today && $live_pending_today > 0) {
                $todo_list[] = [
                    'id' => 'live_pending_after_settle',
                    'title' => 'Live Sales masih pending',
                    'desc' => 'Masih ada ' . $live_pending_today . ' transaksi live pending setelah settlement. Lakukan sync ulang.',
                    'level' => 'warn',
                    'action_label' => ($is_super && $backupKey !== '') ? 'Sync Sales (Force)' : 'Buka Settlement',
                    'action_url' => ($is_super && $backupKey !== '')
                        ? './report/laporan/services/sync_sales.php?key=' . urlencode($backupKey) . '&session=' . urlencode($session) . '&force=1'
                        : $report_url,
                    'action_ajax' => ($is_super && $backupKey !== ''),
                    'action_ack' => ($is_super && $backupKey !== '') ? $todo_ack_url('live_pending_after_settle') : '',
                    'action_target' => ($is_super && $backupKey !== '') ? '_blank' : '_self'
                ];
            }

            // Audit missing / kurang bayar
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
                    $stmtSel = $db_sync->prepare("SELECT SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtSel->execute([':d' => $yesterday]);
                    $rowSel = $stmtSel->fetch(PDO::FETCH_ASSOC) ?: [];
                    $sel = (int)($rowSel['sel'] ?? 0);
                    $kb = (int)($rowSel['kb'] ?? 0);
                    if ($sel < 0 || $kb > 0) {
                        $val = $sel < 0 ? $sel : (0 - $kb);
                        $abs = number_format(abs((int)$val), 0, ",", ".");
                        $blok_list = '';
                        try {
                            $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                                FROM audit_rekap_manual
                                WHERE report_date = :d
                                GROUP BY blok_name
                                HAVING sel < 0 OR kb > 0
                                ORDER BY blok_name ASC
                                LIMIT 10");
                            $stmtBlok->execute([':d' => $yesterday]);
                            $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $parts = [];
                            foreach ($rows as $br) {
                                $bname = trim((string)($br['blok_name'] ?? ''));
                                if ($bname === '') $bname = '-';
                                $bname = str_replace('-', ' ', $bname);
                                $bsel = (int)($br['sel'] ?? 0);
                                $bkb = (int)($br['kb'] ?? 0);
                                $sub = [];
                                if ($bsel < 0) $sub[] = 'selisih ' . number_format(abs($bsel), 0, ",", ".");
                                if ($bkb > 0) $sub[] = 'kurang ' . number_format($bkb, 0, ",", ".");
                                $parts[] = $bname . ' (' . implode(' + ', $sub) . ')';
                            }
                            if (!empty($parts)) {
                                $blok_list = ' ' . implode(', ', $parts) . '.';
                            }
                        } catch (Exception $e) {
                        }
                        $todo_list[] = [
                            'id' => 'audit_kurang_' . $yesterday,
                            'title' => 'Audit kurang bayar (Kemarin)',
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

            // Kurang bayar hari ini
            try {
                $stmtTodayAudit = $db_sync->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d");
                $stmtTodayAudit->execute([':d' => $today]);
                $audit_t_count = (int)$stmtTodayAudit->fetchColumn();
            } catch (Exception $e) {
                $audit_t_count = 0;
            }
            if ($audit_t_count > 0) {
                try {
                    $stmtSelT = $db_sync->prepare("SELECT SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb FROM audit_rekap_manual WHERE report_date = :d");
                    $stmtSelT->execute([':d' => $today]);
                    $rowSelT = $stmtSelT->fetch(PDO::FETCH_ASSOC) ?: [];
                    $selT = (int)($rowSelT['sel'] ?? 0);
                    $kbT = (int)($rowSelT['kb'] ?? 0);
                    if ($selT < 0 || $kbT > 0) {
                        $valT = $selT < 0 ? $selT : (0 - $kbT);
                        $absT = number_format(abs((int)$valT), 0, ",", ".");
                        $blok_list = '';
                        try {
                            $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                                FROM audit_rekap_manual
                                WHERE report_date = :d
                                GROUP BY blok_name
                                HAVING sel < 0 OR kb > 0
                                ORDER BY blok_name ASC
                                LIMIT 10");
                            $stmtBlok->execute([':d' => $today]);
                            $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $parts = [];
                            foreach ($rows as $br) {
                                $bname = trim((string)($br['blok_name'] ?? ''));
                                if ($bname === '') $bname = '-';
                                $bname = str_replace('-', ' ', $bname);
                                $bsel = (int)($br['sel'] ?? 0);
                                $bkb = (int)($br['kb'] ?? 0);
                                $sub = [];
                                if ($bsel < 0) $sub[] = 'selisih ' . number_format(abs($bsel), 0, ",", ".");
                                if ($bkb > 0) $sub[] = 'kurang ' . number_format($bkb, 0, ",", ".");
                                $parts[] = $bname . ' (' . implode(' + ', $sub) . ')';
                            }
                            if (!empty($parts)) {
                                $blok_list = ' ' . implode(', ', $parts) . '.';
                            }
                        } catch (Exception $e) {
                        }
                        $todo_list[] = [
                            'id' => 'audit_kurang_' . $today,
                            'title' => 'Audit kurang bayar (Hari Ini)',
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

            // Kurang bayar tanggal lain
            try {
                $stmtKb = $db_sync->query("SELECT report_date, SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                    FROM audit_rekap_manual
                    GROUP BY report_date
                    HAVING sel < 0 OR kb > 0
                    ORDER BY report_date DESC
                    LIMIT 20");
                $kb_rows = $stmtKb ? $stmtKb->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Exception $e) {
                $kb_rows = [];
            }
            foreach ($kb_rows as $kr) {
                $rdate = (string)($kr['report_date'] ?? '');
                if ($rdate === '' || $rdate === $today || $rdate === $yesterday) continue;
                $selV = (int)($kr['sel'] ?? 0);
                $kbV = (int)($kr['kb'] ?? 0);
                if ($selV >= 0 && $kbV <= 0) continue;
                $parts = [];
                if ($selV < 0) {
                    $parts[] = 'selisih Rp ' . number_format(abs($selV), 0, ",", ".");
                }
                if ($kbV > 0) {
                    $parts[] = 'kurang bayar Rp ' . number_format($kbV, 0, ",", ".");
                }
                $blok_list = '';
                try {
                    $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(COALESCE(selisih_setoran,0)) AS sel, SUM(COALESCE(kurang_bayar_amt,0)) AS kb
                        FROM audit_rekap_manual
                        WHERE report_date = :d
                        GROUP BY blok_name
                        HAVING sel < 0 OR kb > 0
                        ORDER BY blok_name ASC
                        LIMIT 10");
                    $stmtBlok->execute([':d' => $rdate]);
                    $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $partsBlok = [];
                    foreach ($rows as $br) {
                        $bname = trim((string)($br['blok_name'] ?? ''));
                        if ($bname === '') $bname = '-';
                        $bname = str_replace('-', ' ', $bname);
                        $bsel = (int)($br['sel'] ?? 0);
                        $bkb = (int)($br['kb'] ?? 0);
                        $sub = [];
                        if ($bsel < 0) $sub[] = 'selisih ' . number_format(abs($bsel), 0, ",", ".");
                        if ($bkb > 0) $sub[] = 'kurang ' . number_format($bkb, 0, ",", ".");
                        $partsBlok[] = $bname . ' (' . implode(' + ', $sub) . ')';
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
                    'title' => 'Audit kurang bayar',
                    'desc' => $desc,
                    'level' => 'danger',
                    'action_label' => 'Buka Tanggal ' . $rlabel,
                    'action_url' => './?report=selling&session=' . urlencode($session) . '&date=' . urlencode($rdate),
                    'action_target' => '_self'
                ];
            }

            // Refund belum diaudit (semua tanggal yang belum locked)
            try {
                $stmtRefund = $db_sync->query("SELECT report_date, SUM(COALESCE(refund_amt,0)) AS total_refund
                    FROM audit_rekap_manual
                    WHERE COALESCE(refund_amt,0) > 0 AND COALESCE(is_locked,0) = 0
                    GROUP BY report_date
                    ORDER BY report_date DESC");
                $refund_rows = $stmtRefund ? $stmtRefund->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Exception $e) {
                $refund_rows = [];
            }
            foreach ($refund_rows as $rr) {
                $rdate = (string)($rr['report_date'] ?? '');
                if ($rdate === '') continue;
                $rlabel = $format_ddmmyyyy($rdate);
                $amt = number_format((int)($rr['total_refund'] ?? 0), 0, ",", ".");
                $blok_list = '';
                try {
                    $stmtBlok = $db_sync->prepare("SELECT blok_name, SUM(COALESCE(refund_amt,0)) AS total_refund
                        FROM audit_rekap_manual
                        WHERE COALESCE(refund_amt,0) > 0 AND COALESCE(is_locked,0) = 0 AND report_date = :d
                        GROUP BY blok_name
                        ORDER BY blok_name ASC
                        LIMIT 10");
                    $stmtBlok->execute([':d' => $rdate]);
                    $rows = $stmtBlok->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $parts = [];
                    foreach ($rows as $br) {
                        $bname = trim((string)($br['blok_name'] ?? ''));
                        if ($bname === '') $bname = '-';
                        $bname = str_replace('-', ' ', $bname);
                        $bamt = number_format((int)($br['total_refund'] ?? 0), 0, ",", ".");
                        $parts[] = $bname . ' (Rp ' . $bamt . ')';
                    }
                    if (!empty($parts)) {
                        $blok_list = ' ' . implode(', ', $parts) . '.';
                    }
                } catch (Exception $e) {
                }
                $todo_list[] = [
                    'id' => 'refund_pending_' . $rdate,
                    'title' => 'Refund belum diaudit',
                    'desc' => 'Ada refund Rp ' . $amt . ' yang belum diaudit untuk tanggal ' . $rlabel . '.' . $blok_list,
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
