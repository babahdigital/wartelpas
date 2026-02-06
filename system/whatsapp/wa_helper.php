<?php

require_once __DIR__ . '/../../include/db_helpers.php';
require_once __DIR__ . '/../../include/db.php';

function wa_table_exists(PDO $db, $table)
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
    $stmt->execute([':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function wa_migrate_whatsapp_db($targetFile)
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!function_exists('get_stats_db_path')) return;
    $legacyFile = get_stats_db_path();
    if ($legacyFile === $targetFile || !is_file($legacyFile)) return;

    try {
        $legacy = new PDO('sqlite:' . $legacyFile);
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $target = new PDO('sqlite:' . $targetFile);
        $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $target->exec("PRAGMA journal_mode=WAL;");
        $target->exec("PRAGMA busy_timeout=5000;");

        // Create tables in target if not exists
        $target->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
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
        $target->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_recipients_target ON whatsapp_recipients(target)");
        $target->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT,
            message TEXT,
            pdf_file TEXT,
            status TEXT,
            response_json TEXT,
            request_id TEXT,
            message_id TEXT,
            status_detail TEXT,
            updated_at TEXT,
            created_at TEXT
        )");
        $target->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_created ON whatsapp_logs(created_at)");

        // Copy recipients if target empty
        if (wa_table_exists($legacy, 'whatsapp_recipients')) {
            $count = (int)$target->query("SELECT COUNT(*) FROM whatsapp_recipients")->fetchColumn();
            if ($count === 0) {
                $rows = $legacy->query("SELECT label,target,target_type,active,receive_retur,receive_report,receive_ls,receive_todo,created_at,updated_at FROM whatsapp_recipients")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $stmt = $target->prepare("INSERT INTO whatsapp_recipients (label,target,target_type,active,receive_retur,receive_report,receive_ls,receive_todo,created_at,updated_at)
                        VALUES (:label,:target,:target_type,:active,:receive_retur,:receive_report,:receive_ls,:receive_todo,:created_at,:updated_at)");
                    foreach ($rows as $r) {
                        $stmt->execute([
                            ':label' => $r['label'] ?? '',
                            ':target' => $r['target'] ?? '',
                            ':target_type' => $r['target_type'] ?? 'number',
                            ':active' => (int)($r['active'] ?? 1),
                            ':receive_retur' => (int)($r['receive_retur'] ?? 1),
                            ':receive_report' => (int)($r['receive_report'] ?? 1),
                            ':receive_ls' => (int)($r['receive_ls'] ?? 1),
                            ':receive_todo' => (int)($r['receive_todo'] ?? 1),
                            ':created_at' => $r['created_at'] ?? null,
                            ':updated_at' => $r['updated_at'] ?? null,
                        ]);
                    }
                }
            }
        }

        // Copy logs if target empty
        if (wa_table_exists($legacy, 'whatsapp_logs')) {
            $count = (int)$target->query("SELECT COUNT(*) FROM whatsapp_logs")->fetchColumn();
            if ($count === 0) {
                $rows = $legacy->query("SELECT target,message,pdf_file,status,response_json,created_at FROM whatsapp_logs")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $stmt = $target->prepare("INSERT INTO whatsapp_logs (target,message,pdf_file,status,response_json,created_at)
                        VALUES (:target,:message,:pdf_file,:status,:response_json,:created_at)");
                    foreach ($rows as $r) {
                        $stmt->execute([
                            ':target' => $r['target'] ?? '',
                            ':message' => $r['message'] ?? '',
                            ':pdf_file' => $r['pdf_file'] ?? '',
                            ':status' => $r['status'] ?? '',
                            ':response_json' => $r['response_json'] ?? '',
                            ':created_at' => $r['created_at'] ?? null,
                        ]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        // silent
    }
}

function wa_get_db_file()
{
    static $dbFile = null;
    if ($dbFile !== null) return $dbFile;
    $dbFile = function_exists('get_whatsapp_db_path') ? get_whatsapp_db_path() : get_stats_db_path();
    wa_migrate_whatsapp_db($dbFile);
    return $dbFile;
}

function wa_get_env_config() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cfg = app_db_get_whatsapp_config();
    $cache = is_array($cfg) ? $cfg : [];
    return $cache;
}

function wa_get_templates() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $root_dir = dirname(__DIR__, 2);
    $file = $root_dir . '/settings/whatsapp_templates.json';
    if (!is_file($file)) {
        $cache = [];
        return $cache;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $cache = [];
        return $cache;
    }
    $data = json_decode($raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function wa_get_template_body($id) {
    $id = (string)$id;
    foreach (wa_get_templates() as $tpl) {
        if (!is_array($tpl)) continue;
        if ((string)($tpl['id'] ?? '') === $id) {
            return (string)($tpl['body'] ?? '');
        }
    }
    return '';
}

function wa_render_template($body, array $vars = []) {
    $out = (string)$body;
    foreach ($vars as $k => $v) {
        $key = strtoupper((string)$k);
        $out = str_replace('{{' . $key . '}}', (string)$v, $out);
    }
    return $out;
}

function wa_normalize_target_single($target, $countryCode = '62') {
    $target = trim((string)$target);
    if ($target === '') return '';
    if (stripos($target, '@g.us') !== false) return $target;

    $clean = preg_replace('/\D+/', '', $target);
    if ($clean === '') return '';
    if (strpos($clean, '0') === 0) {
        $clean = $countryCode . substr($clean, 1);
    }
    if ($countryCode !== '' && strpos($clean, $countryCode) !== 0) {
        $clean = $countryCode . $clean;
    }
    return $clean;
}

function wa_normalize_target($target, $countryCode = '62') {
    $target = trim((string)$target);
    if ($target === '') return '';
    if (preg_match('/[\r\n,;]+/', $target)) {
        $parts = preg_split('/[\r\n,;]+/', $target);
        $normalized = [];
        foreach ((array)$parts as $part) {
            $part = trim((string)$part);
            if ($part === '') continue;
            $norm = wa_normalize_target_single($part, $countryCode);
            if ($norm !== '') $normalized[] = $norm;
        }
        $normalized = array_values(array_unique($normalized));
        return implode(',', $normalized);
    }
    return wa_normalize_target_single($target, $countryCode);
}

function wa_log_message($target, $message, $status, $responseJson = '', $pdfFile = '') {
    $root_dir = dirname(__DIR__, 2);
    $dbFile = wa_get_db_file();
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode=WAL;");
        $db->exec("PRAGMA busy_timeout=5000;");
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT,
            message TEXT,
            pdf_file TEXT,
            status TEXT,
            response_json TEXT,
            request_id TEXT,
            message_id TEXT,
            status_detail TEXT,
            updated_at TEXT,
            created_at TEXT
        )");
        $cols = $db->query("PRAGMA table_info(whatsapp_logs)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
        if (!in_array('request_id', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_logs ADD COLUMN request_id TEXT");
        }
        if (!in_array('message_id', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_logs ADD COLUMN message_id TEXT");
        }
        if (!in_array('status_detail', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_logs ADD COLUMN status_detail TEXT");
        }
        if (!in_array('updated_at', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_logs ADD COLUMN updated_at TEXT");
        }

        $resp = json_decode((string)$responseJson, true);
        $reqId = is_array($resp) ? (string)($resp['requestid'] ?? '') : '';
        $respTargets = is_array($resp) && isset($resp['target']) && is_array($resp['target']) ? $resp['target'] : [];
        $respIds = is_array($resp) && isset($resp['id']) && is_array($resp['id']) ? $resp['id'] : [];

        $targets = [];
        if (!empty($respTargets)) {
            foreach ($respTargets as $t) {
                $t = trim((string)$t);
                if ($t !== '') $targets[] = $t;
            }
        } else {
            $parts = preg_split('/\s*,\s*/', (string)$target, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $p) {
                $p = trim((string)$p);
                if ($p !== '') $targets[] = $p;
            }
        }
        if (empty($targets)) {
            $targets = [(string)$target];
        }

        $stmt = $db->prepare("INSERT INTO whatsapp_logs (target, message, pdf_file, status, response_json, request_id, message_id, status_detail, updated_at, created_at)
            VALUES (:t,:m,:p,:s,:r,:req,:mid,:sd,:u,:c)");

        $now = date('Y-m-d H:i:s');
        foreach ($targets as $idx => $t) {
            $mid = isset($respIds[$idx]) ? (string)$respIds[$idx] : '';
            $stmt->execute([
                ':t' => (string)$t,
                ':m' => (string)$message,
                ':p' => (string)$pdfFile,
                ':s' => (string)$status,
                ':r' => (string)$responseJson,
                ':req' => $reqId,
                ':mid' => $mid,
                ':sd' => '',
                ':u' => $now,
                ':c' => $now
            ]);
        }
    } catch (Exception $e) {
        // silent
    }
}

function wa_get_active_recipients($category = '') {
    $root_dir = dirname(__DIR__, 2);
    $dbFile = wa_get_db_file();
    $targets = [];
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode=WAL;");
        $db->exec("PRAGMA busy_timeout=5000;");
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
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
        $cols = $db->query("PRAGMA table_info(whatsapp_recipients)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
        if (!in_array('receive_ls', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_ls INTEGER NOT NULL DEFAULT 1");
        }
        if (!in_array('receive_todo', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_todo INTEGER NOT NULL DEFAULT 1");
        }
        $where = "active = 1";
        if ($category === 'retur') {
            $where .= " AND receive_retur = 1";
        } elseif ($category === 'report') {
            $where .= " AND receive_report = 1";
        } elseif ($category === 'todo') {
            $where .= " AND receive_todo = 1";
        } elseif ($category === 'ls') {
            $where .= " AND receive_ls = 1";
        }
        $stmt = $db->query("SELECT target FROM whatsapp_recipients WHERE {$where} ORDER BY id ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            $t = trim((string)($r['target'] ?? ''));
            if ($t !== '') $targets[] = $t;
        }
    } catch (Exception $e) {
        // silent
    }
    return $targets;
}

function wa_upsert_recipient($label, $target, $targetType = 'number')
{
    $cfg = wa_get_env_config();
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $target = wa_normalize_target_single($target, $country);
    if ($target === '') return false;

    $dbFile = wa_get_db_file();
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode=WAL;");
        $db->exec("PRAGMA busy_timeout=5000;");
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
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
        $cols = $db->query("PRAGMA table_info(whatsapp_recipients)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
        if (!in_array('receive_ls', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_ls INTEGER NOT NULL DEFAULT 1");
        }
        if (!in_array('receive_todo', $colNames, true)) {
            $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_todo INTEGER NOT NULL DEFAULT 1");
        }

        $stmt = $db->prepare("SELECT id FROM whatsapp_recipients WHERE target = :t LIMIT 1");
        $stmt->execute([':t' => $target]);
        $id = (int)$stmt->fetchColumn();
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE whatsapp_recipients SET label = :l, target_type = :tt, active = 1, receive_retur = 0, receive_report = 0, receive_ls = 0, receive_todo = 0, updated_at = :u WHERE id = :id");
            $stmt->execute([
                ':l' => (string)$label,
                ':tt' => (string)$targetType,
                ':u' => $now,
                ':id' => $id,
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, receive_retur, receive_report, receive_ls, receive_todo, created_at, updated_at)
                VALUES (:l, :t, :tt, 1, 0, 0, 0, 0, :c, :u)");
            $stmt->execute([
                ':l' => (string)$label,
                ':t' => (string)$target,
                ':tt' => (string)$targetType,
                ':c' => $now,
                ':u' => $now,
            ]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function wa_delete_recipient($target = '', $label = '')
{
    $cfg = wa_get_env_config();
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $target = wa_normalize_target_single($target, $country);
    $label = trim((string)$label);
    if ($target === '' && $label === '') return false;

    $dbFile = wa_get_db_file();
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode=WAL;");
        $db->exec("PRAGMA busy_timeout=5000;");
        $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
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
        if ($target !== '') {
            $stmt = $db->prepare("DELETE FROM whatsapp_recipients WHERE target = :t");
            $stmt->execute([':t' => $target]);
        }
        if ($label !== '') {
            $stmt = $db->prepare("DELETE FROM whatsapp_recipients WHERE label = :l");
            $stmt->execute([':l' => $label]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function wa_send_template_message($templateId, array $vars = [], $target = '')
{
    $body = wa_get_template_body($templateId);
    if ($body === '') return ['ok' => false, 'message' => 'Template tidak ditemukan.'];
    $message = wa_render_template($body, $vars);
    return wa_send_text($message, $target, '');
}

function wa_parse_send_response($resp)
{
    $status = 'success';
    $message = 'Sent';
    $json = json_decode((string)$resp, true);
    if (is_array($json)) {
        $detail = strtolower((string)($json['detail'] ?? ''));
        $process = strtolower((string)($json['process'] ?? ''));
        $error = strtolower((string)($json['error'] ?? ''));
        $statusFlag = $json['status'] ?? null;

        if ($statusFlag === false || $error !== '') {
            $status = 'failed';
            $message = $error !== '' ? $error : 'Failed';
        } elseif (strpos($detail, 'queue') !== false || strpos($detail, 'pending') !== false || $process === 'pending') {
            $status = 'success';
            $message = 'Sent';
        }
    }
    return ['status' => $status, 'message' => $message];
}

function wa_validate_number_remote($target, $country, $token)
{
    if ($target === '' || $token === '') return [false, 'Token WhatsApp belum diisi.', 'error'];
    if (!function_exists('curl_init')) return [false, 'cURL tidak tersedia.', 'error'];

    $endpoint = 'https://api.fonnte.com/validate';
    $postFields = [
        'target' => (string)$target,
        'countryCode' => (string)$country,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err !== '' || $code >= 400) {
        $errMsg = $err !== '' ? $err : ('HTTP ' . $code);
        return [false, $errMsg, 'error'];
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json) || empty($json['status'])) {
        return [false, 'Respon validate tidak valid.', 'error'];
    }
    $registered = $json['registered'] ?? [];
    if (is_array($registered) && in_array((string)$target, $registered, true)) {
        return [true, 'OK', 'ok'];
    }

    return [false, 'Nomor tidak terdaftar di WhatsApp.', 'invalid'];
}

function wa_send_text($message, $target = '', $category = '') {
    $cfg = wa_get_env_config();
    $endpoint = trim((string)($cfg['endpoint_send'] ?? 'https://api.fonnte.com/send'));
    $token = trim((string)($cfg['token'] ?? ''));
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $defaultTarget = trim((string)($cfg['notify_target'] ?? ''));
    $notify_ls_enabled = !isset($cfg['notify_ls_enabled']) || $cfg['notify_ls_enabled'] === true || $cfg['notify_ls_enabled'] === 1 || $cfg['notify_ls_enabled'] === '1';

    if ($category === 'report' || $category === 'ls') {
        if (!$notify_ls_enabled) {
            wa_log_message($target, $message, 'failed: disabled', 'notif L/S nonaktif');
            return ['ok' => false, 'message' => 'Notif L/S nonaktif.'];
        }
    }


    if ($target === '') {
        if ($category !== '') {
            $list = wa_get_active_recipients($category);
            if (!empty($list)) {
                $target = implode(',', $list);
            } else {
                wa_log_message($target, $message, 'skipped: empty recipients', 'no active recipients for category: ' . $category);
                return ['ok' => false, 'message' => 'Tidak ada penerima aktif untuk kategori ini.'];
            }
        } else {
            $list = wa_get_active_recipients('');
            $target = !empty($list) ? implode(',', $list) : $defaultTarget;
        }
    }
    $target = wa_normalize_target($target, $country);

    // Validate single-number targets to avoid false queued status
    $validate_warning = '';
    if ($target !== '' && strpos($target, ',') === false && stripos($target, '@g.us') === false) {
        [$okValidate, $errValidate, $validateType] = wa_validate_number_remote($target, $country, $token);
        if (!$okValidate && $validateType === 'invalid') {
            wa_log_message($target, $message, 'invalid', $errValidate);
            return ['ok' => false, 'message' => $errValidate];
        }
        if (!$okValidate && $validateType === 'error') {
            $validate_warning = $errValidate;
        }
    }

    if ($endpoint === '' || $token === '' || $target === '') {
        wa_log_message($target, $message, 'failed: config', 'missing endpoint/token/target');
        return ['ok' => false, 'message' => 'Config WA belum lengkap.'];
    }
    if (!function_exists('curl_init')) {
        wa_log_message($target, $message, 'failed: curl', 'curl not available');
        return ['ok' => false, 'message' => 'cURL tidak tersedia.'];
    }

    $postFields = [
        'target' => $target,
        'message' => $message,
        'countryCode' => (string)$country,
        'connectOnly' => 'false'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err !== '' || $code >= 400) {
        $errMsg = $err !== '' ? $err : ('HTTP ' . $code);
        $detail = $validate_warning !== '' ? ('validate: ' . $validate_warning . ' | ') : '';
        wa_log_message($target, $message, 'failed', $detail . $errMsg . ' | ' . (string)$resp);
        return ['ok' => false, 'message' => $errMsg];
    }

    $parsed = wa_parse_send_response($resp);
    $detail = $validate_warning !== '' ? ('validate: ' . $validate_warning . ' | ') : '';
    wa_log_message($target, $message, $parsed['status'], $detail . (string)$resp);
    return ['ok' => $parsed['status'] !== 'failed', 'message' => $parsed['message'], 'response' => $resp];
}

function wa_send_file($message, $filePath, $target = '', $category = 'report') {
    $cfg = wa_get_env_config();
    $endpoint = trim((string)($cfg['endpoint_send'] ?? 'https://api.fonnte.com/send'));
    $token = trim((string)($cfg['token'] ?? ''));
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $defaultTarget = trim((string)($cfg['notify_target'] ?? ''));
    $notify_ls_enabled = !isset($cfg['notify_ls_enabled']) || $cfg['notify_ls_enabled'] === true || $cfg['notify_ls_enabled'] === 1 || $cfg['notify_ls_enabled'] === '1';

    if ($category === 'report' || $category === 'ls') {
        if (!$notify_ls_enabled) {
            wa_log_message($target, $message, 'failed: disabled', 'notif L/S nonaktif', basename((string)$filePath));
            return ['ok' => false, 'message' => 'Notif L/S nonaktif.'];
        }
    }

    if ($target === '') {
        if ($category !== '') {
            $list = wa_get_active_recipients($category);
            if (!empty($list)) {
                $target = implode(',', $list);
            } else {
                wa_log_message($target, $message, 'skipped: empty recipients', 'no active recipients for category: ' . $category, basename((string)$filePath));
                return ['ok' => false, 'message' => 'Tidak ada penerima aktif untuk kategori ini.'];
            }
        } else {
            $list = wa_get_active_recipients('');
            $target = !empty($list) ? implode(',', $list) : $defaultTarget;
        }
    }
    $target = wa_normalize_target($target, $country);

    if ($endpoint === '' || $token === '' || $target === '') {
        wa_log_message($target, $message, 'failed: config', 'missing endpoint/token/target', basename((string)$filePath));
        return ['ok' => false, 'message' => 'Config WA belum lengkap.'];
    }
    if (!function_exists('curl_init')) {
        wa_log_message($target, $message, 'failed: curl', 'curl not available', basename((string)$filePath));
        return ['ok' => false, 'message' => 'cURL tidak tersedia.'];
    }
    if ($filePath === '' || !is_file($filePath)) {
        wa_log_message($target, $message, 'failed: file', 'file not found', basename((string)$filePath));
        return ['ok' => false, 'message' => 'File PDF tidak ditemukan.'];
    }

    $size = filesize($filePath);
    if ($size !== false && $size > 4 * 1024 * 1024) {
        wa_log_message($target, $message, 'failed: size', 'file > 4MB', basename((string)$filePath));
        return ['ok' => false, 'message' => 'File PDF lebih dari 4MB.'];
    }

    $postFields = [
        'target' => $target,
        'message' => $message,
        'countryCode' => (string)$country,
        'connectOnly' => 'false',
        'file' => new CURLFile($filePath, 'application/pdf', basename($filePath)),
        'filename' => basename($filePath)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err !== '' || $code >= 400) {
        $errMsg = $err !== '' ? $err : ('HTTP ' . $code);
        wa_log_message($target, $message, 'failed', $errMsg . ' | ' . (string)$resp, basename($filePath));
        return ['ok' => false, 'message' => $errMsg];
    }

    $parsed = wa_parse_send_response($resp);
    wa_log_message($target, $message, $parsed['status'], (string)$resp, basename($filePath));
    return ['ok' => $parsed['status'] !== 'failed', 'message' => $parsed['message'], 'response' => $resp];
}
