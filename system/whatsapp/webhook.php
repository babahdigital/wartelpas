<?php
// Webhook receiver for Fonnte message status updates
error_reporting(0);

require_once __DIR__ . '/../../include/db_helpers.php';
require_once __DIR__ . '/wa_helper.php';

$env = [];
$envFile = __DIR__ . '/../../include/env.php';
if (is_file($envFile)) {
    require $envFile;
}

$tokenCfg = $env['security']['whatsapp_webhook_token'] ?? '';
if ($tokenCfg !== '') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
        $token = trim((string)$_SERVER['HTTP_X_WARTELPAS_KEY']);
    }
    if ($token === '' || !hash_equals((string)$tokenCfg, (string)$token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Invalid token']);
        exit;
    }
}

$raw = file_get_contents('php://input');
$payload = [];
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $payload = $json;
    }
}
if (empty($payload)) {
    $payload = $_POST ?: [];
}

$requestId = (string)($payload['requestid'] ?? $payload['request_id'] ?? '');
$messageId = (string)($payload['id'] ?? $payload['message_id'] ?? '');
$statusRaw = (string)($payload['message_status'] ?? $payload['status'] ?? $payload['state'] ?? '');
$target = (string)($payload['target'] ?? '');

$statusLower = strtolower($statusRaw);
if (in_array($statusLower, ['sent','delivered','success'], true)) {
    $finalStatus = 'sent';
} elseif (in_array($statusLower, ['failed','error','invalid','expired'], true)) {
    $finalStatus = 'failed';
} elseif (in_array($statusLower, ['pending','queued','processing','waiting'], true)) {
    $finalStatus = 'queued';
} else {
    $finalStatus = $statusLower !== '' ? $statusLower : 'queued';
}

$dbFile = wa_get_db_file();
$updated = 0;
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $now = date('Y-m-d H:i:s');
    if ($messageId !== '') {
        $stmt = $db->prepare("UPDATE whatsapp_logs SET status = :s, status_detail = :d, updated_at = :u WHERE message_id = :mid");
        $stmt->execute([
            ':s' => $finalStatus,
            ':d' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':u' => $now,
            ':mid' => $messageId,
        ]);
        $updated += $stmt->rowCount();
    }
    if ($updated === 0 && $requestId !== '') {
        $stmt = $db->prepare("UPDATE whatsapp_logs SET status = :s, status_detail = :d, updated_at = :u WHERE request_id = :rid");
        $stmt->execute([
            ':s' => $finalStatus,
            ':d' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':u' => $now,
            ':rid' => $requestId,
        ]);
        $updated += $stmt->rowCount();
    }
    if ($updated === 0 && $target !== '') {
        $stmt = $db->prepare("SELECT id FROM whatsapp_logs WHERE target = :t ORDER BY id DESC LIMIT 1");
        $stmt->execute([':t' => $target]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE whatsapp_logs SET status = :s, status_detail = :d, updated_at = :u WHERE id = :id");
            $stmt->execute([
                ':s' => $finalStatus,
                ':d' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':u' => $now,
                ':id' => $id,
            ]);
            $updated += $stmt->rowCount();
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'DB error']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'updated' => $updated]);
