<?php

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$wa_env = $env['whatsapp'] ?? [];

return [
    'db_file' => $root_dir . '/db_data/mikhmon_stats.db',
    'pdf_dir' => $root_dir . '/report/pdf',
    'log_limit' => isset($wa_env['log_limit']) ? (int)$wa_env['log_limit'] : 50,
    'timezone' => isset($wa_env['timezone']) ? (string)$wa_env['timezone'] : 'Asia/Makassar',
    'wa' => [
        'endpoint_send' => isset($wa_env['endpoint_send']) ? (string)$wa_env['endpoint_send'] : 'https://api.fonnte.com/send',
        'token' => isset($wa_env['token']) ? (string)$wa_env['token'] : '',
        'country_code' => isset($wa_env['country_code']) ? (string)$wa_env['country_code'] : '62'
    ]
];