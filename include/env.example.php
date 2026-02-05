<?php
// Dummy environment configuration template
// Copy to include/env.php and fill with real values.

$env = [
    'system' => [
        'db_file' => 'db_data/babahdigital_main.db',
        'app_db_file' => 'db_data/babahdigital_app.db',
        'base_url' => '',
        'ghost_min_bytes' => 0,
    ],
    'auth' => [
        'superadmin_user' => '',
        'superadmin_pass' => '',
        'superadmins' => [],
    ],
    'backup' => [
        'secret' => 'CHANGE_ME',
        'allowed_ips' => ['127.0.0.1', '::1'],
        'keep_days' => 14,
        'keep_count' => 30,
        'min_db_size' => 65536,
        'rate_window' => 300,
        'rate_limit' => 1,
    ],
    'security' => [
        'tools' => ['token' => 'CHANGE_ME'],
        'live_ingest' => ['token' => 'CHANGE_ME'],
        'usage_ingest' => ['token' => 'CHANGE_ME'],
    ],
    'pricing' => [
        'price_10' => 0,
        'price_30' => 0,
        'profile_prices' => [],
    ],
    'profiles' => [
        'labels' => [],
        'profile_10' => '10Menit',
        'profile_30' => '30Menit',
    ],
    'blok' => [
        'letters' => 'A-F',
        'suffixes' => ['10', '30'],
    ],
    'retur_request' => [
        'enabled' => false,
    ],
    'todo' => [
        'audit_after' => '18:00',
        'settlement_open' => '18:00',
        'settlement_close' => '23:59',
        'phone_after' => '18:00',
        'settlement_running_minutes' => 20,
    ],
    'rclone' => [
        'enable' => false,
        'download' => false,
        'bin' => '',
        'remote' => '',
    ],
];
