<?php

require_once __DIR__ . '/../../include/db_helpers.php';

function wa_get_env_config() {
    $root_dir = dirname(__DIR__, 2);
    $env = [];
    $envFile = $root_dir . '/include/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    return $env['whatsapp'] ?? [];
}

function wa_normalize_target($target, $countryCode = '62') {
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

function wa_log_message($target, $message, $status, $responseJson = '', $pdfFile = '') {
    $root_dir = dirname(__DIR__, 2);
    $dbFile = get_stats_db_path();
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
            created_at TEXT
        )");
        $stmt = $db->prepare("INSERT INTO whatsapp_logs (target, message, pdf_file, status, response_json, created_at)
            VALUES (:t,:m,:p,:s,:r,:c)");
        $stmt->execute([
            ':t' => (string)$target,
            ':m' => (string)$message,
            ':p' => (string)$pdfFile,
            ':s' => (string)$status,
            ':r' => (string)$responseJson,
            ':c' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // silent
    }
}

function wa_get_active_recipients($category = '') {
    $root_dir = dirname(__DIR__, 2);
    $dbFile = get_stats_db_path();
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
            created_at TEXT,
            updated_at TEXT
        )");
        $where = "active = 1";
        if ($category === 'retur') {
            $where .= " AND receive_retur = 1";
        } elseif ($category === 'report') {
            $where .= " AND receive_report = 1";
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

function wa_send_text($message, $target = '', $category = '') {
    $cfg = wa_get_env_config();
    $endpoint = trim((string)($cfg['endpoint_send'] ?? 'https://api.fonnte.com/send'));
    $token = trim((string)($cfg['token'] ?? ''));
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $defaultTarget = trim((string)($cfg['notify_target'] ?? ''));

    if ($target === '') {
        $target = $defaultTarget;
        if ($target === '') {
            $list = wa_get_active_recipients($category);
            if (!empty($list)) {
                $target = implode(',', $list);
            }
        }
    }
    $target = wa_normalize_target($target, $country);

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
        wa_log_message($target, $message, 'failed', $errMsg . ' | ' . (string)$resp);
        return ['ok' => false, 'message' => $errMsg];
    }

    wa_log_message($target, $message, 'success', (string)$resp);
    return ['ok' => true, 'message' => 'Sent', 'response' => $resp];
}

function wa_send_file($message, $filePath, $target = '', $category = 'report') {
    $cfg = wa_get_env_config();
    $endpoint = trim((string)($cfg['endpoint_send'] ?? 'https://api.fonnte.com/send'));
    $token = trim((string)($cfg['token'] ?? ''));
    $country = trim((string)($cfg['country_code'] ?? '62'));
    $defaultTarget = trim((string)($cfg['notify_target'] ?? ''));

    if ($target === '') {
        $target = $defaultTarget;
        if ($target === '') {
            $list = wa_get_active_recipients($category);
            if (!empty($list)) {
                $target = implode(',', $list);
            }
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

    wa_log_message($target, $message, 'success', (string)$resp, basename($filePath));
    return ['ok' => true, 'message' => 'Sent', 'response' => $resp];
}
