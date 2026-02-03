<?php
// Tools: WhatsApp L/S Alert Scheduler
// Jalankan via cron/scheduler: php tools/wa_ls_alert.php

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/include/db.php';
$helperFile = $root_dir . '/system/whatsapp/wa_helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;
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

function wa_alert_should_send($db, $key) {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_alerts (key TEXT PRIMARY KEY, last_sent TEXT)");
    $stmt = $db->prepare("SELECT last_sent FROM whatsapp_alerts WHERE key = :k");
    $stmt->execute([':k' => $key]);
    $last = (string)($stmt->fetchColumn() ?: '');
    return $last === '';
}

function wa_alert_mark_sent($db, $key) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO whatsapp_alerts (key, last_sent) VALUES (:k, :t)");
    $stmt->execute([':k' => $key, ':t' => date('Y-m-d H:i:s')]);
}

function wa_pick_last_sync($db, $table) {
    $cols = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $names = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    $col = '';
    if (in_array('sync_date', $names, true)) {
        $col = 'sync_date';
    } elseif (in_array('created_at', $names, true)) {
        $col = 'created_at';
    } elseif ($table === 'live_sales' && in_array('sale_datetime', $names, true)) {
        $col = 'sale_datetime';
    }
    if ($col === '') return '-';
    $val = (string)$db->query("SELECT MAX($col) FROM $table")->fetchColumn();
    return $val !== '' ? $val : '-';
}

$last_sales = wa_pick_last_sync($db, 'sales_history');
$last_live = wa_pick_last_sync($db, 'live_sales');

$late_minutes = 60;
$alerts = [];

$live_ts = ($last_live !== '-') ? strtotime($last_live) : false;
$live_diff = $live_ts ? (int)floor((time() - $live_ts) / 60) : null;
if ($last_live === '-' || ($live_diff !== null && $live_diff >= $late_minutes)) {
    $alerts[] = [
        'key' => 'ls_live',
        'type' => 'Live',
        'time' => $last_live === '-' ? 'Tidak ada data' : $last_live,
        'minutes' => $live_diff !== null ? $live_diff : '-'
    ];
}

$sales_ts = ($last_sales !== '-') ? strtotime($last_sales) : false;
$sales_diff = $sales_ts ? (int)floor((time() - $sales_ts) / 60) : null;
if ($last_sales === '-' || ($sales_diff !== null && $sales_diff >= $late_minutes)) {
    $alerts[] = [
        'key' => 'ls_sales',
        'type' => 'Sales',
        'time' => $last_sales === '-' ? 'Tidak ada data' : $last_sales,
        'minutes' => $sales_diff !== null ? $sales_diff : '-'
    ];
}

if (!function_exists('wa_send_text')) {
    echo "WA helper tidak tersedia.\n";
    exit(1);
}

$template = function_exists('wa_get_template_body') ? wa_get_template_body('ls_alert') : '';
if ($template === '') {
    $template = "⚠️ *L/S {{TYPE}} TELAT*\nTerakhir: {{TIME}}\nSelisih: {{MINUTES}} menit.";
}

foreach ($alerts as $a) {
    if (!wa_alert_should_send($db, $a['key'])) continue;
    $msg = function_exists('wa_render_template')
        ? wa_render_template($template, [
            'type' => $a['type'],
            'time' => $a['time'],
            'minutes' => (string)$a['minutes']
        ])
        : str_replace(['{{TYPE}}','{{TIME}}','{{MINUTES}}'], [$a['type'],$a['time'],(string)$a['minutes']], $template);
    wa_send_text($msg, '', 'ls');
    wa_alert_mark_sent($db, $a['key']);
}

echo "OK\n";
