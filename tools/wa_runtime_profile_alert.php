<?php
// Tools: WhatsApp Runtime Profile Alert
// Contoh:
// php tools/wa_runtime_profile_alert.php --missing-count 2 --missing-profiles "10Menit,30Menit" --targets "10Menit,30Menit"

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

if (!function_exists('wa_send_text')) {
    echo "WA helper tidak tersedia.\n";
    exit(1);
}

$args = $_SERVER['argv'] ?? [];
$opts = [
    'missing-count' => '0',
    'missing-profiles' => '',
    'targets' => '',
    'host' => '',
    'mode' => 'deploy_smoke',
];

for ($i = 1; $i < count($args); $i++) {
    $arg = (string)$args[$i];
    if (strpos($arg, '--') !== 0) {
        continue;
    }

    $raw = substr($arg, 2);
    $key = $raw;
    $val = '1';

    $eqPos = strpos($raw, '=');
    if ($eqPos !== false) {
        $key = substr($raw, 0, $eqPos);
        $val = substr($raw, $eqPos + 1);
    } elseif (isset($args[$i + 1]) && strpos((string)$args[$i + 1], '--') !== 0) {
        $val = (string)$args[$i + 1];
        $i++;
    }

    if (array_key_exists($key, $opts)) {
        $opts[$key] = trim((string)$val);
    }
}

$missing_count = max(0, (int)($opts['missing-count'] ?? 0));
if ($missing_count <= 0) {
    echo "SKIP: missing_count=0\n";
    exit(0);
}

$missing_profiles = trim((string)($opts['missing-profiles'] ?? ''));
$targets = trim((string)($opts['targets'] ?? ''));
$host = trim((string)($opts['host'] ?? ''));
$mode = trim((string)($opts['mode'] ?? 'deploy_smoke'));

if ($missing_profiles === '') {
    $missing_profiles = '-';
}
if ($targets === '') {
    $targets = '-';
}
if ($host === '') {
    $host = function_exists('gethostname') ? (string)gethostname() : '';
}
if ($host === '') {
    $host = php_uname('n');
}
if ($host === '') {
    $host = '-';
}

if (strlen($missing_profiles) > 180) {
    $missing_profiles = substr($missing_profiles, 0, 177) . '...';
}
if (strlen($targets) > 120) {
    $targets = substr($targets, 0, 117) . '...';
}

$template = function_exists('wa_get_template_body') ? wa_get_template_body('runtime_profile_alert') : '';
if ($template === '') {
    $template = "🚨 *RUNTIME PROFILE MISMATCH*\n"
        . "Host: {{HOST}}\n"
        . "Mode: {{MODE}}\n"
        . "Target: {{TARGETS}}\n"
        . "Profile bermasalah: {{MISSING_PROFILES}}\n"
        . "Jumlah mismatch: {{MISSING_COUNT}}\n"
        . "Waktu: {{TIME}}\n"
        . "Tindakan: cek router_apply_profiles_runtime + router_audit_runtime.";
}

$vars = [
    'host' => $host,
    'mode' => $mode,
    'targets' => $targets,
    'missing_profiles' => $missing_profiles,
    'missing_count' => (string)$missing_count,
    'time' => date('d-m-Y H:i:s'),
];

$msg = function_exists('wa_render_template')
    ? wa_render_template($template, $vars)
    : str_replace(
        ['{{HOST}}', '{{MODE}}', '{{TARGETS}}', '{{MISSING_PROFILES}}', '{{MISSING_COUNT}}', '{{TIME}}'],
        [$host, $mode, $targets, $missing_profiles, (string)$missing_count, date('d-m-Y H:i:s')],
        $template
    );

$res = wa_send_text($msg, '', 'ls');
if (!empty($res['ok'])) {
    echo "OK\n";
    exit(0);
}

$err = trim((string)($res['message'] ?? 'gagal kirim alert WA runtime'));
echo 'FAIL|' . ($err !== '' ? $err : 'unknown') . "\n";
exit(1);
