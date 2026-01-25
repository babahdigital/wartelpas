<?php

return [
    'db_file' => dirname(__DIR__, 2) . '/db_data/mikhmon_stats.db',
    'pdf_dir' => dirname(__DIR__) . '/pdf',
    'log_limit' => 50,
    'timezone' => 'Asia/Makassar',
    'wa' => [
        'endpoint_send' => 'https://api.fonnte.com/send',
        'token' => 'g7A8dpDfJrnR5SBGXunU',
        'country_code' => '62'
    ]
];
