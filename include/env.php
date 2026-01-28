<?php
// PROTEKSI FILE ENV
if (substr($_SERVER["REQUEST_URI"], -7) == "env.php") { header("Location:./"); exit(); };

// KONFIGURASI TERPUSAT BACKUP/RESTORE + RCLONE
$env = [
    'backup' => [
        'secret' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE=',
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
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE=',
            'allowlist' => []
        ],
        'live_ingest' => [
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE=',
            'allowlist' => []
        ],
        'sync_sales' => [
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE=',
            'allowlist' => []
        ],
        'sync_stats' => [
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE=',
            'allowlist' => []
        ],
        'tools' => [
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE='
        ],
        'settlement_log' => [
            'token' => 'kX8gerJCDnsEn3GWbk5FCRHfpujZBpja2O3lbHvToWE='
        ]
    ],
    'pricing' => [
        'price_10' => 5000,
        'price_30' => 20000,
        'profile_prices' => [
            '10menit' => 5000,
            '30menit' => 20000
        ],
        'profile_aliases' => []
    ],
    'profiles' => [
        'profile_10' => '10Menit',
        'profile_30' => '30Menit',
        'label_10' => '10 Menit',
        'label_30' => '30 Menit',
        'labels' => [
            '10menit' => '10 Menit',
            '30menit' => '30 Menit'
        ]
    ],
    'system' => [
        'base_url' => 'http://wartelpas.sobigidul.net',
        'db_file' => 'db_data/mikhmon_stats.db',
        'log_dir' => 'logs',
        'hotspot_server' => 'wartel',
        'hotspot_profile' => 'wartelpas',
        'dhcp_server' => 'Wartelpas',
        'wartel_subnet' => '172.16.12.1/24',
        'firewall_cleanup' => [
            'enable' => true,
            'include_offline' => true,
            'max_ips' => 200,
            'max_seconds' => 20
        ],
        'settlement_script' => 'CuciGudangManual',
        'ghost_min_bytes' => 51200
    ],
    'blok' => [
        'letters' => 'A-F',
        'suffixes' => ['10', '30'],
        'aliases' => [],
        'names' => [
            'A' => 'ANUGRAH',
            'B' => 'BERKAH',
            'C' => 'CENDIKIA',
            'D' => 'DINAYA',
            'E' => 'EDUKATORIS',
            'F' => 'FIDARSA'
        ]
    ],
    'report' => [
        'status_priority' => ['retur', 'rusak', 'invalid', 'normal']
    ],
    'audit' => [
        'qty_mode' => 'raw',
        'setoran_mode' => 'net'
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

$GLOBALS['env_config'] = $env;
