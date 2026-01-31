<?php
// KHUSUS HANDLE REQUEST AJAX - JSON ONLY
ob_start();
session_start();
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["mikhmon"])) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
    exit;
}

$config = include __DIR__ . '/config.php';
$wa_cfg = $config['wa'] ?? [];

function api_sanitize_wa_target($target) {
    return trim((string)$target);
}

function api_validate_wa_target($target, $type, &$error) {
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
            $error = 'Panjang nomor tidak valid (10-16 digit).';
            return false;
        }
        return $clean;
    }
    if (!preg_match('/@g\.us$/i', $target)) {
        $error = 'Group ID harus diakhiri @g.us.';
        return false;
    }
    return $target;
}

function api_wa_validate_remote($target, $countryCode, $token, &$error) {
    if (empty($token)) {
        $error = 'Token WhatsApp belum diisi di Config.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $error = 'cURL tidak tersedia di server.';
        return false;
    }

    $endpoint = 'https://api.fonnte.com/validate';
    $postFields = [
        'target' => (string)$target,
        'countryCode' => (string)$countryCode
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
        $error = $err !== '' ? $err : ('HTTP Error ' . $code);
        return false;
    }

    $json = json_decode($resp, true);
    if (!$json || !isset($json['status'])) {
        $error = 'Respon server tidak valid.';
        return false;
    }

    $registered = $json['registered'] ?? [];
    if (is_array($registered) && in_array((string)$target, $registered, true)) {
        return true;
    }

    if (strpos($target, '@g.us') !== false) {
        return true;
    }

    $error = 'Nomor tidak terdaftar di WhatsApp.';
    return false;
}

$action = $_GET['wa_action'] ?? '';
if ($action === 'validate') {
    $target_raw = api_sanitize_wa_target($_GET['target'] ?? '');
    $type = $_GET['type'] ?? 'number';
    $err = '';

    $validated_target = api_validate_wa_target($target_raw, $type, $err);
    if ($validated_target === false) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'message' => $err]);
        exit;
    }

    if ($type === 'group') {
        ob_end_clean();
        echo json_encode(['ok' => true, 'message' => 'Format Group ID valid.']);
        exit;
    }

    $token = trim((string)($wa_cfg['token'] ?? ''));
    $country = trim((string)($wa_cfg['country_code'] ?? '62'));
    $is_active = api_wa_validate_remote($validated_target, $country, $token, $err);

    ob_end_clean();
    if ($is_active) {
        echo json_encode(['ok' => true, 'message' => 'Nomor WhatsApp aktif & terdaftar.']);
    } else {
        echo json_encode(['ok' => false, 'message' => $err]);
    }
    exit;
}

ob_end_clean();
echo json_encode(['ok' => false, 'message' => 'Invalid action.']);
exit;