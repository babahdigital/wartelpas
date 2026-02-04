<?php
// Tools: WhatsApp Todo Summary
// Jalankan via cron/scheduler: php tools/wa_todo_summary.php

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/include/db.php';
require_once $root_dir . '/include/todo_helper.php';
$helperFile = $root_dir . '/system/whatsapp/wa_helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;
}

if (!function_exists('wa_send_text')) {
    echo "WA helper tidak tersedia.\n";
    exit(1);
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
    $stats_db = $db_rel;
} else {
    $stats_db = $root_dir . '/' . ltrim($db_rel, '/');
}

if (!is_file($stats_db)) {
    echo "DB stats tidak ditemukan.\n";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $stats_db);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "Gagal membuka DB stats.\n";
    exit(1);
}

function wa_todo_last_sent($db, $key) {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_alerts (key TEXT PRIMARY KEY, last_sent TEXT)");
    $stmt = $db->prepare("SELECT last_sent FROM whatsapp_alerts WHERE key = :k");
    $stmt->execute([':k' => $key]);
    return (string)($stmt->fetchColumn() ?: '');
}

function wa_todo_last_hash($db, $key) {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_alerts (key TEXT PRIMARY KEY, last_sent TEXT)");
    $cols = $db->query("PRAGMA table_info(whatsapp_alerts)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    if (!in_array('last_hash', $colNames, true)) {
        $db->exec("ALTER TABLE whatsapp_alerts ADD COLUMN last_hash TEXT");
    }
    $stmt = $db->prepare("SELECT last_hash FROM whatsapp_alerts WHERE key = :k");
    $stmt->execute([':k' => $key]);
    return (string)($stmt->fetchColumn() ?: '');
}

function wa_todo_mark_sent($db, $key, $hash = '') {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_alerts (key TEXT PRIMARY KEY, last_sent TEXT)");
    $cols = $db->query("PRAGMA table_info(whatsapp_alerts)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    if (!in_array('last_hash', $colNames, true)) {
        $db->exec("ALTER TABLE whatsapp_alerts ADD COLUMN last_hash TEXT");
    }
    $stmt = $db->prepare("INSERT OR REPLACE INTO whatsapp_alerts (key, last_sent, last_hash) VALUES (:k, :t, :h)");
    $stmt->execute([':k' => $key, ':t' => date('Y-m-d H:i:s'), ':h' => (string)$hash]);
}

function wa_todo_clear($db, $key) {
    $stmt = $db->prepare("DELETE FROM whatsapp_alerts WHERE key = :k");
    $stmt->execute([':k' => $key]);
}

$force = isset($_GET['force']) && (string)$_GET['force'] === '1';

$backupKey = $env['backup']['secret'] ?? '';
$todo_items = app_collect_todo_items($env, '', $backupKey);
if (empty($todo_items)) {
    wa_todo_clear($db, 'todo_summary');
    echo "Tidak ada todo.\n";
    exit(0);
}

$payload_hash = md5(json_encode($todo_items));
$last_hash = wa_todo_last_hash($db, 'todo_summary');
if (!$force && $last_hash !== '' && $last_hash === $payload_hash) {
    echo "Tidak ada todo baru.\n";
    exit(0);
}

$max_items = 20;
$lines = [];
$idx = 1;
foreach ($todo_items as $item) {
    if ($idx > $max_items) break;
    $title = trim((string)($item['title'] ?? ''));
    $desc = trim((string)($item['desc'] ?? ''));
    $line = $title;
    if ($desc !== '') {
        $line .= ' - ' . $desc;
    }
    $line = preg_replace('/\s+/', ' ', $line);
    $lines[] = $idx . '. ' . $line;
    $idx++;
}

$remaining = count($todo_items) - count($lines);
if ($remaining > 0) {
    $lines[] = '+ ' . $remaining . ' lainnya';
}

$list_text = implode("\n", $lines);
$today_label = date('d-m-Y');
$template = function_exists('wa_get_template_body') ? wa_get_template_body('todo_summary') : '';
if ($template === '') {
    $template = "🔔 *TODO HARI INI* ({{DATE}})\nTotal: {{COUNT}}\n{{LIST}}";
}

$msg = function_exists('wa_render_template')
    ? wa_render_template($template, [
        'date' => $today_label,
        'count' => (string)count($todo_items),
        'list' => $list_text
    ])
    : str_replace(['{{DATE}}','{{COUNT}}','{{LIST}}'], [$today_label,(string)count($todo_items),$list_text], $template);

$res = wa_send_text($msg, '', 'todo');
if (!empty($res['ok'])) {
    wa_todo_mark_sent($db, 'todo_summary', $payload_hash);
    echo "OK\n";
    exit(0);
}

echo "Gagal kirim.\n";
exit(1);
