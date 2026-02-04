<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/db_helpers.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$save_message = '';
$save_type = '';
if (!empty($_SESSION['wa_save_message'])) {
    $save_message = (string)$_SESSION['wa_save_message'];
    $save_type = (string)($_SESSION['wa_save_type'] ?? 'info');
    unset($_SESSION['wa_save_message'], $_SESSION['wa_save_type']);
}

$stats_db = null;
$stats_db_error = '';
$wa_recipients = [];
$wa_logs = [];

try {
    $stats_db_path = get_stats_db_path();
    if ($stats_db_path !== '') {
        $stats_db = new PDO('sqlite:' . $stats_db_path);
        $stats_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stats_db->exec("PRAGMA journal_mode=WAL;");
        $stats_db->exec("PRAGMA busy_timeout=5000;");
        $stats_db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT,
            target TEXT NOT NULL,
            target_type TEXT NOT NULL DEFAULT 'number',
            active INTEGER NOT NULL DEFAULT 1,
            receive_retur INTEGER NOT NULL DEFAULT 1,
            receive_report INTEGER NOT NULL DEFAULT 1,
            receive_ls INTEGER NOT NULL DEFAULT 1,
            receive_todo INTEGER NOT NULL DEFAULT 1,
            created_at TEXT,
            updated_at TEXT
        )");
        $stats_db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_recipients_target ON whatsapp_recipients(target)");
        $cols = $stats_db->query("PRAGMA table_info(whatsapp_recipients)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
        if (!in_array('receive_ls', $colNames, true)) {
            $stats_db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_ls INTEGER NOT NULL DEFAULT 1");
        }
        if (!in_array('receive_todo', $colNames, true)) {
            $stats_db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_todo INTEGER NOT NULL DEFAULT 1");
        }
        $stats_db->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT,
            message TEXT,
            pdf_file TEXT,
            status TEXT,
            response_json TEXT,
            created_at TEXT
        )");
        $stats_db->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_created ON whatsapp_logs(created_at)");
    }
} catch (Exception $e) {
    $stats_db_error = $e->getMessage();
    $stats_db = null;
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'delete_recipient') {
    $del_id = (int)($_POST['wa_id'] ?? 0);
    if ($stats_db && $del_id > 0) {
        try {
            $stmtDel = $stats_db->prepare("DELETE FROM whatsapp_recipients WHERE id = :id");
            $stmtDel->execute([':id' => $del_id]);
            $save_message = 'Penerima WhatsApp berhasil dihapus.';
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal menghapus penerima WhatsApp.';
            $save_type = 'danger';
        }
    } else {
        $save_message = 'Penerima WhatsApp tidak ditemukan.';
        $save_type = 'warning';
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

function sanitize_wa_label($label) {
    $label = trim((string)$label);
    $label = preg_replace('/\s+/', ' ', $label);
    return $label;
}

function sanitize_wa_target($target) {
    return trim((string)$target);
}

function validate_wa_target($target, $type, &$error) {
    $target = trim((string)$target);
    if ($target === '') {
        $error = 'Target wajib diisi.';
        return false;
    }
    if ($type === 'number') {
        $clean = preg_replace('/\D+/', '', $target);
        if ($clean === '') {
            $error = 'Nomor tidak valid.';
            return false;
        }
        if (strpos($clean, '0') === 0) {
            $clean = '62' . substr($clean, 1);
        }
        if (strpos($clean, '62') !== 0) {
            $error = 'Nomor harus diawali 62.';
            return false;
        }
        if (strlen($clean) < 10 || strlen($clean) > 16) {
            $error = 'Panjang nomor tidak valid.';
            return false;
        }
        return $clean;
    }
    if (!preg_match('/@g\.us$/i', $target)) {
        $error = 'Group ID harus berakhiran @g.us.';
        return false;
    }
    return $target;
}

function wa_format_log_message($msg) {
    $msg = trim((string)$msg);
    if ($msg === '') return '-';
    if (stripos($msg, 'permintaan retur baru') !== false) {
        return 'Permintaan Retur';
    }
    if (stripos($msg, 'permintaan refund') !== false) {
        return 'Permintaan Refund';
    }
    if (stripos($msg, 'laporan settlement harian') !== false) {
        return 'Settlement Harian';
    }
    if (stripos($msg, 'laporan pdf') !== false) {
        return 'Laporan PDF';
    }
    if (stripos($msg, 'laporan') !== false) {
        return 'Laporan';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($msg) : strlen($msg);
    if ($len > 60) {
        return (function_exists('mb_substr') ? mb_substr($msg, 0, 57) : substr($msg, 0, 57)) . '...';
    }
    return $msg;
}

function wa_message_class($msg) {
    $msg = strtolower((string)$msg);
    if (strpos($msg, 'permintaan refund') !== false) {
        return 'wa-msg-refund';
    }
    if (strpos($msg, 'permintaan retur') !== false) {
        return 'wa-msg-retur';
    }
    return '';
}

function wa_load_templates($filePath) {
    if (!is_file($filePath)) return [];
    $raw = @file_get_contents($filePath);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wa_save_templates($filePath, array $templates) {
    $payload = json_encode(array_values($templates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($payload === false) return false;
    return @file_put_contents($filePath, $payload) !== false;
}

function wa_display_target($target, array $label_map) {
    $target = trim((string)$target);
    if ($target === '') return '-';
    if (isset($label_map[$target]) && $label_map[$target] !== '') {
        return $label_map[$target];
    }
    if (stripos($target, '@g.us') !== false) {
        return 'Group';
    }
    $clean = preg_replace('/\D+/', '', $target);
    if ($clean === '') return $target;
    $start = substr($clean, 0, 4);
    $end = substr($clean, -3);
    return $start . '****' . $end;
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'add_recipient') {
    $label = sanitize_wa_label($_POST['wa_new_label'] ?? '');
    $target_raw = sanitize_wa_target($_POST['wa_new_target'] ?? '');
    $type = $_POST['wa_new_type'] ?? 'number';
    $active = isset($_POST['wa_new_active']) ? 1 : 0;
    $receive_retur = isset($_POST['wa_new_receive_retur']) ? 1 : 0;
    $receive_report = isset($_POST['wa_new_receive_report']) ? 1 : 0;
    $receive_ls = isset($_POST['wa_new_receive_ls']) ? 1 : 0;
    $receive_todo = isset($_POST['wa_new_receive_todo']) ? 1 : 0;
    $err = '';
    $validated_target = validate_wa_target($target_raw, $type, $err);
    if ($validated_target === false) {
        $save_message = $err;
        $save_type = 'warning';
    } elseif (!$stats_db) {
        $save_message = 'DB WhatsApp tidak tersedia.';
        $save_type = 'danger';
    } else {
        try {
            $now = date('Y-m-d H:i:s');
            $stmtFind = $stats_db->prepare("SELECT id FROM whatsapp_recipients WHERE target = :target");
            $stmtFind->execute([':target' => $validated_target]);
            $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['id'])) {
                $stmtUp = $stats_db->prepare("UPDATE whatsapp_recipients SET label = :label, target_type = :type, active = 1, updated_at = :now WHERE id = :id");
                $stmtUp->execute([
                    ':label' => $label,
                    ':type' => $type,
                    ':now' => $now,
                    ':id' => (int)$existing['id'],
                ]);
                $save_message = 'Penerima WhatsApp diperbarui.';
            } else {
                $stmtAdd = $stats_db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, receive_retur, receive_report, receive_ls, receive_todo, created_at, updated_at) VALUES (:label, :target, :type, :active, :retur, :report, :ls, :todo, :now, :now)");
                $stmtAdd->execute([
                    ':label' => $label,
                    ':target' => $validated_target,
                    ':type' => $type,
                    ':active' => $active,
                    ':retur' => $receive_retur,
                    ':report' => $receive_report,
                    ':ls' => $receive_ls,
                    ':todo' => $receive_todo,
                    ':now' => $now,
                ]);
                $save_message = 'Penerima WhatsApp berhasil ditambahkan.';
            }
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal menambahkan penerima WhatsApp.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'update_recipient') {
    $id = (int)($_POST['wa_id'] ?? 0);
    if ($stats_db && $id > 0) {
        $label = sanitize_wa_label($_POST['wa_label'] ?? '');
        $active = isset($_POST['wa_active']) ? 1 : 0;
        $receive_retur = isset($_POST['wa_receive_retur']) ? 1 : 0;
        $receive_report = isset($_POST['wa_receive_report']) ? 1 : 0;
        $receive_ls = isset($_POST['wa_receive_ls']) ? 1 : 0;
        $receive_todo = isset($_POST['wa_receive_todo']) ? 1 : 0;
        try {
            $stmtUp = $stats_db->prepare("UPDATE whatsapp_recipients SET label = :label, active = :active, receive_retur = :retur, receive_report = :report, receive_ls = :ls, receive_todo = :todo, updated_at = :now WHERE id = :id");
            $stmtUp->execute([
                ':label' => $label,
                ':active' => $active,
                ':retur' => $receive_retur,
                ':report' => $receive_report,
                ':ls' => $receive_ls,
                ':todo' => $receive_todo,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
            ]);
            $save_message = 'Pengaturan penerima diperbarui.';
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal memperbarui penerima.';
            $save_type = 'danger';
        }
    } else {
        $save_message = 'Penerima tidak ditemukan.';
        $save_type = 'warning';
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

$wa_templates_file = __DIR__ . '/whatsapp_templates.json';
$wa_templates = wa_load_templates($wa_templates_file);

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'save_template') {
    $tpl_id = trim((string)($_POST['wa_tpl_id'] ?? ''));
    $tpl_name = trim((string)($_POST['wa_tpl_name'] ?? ''));
    $tpl_category = trim((string)($_POST['wa_tpl_category'] ?? ''));
    $tpl_body = trim((string)($_POST['wa_tpl_body'] ?? ''));
    if ($tpl_name === '' || $tpl_body === '') {
        $save_message = 'Nama dan isi template wajib diisi.';
        $save_type = 'warning';
    } else {
        if ($tpl_id === '') {
            $tpl_id = uniqid('tpl_', true);
        }
        $found = false;
        foreach ($wa_templates as &$tpl) {
            if (!is_array($tpl)) continue;
            if ((string)($tpl['id'] ?? '') === $tpl_id) {
                $tpl['name'] = $tpl_name;
                $tpl['category'] = $tpl_category;
                $tpl['body'] = $tpl_body;
                $found = true;
                break;
            }
        }
        unset($tpl);
        if (!$found) {
            $wa_templates[] = [
                'id' => $tpl_id,
                'name' => $tpl_name,
                'category' => $tpl_category,
                'body' => $tpl_body,
            ];
        }
        if (wa_save_templates($wa_templates_file, $wa_templates)) {
            $save_message = 'Template WhatsApp berhasil disimpan.';
            $save_type = 'success';
        } else {
            $save_message = 'Gagal menyimpan template WhatsApp.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'delete_template') {
    $tpl_id = trim((string)($_POST['wa_tpl_id'] ?? ''));
    if ($tpl_id === '') {
        $save_message = 'Template tidak ditemukan.';
        $save_type = 'warning';
    } else {
        $wa_templates = array_values(array_filter($wa_templates, function($tpl) use ($tpl_id) {
            return (string)($tpl['id'] ?? '') !== $tpl_id;
        }));
        if (wa_save_templates($wa_templates_file, $wa_templates)) {
            $save_message = 'Template WhatsApp berhasil dihapus.';
            $save_type = 'success';
        } else {
            $save_message = 'Gagal menghapus template WhatsApp.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'test_send') {
    $test_message = trim((string)($_POST['wa_test_message'] ?? ''));
    $test_target = trim((string)($_POST['wa_test_target'] ?? ''));
    if ($test_target === '' && !empty($_POST['wa_test_target_select'])) {
        $test_target = trim((string)$_POST['wa_test_target_select']);
    }
    if ($test_message === '') {
        $save_message = 'Pesan test wajib diisi.';
        $save_type = 'warning';
    } elseif ($test_target === '') {
        $save_message = 'Target test wajib diisi.';
        $save_type = 'warning';
    } else {
        $wa_helper_file = __DIR__ . '/../system/whatsapp/wa_helper.php';
        if (file_exists($wa_helper_file)) {
            require_once $wa_helper_file;
        }
        if (function_exists('wa_send_text')) {
            $res = wa_send_text($test_message, $test_target, 'test');
            if (!empty($res['ok'])) {
                $save_message = 'Test WhatsApp terkirim.';
                $save_type = 'success';
            } else {
                $save_message = 'Test WhatsApp gagal: ' . ($res['message'] ?? 'error');
                $save_type = 'danger';
            }
        } else {
            $save_message = 'WA helper tidak tersedia.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'test_template') {
    $tpl_body = trim((string)($_POST['wa_tpl_body'] ?? ''));
    $tpl_category = trim((string)($_POST['wa_tpl_category'] ?? ''));
    if ($tpl_body === '') {
        $save_message = 'Isi template wajib diisi.';
        $save_type = 'warning';
    } else {
        $wa_helper_file = __DIR__ . '/../system/whatsapp/wa_helper.php';
        if (file_exists($wa_helper_file)) {
            require_once $wa_helper_file;
        }
        $message = $tpl_body;
        $category_key = strtolower($tpl_category);
        $targets = function_exists('wa_get_active_recipients') ? wa_get_active_recipients($category_key) : [];
        if ($category_key !== 'test' && empty($targets)) {
            $save_message = 'Tidak ada penerima aktif untuk kategori ini.';
            $save_type = 'warning';
        }
        if (strtolower($tpl_category) === 'todo') {
            $todo_helper = __DIR__ . '/../include/todo_helper.php';
            if (file_exists($todo_helper)) {
                require_once $todo_helper;
            }
            $env = [];
            $envFile = __DIR__ . '/../include/env.php';
            if (file_exists($envFile)) {
                require $envFile;
            }
            $backupKey = $env['backup']['secret'] ?? '';
            $items = function_exists('app_collect_todo_items') ? app_collect_todo_items($env, '', $backupKey) : [];
            if (empty($items)) {
                $save_message = 'Tidak ada data todo untuk dikirim.';
                $save_type = 'warning';
            } else {
                $max_items = 20;
                $lines = [];
                $idx = 1;
                foreach ($items as $it) {
                    if ($idx > $max_items) break;
                    $title = trim((string)($it['title'] ?? ''));
                    $desc = trim((string)($it['desc'] ?? ''));
                    $line = $title;
                    if ($desc !== '') $line .= ' - ' . $desc;
                    $line = preg_replace('/\s+/', ' ', $line);
                    if (function_exists('mb_strlen')) {
                        if (mb_strlen($line) > 140) $line = mb_substr($line, 0, 137) . '...';
                    } else {
                        if (strlen($line) > 140) $line = substr($line, 0, 137) . '...';
                    }
                    $lines[] = $idx . '. ' . $line;
                    $idx++;
                }
                $remaining = count($items) - count($lines);
                if ($remaining > 0) $lines[] = '+ ' . $remaining . ' lainnya';
                $list_text = implode("\n", $lines);
                $vars = [
                    'date' => date('d-m-Y'),
                    'count' => (string)count($items),
                    'list' => $list_text
                ];
                if (function_exists('wa_render_template')) {
                    $message = wa_render_template($tpl_body, $vars);
                } else {
                    $message = str_replace(['{{DATE}}','{{COUNT}}','{{LIST}}'], [$vars['date'],$vars['count'],$vars['list']], $tpl_body);
                }
            }
        }

        if ($save_message === '') {
            if (function_exists('wa_send_text')) {
                $send_category = $category_key !== '' ? $category_key : 'test';
                $res = wa_send_text($message, '', $send_category);
                if (!empty($res['ok'])) {
                    $save_message = 'Test WhatsApp terkirim.';
                    $save_type = 'success';
                } else {
                    $save_message = 'Test WhatsApp gagal: ' . ($res['message'] ?? 'error');
                    $save_type = 'danger';
                }
            } else {
                $save_message = 'WA helper tidak tersedia.';
                $save_type = 'danger';
            }
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['save_whatsapp'])) {
    $wh = app_db_get_whatsapp_config();
    $wh['endpoint_send'] = trim((string)($_POST['wa_endpoint_send'] ?? ($wh['endpoint_send'] ?? '')));
    $wh['token'] = trim((string)($_POST['wa_token'] ?? ($wh['token'] ?? '')));
    $raw_targets = trim((string)($_POST['wa_notify_target'] ?? ($wh['notify_target'] ?? '')));
    $targets = preg_split('/[\r\n,;]+/', $raw_targets);
    $targets = array_filter(array_map('trim', (array)$targets), function ($val) {
        return $val !== '';
    });
    $wh['notify_target'] = implode(',', array_values($targets));
    $wh['notify_request_enabled'] = !empty($_POST['wa_notify_request_enabled']);
    $wh['notify_retur_enabled'] = !empty($_POST['wa_notify_retur_enabled']);
    $wh['notify_refund_enabled'] = !empty($_POST['wa_notify_refund_enabled']);
    $wh['notify_ls_enabled'] = !empty($_POST['wa_notify_ls_enabled']);
    $wh['country_code'] = trim((string)($wh['country_code'] ?? '62'));
    if ($wh['country_code'] === '') {
        $wh['country_code'] = '62';
    }
    $wh['timezone'] = trim((string)($wh['timezone'] ?? 'Asia/Makassar'));
    if ($wh['timezone'] === '') {
        $wh['timezone'] = 'Asia/Makassar';
    }
    $wh['log_limit'] = (int)($_POST['wa_log_limit'] ?? ($wh['log_limit'] ?? 50));

    try {
        app_db_set_whatsapp_config($wh);
        $save_message = 'Konfigurasi WhatsApp berhasil disimpan.';
        $save_type = 'success';
    } catch (Exception $e) {
        @error_log(date('c') . " [admin][whatsapp] db save failed: " . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/admin_errors.log');
        $save_message = 'Gagal menyimpan WhatsApp. Penyimpanan database gagal.';
        $save_type = 'danger';
    }

    if ($is_ajax) {
        if ($save_message === '') {
            $save_message = 'Konfigurasi WhatsApp berhasil disimpan.';
            $save_type = 'success';
        }
    } else {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

$wa = app_db_get_whatsapp_config();
$wa_endpoint_send = $wa['endpoint_send'] ?? '';
$wa_token = $wa['token'] ?? '';
$wa_notify_target = $wa['notify_target'] ?? '';
$wa_notify_target_list = '';
if ($wa_notify_target !== '') {
    $tmp_targets = preg_split('/[\r\n,;]+/', (string)$wa_notify_target);
    $tmp_targets = array_filter(array_map('trim', (array)$tmp_targets), function ($val) {
        return $val !== '';
    });
    $wa_notify_target_list = implode("\n", array_values($tmp_targets));
}
$wa_notify_request_enabled = !empty($wa['notify_request_enabled']);
$wa_notify_retur_enabled = !empty($wa['notify_retur_enabled']);
$wa_notify_refund_enabled = !empty($wa['notify_refund_enabled']);
$wa_notify_ls_enabled = !empty($wa['notify_ls_enabled']);
$wa_log_limit = isset($wa['log_limit']) ? (int)$wa['log_limit'] : 50;

if ($stats_db) {
    try {
        $stmtRec = $stats_db->query("SELECT id, label, target, target_type, active, receive_retur, receive_report, receive_ls, receive_todo, created_at, updated_at FROM whatsapp_recipients ORDER BY id DESC");
        $wa_recipients = $stmtRec ? $stmtRec->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $wa_recipients = [];
    }
    try {
        $limit = $wa_log_limit > 0 ? $wa_log_limit : 50;
        $stmtLog = $stats_db->prepare("SELECT id, target, message, pdf_file, status, created_at FROM whatsapp_logs ORDER BY id DESC LIMIT :lim");
        $stmtLog->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmtLog->execute();
        $wa_logs = $stmtLog->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $wa_logs = [];
    }
}

$wa_label_map = [];
$wa_type_map = [];
foreach ($wa_recipients as $rec) {
    $t = trim((string)($rec['target'] ?? ''));
    $l = trim((string)($rec['label'] ?? ''));
    $tp = trim((string)($rec['target_type'] ?? ''));
    if ($t !== '' && $l !== '') {
        $wa_label_map[$t] = $l;
    }
    if ($t !== '' && $tp !== '') {
        $wa_type_map[$t] = $tp;
    }
}
?>

<style>
    .wa-section-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #cbd5db;
    }
    .wa-muted {
        color: #7f8a96;
        font-size: 12px;
    }
    .wa-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.2px;
    }
    .wa-badge-blue { background: rgba(59,130,246,0.2); color: #93c5fd; }
    .wa-badge-purple { background: rgba(168,85,247,0.2); color: #d8b4fe; }
    .wa-badge-green { background: rgba(34,197,94,0.2); color: #86efac; }
    .wa-badge-red { background: rgba(239,68,68,0.2); color: #fca5a5; }
    .wa-badge-gray { background: rgba(148,163,184,0.2); color: #cbd5db; }
    .wa-switch {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        margin: 7px 0;
        padding-right: 20px;
    }
    .wa-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .wa-switch-slider {
        position: relative;
        width: 36px;
        height: 18px;
        background: #3b424a;
        border-radius: 999px;
        transition: 0.2s;
        box-shadow: inset 0 0 0 1px rgba(148,163,184,0.35);
    }
    .wa-switch-slider::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 14px;
        height: 14px;
        background: #d9e1e7;
        border-radius: 50%;
        transition: 0.2s;
    }
    .wa-switch input:checked + .wa-switch-slider {
        background: #1da851;
        box-shadow: inset 0 0 0 1px rgba(37, 211, 102, 0.6);
    }
    .wa-switch input:checked + .wa-switch-slider::after {
        transform: translateX(18px);
        background: #ffffff;
    }
    .wa-cell-stack {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .wa-log-table th, .wa-log-table td { white-space: nowrap; }
    .wa-log-table td.wa-log-message { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wa-log-stack { display: flex; flex-direction: column; gap: 2px; }
    .wa-log-sub { font-size: 11px; color: #8b98a7; }
    .wa-log-table { border-collapse: collapse; width: 100%; }
    .wa-log-table thead th { background: rgba(15, 23, 42, 0.35); border-bottom: 1px solid rgba(148,163,184,0.18); }
    .wa-log-table tbody tr { border-bottom: 1px solid rgba(148,163,184,0.12); }
    .wa-log-table tbody tr:last-child { border-bottom: none; }
    .wa-log-table th, .wa-log-table td { padding: 10px 12px; }
    .wa-msg-refund { color: #fbbf24; font-weight: 600; }
    .wa-msg-retur { color: #f59e0b; font-weight: 600; }
    .wa-popup-row { margin-bottom: 10px; }
    .wa-popup-switches { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px; }
</style>

<div class="card-modern">
    <div class="card-header-modern">
        <h3><i class="fa fa-whatsapp text-green"></i> Konfigurasi WhatsApp</h3>
    </div>
    <div class="card-body-modern">
        <?php if ($save_message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($save_type); ?>" data-auto-close="1" style="margin-bottom: 15px; padding: 15px; border-radius: 10px; position: relative;">
                <?= htmlspecialchars($save_message); ?>
                <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
            </div>
        <?php endif; ?>
        <form method="post" action="./admin.php?id=whatsapp" data-admin-form="whatsapp">
            <input type="hidden" name="wa_notify_request_enabled" value="<?= $wa_notify_request_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_ls_enabled" value="<?= $wa_notify_ls_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_retur_enabled" value="<?= $wa_notify_retur_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_refund_enabled" value="<?= $wa_notify_refund_enabled ? '1' : '0'; ?>">
            <div class="row">
                <div class="col-6">
                    <div class="form-group-modern">
                        <div class="wa-section-title">Konfigurasi Utama</div>
                        <div class="wa-muted">Endpoint, token, dan limit log.</div>
                    </div>
                    <div class="form-group-modern">
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-link"></i></div>
                            <input class="form-control-modern" type="text" name="wa_endpoint_send" value="<?= htmlspecialchars($wa_endpoint_send); ?>" placeholder="https://api.fonnte.com/send">
                        </div>
                    </div>
                    <div class="form-group-modern">
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-key"></i></div>
                            <input class="form-control-modern" type="password" name="wa_token" value="<?= htmlspecialchars($wa_token); ?>" placeholder="Token Fonnte">
                        </div>
                    </div>
                    <div class="form-group-modern">
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-list"></i></div>
                            <input class="form-control-modern" type="number" min="1" max="500" name="wa_log_limit" value="<?= (int)$wa_log_limit; ?>">
                        </div>
                        <small class="wa-muted" style="display:block; margin-top:6px;">Jumlah log terakhir yang ditampilkan di bawah.</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group-modern">
                        <div class="wa-section-title">Test Sender WhatsApp</div>
                        <div class="wa-muted">Kirim pesan uji ke target manual atau pilih dari daftar penerima.</div>
                    </div>
                    <div class="form-group-modern">
                        <form method="post" action="./admin.php?id=whatsapp">
                            <input type="hidden" name="wa_action" value="test_send">
                            <div class="input-group-modern" style="margin-bottom:8px;">
                                <div class="input-icon"><i class="fa fa-phone"></i></div>
                                <input class="form-control-modern" type="text" name="wa_test_target" placeholder="Target (opsional, jika tidak pilih dropdown)">
                            </div>
                            <div class="input-group-modern" style="margin-bottom:8px;">
                                <div class="input-icon"><i class="fa fa-list"></i></div>
                                <select class="form-control-modern" name="wa_test_target_select">
                                    <option value="">-- Pilih dari daftar penerima --</option>
                                    <?php if (!empty($wa_recipients)): ?>
                                        <?php foreach ($wa_recipients as $rec): ?>
                                            <?php
                                                $label = trim((string)($rec['label'] ?? ''));
                                                $target = trim((string)($rec['target'] ?? ''));
                                                $type = (string)($rec['target_type'] ?? 'number');
                                                $show = $label !== '' ? ($label . ' - ' . $target) : $target;
                                            ?>
                                            <option value="<?= htmlspecialchars($target); ?>">[<?= htmlspecialchars($type); ?>] <?= htmlspecialchars($show); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="input-group-modern" style="margin-bottom:8px;">
                                <div class="input-icon"><i class="fa fa-comment"></i></div>
                                <textarea class="form-control-modern" name="wa_test_message" rows="3" placeholder="Pesan test"></textarea>
                            </div>
                            <div style="display:flex;justify-content:flex-end;margin-top: 20px;">
                                <button class="btn-action btn-primary-m" type="submit">
                                    <i class="fa fa-paper-plane"></i> Kirim Test
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-start;margin-top: -50px;width: 200px;margin-bottom: 20px;">
                <button class="btn-action btn-primary-m" type="submit" name="save_whatsapp">
                    <i class="fa fa-save"></i> Simpan WhatsApp
                </button>
            </div>
        </form>

        <div class="row" style="margin-top: 10px;">
            <div class="col-7">
                <div class="form-group-modern" style="padding-right: 10%; padding-left: 10px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top: 20px;">
                        <label class="form-label" style="margin:0;">Daftar Penerima</label>
                        <a class="btn-action btn-outline" href="javascript:void(0)" onclick="openWaRecipientPopup('new')" style="padding:6px 10px; font-size:12px;">
                            <i class="fa fa-plus"></i> Tambah Penerima
                        </a>
                    </div>
                    <small class="wa-muted" style="display:block; margin-bottom:8px;">Kelola izin notifikasi per member (aktif, retur, laporan, L/S, todo).</small>
                    <?php if ($stats_db_error !== ''): ?>
                        <div class="alert alert-danger" style="margin-bottom:10px;">Gagal membaca DB WhatsApp: <?= htmlspecialchars($stats_db_error); ?></div>
                    <?php endif; ?>
                    <div style="margin-top:8px;">
                        <?php if (empty($wa_recipients)): ?>
                            <div class="admin-empty" style="padding:10px;">Belum ada penerima.</div>
                        <?php else: ?>
                            <?php foreach ($wa_recipients as $rec): ?>
                                <?php
                                    $active = !empty($rec['active']);
                                    $type = strtolower((string)($rec['target_type'] ?? 'number'));
                                    $icon = $active ? 'fa-user' : 'fa-user-times';
                                    $label = trim((string)($rec['label'] ?? ''));
                                    $target = trim((string)($rec['target'] ?? ''));
                                    $name = $label !== '' ? $label : wa_display_target($target, $wa_label_map);
                                    $type_badge = $type === 'group' ? 'wa-badge wa-badge-purple' : 'wa-badge wa-badge-blue';
                                ?>
                                <div class="router-item" style="margin-bottom:8px;">
                                    <div class="router-icon"><i class="fa <?= $icon; ?>"></i></div>
                                    <div class="router-info">
                                        <span class="router-name"><?= htmlspecialchars($name); ?></span>
                                        <span class="router-session">Target: <?= htmlspecialchars(wa_display_target($target, $wa_label_map)); ?> | <span class="<?= $type_badge; ?>"><?= htmlspecialchars($type); ?></span></span>
                                    </div>
                                    <div class="router-actions">
                                        <a href="javascript:void(0)" title="Edit" onclick="openWaRecipientPopup(<?= (int)($rec['id'] ?? 0); ?>)"><i class="fa fa-pencil"></i></a>
                                        <a href="javascript:void(0)" title="Hapus" onclick="submitWaRecipientDelete(<?= (int)($rec['id'] ?? 0); ?>)" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-5">
                <div class="form-group-modern" style="display: inline;position: relative;right: 12px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top: 20px;">
                        <label class="form-label" style="margin:0;">Template WhatsApp</label>
                        <a class="btn-action btn-outline" href="javascript:void(0)" onclick="openWaTemplatePopup('new')" style="padding:6px 10px; font-size:12px;">
                            <i class="fa fa-plus"></i> Tambah
                        </a>
                    </div>
                    <small class="wa-muted" style="display:block; margin-bottom:8px;">Centralisasi template pesan, bisa diedit dari popup.</small>
                    <div style="max-height: 320px; overflow:auto;">
                        <?php if (empty($wa_templates)): ?>
                            <div class="admin-empty" style="padding:10px;">Belum ada template.</div>
                        <?php else: ?>
                            <?php foreach ($wa_templates as $tpl): ?>
                                <?php
                                    $tpl_id = (string)($tpl['id'] ?? '');
                                    $tpl_name = trim((string)($tpl['name'] ?? ''));
                                    $tpl_cat = trim((string)($tpl['category'] ?? ''));
                                    $tpl_body = trim((string)($tpl['body'] ?? ''));
                                    $tpl_preview = $tpl_body !== '' ? (strlen($tpl_body) > 60 ? substr($tpl_body, 0, 57) . '...' : $tpl_body) : '-';
                                ?>
                                <div class="router-item" style="margin-bottom:8px;">
                                    <div class="router-icon"><i class="fa fa-comment"></i></div>
                                    <div class="router-info">
                                        <span class="router-name"><?= htmlspecialchars($tpl_name !== '' ? $tpl_name : 'Template'); ?></span>
                                        <span class="router-session"><?= htmlspecialchars($tpl_cat !== '' ? $tpl_cat : 'general'); ?> • <?= htmlspecialchars($tpl_preview); ?></span>
                                    </div>
                                    <div class="router-actions">
                                        <a href="javascript:void(0)" title="Edit" onclick="openWaTemplatePopup('<?= htmlspecialchars($tpl_id); ?>')"><i class="fa fa-pencil"></i></a>
                                        <a href="javascript:void(0)" title="Hapus" onclick="submitWaTemplateDelete('<?= htmlspecialchars($tpl_id); ?>')" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <form id="waRecipientForm" method="post" action="./admin.php?id=whatsapp" style="display:none;"></form>
        <form id="waTemplateForm" method="post" action="./admin.php?id=whatsapp" style="display:none;"></form>
        <form id="waTemplateTestForm" method="post" action="./admin.php?id=whatsapp" style="display:none;"></form>

        <div class="row" style="margin-top: 10px;">
            <div class="col-12">
                <div class="form-group-modern">
                    <label class="form-label">Log WhatsApp Terkirim</label>
                    <div style="overflow:auto;">
                        <table class="table wa-log-table" style="min-width:760px;">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Pesan</th>
                                    <th>File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wa_logs)): ?>
                                    <tr><td colspan="5" style="text-align:center;">Belum ada log.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($wa_logs as $log): ?>
                                        <?php
                                            $raw_msg = (string)($log['message'] ?? '');
                                            $msg = wa_format_log_message($raw_msg);
                                            $msg_class = wa_message_class($raw_msg);
                                            $status = strtolower((string)($log['status'] ?? ''));
                                            $status_badge = strpos($status, 'success') !== false ? 'wa-badge wa-badge-green' : (strpos($status, 'fail') !== false || strpos($status, 'error') !== false ? 'wa-badge wa-badge-red' : 'wa-badge wa-badge-blue');
                                            $created = (string)($log['created_at'] ?? '');
                                            $ts = $created !== '' ? strtotime($created) : false;
                                            $date = $ts ? date('d-m-Y', $ts) : '-';
                                            $time = $ts ? date('H:i', $ts) : '-';
                                            $target_raw = trim((string)($log['target'] ?? ''));
                                            $target_label = wa_display_target($target_raw, $wa_label_map);
                                            $target_name = isset($wa_label_map[$target_raw]) ? $wa_label_map[$target_raw] : $target_label;
                                            $target_type = isset($wa_type_map[$target_raw]) ? $wa_type_map[$target_raw] : (stripos($target_raw, '@g.us') !== false ? 'group' : 'number');
                                            $target_sub = isset($wa_label_map[$target_raw]) ? ucfirst($target_type) : '';
                                            $has_file = trim((string)($log['pdf_file'] ?? '')) !== '';
                                            $file_badge = $has_file ? 'wa-badge wa-badge-blue' : 'wa-badge wa-badge-gray';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="wa-log-stack">
                                                    <span><?= htmlspecialchars($date); ?></span>
                                                    <span class="wa-log-sub"><?= htmlspecialchars($time); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="wa-log-stack">
                                                    <span><?= htmlspecialchars($target_name); ?></span>
                                                    <?php if ($target_sub !== ''): ?>
                                                        <span class="wa-log-sub"><?= htmlspecialchars($target_sub); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><span class="<?= $status_badge; ?>"><?= htmlspecialchars($log['status'] ?? '-'); ?></span></td>
                                            <td class="wa-log-message <?= $msg_class; ?>" title="<?= htmlspecialchars($msg); ?>"><?= htmlspecialchars($msg); ?></td>
                                            <td><span class="<?= $file_badge; ?>"><?= $has_file ? 'PDF' : 'Text'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function () {
                var alertBox = document.querySelector('[data-auto-close="1"]');
                if (!alertBox) return;
                setTimeout(function () {
                    if (alertBox && alertBox.style.display !== 'none') {
                        alertBox.style.display = 'none';
                    }
                }, 3000);
            })();

            window.__waRecipients = <?= json_encode($wa_recipients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            window.__waTemplates = <?= json_encode($wa_templates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            function submitWaRecipientForm(payload) {
                var form = document.getElementById('waRecipientForm');
                if (!form) return;
                form.innerHTML = '';
                Object.keys(payload || {}).forEach(function(key){
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = payload[key];
                    form.appendChild(input);
                });
                form.submit();
            }

            function submitWaTemplateForm(payload) {
                var form = document.getElementById('waTemplateForm');
                if (!form) return;
                form.innerHTML = '';
                Object.keys(payload || {}).forEach(function(key){
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = payload[key];
                    form.appendChild(input);
                });
                form.submit();
            }

            function submitWaTemplateTest(payload) {
                var form = document.getElementById('waTemplateTestForm');
                if (!form) return;
                form.innerHTML = '';
                Object.keys(payload || {}).forEach(function(key){
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = payload[key];
                    form.appendChild(input);
                });
                form.submit();
            }

            function openWaRecipientPopup(recId) {
                if (!window.MikhmonPopup) return;
                var isNew = !recId || recId === 'new';
                var data = null;
                if (!isNew && Array.isArray(window.__waRecipients)) {
                    data = window.__waRecipients.find(function(r){ return String(r.id) === String(recId); }) || null;
                }
                if (!data) {
                    data = { id: 'new', label: '', target: '', target_type: 'number', active: 1, receive_retur: 1, receive_report: 1, receive_ls: 1, receive_todo: 1 };
                }

                var html = '' +
                    '<div class="m-pass-form">' +
                        '<div class="wa-popup-row">' +
                            '<label class="m-pass-label">Nama / Label</label>' +
                            '<input id="wa-rec-label" type="text" class="m-pass-input" value="' + (data.label || '') + '" placeholder="Nama penerima" />' +
                        '</div>' +
                        (isNew ?
                            '<div class="wa-popup-row">' +
                                '<label class="m-pass-label">Target</label>' +
                                '<input id="wa-rec-target" type="text" class="m-pass-input" value="" placeholder="62812xxxxxxx atau 1203xxxx@g.us" />' +
                            '</div>' +
                            '<div class="wa-popup-row">' +
                                '<label class="m-pass-label">Tipe</label>' +
                                '<select id="wa-rec-type" class="m-pass-input">' +
                                    '<option value="number">Nomor</option>' +
                                    '<option value="group">Group</option>' +
                                '</select>' +
                            '</div>'
                        :
                            '<div class="wa-popup-row">' +
                                '<label class="m-pass-label">Target</label>' +
                                '<input type="text" class="m-pass-input" value="' + (data.target || '') + '" readonly />' +
                            '</div>' +
                            '<div class="wa-popup-row">' +
                                '<label class="m-pass-label">Tipe</label>' +
                                '<input type="text" class="m-pass-input" value="' + (data.target_type || 'number') + '" readonly />' +
                            '</div>'
                        ) +
                        '<div class="wa-popup-switches">' +
                            '<label class="wa-switch">' +
                                '<input id="wa-rec-active" type="checkbox" ' + ((data.active ? 'checked' : '')) + '>' +
                                '<span class="wa-switch-slider"></span>' +
                                '<span style="font-size:12px; color:#cbd5db;">Aktif</span>' +
                            '</label>' +
                            '<label class="wa-switch">' +
                                '<input id="wa-rec-retur" type="checkbox" ' + ((data.receive_retur ? 'checked' : '')) + '>' +
                                '<span class="wa-switch-slider"></span>' +
                                '<span style="font-size:12px; color:#cbd5db;">Notif Retur</span>' +
                            '</label>' +
                            '<label class="wa-switch">' +
                                '<input id="wa-rec-report" type="checkbox" ' + ((data.receive_report ? 'checked' : '')) + '>' +
                                '<span class="wa-switch-slider"></span>' +
                                '<span style="font-size:12px; color:#cbd5db;">Notif Laporan</span>' +
                            '</label>' +
                            '<label class="wa-switch">' +
                                '<input id="wa-rec-ls" type="checkbox" ' + ((data.receive_ls ? 'checked' : '')) + '>' +
                                '<span class="wa-switch-slider"></span>' +
                                '<span style="font-size:12px; color:#cbd5db;">Notif L/S</span>' +
                            '</label>' +
                            '<label class="wa-switch">' +
                                '<input id="wa-rec-todo" type="checkbox" ' + ((data.receive_todo ? 'checked' : '')) + '>' +
                                '<span class="wa-switch-slider"></span>' +
                                '<span style="font-size:12px; color:#cbd5db;">Notif Todo</span>' +
                            '</label>' +
                        '</div>' +
                    '</div>';

                window.MikhmonPopup.open({
                    title: isNew ? 'Tambah Penerima' : 'Edit Penerima',
                    iconClass: 'fa fa-user',
                    statusIcon: 'fa fa-whatsapp',
                    statusColor: '#22c55e',
                    cardClass: 'is-medium',
                    messageHtml: html,
                    buttons: [
                        { label: 'Batal', className: 'm-btn m-btn-cancel' },
                        {
                            label: 'Simpan',
                            className: 'm-btn m-btn-success',
                            close: false,
                            onClick: function(){
                                var label = (document.getElementById('wa-rec-label') || {}).value || '';
                                var active = (document.getElementById('wa-rec-active') || {}).checked;
                                var retur = (document.getElementById('wa-rec-retur') || {}).checked;
                                var report = (document.getElementById('wa-rec-report') || {}).checked;
                                var ls = (document.getElementById('wa-rec-ls') || {}).checked;
                                var todo = (document.getElementById('wa-rec-todo') || {}).checked;
                                if (!label.trim()) {
                                    label = '';
                                }
                                if (isNew) {
                                    var target = (document.getElementById('wa-rec-target') || {}).value || '';
                                    var type = (document.getElementById('wa-rec-type') || {}).value || 'number';
                                    if (!target.trim()) {
                                        return;
                                    }
                                    submitWaRecipientForm({
                                        wa_action: 'add_recipient',
                                        wa_new_label: label.trim(),
                                        wa_new_target: target.trim(),
                                        wa_new_type: type,
                                        wa_new_active: active ? '1' : '',
                                        wa_new_receive_retur: retur ? '1' : '',
                                        wa_new_receive_report: report ? '1' : '',
                                        wa_new_receive_ls: ls ? '1' : '',
                                        wa_new_receive_todo: todo ? '1' : ''
                                    });
                                } else {
                                    submitWaRecipientForm({
                                        wa_action: 'update_recipient',
                                        wa_id: data.id,
                                        wa_label: label.trim(),
                                        wa_active: active ? '1' : '',
                                        wa_receive_retur: retur ? '1' : '',
                                        wa_receive_report: report ? '1' : '',
                                        wa_receive_ls: ls ? '1' : '',
                                        wa_receive_todo: todo ? '1' : ''
                                    });
                                }
                            }
                        }
                    ]
                });
            }

            function submitWaRecipientDelete(recId) {
                if (!recId) return;
                if (!confirm('Hapus penerima ini?')) return;
                submitWaRecipientForm({ wa_action: 'delete_recipient', wa_id: recId });
            }

            function openWaTemplatePopup(tplId) {
                if (!window.MikhmonPopup) return;
                var isNew = !tplId || tplId === 'new';
                var data = null;
                if (!isNew && Array.isArray(window.__waTemplates)) {
                    data = window.__waTemplates.find(function(t){ return String(t.id) === String(tplId); }) || null;
                }
                if (!data) {
                    data = { id: 'new', name: '', category: 'general', body: '' };
                }

                var html = '' +
                    '<div class="m-pass-form">' +
                        '<div class="wa-popup-row">' +
                            '<label class="m-pass-label">Nama Template</label>' +
                            '<input id="wa-tpl-name" type="text" class="m-pass-input" value="' + (data.name || '') + '" placeholder="Nama template" />' +
                        '</div>' +
                        '<div class="wa-popup-row">' +
                            '<label class="m-pass-label">Kategori</label>' +
                            '<input id="wa-tpl-category" type="text" class="m-pass-input" value="' + (data.category || '') + '" placeholder="retur / report / test" />' +
                        '</div>' +
                        '<div class="wa-popup-row">' +
                            '<label class="m-pass-label">Isi Template</label>' +
                            '<textarea id="wa-tpl-body" class="m-pass-input" rows="6" placeholder="Isi pesan..." style="min-height: 150px; max-width: 100%; min-width: 100%; overflow: hidden; display: block;">' + (data.body || '') + '</textarea>' +
                        '</div>' +
                    '</div>';

                window.MikhmonPopup.open({
                    title: isNew ? 'Tambah Template' : 'Edit Template',
                    iconClass: 'fa fa-comment',
                    statusIcon: 'fa fa-whatsapp',
                    statusColor: '#22c55e',
                    cardClass: 'is-medium',
                    messageHtml: html,
                    buttons: [
                        { label: 'Batal', className: 'm-btn m-btn-cancel' },
                        {
                            label: 'Test Kirim',
                            className: 'm-btn m-btn-warning',
                            close: false,
                            onClick: function(){
                                var body = (document.getElementById('wa-tpl-body') || {}).value || '';
                                submitWaTemplateTest({
                                    wa_action: 'test_template',
                                    wa_tpl_category: (document.getElementById('wa-tpl-category') || {}).value || '',
                                    wa_tpl_body: body
                                });
                            }
                        },
                        {
                            label: 'Simpan',
                            className: 'm-btn m-btn-success',
                            close: false,
                            onClick: function(){
                                var name = (document.getElementById('wa-tpl-name') || {}).value || '';
                                var category = (document.getElementById('wa-tpl-category') || {}).value || '';
                                var body = (document.getElementById('wa-tpl-body') || {}).value || '';
                                if (!name.trim() || !body.trim()) {
                                    return;
                                }
                                submitWaTemplateForm({
                                    wa_action: 'save_template',
                                    wa_tpl_id: isNew ? '' : data.id,
                                    wa_tpl_name: name.trim(),
                                    wa_tpl_category: category.trim(),
                                    wa_tpl_body: body
                                });
                            }
                        }
                    ]
                });
            }

            function submitWaTemplateDelete(tplId) {
                if (!tplId) return;
                if (!confirm('Hapus template ini?')) return;
                submitWaTemplateForm({ wa_action: 'delete_template', wa_tpl_id: tplId });
            }
        </script>
    </div>
</div>
