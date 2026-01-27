<?php
// PROTEKSI FILE ENV
if (substr($_SERVER["REQUEST_URI"], -7) == "env.php") { header("Location:./"); exit(); };

// KONFIGURASI TERPUSAT BACKUP/RESTORE + RCLONE
$env = [
    'backup' => [
        'secret' => 'WartelpasSecureKey',
        'allowed_ips' => ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'],
        'rate_window' => 300,
        'rate_limit' => 1,
        'keep_days' => 14,
        'keep_count' => 30,
        'min_db_size' => 65536
    ],
    'auth' => [
        'operator_user' => 'kamtib',
        'operator_pass' => 'kamtib123'
    ],
    'security' => [
        'sync_usage' => [
            'token' => '',
            'allowlist' => []
        ],
        'usage_ingest' => [
            'token' => 'WartelpasSecureKey',
            'allowlist' => []
        ],
        'live_ingest' => [
            'token' => 'WartelpasSecureKey',
            'allowlist' => []
        ],
        'sync_sales' => [
            'token' => 'WartelpasSecureKey',
            'allowlist' => []
        ],
        'sync_stats' => [
            'token' => 'WartelpasSecureKey',
            'allowlist' => []
        ],
        'tools' => [
            'token' => 'WartelpasSecureKey'
        ],
        'settlement_log' => [
            'token' => 'WartelpasSecureKey'
        ]
    ],
    'pricing' => [
        'price_10' => 5000,
        'price_30' => 20000
    ],
    'profiles' => [
        'profile_10' => '10Menit',
        'profile_30' => '30Menit',
        'label_10' => '10 Menit',
        'label_30' => '30 Menit'
    ],
    'system' => [
        'db_file' => 'db_data/mikhmon_stats.db',
        'log_dir' => 'logs',
        'hotspot_server' => 'wartel',
        'settlement_script' => 'CuciGudangManual'
    ],
    'blok' => [
        'letters' => 'A-F',
        'suffixes' => ['10', '30'],
        'names' => [
            'A' => 'ANUGRAH',
            'B' => 'BERKAH',
            'C' => 'CENDIKIA',
            'D' => 'DINAYA',
            'E' => 'EDUKATORIS',
            'F' => 'FIDARSA'
        ]
    ],
    'rclone' => [
        'bin' => '/usr/bin/rclone',
        'remote' => 'Wartelpas:Wartelpas',
        'enable' => true,
        'upload' => true,
        'download' => true
    ],
    'whatsapp' => [
        'endpoint_send' => 'https://api.fonnte.com/send',
        'token' => 'g7A8dpDfJrnR5SBGXunU',
        'country_code' => '62',
        'timezone' => 'Asia/Makassar',
        'log_limit' => 50
    ]
];
