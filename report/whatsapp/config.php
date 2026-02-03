<?php

require_once __DIR__ . '/../../include/db_helpers.php';
require_once __DIR__ . '/../../include/db.php';

$root_dir = dirname(__DIR__, 2);
$dbFile = get_stats_db_path();
$wa_env = app_db_get_whatsapp_config();

return [
    'db_file' => $dbFile,
    'pdf_dir' => dirname(__DIR__) . '/pdf',
    'log_limit' => isset($wa_env['log_limit']) ? (int)$wa_env['log_limit'] : 50,
    'timezone' => isset($wa_env['timezone']) ? (string)$wa_env['timezone'] : 'Asia/Makassar',
    'wa' => [
        'endpoint_send' => isset($wa_env['endpoint_send']) ? (string)$wa_env['endpoint_send'] : 'https://api.fonnte.com/send',
        'token' => isset($wa_env['token']) ? (string)$wa_env['token'] : '',
        'country_code' => isset($wa_env['country_code']) ? (string)$wa_env['country_code'] : '62'
    ]
];
